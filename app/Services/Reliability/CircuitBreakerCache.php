<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Models\CircuitBreaker as CircuitBreakerRecord;
use App\Support\Cache\VersionedCache;

/**
 * A short-lived snapshot of each breaker row, so an OPEN breaker sheds load without
 * touching the database (Req 31.3 / NFR2, "persisted + cached").
 *
 * ## The one rule
 *
 * **A snapshot may only ever make the breaker more conservative.** It can send a call
 * straight to fail-fast; it can never authorise one. `PersistedCircuitBreaker` settles
 * every admission against the row itself — the probe claim and the `OPEN → HALF_OPEN`
 * move are conditional `UPDATE`s, and a `CLOSED` snapshot only buys the caller the right
 * to *ask* the row. So Correctness Property 13 does not depend on this TTL, on the store
 * being shared between workers, or on the cache existing at all: turn it off
 * (`wa.reliability.circuit.cache.enabled = false`) and the breaker behaves identically,
 * just with one more `SELECT` per guarded call.
 *
 * What the cache is actually for is the moment it matters most: a dependency is down,
 * every worker in the fleet is hitting the same breaker, and each of those calls is
 * *rejected*. That path performs **no writes**, so the snapshot stays hot and each
 * rejection costs one cache read instead of one indexed query per call per worker.
 *
 * ## Invalidation is a write-through, not a version bump
 *
 * `VersionedCache` is the platform's caching primitive and this uses it for the store/TTL
 * plumbing and its namespaced keys — but deliberately **not** for its headline feature.
 * Every write here is to exactly one breaker, and that breaker's key is known, so the
 * fresh snapshot is `put()` straight over the old one: precise, and the next reader gets
 * current state rather than a miss.
 *
 * Bumping instead would be actively harmful. A bump invalidates the *whole namespace*, so
 * one busy CLOSED breaker recording successes would keep evicting the snapshot of the OPEN
 * breaker next to it — destroying exactly the fail-fast path the cache exists for. `bump()`
 * is kept for the coarse, rare case (`flush()`: an operator clearing every snapshot after
 * editing thresholds).
 *
 * ## Plain data only
 *
 * Entries are attribute arrays of scalars, never live models, so a snapshot stays readable
 * across a deploy. A read hydrates an **unsaved** model with `newFromBuilder()` so all the
 * row's predicates (`openDurationHasElapsed()`, `hasProbeCapacity()`, `errorRate()`) are
 * available on it — while `exists = false` keeps it from being mistaken for something
 * writable.
 *
 * @phpstan-type BreakerSnapshot array{
 *     id: int,
 *     scope: string,
 *     name: string,
 *     state: string,
 *     failure_count: int,
 *     success_count: int,
 *     half_open_probes: int,
 *     half_open_successes: int,
 *     window_started_at: string|null,
 *     opened_at: string|null,
 *     last_failure_at: string|null,
 *     last_success_at: string|null,
 * }
 */
final readonly class CircuitBreakerCache
{
    public function __construct(
        private VersionedCache $cache,
        private bool $enabled = true,
    ) {}

    /**
     * Build the cache from `wa.reliability.circuit.cache`.
     *
     * `store` falls back to `wa.cache.store` and then to the default store; the TTL is
     * deliberately far shorter than any breaker clock (see the config block).
     */
    public static function fromConfig(): self
    {
        $store = config('wa.reliability.circuit.cache.store') ?? config('wa.cache.store');
        $namespace = config('wa.reliability.circuit.cache.namespace');
        $ttl = (int) config('wa.reliability.circuit.cache.ttl', 5);

        return new self(
            cache: new VersionedCache(
                namespace: is_string($namespace) && trim($namespace) !== '' ? $namespace : 'reliability:circuit',
                ttlSeconds: $ttl > 0 ? $ttl : 5,
                store: is_string($store) && $store !== '' ? $store : null,
            ),
            enabled: (bool) config('wa.reliability.circuit.cache.enabled', true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The cached snapshot for $key, or null when there is none.
     *
     * Never falls through to the database: a miss means "ask the row", and the caller
     * (which is about to touch the row anyway) is the right place to decide that.
     */
    public function get(CircuitBreakerKey $key): ?CircuitBreakerRecord
    {
        if (! $this->enabled) {
            return null;
        }

        $cached = $this->cache->get($key->cacheKey());

        return is_array($cached) ? $this->hydrate($cached) : null;
    }

    /**
     * Write $row's current state over any existing snapshot.
     *
     * Called after **every** write to a breaker row — including counter-only ones, so a
     * snapshot never reports fewer spent probes than the row has actually handed out.
     */
    public function put(CircuitBreakerKey $key, CircuitBreakerRecord $row): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache->put($key->cacheKey(), $this->snapshot($row));
    }

    /**
     * Drop one breaker's snapshot, so the next read goes to the row.
     */
    public function forget(CircuitBreakerKey $key): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache->forget($key->cacheKey());
    }

    /**
     * Make every snapshot in the namespace unreachable at once.
     *
     * The one legitimate use of a version bump here: thresholds were edited, or an
     * operator wants the whole fleet to re-read state from the rows. Not used on the
     * write path — see the class docblock for why that would be self-defeating.
     */
    public function flush(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache->bump();
    }

    /**
     * @return BreakerSnapshot
     */
    private function snapshot(CircuitBreakerRecord $row): array
    {
        return [
            'id' => (int) $row->getKey(),
            'scope' => $row->scope->value,
            'name' => $row->name,
            'state' => $row->state->value,
            'failure_count' => $row->failure_count,
            'success_count' => $row->success_count,
            'half_open_probes' => $row->half_open_probes,
            'half_open_successes' => $row->half_open_successes,
            'window_started_at' => $row->window_started_at?->toDateTimeString(),
            'opened_at' => $row->opened_at?->toDateTimeString(),
            'last_failure_at' => $row->last_failure_at?->toDateTimeString(),
            'last_success_at' => $row->last_success_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function hydrate(array $snapshot): ?CircuitBreakerRecord
    {
        // A snapshot written by an older deploy may not carry every key this one reads.
        // Treating that as a miss is always safe — the caller falls back to the row.
        foreach (['id', 'scope', 'name', 'state'] as $required) {
            if (! array_key_exists($required, $snapshot)) {
                return null;
            }
        }

        $row = new CircuitBreakerRecord;

        return $row->newFromBuilder($snapshot);
    }
}
