<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Services\Reliability\CircuitBreakerThresholds;
use Tests\Fixtures\Breakers;

/*
|--------------------------------------------------------------------------
| CircuitBreakerThresholds — config resolution and the trip predicate
|--------------------------------------------------------------------------
| The declarative half of Algorithm 7: which numbers apply to which family, and the open
| condition evaluated against a row (Req 31.3 / NFR2; Req 13.13 / B4).
*/

it('ships the design\'s LLM provider row as the platform default', function (): void {
    $provider = CircuitBreakerThresholds::forScope(CircuitScope::Provider);

    expect($provider->failureThreshold)->toBe(5)
        ->and($provider->windowSeconds)->toBe(30)
        ->and($provider->errorRateThreshold)->toBe(0.5)
        ->and($provider->errorRateSample)->toBe(20)
        ->and($provider->openSeconds)->toBe(30)
        ->and($provider->probeLimit)->toBe(3)
        ->and($provider->probeSuccesses)->toBe(1)
        ->and($provider->errorRateArmIsLive())->toBeTrue();
});

it('ships the design\'s gateway and bridge rows as family overrides', function (): void {
    $gateway = CircuitBreakerThresholds::forScope(CircuitScope::Gateway);
    $bridge = CircuitBreakerThresholds::forScope(CircuitScope::Bridge);

    // Gateway: ≥5 fails / 60s, open 60s, 3 probes, no rate arm.
    expect($gateway->failureThreshold)->toBe(5)
        ->and($gateway->windowSeconds)->toBe(60)
        ->and($gateway->openSeconds)->toBe(60)
        ->and($gateway->probeLimit)->toBe(3)
        ->and($gateway->errorRateThreshold)->toBeNull()
        ->and($gateway->errorRateArmIsLive())->toBeFalse()
        // Bridge: ≥3 fails / 30s, open 15s, 2 probes, no rate arm.
        ->and($bridge->failureThreshold)->toBe(3)
        ->and($bridge->windowSeconds)->toBe(30)
        ->and($bridge->openSeconds)->toBe(15)
        ->and($bridge->probeLimit)->toBe(2)
        ->and($bridge->errorRateThreshold)->toBeNull();
});

it('resolves each knob family-override first, then default, then fallback', function (): void {
    config()->set('wa.reliability.circuit.defaults', ['failure_threshold' => 9]);
    config()->set('wa.reliability.circuit.scopes', [
        CircuitScope::Bridge->value => ['open_seconds' => 7],
    ]);

    $bridge = CircuitBreakerThresholds::forScope(CircuitScope::Bridge);

    expect($bridge->openSeconds)->toBe(7)
        // …from the platform default,
        ->and($bridge->failureThreshold)->toBe(9)
        // …and from the compiled-in fallback, because the defaults array above named
        // neither. Deleting a key from config cannot leave a threshold unset.
        ->and($bridge->probeLimit)->toBe(CircuitBreakerThresholds::DEFAULT_PROBES)
        ->and($bridge->windowSeconds)->toBe(CircuitBreakerThresholds::DEFAULT_WINDOW_SECONDS);
});

it('treats a null error rate as "arm off" rather than "key absent"', function (): void {
    config()->set('wa.reliability.circuit.defaults', ['error_rate' => 0.5]);
    config()->set('wa.reliability.circuit.scopes', [
        CircuitScope::Gateway->value => ['error_rate' => null],
    ]);

    expect(CircuitBreakerThresholds::forScope(CircuitScope::Gateway)->errorRateThreshold)->toBeNull()
        ->and(CircuitBreakerThresholds::forScope(CircuitScope::Provider)->errorRateThreshold)->toBe(0.5);
});

it('rejects values that cannot describe a working breaker', function (): void {
    expect(fn () => new CircuitBreakerThresholds(probeLimit: 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CircuitBreakerThresholds(windowSeconds: 0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CircuitBreakerThresholds(failureThreshold: -1))
        ->toThrow(InvalidArgumentException::class)
        // A rate of 1 or more could never be exceeded, so it is an arm that never fires —
        // write null if that is what you mean.
        ->and(fn () => new CircuitBreakerThresholds(errorRateThreshold: 1.0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CircuitBreakerThresholds(errorRateThreshold: -0.5))
        ->toThrow(InvalidArgumentException::class)
        // More successes required than probes admitted = a breaker that can never close.
        ->and(fn () => new CircuitBreakerThresholds(probeLimit: 2, probeSuccesses: 3))
        ->toThrow(InvalidArgumentException::class);
});

it('opens on the count arm at the threshold and not before', function (): void {
    $thresholds = new CircuitBreakerThresholds(failureThreshold: 5, errorRateThreshold: null);

    expect($thresholds->tripsOn(Breakers::record(failures: 4, successes: 0)))->toBeFalse()
        ->and($thresholds->tripsOn(Breakers::record(failures: 5, successes: 0)))->toBeTrue()
        ->and($thresholds->tripsOn(Breakers::record(failures: 6, successes: 40)))->toBeTrue();
});

it('opens on the rate arm only above the threshold and only with enough evidence', function (): void {
    $thresholds = new CircuitBreakerThresholds(
        failureThreshold: 1000,
        errorRateThreshold: 0.5,
        errorRateSample: 20,
    );

    expect($thresholds->errorRateExceededBy(Breakers::record(failures: 10, successes: 10)))->toBeFalse()
        ->and($thresholds->errorRateExceededBy(Breakers::record(failures: 11, successes: 9)))->toBeTrue()
        // 19 calls is below the sample floor, however bad they were.
        ->and($thresholds->errorRateExceededBy(Breakers::record(failures: 19, successes: 0)))->toBeFalse()
        // …and a breaker nobody has called is never opened by this arm.
        ->and($thresholds->errorRateExceededBy(Breakers::record(failures: 0, successes: 0)))->toBeFalse()
        ->and($thresholds->tripsOn(Breakers::record(failures: 0, successes: 0)))->toBeFalse();
});

it('re-opens a half-open breaker on a single failure, whatever the counters say', function (): void {
    $thresholds = new CircuitBreakerThresholds(failureThreshold: 100, errorRateThreshold: null);

    expect($thresholds->tripsOn(Breakers::record(failures: 1, successes: 0, state: CircuitState::HalfOpen)))
        ->toBeTrue()
        ->and($thresholds->tripsOn(Breakers::record(failures: 1, successes: 0)))->toBeFalse();
});

it('never consults the rate arm when it is disabled', function (): void {
    $thresholds = new CircuitBreakerThresholds(failureThreshold: 1000, errorRateThreshold: null);

    expect($thresholds->tripsOn(Breakers::record(failures: 999, successes: 0)))->toBeFalse()
        ->and($thresholds->errorRateArmIsLive())->toBeFalse();
});
