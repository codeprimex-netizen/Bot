<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantTier;
use App\Exceptions\Tenancy\UnknownShardConnectionException;
use App\Models\Tenant;
use App\Models\TenantTierAssignment;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Tier resolution driven by `tenant_tiers` rows on top of `config/wa.php` defaults —
 * the router that makes a tenant's isolation level a config flip (Req 1.6 / A1).
 *
 * ## Precedence
 *
 * Three layers answer "what tier is this tenant on?", most specific first:
 *
 * 1. **`wa.tenancy.tiers.overrides`** — an operator break-glass map (`tenant id` or
 *    `slug` → tier). Deploy-time and reversible without a write, for draining a
 *    tenant off a failing shard or reproducing a tier in staging.
 * 2. **the tenant's `tenant_tiers` row** — the durable, per-tenant decision that the
 *    admin panel and provisioning write.
 * 3. **`wa.tenancy.tiers.default`** — what every tenant without a row is on, which is
 *    almost all of them.
 *
 * The same layering applies to lane weight (row weight → tier's configured default →
 * built-in), so an operator can reshape the whole fairness curve in config while a
 * single noisy tenant stays pinned by its row.
 *
 * ## Shard routing
 *
 * `connection()` is the `DEDICATED_DB` cutover path and it is deliberately boring:
 * a row's `dedicated_conn`, else its `shard_key` through the configured map, else a
 * consistent hash of the tenant id over the configured ring, else `null` for the
 * shared connection. Only connections that already exist in `config/database.php` are
 * ever returned — a name that does not resolve raises
 * `UnknownShardConnectionException` rather than quietly falling back to the shared
 * database (see that class). On a single-database install nothing is configured, so
 * every tenant resolves to `null` and behaviour is unchanged.
 *
 * ## Caching
 *
 * Only the **database lookup** is cached — never the config-derived answer. That is
 * what lets a config flip take effect on the next call while a row change still needs
 * (and gets) explicit invalidation: `TenantTierAssignment` calls `forget()` on every
 * write, and `flush()` bumps a cache version so the whole namespace is dropped
 * without enumerating keys. A per-instance memo makes repeated questions about the
 * same tenant — exactly what a dispatch loop asks — free.
 *
 * The version-bump scheme below is hand-rolled here and duplicates
 * `App\Support\Cache\VersionedCache`, which landed alongside it as the platform's
 * shared primitive for exactly this pattern. `VersionedCache` is the one to
 * consolidate onto — this class should become a caller of it rather than a second
 * implementation. Two behaviours have to be reconciled first, and neither is
 * incidental: this cache stores a **negative hit** ("no `tenant_tiers` row") as a
 * real hit, which is the common case and the whole point of caching here, whereas
 * `VersionedCache::remember()` deliberately does not cache `null`; and a
 * `cache.ttl` of 0 here means "cache forever and rely on write invalidation
 * alone", which `VersionedCache` rejects by design (it needs a TTL so superseded
 * entries expire). Left as-is deliberately: the duplication is contained to the
 * private helpers below and unpicking it is a behaviour change, not a cleanup.
 */
final class ConfiguredTierResolver implements TierResolver
{
    /**
     * The `tenant_tiers` row per tenant id for this instance's lifetime, `null` where
     * the tenant has no row. Keys present with a `null` value are negative hits.
     *
     * @var array<string, array{tier: string, lane_weight: int|null, shard_key: string|null, connection: string|null}|null>
     */
    private array $memo = [];

    public function __construct(private readonly CacheFactory $cache) {}

    public function tierOf(Tenant $tenant): TenantTier
    {
        $override = $this->overrideFor($tenant);

        if ($override !== null) {
            return $override;
        }

        $row = $this->assignment($tenant->id);

        if ($row !== null) {
            $tier = TenantTier::tryFromLoose($row['tier']);

            if ($tier !== null) {
                return $tier;
            }
        }

        return $this->defaultTier();
    }

    public function laneWeight(Tenant $tenant): int
    {
        $row = $this->assignment($tenant->id);
        $pinned = $row['lane_weight'] ?? null;

        return $this->clampWeight($pinned ?? $this->tierOf($tenant)->defaultLaneWeight());
    }

    public function connection(Tenant $tenant): ?string
    {
        if (! $this->tierOf($tenant)->usesDedicatedConnection()) {
            // Both shared tiers live on the default connection; no shard config is
            // even consulted, so a half-configured ring cannot affect them.
            return null;
        }

        $row = $this->assignment($tenant->id);
        $named = $row['connection'] ?? null;

        if ($named !== null) {
            return $this->assertConfigured($tenant, $named);
        }

        $shardKey = $row['shard_key'] ?? null;

        if ($shardKey !== null) {
            $mapped = $this->shardMap()[$shardKey] ?? null;

            if ($mapped !== null) {
                return $this->assertConfigured($tenant, $mapped);
            }
        }

        $ring = $this->shardRing();

        if ($ring !== []) {
            return $this->assertConfigured($tenant, $ring[$this->ringSlot($tenant->id, count($ring))]);
        }

        // Tier says "dedicated database" but this deployment has no shards yet: the
        // tenant keeps its dedicated workers on the shared connection until a cutover
        // target exists. This is the state every install starts in.
        return null;
    }

    public function shardKey(Tenant $tenant): ?string
    {
        return $this->assignment($tenant->id)['shard_key'] ?? null;
    }

    public function forget(Tenant|string $tenant): void
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        unset($this->memo[$tenantId]);

        if ($this->cacheEnabled()) {
            $this->store()->forget($this->cacheKey($tenantId));
        }
    }

    public function flush(): void
    {
        $this->memo = [];

        if (! $this->cacheEnabled()) {
            return;
        }

        // Version bump rather than key enumeration: cache stores cannot list keys by
        // prefix, and every existing key embeds the old version, so incrementing
        // orphans all of them at once and they expire on their own TTL.
        $store = $this->store();
        $key = $this->versionKey();

        if ($store->add($key, 2, null) === false) {
            $store->increment($key);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Lookup + caching
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant's `tenant_tiers` row, normalized for caching, or null when it has none.
     *
     * @return array{tier: string, lane_weight: int|null, shard_key: string|null, connection: string|null}|null
     */
    private function assignment(string $tenantId): ?array
    {
        if (array_key_exists($tenantId, $this->memo)) {
            return $this->memo[$tenantId];
        }

        if (! $this->cacheEnabled()) {
            return $this->memo[$tenantId] = $this->load($tenantId);
        }

        $ttl = $this->configInt('cache.ttl', 300);
        $key = $this->cacheKey($tenantId);

        // Wrapped in a one-key envelope so "this tenant has no row" is a cache *hit*
        // rather than indistinguishable from a miss — otherwise the most common case
        // (no row at all) would query on every single call.
        $cached = $ttl > 0
            ? $this->store()->remember($key, $ttl, fn (): array => ['row' => $this->load($tenantId)])
            : $this->store()->rememberForever($key, fn (): array => ['row' => $this->load($tenantId)]);

        $row = is_array($cached) ? ($cached['row'] ?? null) : null;

        return $this->memo[$tenantId] = is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * @return array{tier: string, lane_weight: int|null, shard_key: string|null, connection: string|null}|null
     */
    private function load(string $tenantId): ?array
    {
        // `forTenant()` rather than the ambient scope: the scheduler, the shard router,
        // and platform screens all ask about a tenant they are not acting as, and this
        // read names its tenant explicitly (task 0.3's sanctioned hatch).
        $assignment = TenantTierAssignment::forTenant($tenantId)->first();

        if (! $assignment instanceof TenantTierAssignment) {
            return null;
        }

        return [
            'tier' => $assignment->tier->value,
            'lane_weight' => $assignment->lane_weight,
            'shard_key' => $this->trimmedOrNull($assignment->shard_key),
            'connection' => $assignment->connectionName(),
        ];
    }

    /**
     * Re-type a payload that has been through the cache serializer.
     *
     * @param  array<array-key, mixed>  $row
     * @return array{tier: string, lane_weight: int|null, shard_key: string|null, connection: string|null}
     */
    private function normalize(array $row): array
    {
        $weight = $row['lane_weight'] ?? null;

        return [
            'tier' => is_string($row['tier'] ?? null) ? $row['tier'] : TenantTier::Shared->value,
            'lane_weight' => is_numeric($weight) ? (int) $weight : null,
            'shard_key' => $this->trimmedOrNull($row['shard_key'] ?? null),
            'connection' => $this->trimmedOrNull($row['connection'] ?? null),
        ];
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('wa.tenancy.tiers.cache.enabled', true);
    }

    private function store(): CacheRepository
    {
        $store = config('wa.tenancy.tiers.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    private function cacheKey(string $tenantId): string
    {
        return $this->cachePrefix().':v'.$this->cacheVersion().':'.$tenantId;
    }

    private function versionKey(): string
    {
        return $this->cachePrefix().':version';
    }

    private function cachePrefix(): string
    {
        $prefix = config('wa.tenancy.tiers.cache.prefix');

        return is_string($prefix) && $prefix !== '' ? $prefix : 'tenancy:tier';
    }

    private function cacheVersion(): int
    {
        $version = $this->store()->get($this->versionKey(), 1);

        return is_numeric($version) ? max(1, (int) $version) : 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Config layer
    |--------------------------------------------------------------------------
    */

    private function defaultTier(): TenantTier
    {
        return TenantTier::tryFromLoose(config('wa.tenancy.tiers.default')) ?? TenantTier::Shared;
    }

    /**
     * The operator break-glass tier for this tenant, by id or by slug.
     */
    private function overrideFor(Tenant $tenant): ?TenantTier
    {
        $overrides = $this->configArray('overrides');

        if ($overrides === []) {
            return null;
        }

        foreach ([$tenant->id, $tenant->slug] as $handle) {
            if (array_key_exists($handle, $overrides)) {
                $tier = TenantTier::tryFromLoose($overrides[$handle]);

                if ($tier !== null) {
                    return $tier;
                }
            }
        }

        return null;
    }

    /**
     * Keep a weight inside `[1, lane_weight_max]`.
     *
     * The floor is the point: a zero or negative weight would remove a tenant from
     * the deficit-round-robin rotation entirely, which is starvation — the exact thing
     * Req 1.7 forbids. The ceiling bounds how much of a window one tenant can claim.
     */
    private function clampWeight(int $weight): int
    {
        $max = max(TenantTier::FALLBACK_LANE_WEIGHT, $this->configInt('lane_weight_max', 1000));

        return max(TenantTier::FALLBACK_LANE_WEIGHT, min($max, $weight));
    }

    /**
     * @return array<string, string>
     */
    private function shardMap(): array
    {
        $map = [];

        foreach ($this->configArray('shard.connections') as $shardKey => $connection) {
            if (is_string($connection) && trim($connection) !== '') {
                $map[(string) $shardKey] = trim($connection);
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function shardRing(): array
    {
        $ring = [];

        foreach ($this->configArray('shard.ring') as $connection) {
            if (is_string($connection) && trim($connection) !== '') {
                $ring[] = trim($connection);
            }
        }

        return array_values(array_unique($ring));
    }

    /**
     * Which ring slot a tenant hashes to — deterministic, and stable for the life of a
     * given ring size, so a tenant is never routed to two different shards.
     */
    private function ringSlot(string $tenantId, int $slots): int
    {
        return (int) (sprintf('%u', crc32($tenantId)) % $slots);
    }

    /**
     * A connection name is only ever returned if the deployment actually defines it.
     */
    private function assertConfigured(Tenant $tenant, string $connection): string
    {
        $connections = config('database.connections');

        if (! is_array($connections) || ! array_key_exists($connection, $connections)) {
            throw UnknownShardConnectionException::forTenant($tenant->id, $connection);
        }

        return $connection;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config('wa.tenancy.tiers.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function configArray(string $key): array
    {
        $value = config('wa.tenancy.tiers.'.$key);

        return is_array($value) ? $value : [];
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
