<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Exceptions\Tenancy\InvalidTenantTransitionException;
use App\Exceptions\Tenancy\TenantNotOperationalException;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningSpec;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use App\Services\Tenancy\Provisioning\TenantProvisioningStepRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * The tenant state machine, enforced and audited (Req 1.1 / A1; Req 10.3 / B1;
 * design.md §"Tenant lifecycle").
 *
 * ## Why "audited" is in the name
 *
 * Suspending, reactivating, and cancelling a tenant are privileged acts with money and
 * customer data behind them, and they happen from three different places — the Admin
 * panel in platform mode (task 30.3), a dunning/scheduler job with no tenant bound
 * (task 10.x), and tenant self-service. An audit entry is therefore not decoration
 * around the write, it is half of what the write *is*, which is why it happens in the
 * same database transaction: there is no reachable state where a tenant is suspended
 * and nothing says who did it.
 *
 * Two details make that work in all three contexts:
 *
 * - **The chain is named, not inferred.** Every entry passes `tenant: $tenant`
 *   explicitly, so it lands on the subject tenant's chain whether the caller is that
 *   tenant, another tenant, platform mode, or nobody. Inferring it would put an
 *   admin's suspension of tenant B onto the platform chain, away from B's history.
 * - **This service never opens platform mode.** It has no need to: `tenants` is not
 *   `BelongsToTenant` (it is the root of the ownership tree), and the audit writer
 *   names its own chain. Wrapping transitions in `asPlatform()` would add a
 *   `platform.mode.entered` / `.exited` pair to the platform chain per transition —
 *   two rows about the bypass, and no extra evidence about the tenant.
 *
 * ## Provisioning is the same shape, one size up
 *
 * `provision()` (Req 1.8 / A1) is the tenant's birth rather than a transition, and it
 * has to make five writes look like one. It is built the same way as the transitions —
 * one transaction, one audit entry on the tenant's own chain — with two additions: the
 * work itself is a configured, ordered list of `TenantProvisioningStep`s rather than
 * code in this class, and steps that touch state outside the database compensate
 * explicitly when the transaction rolls back. See that method's docblock.
 *
 * ## Everything else is deliberately small
 *
 * No queue draining, no cache invalidation, no session teardown on suspend. Suspension
 * is defined by the guards at the bottom of this class: a status change plus predicates
 * that the send path, the inbound path, and the panels consult. That is what makes it
 * reversible in one write and impossible to half-apply.
 */
final class AuditedTenantLifecycle implements TenantLifecycle
{
    /**
     * Fallback retention window, in days, when none is configured.
     */
    private const int DEFAULT_RETENTION_DAYS = 30;

    /**
     * How much of a caller-supplied reason is kept. An audit payload is evidence of a
     * decision, not a place to paste a support thread.
     */
    private const int REASON_LIMIT = 500;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ConnectionInterface $connection,
        private readonly TenantStorage $storage,
        private readonly TenantProvisioningStepRegistry $steps,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Provisioning (Req 1.8 / A1)
    |--------------------------------------------------------------------------
    */

    /**
     * Run the configured provisioning pipeline as one atomic unit.
     *
     * Read `TenantLifecycle::provision()` for the contract, the accepted spec and the
     * two steps Req 1.8 still owes. This method is deliberately nothing but the
     * transaction and the compensation loop — it knows how to *fail*, and the steps know
     * what a tenant needs.
     *
     * ## Why the compensation loop exists at all
     *
     * If every step were SQL on one connection, the transaction alone would satisfy
     * "atomically" and this would be a `foreach`. It is not: provisioning creates a
     * directory on disk, seals a DEK, and warms two in-process caches, and a `ROLLBACK`
     * moves none of that back. So the pipeline tracks which steps it *started* — started,
     * not finished, because a step that threw halfway is precisely the one with a partial
     * effect — and undoes them in exact reverse order.
     *
     * ## Order of the two halves
     *
     * The transaction is rolled back **first**, then compensations run. Compensating
     * before the rollback would have each `rollback()` racing the very rows the rollback
     * is about to remove (and, for anything reading the database, seeing state that is
     * about to cease existing). This way each compensation runs against the final,
     * post-rollback world: the tenant row is already gone, which is why a `rollback()`
     * must never assume it can read one.
     *
     * ## Failures inside a compensation are swallowed, on purpose
     *
     * The caller needs the *original* failure — the taken slug, the unreachable key
     * store — because that is the one it can act on. A compensation that fails would
     * replace it with something like "unable to delete directory", turning a 409 a
     * signup form could render into an unrelated 500. Suppressed, not ignored: the
     * design's observability layer (task 6.x) is where the residue gets reported, and the
     * only residue possible here is an empty directory under a ULID no tenant row names.
     */
    public function provision(array $spec): Tenant
    {
        $context = new TenantProvisioningContext(TenantProvisioningSpec::fromArray($spec));

        // The pipeline is resolved before the transaction opens: a misconfigured step list
        // is a deployment error, and it must not be discovered with a transaction held
        // open and a tenant row already written.
        $steps = $this->steps->steps();

        /** @var list<TenantProvisioningStep> $started */
        $started = [];

        try {
            $this->connection->transaction(function () use ($steps, $context, &$started): void {
                foreach ($steps as $step) {
                    $started[] = $step;
                    $context->markApplied($step->name());
                    $step->apply($context);
                }
            });
        } catch (Throwable $failure) {
            $this->compensate($started, $context);

            throw $failure;
        }

        return $context->tenant();
    }

    /*
    |--------------------------------------------------------------------------
    | Transitions
    |--------------------------------------------------------------------------
    */

    public function transitionTo(Tenant $tenant, TenantStatus $to, string $reason): Tenant
    {
        $reason = $this->normalizeReason($reason);
        $from = $tenant->status;

        if ($from === $to) {
            // Idempotent by design: a retried job, a double-clicked admin button, and a
            // webhook redelivery all land here, and none of them is an error. No write
            // and no audit entry — the state is already what was asked for, and a second
            // row would misrepresent one decision as two.
            return $tenant;
        }

        if (! $from->canTransitionTo($to)) {
            throw InvalidTenantTransitionException::between($from, $to, $tenant->id);
        }

        // Read before the transaction: it is disk I/O and it only informs the audit
        // payload, so it has no business holding a row lock.
        $offboarding = $to === TenantStatus::Cancelled ? $this->offboardingDebt($tenant) : [];

        $this->connection->transaction(function () use ($tenant, $from, $to, $reason, $offboarding): void {
            $this->applyStatus($tenant, $to);

            $this->audit->write(
                $this->actionFor($from, $to),
                [
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $reason,
                    ...$offboarding,
                ],
                $tenant,
                tenant: $tenant,
            );
        });

        return $tenant;
    }

    public function activate(Tenant $tenant, string $reason = 'Subscription activated'): Tenant
    {
        return $this->transitionTo($tenant, TenantStatus::Active, $reason);
    }

    public function suspend(Tenant $tenant, string $reason): Tenant
    {
        return $this->transitionTo($tenant, TenantStatus::Suspended, $reason);
    }

    public function reactivate(Tenant $tenant, string $reason = 'Suspension lifted'): Tenant
    {
        return $this->transitionTo($tenant, TenantStatus::Active, $reason);
    }

    public function cancel(Tenant $tenant, string $reason): Tenant
    {
        return $this->transitionTo($tenant, TenantStatus::Cancelled, $reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    public function canSendOutbound(Tenant|string $tenant): bool
    {
        return $this->statusOf($tenant)?->isOperational() === true;
    }

    public function assertCanSendOutbound(Tenant|string $tenant): void
    {
        $status = $this->statusOf($tenant);

        if ($status === null) {
            throw TenantNotOperationalException::unknownTenant($this->idOf($tenant), 'send outbound messages');
        }

        if (! $status->isOperational()) {
            throw TenantNotOperationalException::outboundBlocked($this->idOf($tenant), $status);
        }
    }

    public function canRecordInbound(Tenant|string $tenant): bool
    {
        $status = $this->statusOf($tenant);

        // Suspension keeps the inbound log (Req 10.3); cancellation stops it, because a
        // tenant inside its deletion window must not take on new personal data.
        return $status !== null && $status !== TenantStatus::Cancelled;
    }

    public function canAutoReply(Tenant|string $tenant): bool
    {
        return $this->canSendOutbound($tenant);
    }

    public function canMutate(Tenant|string $tenant): bool
    {
        return $this->statusOf($tenant)?->isOperational() === true;
    }

    public function assertCanMutate(Tenant|string $tenant, string $intent): void
    {
        $status = $this->statusOf($tenant);

        if ($status === null) {
            throw TenantNotOperationalException::unknownTenant($this->idOf($tenant), $intent);
        }

        if (! $status->isOperational()) {
            throw TenantNotOperationalException::mutationBlocked($this->idOf($tenant), $status, $intent);
        }
    }

    public function isReadOnly(Tenant|string $tenant): bool
    {
        return ! $this->canMutate($tenant);
    }

    /*
    |--------------------------------------------------------------------------
    | Offboarding seam
    |--------------------------------------------------------------------------
    */

    public function purgeDueAt(Tenant $tenant): ?Carbon
    {
        $cancelledAt = $tenant->cancelled_at;

        if ($cancelledAt === null) {
            return null;
        }

        return $cancelledAt->copy()->addDays($this->retentionDays());
    }

    public function isPurgeDue(Tenant $tenant): bool
    {
        $dueAt = $this->purgeDueAt($tenant);

        return $dueAt !== null && $dueAt->isPast();
    }

    public function retentionDays(): int
    {
        $days = config('wa.tenancy.lifecycle.retention_days');

        // A zero or negative window would make a cancellation purgeable the instant it
        // happens, which is the one thing a retention window exists to prevent.
        return is_numeric($days) && (int) $days > 0 ? (int) $days : self::DEFAULT_RETENTION_DAYS;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Undo, newest first, everything the database transaction could not.
     *
     * @param  list<TenantProvisioningStep>  $started
     */
    private function compensate(array $started, TenantProvisioningContext $context): void
    {
        foreach (array_reverse($started) as $step) {
            try {
                $step->rollback($context);
            } catch (Throwable) {
                // Deliberately swallowed — see provision()'s docblock. The original failure
                // is the one the caller must receive.
            }
        }
    }

    /**
     * The status write, plus the timestamps that make the history legible.
     *
     * `suspended_at` is cleared on the way out of suspension so it always answers "when
     * did the *current* suspension start?" rather than "when was it last suspended?" —
     * the audit trail already answers the second question, and answers it better.
     */
    private function applyStatus(Tenant $tenant, TenantStatus $to): void
    {
        $now = Carbon::now();

        $tenant->status = $to;
        $tenant->suspended_at = $to === TenantStatus::Suspended ? $now : null;

        if ($to === TenantStatus::Cancelled) {
            // Never overwritten on a re-cancel: same-state calls return before reaching
            // here, so the first cancellation is the one the retention window runs from.
            $tenant->cancelled_at = $now;
        }

        $tenant->save();
    }

    /**
     * The audit action for one edge — dotted and past tense, stable enough to query on.
     *
     * `SUSPENDED -> ACTIVE` and `TRIAL -> ACTIVE` are the same write and two different
     * events (a suspension lifted, a trial converted), so the *edge* names the action
     * rather than the destination.
     */
    private function actionFor(TenantStatus $from, TenantStatus $to): string
    {
        return match (true) {
            $to === TenantStatus::Suspended => 'tenant.suspended',
            $to === TenantStatus::Cancelled => 'tenant.cancelled',
            $to === TenantStatus::Active && $from === TenantStatus::Suspended => 'tenant.reactivated',
            $to === TenantStatus::Active => 'tenant.activated',
            default => 'tenant.status.changed',
        };
    }

    /**
     * What offboarding still owes at the moment of cancellation.
     *
     * Cancellation deletes nothing (the design keeps the data until the retention
     * window closes), so the audit entry is the only record that a debt was created.
     * Task 34.3's purge + verification pass closes it and writes the deletion
     * certificate against the same tenant chain; `residual_objects` is read here from
     * `TenantStorage` so the certificate can be compared against the state at
     * cancellation rather than against an assumption.
     *
     * @return array{offboarding: array{retention_days: int, purge_due_at: string, owes: list<string>, residual_objects: bool}}
     */
    private function offboardingDebt(Tenant $tenant): array
    {
        $days = $this->retentionDays();

        return [
            'offboarding' => [
                'retention_days' => $days,
                'purge_due_at' => Carbon::now()->addDays($days)->toIso8601String(),
                'owes' => ['oltp', 'object_storage', 'vector_store', 'analytics'],
                'residual_objects' => ! $this->storage->isPurged($tenant),
            ],
        ];
    }

    /**
     * The status to judge a guard against, or `null` when there is no tenant to judge.
     *
     * A `Tenant` instance is trusted as passed: the send path already resolved it for
     * this unit of work, and re-reading the row on every message would put a query in
     * front of every send. An id is looked up — `tenants` carries no `TenantScope`
     * (it is the root of the ownership tree), so this works from any context: platform
     * mode, another tenant, or nothing bound at all.
     */
    private function statusOf(Tenant|string $tenant): ?TenantStatus
    {
        if ($tenant instanceof Tenant) {
            return $tenant->status;
        }

        if (trim($tenant) === '') {
            return null;
        }

        return Tenant::query()->select(['id', 'status'])->whereKey($tenant)->first()?->status;
    }

    private function idOf(Tenant|string $tenant): string
    {
        return $tenant instanceof Tenant ? $tenant->id : $tenant;
    }

    /**
     * Every transition carries a reason, for the same reason
     * `TenantContext::enterPlatformMode()` does: it is audited, and "someone suspended
     * this tenant, unclear why" is not evidence.
     */
    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'A tenant lifecycle transition requires a non-empty reason (it is audited).'
            );
        }

        return mb_strimwidth($reason, 0, self::REASON_LIMIT, '…');
    }
}
