<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Builders\TenantScopedBuilder;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantOwnershipGuard;
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
 *   not supply one, refuses to write an unattributed row if it cannot, and refuses
 *   an explicit `tenant_id` that names a *different* tenant than the acting one;
 * - the `tenant()` relation;
 * - the ownership checks of `GuardsTenantOwnership` — the second line of defence on
 *   the seams a query scope cannot reach (instance writes, unscoped hydration,
 *   find-by-id, route binding), all of them failing with
 *   `CrossTenantAccessException` (403, Req 1.3 / A1);
 * - two explicit, greppable escape hatches — `withoutTenantScope()` and
 *   `forTenant()`. There is no implicit one.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    use GuardsTenantOwnership;

    /**
     * Booted once per model class by Eloquent's trait-boot convention.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            $named = $model->getAttribute(TenantScope::COLUMN);

            if ($named !== null) {
                // An explicit tenant_id wins: provisioning, imports, and platform
                // writes all need to name the tenant they are writing for. What a
                // caller may *not* do is name a tenant other than the one it is
                // acting as — that is forgery, and it is denied (Req 1.3 / A1).
                app(TenantOwnershipGuard::class)->assertAttributable($model, $named);

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
     * Because the bypass is declared here, rows it returns are exempt from the
     * *retrieval* ownership check — the caller has said, in reviewable code, that this
     * read crosses tenants. Writing one of those rows is still denied
     * (`GuardsTenantOwnership`), and removing the scope by hand rather than through
     * this helper leaves the retrieval check armed.
     *
     * @return TenantScopedBuilder<static>
     */
    public static function withoutTenantScope(): TenantScopedBuilder
    {
        /** @var TenantScopedBuilder<static> $query */
        $query = static::query()->withoutGlobalScope(TenantScope::class);

        return $query->withSanctionedTenantBypass();
    }

    /**
     * A query scoped to one named tenant regardless of the acting context.
     *
     * Safer than `withoutTenantScope()` where the tenant is known — the constraint
     * is still there, it is just stated by the caller (platform-admin drill-downs,
     * cross-tenant schedulers).
     *
     * @return TenantScopedBuilder<static>
     */
    public static function forTenant(Tenant|string $tenant): TenantScopedBuilder
    {
        $query = static::withoutTenantScope();

        return $query->where(
            $query->getModel()->qualifyColumn(TenantScope::COLUMN),
            '=',
            $tenant instanceof Tenant ? $tenant->id : $tenant,
        );
    }
}
