<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use Closure;

/**
 * Where the weighted-fair scheduler keeps its deficit counters, and how concurrent
 * workers agree on them (Req 1.7 / A1; Req 30.2 / NFR1).
 *
 * The scheduler itself is stateless; every number it needs between rounds lives here,
 * keyed by **queue lane** (`campaign`, `ai-reply`, `transactional`), because fairness is
 * a property of a worker pool and the same tenant may legitimately be at the front of one
 * lane and the back of another.
 *
 * ## Why a mutate-with-callback and not get/put
 *
 * A claim reads credits, decides a grant, and writes the credits back. Exposing `get()`
 * and `put()` would make every caller responsible for closing that race, and the whole
 * point of this seam is that exactly one implementation gets it right. `mutate()` runs
 * the callback **inside the lane's lock**, so two workers claiming from the same lane
 * serialize their decisions rather than both spending the same credit.
 *
 * The callback must stay short: it decides, it does not dispatch. Dispatching happens
 * after `mutate()` returns (see `DispatchPlan`).
 *
 * ## Implementations
 *
 * `CacheDeficitLedger` is the one that ships, on the configured cache store — the
 * database store today, Redis after Req 30.3's documented drop-in upgrade (task 36.3),
 * with no change here or in the scheduler. A test that wants deterministic credits can
 * bind its own implementation under `tests/`.
 */
interface DeficitLedger
{
    /**
     * Read a lane's credits, let `$work` mutate them, persist them, return whatever
     * `$work` returned.
     *
     * Held under the lane's lock for the duration of `$work`. Implementations must
     * persist the mutated credits even when `$work` returns `null`, and must release the
     * lock even when it throws.
     *
     * @template TReturn
     *
     * @param  string  $queueLane  worker pool the credits belong to
     * @param  Closure(LaneCredits): TReturn  $work
     * @return TReturn
     */
    public function mutate(string $queueLane, Closure $work): mixed;

    /**
     * A lane's credits as they stand, for diagnostics and tests. The returned object is a
     * copy: mutating it changes nothing.
     */
    public function credits(string $queueLane): LaneCredits;

    /**
     * Forget a lane's credits entirely.
     *
     * Every lane starts equal afterwards, which is fair but discards the carried
     * remainders that smooth proportionality across windows — an operator tool (drain a
     * lane, retire a tenant set), not something the dispatch path calls.
     */
    public function reset(string $queueLane): void;
}
