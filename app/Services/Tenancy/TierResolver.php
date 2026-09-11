<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantTier;
use App\Models\Tenant;

/**
 * The one place that answers "how isolated is this tenant, and where does its work
 * and its data go?" (Req 1.6 / A1).
 *
 * This interface is the seam that makes a tenant's tier a **configuration flip
 * rather than a code change**. Callers never read `tenant_tiers` and never branch on
 * a tier: the dispatch scheduler asks for a `laneWeight()`, a repository asks for a
 * `connection()`, an admin screen asks for a `tierOf()`, and escalating a tenant from
 * `SHARED` to `DEDICATED_WORKER` to `DEDICATED_DB` changes only rows and config —
 * never a call site.
 *
 * Answers are cached, so implementations expose explicit invalidation
 * (`forget()` / `flush()`); `App\Models\TenantTierAssignment` calls `forget()` on
 * every write so a flip takes effect immediately.
 */
interface TierResolver
{
    /*
    |--------------------------------------------------------------------------
    | Design contract (design.md § Components and Interfaces → Tenancy layer)
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant's tier: `SHARED`, `DEDICATED_WORKER`, or `DEDICATED_DB`.
     *
     * Always answers — a tenant with no `tenant_tiers` row is on the configured
     * default tier.
     */
    public function tierOf(Tenant $tenant): TenantTier;

    /**
     * The tenant's weighted-fair dispatch share (Req 1.7 / A1; Req 30.2, 30.6 / NFR1).
     *
     * Always ≥ 1 — a weight can be raised or lowered but never zeroed, so the
     * deficit-round-robin scheduler can never starve a tenant outright.
     */
    public function laneWeight(Tenant $tenant): int;

    /**
     * The database connection the tenant's rows live on, or `null` for the shared
     * (default) connection.
     *
     * `null` is the answer for every tier except `DEDICATED_DB`, and also for a
     * `DEDICATED_DB` tenant on a deployment where no shard is configured yet — a
     * single-database install therefore behaves exactly as it does today.
     */
    public function connection(Tenant $tenant): ?string;

    /*
    |--------------------------------------------------------------------------
    | Sharding & cache seams
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant's explicit shard pin, or `null` when it has none (it is then routed
     * by consistent hash, or not sharded at all).
     *
     * The seam the horizontal-write-scaling work (Req 30.5 / NFR1) builds on.
     */
    public function shardKey(Tenant $tenant): ?string;

    /**
     * Drop the cached answers for one tenant.
     */
    public function forget(Tenant|string $tenant): void;

    /**
     * Drop the cached answers for every tenant.
     */
    public function flush(): void;
}
