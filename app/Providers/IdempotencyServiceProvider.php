<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Reliability\DatabaseIdempotencyStore;
use App\Services\Reliability\IdempotencyStore;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the generic side-effect dedup ledger (Req 31.2 / NFR2; Req 25.2 / D2).
 *
 * A singleton because the store is stateless: its entire memory is the `idempotency_keys`
 * table, and the only thing it holds is the tenant context it asks for attribution. One
 * instance per worker process is therefore safe, and it keeps a `once()` call off the
 * container's critical path.
 *
 * The interface is bound to the one database implementation deliberately — there is no
 * cache-backed or in-memory variant, and there should not be: a dedup guarantee that
 * survives a restart, a cache flush, and a second app server is exactly what a unique index
 * gives and what nothing in front of the database can.
 *
 * Deliberately *not* a shared `ReliabilityServiceProvider`, for the reason
 * `CircuitBreakerServiceProvider` states: each reliability primitive is cheaper to reason
 * about with its own provider than as one more branch in a common one.
 */
class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IdempotencyStore::class, DatabaseIdempotencyStore::class);
    }
}
