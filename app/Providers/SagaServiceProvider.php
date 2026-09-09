<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Reliability\PersistedSagaOrchestrator;
use App\Services\Reliability\SagaDefinitionRegistry;
use App\Services\Reliability\SagaOrchestrator;
use Illuminate\Support\ServiceProvider;

/**
 * Wires saga orchestration (Req 15.5 / B6, Req 31.5 / NFR2, Algorithm 8).
 *
 * Singletons because both classes are stateless: the orchestrator's entire memory is the
 * `sagas` / `saga_steps` / `idempotency_keys` rows, and the registry re-resolves its
 * definitions from the container on every lookup rather than caching instances — so
 * neither can carry one tenant's saga into the next one's, even on a queue worker that
 * lives for days.
 *
 * The step *definitions* are deliberately not registered here. They are resolved by class
 * name from `wa.reliability.saga.definitions`, so a later phase adds a saga by writing a
 * definition and appending it to that array — see `SagaDefinitionRegistry`. That list is
 * empty until Phase 5 introduces the order → payment → fulfilment saga, which is why
 * running a saga of an unclaimed type raises rather than this provider refusing to boot.
 *
 * Deliberately *not* folded into a shared `ReliabilityServiceProvider`, for the reason
 * `CircuitBreakerServiceProvider` and `IdempotencyServiceProvider` each state: one
 * provider per primitive is cheaper to reason about than one more branch in a common one.
 */
class SagaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SagaDefinitionRegistry::class);
        $this->app->singleton(SagaOrchestrator::class, PersistedSagaOrchestrator::class);
    }
}
