<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Builders\TenantScopedBuilder;
use App\Services\Tenancy\TenantOwnershipGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Defense in depth for one tenant-owned model: the ownership checks that
 * `TenantScope` structurally cannot make (Req 1.3 / A1, Correctness Property 1).
 *
 * Composed into `BelongsToTenant`, so every model that is tenant-scoped is also
 * ownership-guarded — the two halves cannot drift apart, and there is no model that
 * has one without the other.
 *
 * ## What it wires up
 *
 * | Hook / seam        | Guards                                                              |
 * |--------------------|---------------------------------------------------------------------|
 * | `retrieved`        | an instance hydrated outside the scope (`fresh()`, `refresh()`, raw unscoped queries) surfacing inside a different tenant's context |
 * | `saving`           | `save()` / `update()` on an existing instance owned by another tenant — including an attempt to *move* an owned row to another tenant |
 * | `deleting`         | `delete()` on an instance owned by another tenant                    |
 * | `newEloquentBuilder()` | `find()` / `findOrFail()` on a foreign id → typed 403 instead of a 404, and the sanctioned-bypass suspension (`TenantScopedBuilder`) |
 * | `resolveRouteBinding()` | route-model binding on a foreign id → typed 403 instead of a 404 |
 *
 * The insert path is *not* guarded here: `saving` fires before `creating`, when
 * `tenant_id` has not been stamped yet, so forging a `tenant_id` on create is
 * checked in `BelongsToTenant`'s `creating` hook where the attribute is final.
 *
 * `saveQuietly()` / `deleteQuietly()` fire no events and therefore skip these
 * checks. That is what "quietly" means, it is already how the framework's own
 * timestamps and observers behave, and the rows those calls touch are still reached
 * through a scoped or explicitly-bypassed query.
 *
 * Nested route bindings (`resolveChildRouteBinding()`) are not overridden: the child
 * is resolved through the parent's relation, which is guarded by
 * `TenantOwnershipGuard::assertRelationAccessible()` on the parent side.
 *
 * @phpstan-require-extends Model
 */
trait GuardsTenantOwnership
{
    /**
     * Booted once per model class by Eloquent's trait-boot convention (which walks
     * traits recursively, so being composed into `BelongsToTenant` is enough).
     */
    protected static function bootGuardsTenantOwnership(): void
    {
        static::retrieved(static function (Model $model): void {
            self::tenantOwnershipGuard()->assertRetrievable($model);
        });

        static::saving(static function (Model $model): void {
            if (! $model->exists) {
                // Insert: tenant_id may not be stamped yet. See BelongsToTenant::creating().
                return;
            }

            self::tenantOwnershipGuard()->assertOwned($model, 'save');
        });

        static::deleting(static function (Model $model): void {
            self::tenantOwnershipGuard()->assertOwned($model, 'delete');
        });
    }

    /**
     * Assert that this instance belongs to the acting tenant, and refuse with a
     * `CrossTenantAccessException` (403) if it does not.
     *
     * The hooks above call this for you on every write. Call it directly wherever a
     * model instance arrives from somewhere Eloquent cannot vouch for — an unscoped
     * lookup, a cache, a queued job payload, a service that took a `Model` argument.
     *
     * A no-op in platform mode and when no tenant is bound: there is no acting tenant
     * to be crossed. See `TenantOwnershipGuard` for why that is the only honest
     * answer there.
     */
    public function assertBelongsToCurrentTenant(string $operation = 'access'): void
    {
        self::tenantOwnershipGuard()->assertOwned($this, $operation);
    }

    /**
     * Use the tenancy-aware builder, so `find()` on another tenant's id is a typed
     * 403 rather than a silent miss.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return TenantScopedBuilder<static>
     */
    public function newEloquentBuilder($query): TenantScopedBuilder
    {
        /** @var TenantScopedBuilder<static> $builder */
        $builder = new TenantScopedBuilder($query);

        return $builder;
    }

    /**
     * Resolve a route parameter, denying another tenant's record with a typed 403.
     *
     * Route-model binding is the most common find-by-id path in the panels and the
     * API, and with `TenantScope` applied a foreign id would simply miss and surface
     * as a 404. Req 1.3 wants it named for what it is.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $model = parent::resolveRouteBinding($value, $field);

        if ($model === null) {
            self::tenantOwnershipGuard()->assertKeyNotOwnedByAnotherTenant(
                $this,
                $field ?? $this->getRouteKeyName(),
                $value,
            );
        }

        return $model;
    }

    private static function tenantOwnershipGuard(): TenantOwnershipGuard
    {
        return app(TenantOwnershipGuard::class);
    }
}
