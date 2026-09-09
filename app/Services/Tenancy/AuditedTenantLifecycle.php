<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Exceptions\Tenancy\InvalidTenantTransitionException;
use App\Exceptions\Tenancy\TenantNotOperationalException;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
    ) {}

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
