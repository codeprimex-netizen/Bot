<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Tenant;
use App\Services\Tenancy\TierResolver;
use Closure;

/**
 * Weighted-fair dispatch by **deficit round robin** (Req 1.7 / A1; Req 30.2, 30.6 / NFR1;
 * design.md §"Noisy-neighbor prevention"; Correctness Property 19).
 *
 * ## The model, exactly
 *
 * - **Unit** — one call of the caller's dispatch closure: one message, one recipient, one
 *   AI reply. The scheduler counts units and nothing else; it does not know what work is.
 * - **Quantum** — what one round is worth to a tenant:
 *   `quantum(t) = TierResolver::laneWeight(t) × wa.dispatch.fair.quantum`. The weight floor
 *   of 1 is enforced by the resolver, so **every** tenant's quantum is ≥ 1 — that single
 *   fact is why no tenant can be starved.
 * - **Credit (the deficit counter)** — a tenant's unspent entitlement, held in
 *   `DeficitLedger` per queue lane. One unit costs one credit.
 * - **Round** — every active tenant is credited its quantum, then the scheduler walks the
 *   tenants in rotation order granting **one unit per visit** while a tenant still has both
 *   credit and backlog. Passes repeat until no tenant can be granted anything more, then
 *   the round ends.
 * - **Carry** — credit unspent at the end of a round stays, capped at
 *   `(1 + wa.dispatch.fair.max_carry_quanta) × quantum`. Carrying is what makes the share
 *   exact over a window even when a round's budget divides unevenly; the cap is what stops
 *   a tenant that was idle or rate-capped for a long time from arriving with a hoard and
 *   swallowing a whole window.
 * - **Forfeit** — a tenant whose backlog runs out (or that is absent from the candidate set,
 *   or whose closure reports "no work") drops to zero credit. Textbook DRR: an empty queue
 *   keeps no deficit.
 *
 * ### Why one unit per visit rather than one tenant at a time
 *
 * Classic DRR serves a queue until its quantum is exhausted before moving on. That is
 * correct on average, but it means a weight-50 tenant sends 50 messages back to back while a
 * weight-1 tenant waits — the *share* is fair and the *latency* is not. Granting one unit
 * per visit produces the same per-round totals (a tenant still gets exactly its quantum) with
 * the tenants interleaved, so a small tenant's single message is never queued behind a large
 * tenant's whole quantum. It also makes no-starvation immediate rather than eventual: the
 * first pass of every round grants one unit to *every* active tenant before any tenant gets
 * its second.
 *
 * ### The noisy-neighbour bound (Req 30.2, 30.6)
 *
 * In one round a tenant can be granted at most `(1 + max_carry_quanta) × quantum` units, no
 * matter how much backlog it has. So for tenants that stay backlogged for a window of `N`
 * units, `dispatched(t) = N × w(t)/Σw ± (1 + max_carry_quanta) × quantum(t)` — proportional
 * to weight, with an error bounded by a couple of quanta and independent of backlog. A tenant
 * with ten million queued recipients and weight 1 gets the same share as a tenant with ten,
 * which is the whole claim of Req 30.2. Tenants whose backlog is smaller than their share
 * simply take all of it and release the rest.
 *
 * One deliberate exception, worth stating because it is a *choice*: a window smaller than a
 * single round (`budget < Σ quanta`) cannot be both proportional and starvation-free, since
 * the first pass hands one unit to every lane and that over-serves the light ones. When the
 * two guarantees collide, the floor wins — a tenant waiting behind a heavier one is a
 * fairness *error*, a starved tenant is an outage. Proportionality is therefore a claim
 * about windows of a round or more, and `eachEligibleTenant()` exists so a caller can ask
 * for exactly one round without having to compute its size.
 *
 * ## Concurrency
 *
 * The class holds **no state**: it is a singleton shared by every worker, and everything that
 * has to persist between rounds lives in `DeficitLedger`, which serializes claims per lane
 * under a lock. See `CacheDeficitLedger` for the two documented degradations (no lock driver,
 * lock timeout) and why each costs at most one quantum of precision rather than the property.
 *
 * A claim is deliberately separated from the dispatching it authorises (`DispatchPlan`): the
 * lock covers arithmetic only, never a bridge call.
 *
 * ## Deliberately not here
 *
 * - **Finding pending work.** The caller queries its own lane (see `DispatchLane`).
 * - **Rate/quota caps.** A single narrow gate, `DispatchEligibility`, answers "can this
 *   tenant be dispatched right now?"; tasks 2.3, 9.6 and 36.5 append their gate to
 *   `wa.dispatch.eligibility.gates` and this class does not change.
 * - **Audit entries.** A dispatch decision is not a privileged action; writing one
 *   hash-chained audit row per message would swamp the trail Req 24.2 exists for.
 *   Observability of shares belongs in metrics (task 33.x), fed by `DispatchRoundResult`.
 */
final readonly class DeficitRoundRobinScheduler implements FairScheduler
{
    /**
     * Credits one unit of work costs. Fixed at 1: a unit *is* the accounting granularity,
     * and a variable cost (bytes, tokens) would mean the scheduler had to know what the work
     * is. A caller that wants a heavier unit to count for more passes a smaller weight or a
     * smaller budget.
     */
    private const int COST_PER_UNIT = 1;

    public function __construct(
        private TierResolver $tiers,
        private DispatchEligibility $eligibility,
        private DeficitLedger $ledger,
    ) {}

    public function nextTenant(iterable $candidates, string $queueLane = self::DEFAULT_QUEUE_LANE): ?Tenant
    {
        $lanes = $this->normalize($candidates);
        [$eligible] = $this->partition($lanes);

        if ($eligible === []) {
            $this->prune($queueLane, array_keys($lanes));

            return null;
        }

        $granted = $this->claim($queueLane, $lanes, $eligible, 1)->first();

        return $granted === null ? null : $eligible[$granted]->tenant;
    }

    public function dispatchRound(
        iterable $candidates,
        int $budget,
        Closure $dispatch,
        string $queueLane = self::DEFAULT_QUEUE_LANE,
    ): DispatchRoundResult {
        $lanes = $this->normalize($candidates);
        [$eligible, $skipped] = $this->partition($lanes);

        return $this->run($queueLane, $lanes, $eligible, $skipped, max(0, $budget), $dispatch);
    }

    public function eachEligibleTenant(
        iterable $candidates,
        Closure $callback,
        string $queueLane = self::DEFAULT_QUEUE_LANE,
    ): DispatchRoundResult {
        $lanes = $this->normalize($candidates);
        [$eligible, $skipped] = $this->partition($lanes);

        $budget = 0;

        foreach ($eligible as $lane) {
            // One round's worth, and never more than the tenant actually has queued — an
            // unbounded backlog contributes its quantum, not PHP_INT_MAX.
            $budget += min($this->quantumFor($lane->tenant), $lane->backlog);
        }

        return $this->run($queueLane, $lanes, $eligible, $skipped, $budget, $callback);
    }

    public function quantumFor(Tenant $tenant): int
    {
        // The weight is read here and nowhere else in the dispatch path: `laneWeight()` is
        // already cached and clamped to `[1, lane_weight_max]`, so a weight change in
        // `tenant_tiers` (which invalidates that cache on write) or in `wa.tenancy.tiers`
        // takes effect on the very next round.
        return max(1, $this->tiers->laneWeight($tenant) * $this->quantumUnits());
    }

    /*
    |--------------------------------------------------------------------------
    | Claim → dispatch → settle
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, DispatchLane>  $lanes  every candidate, eligible or not
     * @param  array<string, DispatchLane>  $eligible
     * @param  list<string>  $skipped
     * @param  Closure(Tenant, int): mixed  $dispatch
     */
    private function run(
        string $queueLane,
        array $lanes,
        array $eligible,
        array $skipped,
        int $budget,
        Closure $dispatch,
    ): DispatchRoundResult {
        if ($eligible === [] || $budget <= 0) {
            // Still settle the ledger: tenants that have gone quiet must not keep credit,
            // whether or not anybody was dispatched this time.
            $this->prune($queueLane, array_keys($lanes));

            return new DispatchRoundResult([], $skipped, [], 0, $budget);
        }

        /** @var array<string, int> $dispatched */
        $dispatched = [];
        /** @var array<string, true> $drained */
        $drained = [];
        $candidates = $eligible;
        $remaining = $budget;
        $rounds = 0;
        $unit = 0;

        // Re-claimed once per tenant that turns out to be dry, so a backlog count that was
        // right when it was read and is not any more costs the *tenant* the rest of its
        // grants, not the *worker* the rest of its budget. Each pass drops at least one
        // candidate, so the loop runs at most once per candidate.
        while ($remaining > 0 && $candidates !== []) {
            $requested = $remaining;
            $plan = $this->claim($queueLane, $lanes, $candidates, $requested);

            if ($plan->isEmpty()) {
                break;
            }

            $rounds += $plan->rounds;
            /** @var array<string, true> $ranDry */
            $ranDry = [];

            foreach ($plan->sequence as $tenantId) {
                if (array_key_exists($tenantId, $ranDry)) {
                    // The tenant already told us it has nothing left; its remaining grants
                    // are released rather than spent on a second pointless call.
                    continue;
                }

                if ($dispatch($candidates[$tenantId]->tenant, $unit) === false) {
                    $ranDry[$tenantId] = true;

                    continue;
                }

                $dispatched[$tenantId] = ($dispatched[$tenantId] ?? 0) + 1;
                $remaining--;
                $unit++;
            }

            if ($ranDry === []) {
                break;
            }

            $this->forfeit($queueLane, array_keys($ranDry));

            foreach (array_keys($ranDry) as $tenantId) {
                $drained[$tenantId] = true;
                unset($candidates[$tenantId]);
            }

            if ($plan->size() < $requested) {
                // The claim could not fill the budget, so every remaining backlog is
                // already accounted for: another claim would grant nothing.
                break;
            }
        }

        return new DispatchRoundResult($dispatched, $skipped, array_keys($drained), $rounds, $budget);
    }

    /**
     * Take the lane's lock and turn credits + weights + backlogs into a grant sequence.
     *
     * @param  array<string, DispatchLane>  $lanes
     * @param  array<string, DispatchLane>  $eligible
     */
    private function claim(string $queueLane, array $lanes, array $eligible, int $budget): DispatchPlan
    {
        return $this->ledger->mutate($queueLane, function (LaneCredits $credits) use ($lanes, $eligible, $budget): DispatchPlan {
            // Tenants outside the candidate set have no pending work: forfeit their credit
            // before granting, so an idle tenant cannot accumulate a burst. Tenants that are
            // *present but ineligible* keep theirs untouched — they are capped, not quiet,
            // and must resume on their normal share the moment the cap lifts.
            $credits->retain(array_keys($lanes));

            return $this->grant($credits, $eligible, $budget);
        });
    }

    /**
     * The deficit round robin itself.
     *
     * @param  array<string, DispatchLane>  $eligible
     */
    private function grant(LaneCredits $credits, array $eligible, int $budget): DispatchPlan
    {
        /** @var array<string, int> $quantum */
        $quantum = [];
        /** @var array<string, int> $backlog */
        $backlog = [];

        foreach ($eligible as $tenantId => $lane) {
            $quantum[$tenantId] = $this->quantumFor($lane->tenant);
            $backlog[$tenantId] = $lane->backlog;
        }

        $ceilingFactor = 1 + $this->maxCarryQuanta();
        $active = $this->rotate(array_keys($eligible), $credits->cursor());
        $maxRounds = $this->maxRounds();

        /** @var list<string> $sequence */
        $sequence = [];
        $remaining = $budget;
        $rounds = 0;

        while ($remaining > 0 && $active !== [] && $rounds < $maxRounds) {
            $rounds++;

            foreach ($active as $tenantId) {
                $credits->credit($tenantId, $quantum[$tenantId], $quantum[$tenantId] * $ceilingFactor);
            }

            $granted = 0;

            // Interleaved passes: one unit per tenant per pass, so the tenants alternate and
            // the first pass reaches everybody before anyone gets a second unit.
            while ($remaining > 0) {
                $pass = 0;

                foreach ($active as $tenantId) {
                    if ($remaining === 0) {
                        break;
                    }

                    if ($credits->get($tenantId) < self::COST_PER_UNIT || $backlog[$tenantId] <= 0) {
                        continue;
                    }

                    $credits->spend($tenantId, self::COST_PER_UNIT);
                    $credits->advanceCursor($tenantId);
                    $backlog[$tenantId]--;
                    $remaining--;
                    $sequence[] = $tenantId;
                    $pass++;
                }

                if ($pass === 0) {
                    break;
                }

                $granted += $pass;
            }

            // A tenant whose backlog is gone leaves the rotation and forfeits its credit.
            $stillActive = [];

            foreach ($active as $tenantId) {
                if ($backlog[$tenantId] > 0) {
                    $stillActive[] = $tenantId;

                    continue;
                }

                $credits->forfeit($tenantId);
            }

            $emptied = count($stillActive) !== count($active);
            $active = $stillActive;

            if ($granted === 0 && ! $emptied) {
                // Nothing granted and nothing drained: crediting again would change nothing
                // (it is capped), so the window is genuinely finished.
                break;
            }
        }

        return new DispatchPlan($sequence, $rounds);
    }

    /**
     * Forfeit the credit of tenants whose dispatch closure reported no work.
     *
     * @param  list<string>  $tenantIds
     */
    private function forfeit(string $queueLane, array $tenantIds): void
    {
        $this->ledger->mutate($queueLane, static function (LaneCredits $credits) use ($tenantIds): null {
            foreach ($tenantIds as $tenantId) {
                $credits->forfeit($tenantId);
            }

            return null;
        });
    }

    /**
     * Drop the credit of every tenant outside the candidate set.
     *
     * @param  list<string>  $tenantIds
     */
    private function prune(string $queueLane, array $tenantIds): void
    {
        $this->ledger->mutate($queueLane, static function (LaneCredits $credits) use ($tenantIds): null {
            $credits->retain($tenantIds);

            return null;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Candidate handling
    |--------------------------------------------------------------------------
    */

    /**
     * The candidate set as lanes keyed by tenant id, empty lanes dropped and duplicates
     * merged.
     *
     * @param  iterable<array-key, DispatchLane|Tenant>  $candidates
     * @return array<string, DispatchLane>
     */
    private function normalize(iterable $candidates): array
    {
        $lanes = [];

        foreach ($candidates as $candidate) {
            $lane = DispatchLane::from($candidate);

            if ($lane->isEmpty()) {
                continue;
            }

            $tenantId = $lane->tenantId();
            $lanes[$tenantId] = isset($lanes[$tenantId]) ? $lanes[$tenantId]->merged($lane) : $lane;
        }

        return $lanes;
    }

    /**
     * Split the candidates into "can be dispatched now" and "skipped this window".
     *
     * Evaluated **before** the ledger lock is taken: a gate may touch the cache or the
     * database (task 2.3's quota verdict, task 9.6's pacing counters), and none of that
     * belongs inside a lock that every worker on the lane is waiting for.
     *
     * @param  array<string, DispatchLane>  $lanes
     * @return array{0: array<string, DispatchLane>, 1: list<string>}
     */
    private function partition(array $lanes): array
    {
        $eligible = [];
        $skipped = [];

        foreach ($lanes as $tenantId => $lane) {
            if ($this->eligibility->canDispatch($lane->tenant)) {
                $eligible[$tenantId] = $lane;

                continue;
            }

            $skipped[] = $tenantId;
        }

        return [$eligible, $skipped];
    }

    /**
     * Candidate ids in a deterministic order, rotated so the tenant *after* the one served
     * last goes first.
     *
     * Sorted before rotating so two workers looking at the same candidate set walk it the
     * same way; rotated so that when a window is too small to reach every tenant, the
     * advantage of being early moves on instead of always falling to the lowest id.
     *
     * @param  list<string>  $tenantIds
     * @return list<string>
     */
    private function rotate(array $tenantIds, ?string $cursor): array
    {
        sort($tenantIds);

        if ($cursor === null) {
            return $tenantIds;
        }

        $position = array_search($cursor, $tenantIds, true);

        if ($position === false) {
            return $tenantIds;
        }

        $offset = ($position + 1) % count($tenantIds);

        return array_merge(array_slice($tenantIds, $offset), array_slice($tenantIds, 0, $offset));
    }

    /*
    |--------------------------------------------------------------------------
    | Config
    |--------------------------------------------------------------------------
    */

    /**
     * Units one weight point buys per round. Raising it makes rounds coarser (fewer, larger
     * claims) without changing any tenant's *share*, since every quantum scales together.
     */
    private function quantumUnits(): int
    {
        return max(1, $this->configInt('quantum', 1));
    }

    /**
     * How many extra quanta of unspent credit a tenant may carry — the burst allowance, and
     * the constant in the Req 30.2 error bound. `0` means "no carry at all": perfectly flat,
     * but a tenant repeatedly missing out on a fractional share never makes it up.
     */
    private function maxCarryQuanta(): int
    {
        return max(0, $this->configInt('max_carry_quanta', 1));
    }

    /**
     * Safety bound on rounds per call, so a caller that asks for a huge budget across a huge
     * candidate set cannot spend an unbounded time inside one claim.
     */
    private function maxRounds(): int
    {
        return max(1, $this->configInt('max_rounds', 1024));
    }

    private function configInt(string $key, int $default): int
    {
        $value = config('wa.dispatch.fair.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
