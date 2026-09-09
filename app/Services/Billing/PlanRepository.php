<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Cache\VersionedCache;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * The read path for plans: cached, version-invalidated lookups (Req 25.1 / D2,
 * Req 30.4 / NFR1).
 *
 * `PlanGate` (task 2.2) and `QuotaGuard` (task 2.3) resolve a plan on **every**
 * inbound message and every metered action, so those lookups must not be a query
 * each. They go through here:
 *
 * ```php
 * $plan = app(PlanRepository::class)->forTenant($tenant);   // cached
 * if ($plan === null || ! $plan->allows('ai')) { ... }      // no plan = no features
 * ```
 *
 * ## Invalidation
 *
 * Entries live in a `VersionedCache` namespace (`plans:v{n}:...`) and `PlanObserver`
 * bumps `{n}` on every plan write, so a reader composes its key from the post-edit
 * version and cannot name a pre-edit entry — a stale plan is unreachable rather than
 * merely short-lived. See `VersionedCache` for the pattern; later layers (tenant
 * settings, published flows, prompt templates) reuse it the same way.
 *
 * Rows are cached as **attribute arrays**, not model instances: a cached entry then
 * survives a deploy that adds a method or a cast, and rehydration goes through
 * `newFromBuilder()` so the model behaves exactly as if it had been queried.
 * A miss caches nothing (`null` is never stored as a hit), so a lookup for an
 * unknown plan stays a query rather than pinning "does not exist" for the TTL.
 */
final class PlanRepository
{
    /**
     * Cache namespace — the unit the version bump invalidates.
     */
    public const string CACHE_NAMESPACE = 'plans';

    private readonly VersionedCache $cache;

    public function __construct(?VersionedCache $cache = null)
    {
        $this->cache = $cache ?? VersionedCache::for(
            self::CACHE_NAMESPACE,
            (int) config('wa.cache.ttl.plans', 300),
        );
    }

    /**
     * The plan with $id, or null when it does not exist.
     */
    public function find(string $id): ?Plan
    {
        if ($id === '') {
            return null;
        }

        return $this->rememberPlan('id:'.$id, static fn (): ?Plan => Plan::query()->whereKey($id)->first());
    }

    /**
     * The plan with $slug — how seeders, config and support tooling name a plan.
     */
    public function findBySlug(string $slug): ?Plan
    {
        if ($slug === '') {
            return null;
        }

        return $this->rememberPlan('slug:'.$slug, static fn (): ?Plan => Plan::query()->where('slug', $slug)->first());
    }

    /**
     * The plan a tenant is on, or null when it has none (a fresh trial, or a plan
     * that was retired under it — `tenants.plan_id` is `nullOnDelete`).
     *
     * Callers must treat null as *no features and no allowance*, never as a reason
     * to skip the gate.
     */
    public function forTenant(Tenant $tenant): ?Plan
    {
        $planId = $tenant->getAttribute('plan_id');

        return is_string($planId) ? $this->find($planId) : null;
    }

    /**
     * The plan new tenants are provisioned onto (`wa.tenancy.default_plan_slug`) —
     * the seam `TenantLifecycle::provision` (task 1.2) resolves its seed plan through.
     */
    public function defaultPlan(): ?Plan
    {
        return $this->findBySlug((string) config('wa.tenancy.default_plan_slug', 'starter'));
    }

    /**
     * The sellable catalogue in display order — the pricing and upgrade screens.
     *
     * @return Collection<int, Plan>
     */
    public function active(): Collection
    {
        $rows = $this->cache->remember('active', static function (): array {
            return Plan::query()->active()->ordered()->get()
                ->map(static fn (Plan $plan): array => $plan->getAttributes())
                ->all();
        });

        /** @var Collection<int, Plan> $plans */
        $plans = new Collection;

        if (! is_array($rows)) {
            return $plans;
        }

        foreach ($rows as $attributes) {
            if (is_array($attributes)) {
                $plans->push($this->hydrate($attributes));
            }
        }

        return $plans;
    }

    /**
     * Invalidate every cached plan entry at once, returning the new version.
     *
     * `PlanObserver` calls this after each write; call it directly after any write
     * that bypasses Eloquent events (`Plan::query()->update()`, raw SQL, imports).
     */
    public function flush(): int
    {
        return $this->cache->bump();
    }

    /**
     * The current cache version — useful for diagnostics and for tests asserting
     * that a write invalidated the namespace.
     */
    public function version(): int
    {
        return $this->cache->version();
    }

    /**
     * @param  Closure(): ?Plan  $query
     */
    private function rememberPlan(string $key, Closure $query): ?Plan
    {
        $attributes = $this->cache->remember($key, static function () use ($query): ?array {
            $plan = $query();

            return $plan?->getAttributes();
        });

        return is_array($attributes) ? $this->hydrate($attributes) : null;
    }

    /**
     * Rebuild a model from cached raw attributes, marked as existing so saves are
     * updates and casts behave exactly as on a queried row.
     *
     * @param  array<array-key, mixed>  $attributes
     */
    private function hydrate(array $attributes): Plan
    {
        $plan = new Plan;

        // The connection name is stamped explicitly: Eloquent's own hydration sets it
        // from the query, and `Model::is()` compares it — without this a cached plan
        // would not be considered identical to the same row queried directly.
        return $plan->newFromBuilder($attributes, $plan->getConnection()->getName());
    }
}
