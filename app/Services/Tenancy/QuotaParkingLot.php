<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\QuotaHoldStatus;
use App\Enums\QuotaKind;
use App\Events\Tenancy\QuotaHoldResumed;
use App\Events\Tenancy\TenantQuotaRestored;
use App\Models\QuotaHold;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Where metered, long-running work waits when the plan allowance runs out mid-run — and
 * the one thing that hands it back when the allowance returns (Req 3.4 / A3;
 * Req 20.3 / C3; Req 31.1 / NFR2).
 *
 * ```php
 * // …the send gate refused a campaign's next batch:
 * $verdict = $quota->verdict($tenant, QuotaKind::MessagesMonthly, $batch->size());
 *
 * if ($verdict->isDeferred()) {
 *     $parkingLot->park($tenant, $verdict, QuotaHoldSubject::for($campaign, resumer: 'campaign'));
 *     $job->release($verdict->secondsUntilPeriodReset());   // the *job* still defers
 *     return;
 * }
 * ```
 *
 * Req 20.3 in one sentence: *"pause it as `QUOTA_PAUSED` and auto-resume at the next
 * period reset or after top-up/upgrade, never dropping queued sends."* This class owns all
 * three clauses — the pause (`park()`), the period reset (`resumeDue()`, driven by the
 * scheduled `wa:quota:resume-paused` command), and the top-up/upgrade
 * (`allowanceChanged()`).
 *
 * ## It composes with `release()`, it does not replace it
 *
 * Req 31.1 forbids ever dropping an outbound job on a quota limit, and the queue's answer
 * to that is `release()` — the job goes back on the queue and comes round again. That stays
 * exactly as it is. A hold is about the *unit of work above the job*: a campaign of 5 000
 * sends whose individual jobs would otherwise each bounce every few seconds for three
 * weeks, with nothing anywhere recording that the campaign is waiting and nobody telling
 * the tenant.
 *
 * So the two layers answer different questions and the send pipeline (task 9.3) uses both:
 *
 * | Layer | Scope | On a deferred verdict |
 * |---|---|---|
 * | `$job->release($seconds)` | one send | the send is retried later — never dropped |
 * | `park()` | the campaign / batch / import | the run is parked, the tenant is told, and it is resumed once |
 *
 * ### What task 9.3 must call
 *
 * 1. `TenantLifecycle::assertCanSendOutbound()`, then `PlanGate`, then
 *    `QuotaGuard::verdict()` — the order Algorithm 3 fixes.
 * 2. `isDeferred()` → `park()` **and** `release($verdict->secondsUntilPeriodReset())`.
 *    `park()` is idempotent per unit of work, so calling it from every refused job of the
 *    same campaign is correct and cheap: it updates one row.
 * 3. `isBlocked()` → `park()` (which parks *nothing* and returns null, but still notifies
 *    the tenant — Req 3.4 applies to a block too) and then fail the job explicitly with
 *    `QuotaExceededException::from()`. Blocked work must never be released: no reset is
 *    coming, so a release would bounce for ever.
 * 4. after the bridge confirms, `QuotaGuard::consume()` keyed by the message idempotency
 *    key — exactly once, whatever happened above (Req 3.5).
 *
 * A resumer must never consume quota itself; the work it releases goes back through the
 * send gate, which meters it.
 *
 * ## What task 26.2 (campaigns) adds — and must not re-invent
 *
 * 1. keep `campaigns.status = QUOTA_PAUSED` for display and for its own invariants — it is
 *    the *projection* of a hold, not a second source of truth;
 * 2. park with `QuotaHoldSubject::for($campaign, resumer: 'campaign')` from the batch job's
 *    deferred branch;
 * 3. implement `CampaignQuotaResumer implements QuotaResumer` (flip the status back,
 *    re-dispatch the batch — idempotently) and register it as
 *    `'campaign' => CampaignQuotaResumer::class` under `wa.tenancy.quota.holds.resumers`.
 *
 * That is the whole integration: no second table, no second scheduled command, no second
 * definition of "when does the allowance come back". Every later parked-work owner (drip
 * sequences, contact imports, exports) follows the same three steps.
 *
 * ## Resuming: the authority is `QuotaGuard`, not the clock
 *
 * `resume_at` is only ever a hint about *when it is worth asking*. The decision itself is
 * a fresh `QuotaGuard::verdict()` for the hold's own kind and units, which means:
 *
 * - a hold resumes exactly when the allowance really covers it — not merely when a
 *   timestamp passed;
 * - a refusal that has stopped being transient (a downgrade, a limit edited to 0) is
 *   **not** auto-resumed. `QuotaReason::isTransient()` is the line, and the hold keeps
 *   waiting for a plan change or a top-up — which is the honest answer, because no reset
 *   will ever make that work fit;
 * - nothing is ever resumed twice, because the hand-back happens under a claim
 *   (`QuotaHoldStatus::Resuming`).
 *
 * ## The wallet seam (task 10.4)
 *
 * `allowanceChanged($tenant, TenantQuotaRestored::TRIGGER_TOP_UP)` is the *entire*
 * integration point for "resume after top-up". `WalletService::topUp()` calls it after the
 * credit commits — one line, no knowledge of holds — and the same call serves an admin
 * quota grant. It is already wired for the paths that exist today: a tenant moved to
 * another plan (`TenantPlanObserver`) and a plan whose limits were edited
 * (`PlanObserver` → `allowanceChangedForPlan()`).
 */
final readonly class QuotaParkingLot
{
    /**
     * Cap on the failure backoff multiplier — see `releaseAfterFailure()`.
     */
    private const int MAX_BACKOFF_MULTIPLIER = 12;

    public function __construct(
        private QuotaGuard $quota,
        private TenantContext $context,
        private QuotaResumerRegistry $resumers,
        private QuotaNotifier $notifier,
        private AuditService $audit,
        private Dispatcher $events,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Parking
    |--------------------------------------------------------------------------
    */

    /**
     * Park $subject because $verdict refused it — and tell the tenant.
     *
     * Returns the hold, or **null** when there is nothing to park:
     *
     * - the verdict allows (nothing was refused);
     * - the verdict *blocks*. A block is not transient, so parking it would promise a
     *   resume that is never coming. The tenant is still notified (Req 3.4 covers block as
     *   well as defer) and the caller must fail explicitly with
     *   `QuotaExceededException` — never release, never drop.
     *
     * Idempotent per unit of work: called from every refused job of one campaign it
     * updates a single row (`uniq(tenant_id, dedup_key)`), and the tenant is notified once
     * per quota per period rather than once per job (`QuotaNotifier`).
     */
    public function park(Tenant $tenant, QuotaVerdict $verdict, QuotaHoldSubject $subject): ?QuotaHold
    {
        if ($verdict->isAllowed()) {
            return null;
        }

        if (! $verdict->isDeferred()) {
            $this->notifier->exhausted($tenant, $verdict);

            return null;
        }

        return $this->context->runFor($tenant, function (Tenant $bound) use ($verdict, $subject): QuotaHold {
            $hold = $this->recordHold($bound, $verdict, $subject);

            if ($this->notifier->exhausted($bound, $verdict, $hold)) {
                // Which hold caused the notice, for the operator view. The
                // cross-hold "told once per period" rule lives in the notifier.
                $hold->update(['notified_at' => now()]);
            }

            return $hold;
        });
    }

    /**
     * The open hold for one unit of work, or null when it is not parked.
     *
     * Reads in the acting tenant's scope — a panel asking "is this campaign paused?".
     */
    public function heldFor(Model $work): ?QuotaHold
    {
        $key = $work->getKey();

        if ($key === null || $key === '') {
            return null;
        }

        return QuotaHold::query()
            ->open()
            ->where('holdable_type', $work->getMorphClass())
            ->where('holdable_id', (string) $key)
            ->first();
    }

    public function isParked(Model $work): bool
    {
        return $this->heldFor($work) instanceof QuotaHold;
    }

    /**
     * Give up on a hold: its work no longer exists (a deleted campaign, an offboarded
     * tenant).
     *
     * Returns false when the hold was already closed, so cancelling twice is harmless.
     * This is the *only* sanctioned way a hold stops owing its owner a resume without one
     * happening.
     */
    public function cancel(QuotaHold $hold, string $reason): bool
    {
        $closed = QuotaHold::withoutTenantScope()
            ->whereKey($hold->getKey())
            ->open()
            ->update([
                'status' => QuotaHoldStatus::Cancelled,
                'last_error' => QuotaHold::truncateError($reason),
                'claimed_at' => null,
                'claimed_by' => null,
            ]);

        if ($closed === 0) {
            return false;
        }

        $this->audit->write(
            'quota.work.cancelled',
            ['hold_id' => $hold->id, 'quota' => $hold->quota_kind->value, 'reason' => $reason],
            $hold,
            tenant: $hold->tenant_id,
        );

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Resuming
    |--------------------------------------------------------------------------
    */

    /**
     * The period-reset sweep: hand back every parked unit of work whose allowance has
     * returned.
     *
     * Driven by the scheduled `wa:quota:resume-paused` command. Safe to run every minute
     * and safe to run on several workers at once — see `claim()` for why, and
     * `QuotaResumeReport` for what comes back.
     *
     * @param  int|null  $limit  holds to consider in this sweep; defaults to
     *                           `wa.tenancy.quota.holds.batch`
     */
    public function resumeDue(?int $limit = null): QuotaResumeReport
    {
        $report = new QuotaResumeReport;

        $holds = QuotaHold::withoutTenantScope()
            ->claimable($this->leaseSeconds())
            ->limit($this->batchSize($limit))
            ->get();

        // Null trigger: the sweep does not know *why* an allowance came back, so it infers
        // it per hold — see `triggerFor()`.
        $this->processHolds($holds->all(), null, $report);

        return $report;
    }

    /**
     * One tenant's allowance changed outside the period clock — an upgrade, an admin limit
     * raise, a wallet top-up — so re-check its parked work **now** instead of waiting for
     * the reset.
     *
     * This is the "or after top-up/upgrade" half of Req 20.3, and the seam task 10.4 calls
     * from `WalletService::topUp()`. It does two things, in order:
     *
     * 1. pulls every paused hold's `resume_at` forward to now — durable, so even if this
     *    process dies immediately the next sweep still picks the work up;
     * 2. attempts the resume inline, because an upgrade is a rare, user-initiated action
     *    and a tenant who just paid expects their campaigns moving before the next cron
     *    tick.
     *
     * `QuotaGuard` still has the final word: if the new plan *still* does not cover the
     * work, the hold simply stays parked.
     *
     * @param  string  $trigger  one of `TenantQuotaRestored::TRIGGER_*`
     */
    public function allowanceChanged(
        Tenant $tenant,
        string $trigger = TenantQuotaRestored::TRIGGER_PLAN_CHANGE,
        ?int $limit = null,
    ): QuotaResumeReport {
        $report = new QuotaResumeReport;

        $report->recordPulledForward($this->pullForward(QuotaHold::forTenant($tenant)));

        $holds = QuotaHold::forTenant($tenant)
            ->claimable($this->leaseSeconds())
            ->limit($this->batchSize($limit))
            ->get();

        $this->processHolds($holds->all(), $trigger, $report);

        return $report;
    }

    /**
     * A plan's limits changed, so every tenant on it may have allowance again.
     *
     * Only pulls `resume_at` forward — deliberately no inline resume. A plan edit can touch
     * thousands of tenants, and doing that work inside the admin's request would make an
     * edit's cost proportional to the plan's popularity; the sweep picks it all up within
     * the minute instead. One `UPDATE`, no N+1, nothing loaded into memory.
     *
     * @return int how many holds were pulled forward
     */
    public function allowanceChangedForPlan(string $planId): int
    {
        if (trim($planId) === '') {
            return 0;
        }

        return $this->pullForward(
            QuotaHold::withoutTenantScope()->whereIn(
                'tenant_id',
                Tenant::query()->where('plan_id', $planId)->select('id'),
            ),
        );
    }

    /**
     * Delete finished holds past their retention horizon.
     *
     * Only ever terminal rows (`QuotaHold::scopePrunable()`): an open hold is work somebody
     * is still owed, and no retention policy may delete that.
     */
    public function prune(?int $retentionDays = null): int
    {
        return QuotaHold::withoutTenantScope()
            ->prunable($retentionDays ?? $this->retentionDays())
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — parking
    |--------------------------------------------------------------------------
    */

    /**
     * Create the hold, or bring an existing one up to date.
     *
     * Runs inside `runFor($tenant)`, so the acting tenant *is* the hold's tenant.
     */
    private function recordHold(Tenant $tenant, QuotaVerdict $verdict, QuotaHoldSubject $subject): QuotaHold
    {
        $resumeAt = $this->resumeMoment($verdict->secondsUntilPeriodReset());

        // `createOrFirst()` rather than a read-then-create: two workers parking the same
        // campaign in the same millisecond race on `uniq(tenant_id, dedup_key)`, and the
        // loser is handed the winner's row instead of an exception.
        $hold = QuotaHold::query()->createOrFirst(
            ['tenant_id' => $tenant->id, 'dedup_key' => $subject->dedupKey],
            [
                'quota_kind' => $verdict->kind,
                'period_key' => $verdict->periodKey,
                'reason' => $verdict->reason,
                'units' => $subject->units,
                'status' => QuotaHoldStatus::QuotaPaused,
                'holdable_type' => $subject->holdable?->getMorphClass(),
                'holdable_id' => $this->holdableKey($subject),
                'resumer' => $subject->resumer,
                'payload' => $subject->payload === [] ? null : $subject->payload,
                'resume_at' => $resumeAt,
                'paused_at' => now(),
                // Written explicitly rather than left to the column defaults: the returned
                // instance is handed straight back to the caller, and a model that reports
                // null for a counter the database defaulted to 0 is a trap.
                'resume_attempts' => 0,
                'pause_count' => 1,
            ],
        );

        if ($hold->wasRecentlyCreated) {
            $this->auditPaused($hold, $verdict, reopened: false);

            return $hold;
        }

        if ($hold->status->isClaimed() && ! $hold->claimHasExpired($this->leaseSeconds())) {
            // A worker is mid-hand-back for this very work. Whatever it concludes is more
            // current than this refusal, so the claim is left alone rather than stomped.
            return $hold;
        }

        $reopened = ! $hold->isOpen();

        $hold->fill([
            'quota_kind' => $verdict->kind,
            'period_key' => $verdict->periodKey,
            'reason' => $verdict->reason,
            'units' => $subject->units,
            'status' => QuotaHoldStatus::QuotaPaused,
            'holdable_type' => $subject->holdable?->getMorphClass() ?? $hold->holdable_type,
            'holdable_id' => $this->holdableKey($subject) ?? $hold->holdable_id,
            'resumer' => $subject->resumer ?? $hold->resumer,
            'payload' => $subject->payload === [] ? $hold->payload : $subject->payload,
            // Recomputed from the fresh verdict, which is authoritative: if the allowance
            // really were back, this refusal would not have happened.
            'resume_at' => $resumeAt,
            'claimed_at' => null,
            'claimed_by' => null,
        ]);

        if ($reopened) {
            // The same work parked again in a later period: one row, with its history.
            $hold->fill([
                'paused_at' => now(),
                'resumed_at' => null,
                'last_error' => null,
                'resume_attempts' => 0,
                'pause_count' => $hold->pause_count + 1,
            ]);
        }

        $hold->save();

        if ($reopened) {
            $this->auditPaused($hold, $verdict, reopened: true);
        }

        return $hold;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — resuming
    |--------------------------------------------------------------------------
    */

    /**
     * Run the claim → re-check → hand-back cycle over a batch, then tell each affected
     * tenant once.
     *
     * @param  list<QuotaHold>  $holds
     * @param  string|null  $trigger  what returned the allowance, or null to infer it per hold
     */
    private function processHolds(array $holds, ?string $trigger, QuotaResumeReport $report): void
    {
        /** @var array<string, array{tenant: Tenant, kind: QuotaKind, trigger: string, count: int}> $restored */
        $restored = [];

        foreach ($holds as $hold) {
            $this->attempt($hold, $trigger, $report, $restored);
        }

        foreach ($restored as $entry) {
            $this->notifier->restored(
                $entry['tenant'],
                $entry['kind'],
                $this->quota->periodKeyFor($entry['tenant'], $entry['kind']),
                $entry['trigger'],
                $entry['count'],
            );
        }
    }

    /**
     * One hold: claim it, ask `QuotaGuard` afresh, and act on the answer.
     *
     * @param  array<string, array{tenant: Tenant, kind: QuotaKind, trigger: string, count: int}>  $restored
     */
    private function attempt(QuotaHold $hold, ?string $trigger, QuotaResumeReport $report, array &$restored): void
    {
        $report->recordClaimAttempt();

        if (! $this->claim($hold)) {
            $report->recordContended();

            return;
        }

        $tenant = Tenant::query()->whereKey($hold->tenant_id)->first();

        if (! $tenant instanceof Tenant) {
            // Defensive: the FK cascade removes a tenant's holds with it, so this is the
            // narrow window where the tenant went away mid-sweep. There is nobody to
            // resume for, and leaving the hold claimed for ever would be a leak.
            $this->cancel($hold, 'Owning tenant no longer exists.');
            $report->recordOrphaned();

            return;
        }

        $verdict = $this->quota->verdict($tenant, $hold->quota_kind, $hold->units);
        $trigger ??= $this->triggerFor($tenant, $hold);

        if ($verdict->isAllowed()) {
            try {
                $this->handBack($tenant, $hold, $trigger);
            } catch (Throwable $exception) {
                // The work stays parked and is retried with backoff. This is the branch
                // that keeps Req 31.1 true when a resumer is broken or unregistered: a
                // failed hand-back must never look like a completed one.
                $this->releaseAfterFailure($hold, $exception);
                $report->recordFailed();

                return;
            }

            $this->close($hold);
            $report->recordResumed($hold);
            $this->rememberRestored($restored, $tenant, $hold, $trigger);

            return;
        }

        if ($verdict->isDeferred()) {
            // Still spent for the current period (a daily allowance re-spent immediately, a
            // batch bigger than what one period has left). Wait for the next roll.
            $this->release($hold, $verdict, $this->resumeMoment($verdict->secondsUntilPeriodReset()));
            $report->recordDeferred();

            return;
        }

        // Not transient any more: a downgrade, a limit edited to 0, a plan that no longer
        // prices this kind. Auto-resume would be a lie, so the hold keeps the work and
        // waits for a plan change or a top-up to pull it forward — and the tenant is told
        // that waiting is not enough.
        $this->release($hold, $verdict, now()->addSeconds($this->blockedRecheckSeconds()));
        $this->notifier->exhausted($tenant, $verdict, $hold);
        $report->recordBlocked();
    }

    /**
     * Take exclusive ownership of a hold: `QUOTA_PAUSED → RESUMING`, compare-and-set.
     *
     * This single conditional `UPDATE` is what makes the sweep safe on several workers, on
     * several hosts, with no coordination service and no engine-specific locking: the
     * database decides, and only the worker whose update affected a row proceeds. It is
     * therefore just as true on SQLite (where the test suite runs) as on MySQL 8, unlike
     * `FOR UPDATE SKIP LOCKED`, which compiles away on SQLite.
     *
     * The claim doubles as a lease: a worker killed mid-hand-back leaves the hold
     * `RESUMING`, and after `CLAIM_LEASE_SECONDS` the predicate below lets the next sweep
     * retake it. Work is delayed by the lease, never lost.
     */
    private function claim(QuotaHold $hold): bool
    {
        $now = now();
        $staleBefore = $now->copy()->subSeconds($this->leaseSeconds());

        $claimed = QuotaHold::withoutTenantScope()
            ->whereKey($hold->getKey())
            ->where(function (Builder $claimable) use ($now, $staleBefore): void {
                $claimable
                    ->where(function (Builder $due) use ($now): void {
                        $due->where('status', QuotaHoldStatus::QuotaPaused)->where('resume_at', '<=', $now);
                    })
                    ->orWhere(function (Builder $abandoned) use ($staleBefore): void {
                        $abandoned->where('status', QuotaHoldStatus::Resuming)->where('claimed_at', '<=', $staleBefore);
                    });
            })
            ->update([
                'status' => QuotaHoldStatus::Resuming,
                'claimed_at' => $now,
                'claimed_by' => $this->workerId(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        // Keep the in-memory row honest for the rest of this attempt.
        $hold->status = QuotaHoldStatus::Resuming;
        $hold->claimed_at = $now;

        return true;
    }

    /**
     * Give the work back to its owner, inside the owning tenant's context.
     *
     * Order matters: the resumer runs first (it is the owner's own hand-back), then the
     * per-hold event, and the hold is closed only after both have returned. Anything that
     * throws leaves the hold open, which is what makes a broken resumer a *delay* rather
     * than a lost campaign.
     *
     * @throws Throwable from the resumer, an unregistered resumer key, or a listener
     */
    private function handBack(Tenant $tenant, QuotaHold $hold, string $trigger): void
    {
        $this->context->runFor($tenant, function () use ($hold, $trigger): void {
            if ($hold->resumer !== null) {
                $this->resumers->resolve($hold->resumer)->resume($hold);
            }

            $this->events->dispatch(new QuotaHoldResumed(
                $hold->id,
                $hold->tenant_id,
                $hold->quota_kind,
                $hold->dedup_key,
                $hold->holdable_type,
                $hold->holdable_id,
                is_array($hold->payload) ? $hold->payload : [],
                $trigger,
            ));
        });
    }

    /**
     * Close a hold that has been handed back.
     *
     * A compare-and-set again, so a hold re-parked while the hand-back was in flight is not
     * silently closed — it keeps its new pause, and the next sweep resumes it once more
     * (which is why `QuotaResumer` must be idempotent).
     */
    private function close(QuotaHold $hold): void
    {
        $now = now();

        QuotaHold::withoutTenantScope()
            ->whereKey($hold->getKey())
            ->where('status', QuotaHoldStatus::Resuming)
            ->update([
                'status' => QuotaHoldStatus::Resumed,
                'resumed_at' => $now,
                'claimed_at' => null,
                'claimed_by' => null,
                'last_error' => null,
            ]);

        $hold->status = QuotaHoldStatus::Resumed;
        $hold->resumed_at = $now;

        $this->audit->write(
            'quota.work.resumed',
            [
                'hold_id' => $hold->id,
                'quota' => $hold->quota_kind->value,
                'period_key' => $hold->period_key,
                'units' => $hold->units,
                'resumer' => $hold->resumer,
            ],
            $hold,
            tenant: $hold->tenant_id,
        );
    }

    /**
     * Put a claimed hold back to `QUOTA_PAUSED` with a fresh reason and a fresh wait.
     */
    private function release(QuotaHold $hold, QuotaVerdict $verdict, Carbon $resumeAt): void
    {
        QuotaHold::withoutTenantScope()
            ->whereKey($hold->getKey())
            ->where('status', QuotaHoldStatus::Resuming)
            ->update([
                'status' => QuotaHoldStatus::QuotaPaused,
                // The reason is refreshed so an operator sees *why* it is still waiting:
                // "period exhausted" and "not priced on this plan" need different actions.
                'reason' => $verdict->reason,
                'period_key' => $verdict->periodKey,
                'resume_at' => $resumeAt,
                'claimed_at' => null,
                'claimed_by' => null,
            ]);

        $hold->status = QuotaHoldStatus::QuotaPaused;
        $hold->reason = $verdict->reason;
        $hold->resume_at = $resumeAt;
    }

    /**
     * Put a claimed hold back after a failed hand-back, with the error and a backoff.
     *
     * The backoff grows with the attempt count so a permanently broken resumer does not
     * re-run every minute for a month, and is capped so a fixed deployment recovers
     * promptly.
     */
    private function releaseAfterFailure(QuotaHold $hold, Throwable $exception): void
    {
        $attempts = $hold->resume_attempts + 1;
        $delay = $this->failureBackoffSeconds() * min($attempts, self::MAX_BACKOFF_MULTIPLIER);
        $resumeAt = now()->addSeconds($delay);

        QuotaHold::withoutTenantScope()
            ->whereKey($hold->getKey())
            ->where('status', QuotaHoldStatus::Resuming)
            ->update([
                'status' => QuotaHoldStatus::QuotaPaused,
                'resume_attempts' => $attempts,
                'resume_at' => $resumeAt,
                'claimed_at' => null,
                'claimed_by' => null,
                'last_error' => QuotaHold::truncateError(
                    $exception::class.': '.$exception->getMessage(),
                ),
            ]);

        $hold->status = QuotaHoldStatus::QuotaPaused;
        $hold->resume_attempts = $attempts;
        $hold->resume_at = $resumeAt;
    }

    /**
     * Pull every paused hold on $query forward to now — "it is worth asking again".
     *
     * @param  Builder<QuotaHold>  $query
     */
    private function pullForward(Builder $query): int
    {
        $now = now();

        return $query
            ->where('status', QuotaHoldStatus::QuotaPaused)
            ->where('resume_at', '>', $now)
            ->update(['resume_at' => $now]);
    }

    /**
     * Remember that a tenant's work resumed, so the tenant is told **once** per quota
     * rather than once per hold.
     *
     * @param  array<string, array{tenant: Tenant, kind: QuotaKind, trigger: string, count: int}>  $restored
     */
    private function rememberRestored(array &$restored, Tenant $tenant, QuotaHold $hold, string $trigger): void
    {
        $key = $tenant->id.'|'.$hold->quota_kind->value.'|'.$trigger;

        if (! array_key_exists($key, $restored)) {
            $restored[$key] = [
                'tenant' => $tenant,
                'kind' => $hold->quota_kind,
                'trigger' => $trigger,
                'count' => 0,
            ];
        }

        $restored[$key]['count']++;
    }

    /**
     * What returned the allowance, inferred from the hold itself.
     *
     * The sweep is not told why it is resuming something, but it can work it out exactly,
     * because usage within a period only ever *rises*:
     *
     * - the hold's recorded period is no longer the current one → **the period rolled**;
     * - it is still the current one, yet the allowance now covers the work → the *ceiling*
     *   moved, which can only be an upgrade, an admin limit raise, or a top-up.
     *
     * `period_key` is refreshed every time a re-check leaves the hold parked, so the
     * comparison is against the period the hold was last known to be exhausted in — not
     * against a stale one.
     */
    private function triggerFor(Tenant $tenant, QuotaHold $hold): string
    {
        return $this->quota->periodKeyFor($tenant, $hold->quota_kind) === $hold->period_key
            ? TenantQuotaRestored::TRIGGER_PLAN_CHANGE
            : TenantQuotaRestored::TRIGGER_PERIOD_RESET;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — small things
    |--------------------------------------------------------------------------
    */

    private function auditPaused(QuotaHold $hold, QuotaVerdict $verdict, bool $reopened): void
    {
        $this->audit->write(
            'quota.work.paused',
            [
                'hold_id' => $hold->id,
                'quota' => $hold->quota_kind->value,
                'period_key' => $hold->period_key,
                'units' => $hold->units,
                'resumer' => $hold->resumer,
                'reopened' => $reopened,
                'verdict' => $verdict->toArray(),
            ],
            $hold,
            tenant: $hold->tenant_id,
        );
    }

    /**
     * When it is next worth re-asking the guard — at least a second away, so a hold parked
     * at the instant a period rolls cannot be re-checked in a tight loop.
     */
    private function resumeMoment(int $seconds): Carbon
    {
        return now()->addSeconds(max(1, $seconds));
    }

    private function holdableKey(QuotaHoldSubject $subject): ?string
    {
        $key = $subject->holdable?->getKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * Which process is sitting on a claim — diagnostics only, never a correctness input.
     */
    private function workerId(): string
    {
        $host = gethostname();

        return mb_substr(($host === false ? 'unknown' : $host).':'.getmypid(), 0, 64);
    }

    private function batchSize(?int $limit): int
    {
        if ($limit !== null) {
            return max(1, $limit);
        }

        return max(1, $this->configInt('batch', 200));
    }

    private function leaseSeconds(): int
    {
        return max(1, $this->configInt('lease_seconds', QuotaHold::CLAIM_LEASE_SECONDS));
    }

    private function blockedRecheckSeconds(): int
    {
        return max(1, $this->configInt('blocked_recheck_seconds', 3600));
    }

    private function failureBackoffSeconds(): int
    {
        return max(1, $this->configInt('failure_backoff_seconds', 300));
    }

    private function retentionDays(): int
    {
        return max(1, $this->configInt('retention_days', 30));
    }

    private function configInt(string $key, int $default): int
    {
        $value = config('wa.tenancy.quota.holds.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
