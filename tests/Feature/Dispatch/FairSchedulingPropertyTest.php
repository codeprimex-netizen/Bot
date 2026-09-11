<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Dispatch\DispatchLane;
use Tests\Fixtures\Dispatch;
use Tests\Fixtures\FakeRateCapGate;

/*
|--------------------------------------------------------------------------
| Correctness Property 19 — fair scheduling / noisy-neighbour bound
|--------------------------------------------------------------------------
| design.md: *"∀ set of tenants with pending work → over any dispatch window, the share
| of dispatched units per tenant is proportional to its `lane_weight` within a bounded
| error; no single tenant can starve others beyond that bound."*
|
| **Validates: Requirements 1.7 / A1, 30.2, 30.6 / NFR1**
|
| The bound is not a fudge factor, it is the model: a tenant is credited its quantum
| (`lane_weight × wa.dispatch.fair.quantum`) once per round and spends one credit per
| unit, so a window that ends part-way through a round leaves each tenant at most one
| quantum away from its exact proportional share — and never further, whatever its
| backlog. Every assertion below is stated in those terms:
|
|   | dispatched(t) − N · w(t)/Σw |  ≤  quantum(t)
|
| Weights and backlogs are drawn at random on every iteration, so no assertion is
| quietly relying on a convenient ratio.
*/

beforeEach(function (): void {
    FakeRateCapGate::reset();
    Dispatch::rebuild();
});

it('gives every tenant a share proportional to its lane weight, within one quantum', function (): void {
    foreach (range(1, 10) as $iteration) {
        $weights = Dispatch::randomWeights(random_int(2, 4));
        $tenants = Dispatch::tenantsWeighted($weights);
        $budget = random_int(40, 160);

        // Backlogs are random but all deeper than the window, so the only thing deciding
        // the split is the weight — the saturated case Property 19 is stated about.
        $lanes = [];

        foreach ($tenants as $tenant) {
            $lanes[] = DispatchLane::for($tenant, random_int($budget, $budget * 3));
        }

        $result = Dispatch::window($lanes, $budget);
        $totalWeight = array_sum($weights);

        expect($result->total())->toBe($budget);

        foreach ($tenants as $index => $tenant) {
            $weight = $weights[$index];
            $expected = $budget * $weight / $totalWeight;
            $actual = $result->unitsFor($tenant);

            expect(abs($actual - $expected))->toBeLessThanOrEqual(
                $weight,
                sprintf(
                    'iteration %d: tenant of weight %d got %d of %d units (expected ~%.2f, weights %s)',
                    $iteration,
                    $weight,
                    $actual,
                    $budget,
                    $expected,
                    implode('/', $weights),
                ),
            )
                // No tenant starves: the weight floor of 1 puts every lane in the first
                // pass of every round.
                ->and($actual)->toBeGreaterThan(0);
        }
    }
});

it('never lets one tenant\'s backlog starve the others, however lopsided the queues', function (): void {
    foreach (range(1, 10) as $iteration) {
        $weights = Dispatch::randomWeights(random_int(2, 4));
        $tenants = Dispatch::tenantsWeighted($weights);

        // One tenant has an absurd backlog, the rest have a handful — the noisy-neighbour
        // shape of Req 30.2. Which tenant is noisy is itself random.
        $noisy = random_int(0, count($tenants) - 1);
        $lanes = [];
        $backlogs = [];

        foreach ($tenants as $index => $tenant) {
            $backlogs[$index] = $index === $noisy ? 1_000_000 : random_int(1, 6);
            $lanes[] = DispatchLane::for($tenant, $backlogs[$index]);
        }

        $budget = random_int(count($tenants), 120);
        $result = Dispatch::window($lanes, $budget);

        foreach ($tenants as $index => $tenant) {
            expect($result->unitsFor($tenant))->toBeGreaterThan(
                0,
                sprintf('iteration %d: tenant %d was starved by the noisy lane', $iteration, $index),
            )
                // Nobody is ever dispatched more than it had queued…
                ->and($result->unitsFor($tenant))->toBeLessThanOrEqual($backlogs[$index]);
        }

        // Work-conserving: the window is filled unless the queues genuinely ran out, and
        // the noisy tenant never gets the whole of it however deep its queue.
        expect($result->total())->toBe(min($budget, array_sum($backlogs)))
            ->and($result->unitsFor($tenants[$noisy]))->toBeLessThanOrEqual($budget - (count($tenants) - 1));
    }
});

it('caps what one tenant can take from a single round', function (): void {
    foreach (range(1, 10) as $iteration) {
        $weights = Dispatch::randomWeights(random_int(2, 4));
        $tenants = Dispatch::tenantsWeighted($weights);
        $lanes = [];

        foreach ($tenants as $tenant) {
            $lanes[] = DispatchLane::pending($tenant);
        }

        $budget = random_int(20, 120);
        $result = Dispatch::window($lanes, $budget);

        // The per-round ceiling is `(1 + max_carry_quanta) × quantum`, so over the rounds
        // this window actually took, no tenant can have exceeded that — this is the
        // "bounded share" half of Req 30.2, and it holds independently of backlog because
        // every lane above is unbounded.
        $ceiling = 1 + (int) config('wa.dispatch.fair.max_carry_quanta');

        foreach ($tenants as $index => $tenant) {
            expect($result->unitsFor($tenant))->toBeLessThanOrEqual(
                $result->rounds * $ceiling * $weights[$index],
                sprintf('iteration %d: tenant %d exceeded its per-round ceiling', $iteration, $index),
            );
        }
    }
});

it('gives a skipped tenant its normal share back, and no more, once it clears', function (): void {
    Dispatch::withGate(FakeRateCapGate::class);

    foreach (range(1, 8) as $iteration) {
        $weights = Dispatch::randomWeights(random_int(2, 3));
        $tenants = Dispatch::tenantsWeighted($weights);
        $capped = random_int(0, count($tenants) - 1);
        $budget = random_int(30, 90);

        $lanes = [];

        foreach ($tenants as $tenant) {
            $lanes[] = DispatchLane::for($tenant, $budget * 4);
        }

        FakeRateCapGate::cap($tenants[$capped]);
        $whileCapped = Dispatch::window($lanes, $budget);

        expect($whileCapped->wasSkipped($tenants[$capped]))->toBeTrue()
            ->and($whileCapped->unitsFor($tenants[$capped]))->toBe(0)
            // Skipping frees the units instead of wasting them: the window is still full.
            ->and($whileCapped->total())->toBe($budget);

        FakeRateCapGate::release($tenants[$capped]);
        $afterCap = Dispatch::window($lanes, $budget);

        $totalWeight = array_sum($weights);

        // This second window starts on a *warm* ledger, which is the whole point of the
        // scenario and which sets the bound that applies to it. The tenants that were
        // dispatched while their neighbour sat out ended that window carrying up to
        // `max_carry_quanta` quanta of unspent credit, so they enter this one already in
        // funds — and design.md's error term is stated for exactly that case:
        // `(1 + max_carry_quanta) × quantum(t)`. It is the same constant the multi-worker
        // bound below uses, and the reason the one-quantum bound in the first test of this
        // file is legitimate *there* is that its ledger starts cold.
        $carryBound = 1 + (int) config('wa.dispatch.fair.max_carry_quanta');

        foreach ($tenants as $index => $tenant) {
            $expected = $budget * $weights[$index] / $totalWeight;
            $actual = $afterCap->unitsFor($tenant);

            // Back to proportional immediately: the cap did not hand the skipped tenant's
            // share to the others permanently.
            expect(abs($actual - $expected))->toBeLessThanOrEqual(
                $carryBound * $weights[$index],
                sprintf(
                    'iteration %d: tenant %d got %d of %d units after its cap lifted (expected ~%.2f)',
                    $iteration,
                    $index,
                    $actual,
                    $budget,
                    $expected,
                ),
            );

            if ($index !== $capped) {
                continue;
            }

            // …and the "no more" half of this test's name, which is a *tighter* claim than
            // the bound above rather than a weaker one. The skipped tenant is the one lane
            // that provably enters this window on zero credit — `retain()` keeps an
            // ineligible tenant's credit untouched, and an ineligible tenant is never
            // credited, so being capped froze its entitlement instead of accruing it. A
            // lane starting at zero cannot be carrying a hoard, so it cannot overshoot by
            // more than the single quantum this round grants it. If a skipped tenant ever
            // did accumulate while capped, this is the assertion that fails: it would come
            // back with a burst, and the burst would land here first.
            expect($actual - $expected)->toBeLessThanOrEqual(
                $weights[$index],
                sprintf(
                    'iteration %d: tenant %d took a catch-up burst of %d units (expected ~%.2f) '
                    .'after being capped, so its credit accumulated while it was skipped',
                    $iteration,
                    $index,
                    $actual,
                    $expected,
                ),
            );
        }
    }
});

it('holds the same bound when the window is dispatched by several workers', function (): void {
    // The property must survive a worker group, not just one loop. Each tick is a
    // separate claim against the shared ledger, which is what two Supervisor workers
    // do to each other; the lock that makes it atomic under real concurrency is
    // MySQL's (and Redis's after task 36.3) and cannot be exercised in-process.
    foreach (range(1, 6) as $iteration) {
        $weights = Dispatch::randomWeights(random_int(2, 4));
        $tenants = Dispatch::tenantsWeighted($weights);
        $lanes = [];

        foreach ($tenants as $tenant) {
            $lanes[] = DispatchLane::pending($tenant);
        }

        $totalWeight = array_sum($weights);
        $ticks = random_int(4, 10);

        // At least one full round per tick. A window *smaller* than a round cannot be both
        // proportional and starvation-free — every lane gets its guaranteed first unit,
        // which over-serves the light lanes — and the platform deliberately prefers
        // starvation-free (see `DeficitRoundRobinScheduler`). Proportionality is a claim
        // about windows of a round or more, so that is what is drawn here.
        $perTick = random_int($totalWeight, $totalWeight * 3);
        $units = [];

        foreach (range(1, $ticks) as $ignored) {
            Dispatch::scheduler()->dispatchRound($lanes, $perTick, function (Tenant $tenant) use (&$units): bool {
                $units[$tenant->id] = ($units[$tenant->id] ?? 0) + 1;

                return true;
            });
        }

        $budget = $ticks * $perTick;

        expect(array_sum($units))->toBe($budget);

        foreach ($tenants as $index => $tenant) {
            $expected = $budget * $weights[$index] / $totalWeight;

            // Carried credit is what makes many small windows add up to the same share as
            // one large one, so the bound does not grow with the number of ticks.
            expect(abs(($units[$tenant->id] ?? 0) - $expected))->toBeLessThanOrEqual(
                2 * $weights[$index],
                sprintf(
                    'iteration %d: tenant %d got %d of %d units across %d ticks (expected ~%.2f)',
                    $iteration,
                    $index,
                    $units[$tenant->id] ?? 0,
                    $budget,
                    $ticks,
                    $expected,
                ),
            );
        }
    }
});
