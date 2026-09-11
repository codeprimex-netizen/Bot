<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use App\Services\Reliability\CircuitBreakerThresholds;
use Tests\Fixtures\Breakers;

/*
|--------------------------------------------------------------------------
| Correctness Property 13 — circuit-breaker safety
|--------------------------------------------------------------------------
| design.md: *"∀ breaker in state `OPEN` → the guarded operation is never invoked;
| ∀ transition sequence → `CLOSED→OPEN` occurs only after the failure threshold/error-rate
| within the window, and `HALF_OPEN` admits at most `probeLimit` probes."*
|
| **Validates: Requirements 31.3 / NFR2, 13.13 / B4**
|
| The property is checked the only way it can be honestly checked: by driving `call()` with
| random failure/success sequences and random time advances, reading the **persisted row**
| immediately before each call, and asserting the outcome against what that row licensed.
| Thresholds are drawn at random too, so no assertion is quietly relying on a convenient
| number — and the sequences are long enough that a run walks the whole state machine
| (`CLOSED → OPEN → HALF_OPEN → CLOSED`/`OPEN`) many times over.
|
| Three things are asserted at every single step:
|
|   1. **the safety property** — if the row was `OPEN` and its cool-down had not elapsed,
|      the operation did not run and the caller got a `CircuitOpenException`;
|   2. **transition legality** — every state change is an edge `CircuitState::allowedNext()`
|      permits, and a `CLOSED → OPEN` edge is justified by the counters that produced it;
|   3. **the probe bound** — `half_open_probes` never exceeds the configured budget.
*/

it('never invokes the guarded operation while the breaker is open', function (): void {
    // Aggregated across iterations, purely to prove the sequences reached the interesting
    // states — the property itself is asserted per step, below.
    $trips = 0;
    $probesAdmitted = 0;
    $closures = 0;
    $totalRefusals = 0;

    foreach (range(1, 12) as $iteration) {
        $scope = fake()->randomElement(CircuitScope::cases());
        $name = 'prop-'.$iteration;

        // Random tolerances, including runs with the error-rate arm switched off entirely,
        // so both trip arms are exercised across iterations. The window is drawn wide
        // relative to the failure threshold because the clock below advances a second or so
        // per step: a window too narrow to hold `failure_threshold` calls would rotate
        // before anything could accumulate, and the sequence would never leave CLOSED.
        $failureThreshold = random_int(2, 5);
        $probes = random_int(1, 4);

        Breakers::configure(
            defaults: [
                'failure_threshold' => $failureThreshold,
                'window_seconds' => random_int($failureThreshold * 5, 40),
                'error_rate' => fake()->boolean() ? 0.5 : null,
                'error_rate_sample' => random_int(3, 8),
                'open_seconds' => random_int(5, 30),
                'probes' => $probes,
                'probe_successes' => random_int(1, $probes),
            ],
        );

        // Blank the shipped family overrides, so the drawn defaults are the numbers actually
        // in force whichever family this iteration picked. Set rather than merged: a merge
        // cannot *remove* an override.
        config()->set('wa.reliability.circuit.scopes', [$scope->value => []]);

        $breaker = Breakers::service();
        $thresholds = CircuitBreakerThresholds::forScope($scope);

        $invocations = ['count' => 0];
        $invocationsWhileOpen = 0;

        foreach (range(1, 60) as $step) {
            $before = Breakers::row($scope, $name);
            $stateBefore = $before instanceof CircuitBreakerRecord ? $before->state : CircuitState::Closed;

            // Was this call *definitely* barred? OPEN inside its cool-down is the case
            // Property 13 is stated about; HALF_OPEN with a spent budget is the probe bound.
            $barredByCoolDown = $before instanceof CircuitBreakerRecord
                && $stateBefore->isOpen()
                && ! $before->openDurationHasElapsed($thresholds->openSeconds);

            $ranBefore = $invocations['count'];
            $thrown = Breakers::attempt($breaker, $scope, $name, fake()->boolean(35), $invocations);
            $ran = $invocations['count'] > $ranBefore;

            $after = Breakers::requireRow($scope, $name);

            // ---- 1. the safety property ------------------------------------------------
            if ($barredByCoolDown) {
                $invocationsWhileOpen += $ran ? 1 : 0;
                $totalRefusals++;

                expect($ran)->toBeFalse(sprintf(
                    'iteration %d step %d: the operation ran while the breaker was OPEN '
                    .'(opened_at %s, open_seconds %d).',
                    $iteration,
                    $step,
                    $before->opened_at?->toDateTimeString() ?? 'null',
                    $thresholds->openSeconds,
                ))
                    ->and($thrown)->toBeInstanceOf(CircuitOpenException::class);
            }

            // A refusal, whatever its reason, always means "not attempted".
            if ($thrown instanceof CircuitOpenException) {
                expect($ran)->toBeFalse(sprintf(
                    'iteration %d step %d: CircuitOpenException was thrown but the operation ran.',
                    $iteration,
                    $step,
                ));
            }

            // ---- 2. transition legality -------------------------------------------------
            // One call may take *two* legal edges: an OPEN breaker past its cool-down moves
            // to HALF_OPEN, and the probe it then admits resolves to CLOSED or back to
            // OPEN. So a call's endpoints are checked against the paths those edges allow,
            // not against a single edge — and the one pair no path can reach is
            // `CLOSED -> HALF_OPEN`, because half-open only ever follows a cool-down.
            if ($after->state !== $stateBefore) {
                expect($stateBefore->isClosed() && $after->state->isHalfOpen())->toBeFalse(sprintf(
                    'iteration %d step %d: a CLOSED breaker started rationing calls without '
                    .'ever having opened.',
                    $iteration,
                    $step,
                ));
            }

            if ($stateBefore->isClosed() && $after->state->isOpen()) {
                $trips++;

                // The counters that produced the trip must justify it under one arm or the
                // other. This is the clause that fails if a threshold is ever crossed
                // early, or if the rate arm fires on a window too small to be evidence.
                expect($thresholds->tripsOn($after))->toBeTrue(sprintf(
                    'iteration %d step %d: opened from CLOSED with %d failure(s) and %d call(s) '
                    .'in the window (threshold %d, rate %s over %d).',
                    $iteration,
                    $step,
                    $after->failure_count,
                    $after->windowCalls(),
                    $thresholds->failureThreshold,
                    $thresholds->errorRateThreshold === null ? 'off' : (string) $thresholds->errorRateThreshold,
                    $thresholds->errorRateSample,
                ));

                expect($ran)->toBeTrue(sprintf(
                    'iteration %d step %d: the breaker opened without the operation having run, '
                    .'so nothing failed to justify it.',
                    $iteration,
                    $step,
                ));
            }

            if ($stateBefore->isOpen() && ! $after->state->isOpen()) {
                $probesAdmitted++;

                // The cool-down rule in its strongest form: whatever an OPEN breaker moves
                // to, it cannot move at all until `open_seconds` have passed.
                expect($before?->openDurationHasElapsed($thresholds->openSeconds))->toBeTrue(sprintf(
                    'iteration %d step %d: left OPEN for %s before the %ds cool-down elapsed.',
                    $iteration,
                    $step,
                    $after->state->value,
                    $thresholds->openSeconds,
                ));
            }

            // ---- 3. the probe bound ------------------------------------------------------
            expect($after->half_open_probes)->toBeLessThanOrEqual($thresholds->probeLimit, sprintf(
                'iteration %d step %d: %d probes spent against a budget of %d.',
                $iteration,
                $step,
                $after->half_open_probes,
                $thresholds->probeLimit,
            ))
                ->and($after->half_open_successes)->toBeLessThanOrEqual($after->half_open_probes);

            // Recovery is never assumed from the clock: a breaker only reaches CLOSED
            // through a probe that was admitted *and actually ran*.
            if ($after->state->isClosed() && ! $stateBefore->isClosed()) {
                $closures++;
                $probeWasAdmitted = $stateBefore->isHalfOpen()
                    || ($stateBefore->isOpen() && $before?->openDurationHasElapsed($thresholds->openSeconds) === true);

                expect($probeWasAdmitted)->toBeTrue(sprintf(
                    'iteration %d step %d: closed from %s without a probe being admitted.',
                    $iteration,
                    $step,
                    $stateBefore->value,
                ))
                    ->and($ran)->toBeTrue(sprintf(
                        'iteration %d step %d: closed without the guarded operation running, so '
                        .'nothing proved the dependency healthy.',
                        $iteration,
                        $step,
                    ));
            }

            // Move the clock, so cool-downs elapse and windows rotate at points the test
            // does not choose deliberately. Most steps advance barely at all — otherwise
            // every window would rotate between calls and nothing could ever accumulate to
            // a threshold — and one step in four jumps far enough to cross a cool-down.
            thisTest()->travel(
                fake()->boolean(25)
                    ? random_int(1, $thresholds->openSeconds + 2)
                    : random_int(0, 1)
            )->seconds();
        }

        expect($invocationsWhileOpen)->toBe(0, sprintf(
            'iteration %d: the operation ran %d time(s) while the breaker was OPEN.',
            $iteration,
            $invocationsWhileOpen,
        ));
    }

    // Coverage, not correctness: a property test that never reached OPEN would pass
    // vacuously. Asserted over all 12 iterations rather than each one, because a single
    // draw of thresholds and outcomes may legitimately keep one breaker healthy
    // throughout — twelve of them cannot.
    expect($trips)->toBeGreaterThan(0, 'no breaker ever opened, so nothing was exercised.')
        ->and($totalRefusals)->toBeGreaterThan(0, 'no call was ever refused by an open breaker.')
        ->and($probesAdmitted)->toBeGreaterThan(0, 'no breaker ever reached its cool-down and probed.')
        ->and($closures)->toBeGreaterThan(0, 'no breaker ever recovered, so the close path is untested here.');
});

it('admits at most the probe budget however many workers race for it', function (): void {
    // The property's third clause, under contention: probes are claimed by distinct
    // breaker instances (separate PHP state, one row, one cache) while every earlier probe
    // is still in flight — the shape in which a per-process budget would over-admit.
    foreach (range(1, 8) as $iteration) {
        $probes = random_int(1, 3);
        $workers = $probes + random_int(1, 3);
        $name = 'race-'.$iteration;

        Breakers::configure([
            'error_rate' => null,
            'failure_threshold' => 1,
            'open_seconds' => 10,
            'probes' => $probes,
            // Unreachable within one claim round, so the breaker cannot close mid-race and
            // start admitting calls as a closed circuit instead of as probes.
            'probe_successes' => $probes,
        ]);

        $instances = [];

        foreach (range(1, $workers) as $ignored) {
            $instances[] = Breakers::worker();
        }

        Breakers::service()->trip(CircuitScope::Provider, $name);
        thisTest()->travel(11)->seconds();

        $invocations = ['count' => 0];
        $refusals = 0;

        // Nest the claims: each worker's operation asks the next worker to try, so no probe
        // has resolved when the following one is admitted or refused.
        $claim = function (int $index) use (&$claim, $instances, $workers, $name, &$invocations, &$refusals): void {
            if ($index >= $workers) {
                return;
            }

            $thrown = null;

            try {
                $instances[$index]->call(CircuitScope::Provider, $name, function () use ($claim, $index, &$invocations): string {
                    $invocations['count']++;
                    $claim($index + 1);

                    return 'ok';
                });
            } catch (CircuitOpenException $refused) {
                $thrown = $refused;
                $refusals++;
            }

            if ($thrown instanceof CircuitOpenException) {
                // A refused worker still lets the rest of the race proceed.
                $claim($index + 1);
            }
        };

        $claim(0);

        expect($invocations['count'])->toBe($probes, sprintf(
            'iteration %d: %d worker(s) racing for %d probe(s) ran the operation %d time(s).',
            $iteration,
            $workers,
            $probes,
            $invocations['count'],
        ))
            ->and($refusals)->toBe($workers - $probes)
            ->and(Breakers::requireRow(CircuitScope::Provider, $name)->half_open_probes)
            ->toBeLessThanOrEqual($probes);
    }
});
