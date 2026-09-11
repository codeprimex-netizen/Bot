<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\CircuitBreakerCache;
use App\Services\Reliability\PersistedCircuitBreaker;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the circuit breaker of Algorithm 7 (Req 31.3 / NFR2; Req 13.13 / B4).
 *
 * Both bindings are singletons because both are stateless: the breaker's entire memory is
 * the `circuit_breakers` row, and the snapshot cache holds nothing but a namespace, a TTL,
 * and a store name. One instance per worker process is therefore safe, and it keeps the
 * breaker off the critical path of resolving anything else.
 *
 * Deliberately *not* a broader `ReliabilityServiceProvider`: the outbox relay (task 3.3),
 * the idempotency store (3.4), the saga orchestrator (3.5), and the retry policy (3.6) are
 * separate primitives with separate lifetimes, and each is cheaper to reason about with its
 * own provider than as one more branch in a shared one.
 */
class CircuitBreakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A closure, not an eager instance: config is read on first resolution, so a test
        // that overrides `wa.reliability.circuit.cache.*` before touching the breaker is
        // honoured.
        $this->app->singleton(CircuitBreakerCache::class, fn (): CircuitBreakerCache => CircuitBreakerCache::fromConfig());

        $this->app->singleton(CircuitBreaker::class, PersistedCircuitBreaker::class);
    }
}
