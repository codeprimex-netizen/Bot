<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\QuotaHold;

/**
 * How one subsystem takes its parked work back when the allowance returns
 * (Req 20.3 / C3; Req 31.1 / NFR2).
 *
 * A resumer is registered under a short key in `wa.tenancy.quota.holds.resumers` and named
 * on the hold (`QuotaHoldSubject::for($campaign, resumer: 'campaign')`). The period-reset
 * sweep resolves it, calls it **inside the owning tenant's context**, and only then marks
 * the hold `RESUMED`.
 *
 * ```php
 * // task 26.2
 * final readonly class CampaignQuotaResumer implements QuotaResumer
 * {
 *     public function resume(QuotaHold $hold): void
 *     {
 *         $campaign = $hold->holdable;               // the campaigns row
 *
 *         if (! $campaign instanceof Campaign || ! $campaign->status->isQuotaPaused()) {
 *             return;                                // already resumed or deleted: nothing owed
 *         }
 *
 *         $campaign->update(['status' => CampaignStatus::Running]);
 *         CampaignBatchJob::dispatch($campaign->id);  // the queued sends were never dropped
 *     }
 * }
 * ```
 *
 * ## The two rules
 *
 * 1. **Be idempotent.** A worker that dies after handing the work back but before marking
 *    the hold `RESUMED` leaves the claim to expire, and the next sweep calls `resume()`
 *    again. Re-dispatching a batch that is already running must therefore be a no-op —
 *    check your own state first, as above.
 * 2. **Throw to keep the hold.** Returning normally means "the work is back with me", and
 *    the sweep closes the hold on that promise. Any failure must throw: the hold then
 *    returns to `QUOTA_PAUSED` with the error recorded and a backoff, and the work is
 *    retried rather than lost. Never swallow an error and return.
 *
 * A resumer is resolved from the container, so constructor injection works normally. It
 * must not consume quota: the work it releases will go through the send gate, which
 * meters it exactly once (Req 3.5).
 */
interface QuotaResumer
{
    /**
     * Hand the parked work back to its owner.
     *
     * Called with the hold's tenant bound to `TenantContext`, so every query inside is
     * correctly scoped, and only after `QuotaGuard` has confirmed the allowance now covers
     * `$hold->units` of `$hold->quota_kind`.
     *
     * @throws \Throwable to keep the work parked and retried
     */
    public function resume(QuotaHold $hold): void;
}
