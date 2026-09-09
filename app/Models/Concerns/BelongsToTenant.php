<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level tenancy for one model: **every** read is constrained to the acting
 * tenant and **every** create is stamped with it (Req 1.1, 1.2 / A1;
 * Correctness Property 1).
 *
 * ```php
 * class Campaign extends Model
 * {
 *     use BelongsToTenant;   // + TenantSchema::tenantId($table) in its migration
 * }
 * ```
 *
 * **The rule for every phase after this one:** a table that holds tenant data gets
 * its column and index from `App\Support\Database\TenantSchema::tenantId()`, and its
 * model uses this trait. `TenantOwnedModelsGuardTest` enforces both halves
 * automatically — add a tenant-owned model without the trait and the suite fails.
 *
 * ## What it adds
 *
 * - the `TenantScope` global scope (see that class for the fail-closed table);
 * - a `creating` hook that fills `tenant_id` from the context when the caller did
 *   not supply one, and refuses to write an unattributed row if it cannot;
 * - the `tenant()` relation;
 * - two explicit, greppable escape hatches — `withoutTenantScope()` and
 *   `forTenant()`. There is no implicit one.
 *
 * ## Deliberate non-goals
 *
 * Saving or deleting an *already-loaded* instance is not re-checked here (Eloquent
 * builds those queries without global scopes), and neither is loading a foreign
 * tenant's row by id through an unscoped parent. That ownership check is
 * defense-in-depth and belongs to `CrossTenantAccessException` (task 0.4) — this
 * trait deliberately leaves that seam rather than half-implementing it.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    /**
     * Booted once per model class by Eloquent's trait-boot convention.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute(TenantScope::COLUMN) !== null) {
                // An explicit tenant_id wins: provisioning, imports, and platform
                // writes all need to name the tenant they are writing for.
                return;
            }

            $tenantId = app(TenantContext::class)->currentId();

            if ($tenantId === null) {
                throw MissingTenantContextException::forCreate($model::class);
            }

            $model->setAttribute(TenantScope::COLUMN, $tenantId);
        });
    }

    /**
     * The tenant that owns this row.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, TenantScope::COLUMN);
    }

    /**
     * A query with the tenant scope removed — the **only** sanctioned bypass at a
     * call site, and named so `grep withoutTenantScope` lists every one of them.
     *
     * Legitimate uses are system queries that run *before* a tenant can exist:
     * tenant resolution itself (`DatabaseTenantTokenRepository`), webhook intake
     * that must map an opaque id to its tenant, and platform maintenance commands.
     * Anything that already knows its tenant must use `forTenant()` or
     * `TenantContext::runFor()` instead.
     *
     * @return Builder<static>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * A query scoped to one named tenant regardless of the acting context.
     *
     * Safer than `withoutTenantScope()` where the tenant is known — the constraint
     * is still there, it is just stated by the caller (platform-admin drill-downs,
     * cross-tenant schedulers).
     *
     * @return Builder<static>
     */
    public static function forTenant(Tenant|string $tenant): Builder
    {
        $query = static::withoutTenantScope();

        return $query->where(
            $query->getModel()->qualifyColumn(TenantScope::COLUMN),
            '=',
            $tenant instanceof Tenant ? $tenant->id : $tenant,
        );
    }
}
