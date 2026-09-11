<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\CircuitBreakerCache;
use App\Services\Reliability\PersistedCircuitBreaker;
use RuntimeException;
use Throwable;

/**
 * Shared entry points for the circuit-breaker tests (Req 31.3 / NFR2, Algorithm 7).
 *
 * A class rather than Pest helper functions for the same reason as `Tests\Fixtures\Dispatch`:
 * Pest loads every test file into one process, so a global `breaker()` helper would be a
 * name the whole suite has to keep free for ever.
 */
final class Breakers
{
    /**
     * The failure a guarded operation throws when a test wants it to fail.
     */
    public const string FAILURE_MESSAGE = 'the dependency is down';

    /**
     * The container's breaker, rebuilt so it picks up any config a test has just changed.
     */
    public static function service(): CircuitBreaker
    {
        app()->forgetInstance(CircuitBreakerCache::class);
        app()->forgetInstance(CircuitBreaker::class);

        return app(CircuitBreaker::class);
    }

    /**
     * A second breaker instance over the same rows and the same cache — a "second worker".
     *
     * Independent PHP state (its own in-memory rows), shared database and shared cache
     * store, which is exactly the shape of two queue workers on one row.
     */
    public static function worker(): CircuitBreaker
    {
        return new PersistedCircuitBreaker(app(CircuitBreakerCache::class));
    }

    /**
     * Point the breaker's thresholds at test-sized numbers.
     *
     * @param  array<string, mixed>  $defaults  knobs under `wa.reliability.circuit.defaults`
     * @param  array<string, array<string, mixed>>  $scopes  per-family overrides
     */
    public static function configure(array $defaults = [], array $scopes = []): void
    {
        config()->set('wa.reliability.circuit.defaults', array_merge(
            (array) config('wa.reliability.circuit.defaults'),
            $defaults,
        ));

        if ($scopes !== []) {
            config()->set('wa.reliability.circuit.scopes', array_replace_recursive(
                (array) config('wa.reliability.circuit.scopes'),
                $scopes,
            ));
        }
    }

    public static function disableCache(): void
    {
        config()->set('wa.reliability.circuit.cache.enabled', false);
    }

    /**
     * An **unpersisted** row with a known window, for asserting on the predicates that read
     * attributes only (the trip condition, the error rate, the probe budget).
     */
    public static function record(
        int $failures = 0,
        int $successes = 0,
        CircuitState $state = CircuitState::Closed,
        int $probes = 0,
        int $probeSuccesses = 0,
    ): CircuitBreakerRecord {
        $row = new CircuitBreakerRecord;

        $row->forceFill([
            'scope' => CircuitScope::Provider,
            'name' => 'openai',
            'state' => $state,
            'failure_count' => $failures,
            'success_count' => $successes,
            'half_open_probes' => $probes,
            'half_open_successes' => $probeSuccesses,
        ]);

        return $row;
    }

    /**
     * The persisted row for a breaker, or null when it has never been exercised.
     */
    public static function row(CircuitScope $scope, string $name): ?CircuitBreakerRecord
    {
        return CircuitBreakerRecord::query()->forKey($scope, $name)->first();
    }

    /**
     * The persisted row, failing the test if there is none.
     */
    public static function requireRow(CircuitScope $scope, string $name): CircuitBreakerRecord
    {
        $row = self::row($scope, $name);

        if (! $row instanceof CircuitBreakerRecord) {
            throw new RuntimeException(sprintf('No breaker row for %s:%s.', $scope->value, $name));
        }

        return $row;
    }

    /**
     * Drive $breaker with a fixed outcome, counting invocations.
     *
     * Returns the exception the call ended with, or null when it succeeded — so a caller can
     * assert on the *shape* of the failure (fail-fast vs the operation's own error) without
     * a try/catch at every call site.
     *
     * @param  array{count: int}  $invocations  by reference, incremented when the operation runs
     */
    public static function attempt(
        CircuitBreaker $breaker,
        CircuitScope $scope,
        string $name,
        bool $succeeds,
        array &$invocations,
    ): ?Throwable {
        try {
            $breaker->call($scope, $name, function () use ($succeeds, &$invocations): string {
                $invocations['count']++;

                if (! $succeeds) {
                    throw new RuntimeException(self::FAILURE_MESSAGE);
                }

                return 'ok';
            });

            return null;
        } catch (Throwable $thrown) {
            return $thrown;
        }
    }

    /**
     * Push $breaker through $times failures, ignoring how each one ended.
     *
     * @return int how many times the guarded operation actually ran
     */
    public static function fail(CircuitBreaker $breaker, CircuitScope $scope, string $name, int $times = 1): int
    {
        $invocations = ['count' => 0];

        for ($i = 0; $i < $times; $i++) {
            self::attempt($breaker, $scope, $name, succeeds: false, invocations: $invocations);
        }

        return $invocations['count'];
    }

    /**
     * Push $breaker through $times successes.
     *
     * @return int how many times the guarded operation actually ran
     */
    public static function succeed(CircuitBreaker $breaker, CircuitScope $scope, string $name, int $times = 1): int
    {
        $invocations = ['count' => 0];

        for ($i = 0; $i < $times; $i++) {
            self::attempt($breaker, $scope, $name, succeeds: true, invocations: $invocations);
        }

        return $invocations['count'];
    }
}
