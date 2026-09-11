<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Tenant;
use Closure;

/**
 * Whose turn is it? — weighted-fair dispatch across tenants (Req 1.7 / A1;
 * Req 30.2, 30.6 / NFR1; Correctness Property 19).
 *
 * The contract every dispatch path goes through so that none of them has to implement
 * fairness. A caller brings two things: **the set of tenants with pending work** in one
 * queue lane, and **a budget** (how many units of work this worker is willing to do
 * now). The scheduler answers with an order, in proportion to each tenant's
 * `tenant_tiers.lane_weight` as resolved by `TierResolver`, skipping tenants that cannot
 * be dispatched right now, and never letting one tenant's backlog crowd out another's.
 *
 * ## The three entry points
 *
 * | Method | Shape | Use it when |
 * |---|---|---|
 * | `nextTenant()` | one tenant | a worker that will do a single unit and come back |
 * | `dispatchRound()` | up to `$budget` units | a dispatcher draining a lane with a limit |
 * | `eachEligibleTenant()` | exactly one full round | a tick that wants to give every backlogged tenant its weighted turn |
 *
 * ## Who calls it
 *
 * | Task | Caller | Call |
 * |---|---|---|
 * | **36.4** | the per-lane Supervisor worker loop, once per tick per lane | `eachEligibleTenant($lanes, $fn, 'campaign')` — one weighted round per tick, so a worker group serves every backlogged tenant in weight order |
 * | **13.x** | the campaign dispatcher (design.md: *"campaign dispatch is round-robined across tenants so one large tenant can't starve others"*) | `dispatchRound($lanes, $batchSize, $fn, 'campaign')` with per-tenant recipient counts as backlogs |
 * | **36.5** | the `ai-reply` pump | `dispatchRound($lanes, $slots, $fn, 'ai-reply')`, with its per-tenant in-flight cap contributed as an eligibility gate rather than as a special case here |
 * | 9.3 | the send pipeline | not at all — it sends *one* message it was already handed; fairness is decided upstream, at the point where work is picked up |
 *
 * ## The candidate set must be complete
 *
 * Every call is a statement about a whole lane: *"these are the tenants with pending
 * work"*. A tenant absent from the set is understood to have none, and its carried credit
 * is dropped accordingly (deficit round robin resets an empty lane, or an idle tenant
 * would return with a hoard of credit and burst). Passing a *page* of tenants instead of
 * all of them is therefore a fairness bug, not a performance optimisation: it tells the
 * scheduler that the tenants on the next page went quiet. Filter by "has pending work",
 * never by anything else.
 *
 * ## Context
 *
 * The scheduler runs with **no tenant bound** — it is the thing that decides which tenant
 * to become. It never calls `TenantContext::current()` and never opens platform mode;
 * every read it makes names its tenant explicitly. Callers follow the pattern
 * `Saga::resumable()` documents: find the work unscoped, then do it inside
 * `TenantContext::runFor($tenant, ...)`, which is where the dispatch closure belongs.
 */
interface FairScheduler
{
    /**
     * Queue lane used when a caller does not name one. Real callers always name theirs
     * (`campaign`, `ai-reply`, `transactional`): credits are per lane, and sharing one
     * lane's counters between two worker pools would make each pool's fairness depend on
     * the other's throughput.
     */
    public const string DEFAULT_QUEUE_LANE = 'default';

    /**
     * The tenant whose turn it is, or `null` when none of the candidates can be dispatched
     * right now.
     *
     * Consumes the grant: the returned tenant has already had a credit deducted, so a
     * caller that asks must dispatch (or accept that the tenant paid for a unit it did not
     * get — one unit, once, self-corrected by the next round's crediting). Callers that
     * may decline should use `dispatchRound()`, whose closure can report "no work" and be
     * refunded properly.
     *
     * @param  iterable<array-key, DispatchLane|Tenant>  $candidates  every tenant with pending work in this lane
     */
    public function nextTenant(iterable $candidates, string $queueLane = self::DEFAULT_QUEUE_LANE): ?Tenant;

    /**
     * Dispatch up to `$budget` units across the candidates, weighted-fair.
     *
     * `$dispatch` is called once per granted unit, with the tenant and the 0-based index
     * of the unit within this window. Returning `false` means **"this tenant has nothing
     * left"**: its remaining grants in this window are released to the other tenants and
     * its carried credit is dropped (an empty lane keeps no deficit). Any other return
     * value — including `null` — counts as a dispatched unit.
     *
     * The closure runs *outside* the scheduler's lock, one unit at a time, and is the right
     * place to enter the tenant's context:
     *
     * ```php
     * $scheduler->dispatchRound($lanes, 500, function (Tenant $tenant): bool {
     *     return $tenants->runFor($tenant, fn (): bool => $dispatcher->sendNext($tenant));
     * }, 'campaign');
     * ```
     *
     * An exception thrown by `$dispatch` propagates; units already dispatched stay
     * dispatched and their credits stay spent, so a crashing lane cannot silently rewind
     * the fairness state.
     *
     * @param  iterable<array-key, DispatchLane|Tenant>  $candidates  every tenant with pending work in this lane
     * @param  int  $budget  maximum units to dispatch; `0` or less dispatches nothing
     * @param  Closure(Tenant, int): mixed  $dispatch  returns `false` when the tenant turns out to have no work
     */
    public function dispatchRound(
        iterable $candidates,
        int $budget,
        Closure $dispatch,
        string $queueLane = self::DEFAULT_QUEUE_LANE,
    ): DispatchRoundResult;

    /**
     * Give every eligible candidate exactly one round's worth of turns.
     *
     * The budget is the round itself: a tenant of weight 5 is visited five times and a
     * tenant of weight 1 once, interleaved, and capped by each tenant's own backlog. This
     * is the shape a worker tick wants — "do one fair pass over everybody who has work" —
     * and it is why weight is a *rate* rather than a priority: no lane waits for another
     * lane to empty.
     *
     * @param  iterable<array-key, DispatchLane|Tenant>  $candidates  every tenant with pending work in this lane
     * @param  Closure(Tenant, int): mixed  $callback  as `dispatchRound()`
     */
    public function eachEligibleTenant(
        iterable $candidates,
        Closure $callback,
        string $queueLane = self::DEFAULT_QUEUE_LANE,
    ): DispatchRoundResult;

    /**
     * The units one round grants this tenant: `lane_weight × quantum`.
     *
     * Exposed because it is the noisy-neighbour bound in units — a tenant cannot exceed
     * this in a round, whatever its backlog — and because task 33.x's dashboards want to
     * show a tenant its configured share next to its achieved one.
     */
    public function quantumFor(Tenant $tenant): int;
}
