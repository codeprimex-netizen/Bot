<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * A namespace of cached entries whose whole contents can be invalidated by one
 * atomic increment (design.md §Caching layers & invalidation; Req 30.4 / NFR1).
 *
 * ## The pattern
 *
 * Every entry is stored under a key that carries the namespace's **version**:
 *
 * ```
 * plans:v7:id:01J...        plans:v7:slug:growth        plans:v7:active
 * ```
 *
 * Invalidation never scans or enumerates keys — it bumps the version:
 *
 * ```php
 * $cache = VersionedCache::for('plans', ttlSeconds: 300);
 *
 * $plan = $cache->remember('slug:growth', fn () => Plan::whereSlug('growth')->first()?->getAttributes());
 * $cache->bump();   // every plans:v7:* key is now unreachable, O(1)
 * ```
 *
 * Why a version rather than `Cache::forget()` per key: the reader composes the key
 * it looks under from the *current* version, so after a bump every pre-bump entry
 * is unreachable **at once** — including the ones the writer did not know about
 * (a derived listing, a per-tenant variant, an entry another node wrote). There is
 * no window in which a reader can still name an old key, so a stale read is
 * impossible rather than merely unlikely. Tag-based flushing gives the same result
 * but only on stores that support tags (not `database` or `file`, which is what
 * this platform ships on before the documented Redis upgrade of Req 30.3), and it
 * costs a tag-set write per entry.
 *
 * Orphaned pre-bump entries are not deleted; they simply expire on their own TTL,
 * which is why every namespace has one (`forever` entries are not accepted).
 *
 * ## Version durability
 *
 * The version lives in the same store as the data, written with `forever()`. If it
 * is ever lost (cache flush, eviction, a fresh Redis), it is re-seeded from the
 * millisecond clock rather than from 1 — so a rebuilt version is always *greater*
 * than any version used before, and entries written under an older version can
 * never be resurrected by the counter coming back around to them.
 *
 * ## Reuse
 *
 * This is the one caching primitive for the layers in design.md's invalidation
 * table — plan features (task 2.1), tenant settings, published flow graphs,
 * rendered prompt templates. Instantiate one per namespace; scope per-tenant data
 * by putting the tenant id in the namespace (`"tenant:{$id}:settings"`) so one
 * tenant's bump never invalidates another's entries.
 *
 * Two rules for callers:
 *  1. cache **plain data** (attribute arrays, scalars), not live objects, so an
 *     entry stays readable across deploys;
 *  2. anything that writes past Eloquent events (`Model::query()->update()`, raw
 *     SQL, bulk imports) must call `bump()` itself — model observers cover the
 *     rest.
 */
final class VersionedCache
{
    /**
     * Suffix of the key holding the namespace's current version. It deliberately
     * does *not* contain the version, so it survives every bump.
     */
    private const string VERSION_KEY_SUFFIX = ':version';

    /**
     * Highest version this instance has handed out, so a version lost from the store
     * is re-seeded above it even when the clock has not moved.
     */
    private int $observedCeiling = 0;

    /**
     * @param  string  $namespace  key prefix, e.g. `plans` or `tenant:01J...:settings`
     * @param  int  $ttlSeconds  lifetime of every entry in this namespace
     * @param  string|null  $store  cache store name, or null for the default one
     */
    public function __construct(
        private readonly string $namespace,
        private readonly int $ttlSeconds,
        private readonly ?string $store = null,
    ) {
        if (trim($this->namespace) === '') {
            throw new InvalidArgumentException('A versioned cache namespace cannot be empty.');
        }

        if ($this->ttlSeconds <= 0) {
            throw new InvalidArgumentException(sprintf(
                'A versioned cache needs a positive TTL, got %d seconds. Superseded entries are '
                .'left to expire rather than deleted, so a namespace without a TTL would leak.',
                $this->ttlSeconds,
            ));
        }
    }

    /**
     * A namespace configured from `config/wa.php` (`wa.cache.store`, `wa.cache.ttl.*`).
     */
    public static function for(string $namespace, ?int $ttlSeconds = null): self
    {
        $store = config('wa.cache.store');

        return new self(
            namespace: $namespace,
            ttlSeconds: $ttlSeconds ?? (int) config('wa.cache.ttl.default', 300),
            store: is_string($store) && $store !== '' ? $store : null,
        );
    }

    /**
     * The namespace this instance versions.
     */
    public function namespace(): string
    {
        return $this->namespace;
    }

    /**
     * The current version, seeding it if the store has never held one.
     */
    public function version(): int
    {
        $current = $this->repository()->get($this->versionKey());

        if (is_int($current) && $current > 0) {
            return $this->recordVersion($current);
        }

        $seed = $this->seedVersion();

        $this->repository()->forever($this->versionKey(), $seed);

        return $this->recordVersion($seed);
    }

    /**
     * Invalidate the whole namespace, returning the new version.
     */
    public function bump(): int
    {
        $current = $this->version();

        $bumped = $this->repository()->increment($this->versionKey());

        if (is_int($bumped) && $bumped > $current) {
            return $this->recordVersion($bumped);
        }

        // Stores whose increment is a no-op on a `forever` value (or that lost the
        // key between the two calls) still have to end up on a *higher* version.
        $next = $current + 1;

        $this->repository()->forever($this->versionKey(), $next);

        return $this->recordVersion($next);
    }

    /**
     * The fully-qualified key $key is stored under right now.
     */
    public function key(string $key): string
    {
        return sprintf('%s:v%d:%s', $this->namespace, $this->version(), $key);
    }

    /**
     * Read $key, computing and caching it on a miss.
     *
     * `null` from $callback is not cached as a hit — a negative lookup re-runs
     * rather than being pinned for the TTL.
     *
     * @param  Closure(): mixed  $callback
     */
    public function remember(string $key, Closure $callback): mixed
    {
        $repository = $this->repository();
        $versionedKey = $this->key($key);

        $cached = $repository->get($versionedKey);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        if ($value !== null) {
            $repository->put($versionedKey, $value, $this->ttlSeconds);
        }

        return $value;
    }

    /**
     * Read $key without computing it.
     */
    public function get(string $key): mixed
    {
        return $this->repository()->get($this->key($key));
    }

    public function put(string $key, mixed $value): void
    {
        $this->repository()->put($this->key($key), $value, $this->ttlSeconds);
    }

    /**
     * Increment a counter inside the namespace and return its new value.
     *
     * The counting primitive behind velocity limits (`App\Support\Cache\VelocityCounter`,
     * task 4.5): `add()` first so the entry is created **with the namespace TTL** and then
     * expires on its own, `increment()` afterwards so concurrent workers cannot lose each
     * other's hits. Two callers incrementing at once therefore both count — which matters
     * for an anti-abuse counter, where a lost hit is a bypass.
     *
     * A store whose `increment()` is not atomic (or not supported) still ends up counting,
     * via a read-modify-write fallback: the count may then be low under heavy concurrency,
     * never high, so a threshold cannot be crossed by accident.
     */
    public function increment(string $key, int $by = 1): int
    {
        $repository = $this->repository();
        $versionedKey = $this->key($key);

        if ($repository->add($versionedKey, $by, $this->ttlSeconds)) {
            return $by;
        }

        $incremented = $repository->increment($versionedKey, $by);

        if (is_int($incremented)) {
            return $incremented;
        }

        $current = $repository->get($versionedKey);
        $next = (is_int($current) ? $current : 0) + $by;

        $repository->put($versionedKey, $next, $this->ttlSeconds);

        return $next;
    }

    /**
     * Drop one entry. Prefer `bump()` when a write may have invalidated entries
     * beyond the one you can name.
     */
    public function forget(string $key): void
    {
        $this->repository()->forget($this->key($key));
    }

    public function has(string $key): bool
    {
        return $this->repository()->has($this->key($key));
    }

    private function repository(): Repository
    {
        return Cache::store($this->store);
    }

    private function versionKey(): string
    {
        return $this->namespace.self::VERSION_KEY_SUFFIX;
    }

    /**
     * A first (or rebuilt) version.
     *
     * Millisecond wall clock, floored at "one above the highest version this
     * instance has already handed out". Two independent guarantees, because a lost
     * version key must never re-issue a number that entries are already stored
     * under: the clock covers a fresh process (the store was flushed or replaced),
     * the ceiling covers this one (the key was evicted mid-request, where the clock
     * may not have advanced since the last bump).
     */
    private function seedVersion(): int
    {
        return max((int) (microtime(true) * 1000), $this->observedCeiling + 1);
    }

    /**
     * Note the version in use and return it unchanged.
     */
    private function recordVersion(int $version): int
    {
        $this->observedCeiling = max($this->observedCeiling, $version);

        return $version;
    }
}
