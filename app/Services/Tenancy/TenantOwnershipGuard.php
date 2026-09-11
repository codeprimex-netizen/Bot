<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The **second** line of defence for row-level isolation (Req 1.3 / A1,
 * Correctness Property 1).
 *
 * `TenantScope` (task 0.3) is the first: it constrains every query built from a
 * tenant-owned model. This guard covers the seams that a query scope structurally
 * cannot, and which task 0.3 documented and deliberately left open:
 *
 * | Seam                                            | Checked by                          |
 * |-------------------------------------------------|-------------------------------------|
 * | `save()` / `update()` / `delete()` on an instance (Eloquent builds those through `newModelQuery()`, without scopes) | `assertOwned()` from the `saving`/`deleting` hooks |
 * | a relation load reached through a foreign parent, including relations that drop the scope on purpose | `assertRelationAccessible()` at the top of the relation method |
 * | an explicit `tenant_id` on create — legitimate for provisioning, forgeable from a tenant context | `assertAttributable()` from the `creating` hook |
 * | `find()` / `findOrFail()` / route-model binding naming a foreign id | `assertKeyNotOwnedByAnotherTenant()`, which turns a scope-induced miss into a typed 403 instead of a bare 404 |
 * | an instance hydrated outside the scope (`fresh()`, `refresh()`, raw `newQueryWithoutScopes()`) | `assertRetrievable()` from the `retrieved` hook |
 *
 * It never *replaces* the scope, and it is not a way to relax it: a query with no
 * tenant bound still fails closed with `MissingTenantContextException` before this
 * guard is ever consulted.
 *
 * ## The one rule
 *
 * The guard only ever answers one question — *does this row belong to the tenant
 * this code is acting as?* — and it can only answer it when a tenant **is** acting.
 * So it stands down, by design, in exactly two states:
 *
 * - **platform mode** (`actingAsPlatform()`): the audited platform bypass of
 *   Req 1.5, where there is no acting tenant to compare against;
 * - **no tenant bound**: provisioning, tenant resolution, webhook intake, console
 *   commands and schedulers. Nothing is being impersonated, so nothing is being
 *   crossed — and the paths that must *not* run unscoped there are already
 *   fail-closed by `TenantScope`.
 *
 * The two sanctioned bypasses of task 0.3 — `withoutTenantScope()` and
 * `forTenant()` — declare themselves on the query (`withSanctionedTenantBypass()`)
 * and suspend the *retrieval* check for their own hydration only; see
 * `withoutRetrievalGuard()` and `TenantScopedBuilder`. Nothing else does: a relation
 * that drops the scope, a `fresh()`, or a hand-rolled `withoutGlobalScope()` all stay
 * guarded, which is what closes the eager-loading path through a foreign parent.
 * Writes have no suspension at all — holding a foreign row is a sanctioned bypass,
 * persisting one is not.
 *
 * ### Known sharp edge
 *
 * `cursor()` / `lazy()` hydrate outside the query call, so a deliberately unscoped
 * streaming read taken *while a tenant is bound* trips `assertRetrievable()`. That
 * is loud and greppable rather than silent: wrap it in `withoutRetrievalGuard()`,
 * or — better — run platform-wide streaming reads in `asPlatform()`.
 */
final class TenantOwnershipGuard
{
    /**
     * Nesting depth of `withoutRetrievalGuard()` frames.
     */
    private int $retrievalSuspensions = 0;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * A row was hydrated: refuse to hand it to code acting as a different tenant.
     */
    public function assertRetrievable(Model $model): void
    {
        if ($this->retrievalSuspensions > 0) {
            return;
        }

        $acting = $this->actingTenantId();

        if ($acting === null) {
            return;
        }

        $owner = $this->ownerTenantId($model);

        if ($owner === null || $owner === $acting) {
            return;
        }

        throw CrossTenantAccessException::forRetrieval($model::class, $owner, $acting);
    }

    /**
     * A row is about to be written: refuse when either its current or its stored
     * `tenant_id` names a tenant other than the acting one.
     *
     * Both values matter. The stored one catches a write through a foreign instance;
     * the current one catches an attempt to *move* an owned row to another tenant.
     *
     * @param  string  $operation  verb for the message ('save', 'update', 'delete', ...)
     */
    public function assertOwned(Model $model, string $operation): void
    {
        $acting = $this->actingTenantId();

        if ($acting === null) {
            return;
        }

        foreach ([$this->ownerTenantId($model), $this->storedOwnerTenantId($model)] as $owner) {
            if ($owner !== null && $owner !== $acting) {
                throw CrossTenantAccessException::forWrite($model::class, $operation, $owner, $acting);
            }
        }
    }

    /**
     * A create named its `tenant_id` explicitly: allow it only when it names the
     * acting tenant.
     *
     * Naming a tenant is a feature — provisioning, imports, and platform writes all
     * need it — but from inside a tenant context it is a forgery attempt.
     */
    public function assertAttributable(Model $model, mixed $tenantId): void
    {
        $requested = $this->stringifyId($tenantId);
        $acting = $this->actingTenantId();

        if ($requested === null || $acting === null || $requested === $acting) {
            return;
        }

        throw CrossTenantAccessException::forAttribution($model::class, $requested, $acting);
    }

    /**
     * A relation is being loaded from `$parent`: refuse when the acting tenant does
     * not own the parent.
     *
     * This is the check that makes a scope-dropping relation safe. `Tenant::usage()`
     * and `Tenant::apiTokens()` remove `TenantScope` on purpose (they already name
     * their tenant, so the scope would only make them fail closed on platform and
     * console paths) — which means the constraint that matters is *which parent the
     * caller was allowed to hold*, and that is this method.
     */
    public function assertRelationAccessible(Model $parent, string $relation): void
    {
        $acting = $this->actingTenantId();

        if ($acting === null) {
            return;
        }

        $owner = $parent instanceof Tenant
            ? $this->stringifyId($parent->getKey())
            : $this->ownerTenantId($parent);

        if ($owner === null || $owner === $acting) {
            return;
        }

        throw CrossTenantAccessException::forRelation($parent::class, $relation, $owner, $acting);
    }

    /**
     * A lookup by id (or by a route-binding field) found nothing **under the acting
     * tenant**: if the row exists at all, the caller named somebody else's record and
     * gets a typed 403 instead of a bare 404 (Req 1.3 / A1).
     *
     * A row that exists nowhere stays an ordinary miss — `find()` returns `null`,
     * `findOrFail()` throws `ModelNotFoundException` — because "no such record" is
     * not a cross-tenant access attempt.
     *
     * Costs one narrow, unscoped `limit 1` probe, and only on a miss.
     */
    public function assertKeyNotOwnedByAnotherTenant(Model $model, string $field, mixed $value): void
    {
        $acting = $this->actingTenantId();
        $key = $this->stringifyId($value);

        if ($acting === null || $key === null) {
            return;
        }

        // Deliberately answered on the query builder: the probe must not hydrate a
        // model (that would fire `retrieved` and recurse into this very guard).
        $owner = $this->stringifyId(
            $model->newQuery()
                ->withoutGlobalScope(TenantScope::class)
                ->where($model->qualifyColumn($field), '=', $key)
                ->toBase()
                ->value($model->qualifyColumn(TenantScope::COLUMN))
        );

        if ($owner === null || $owner === $acting) {
            return;
        }

        throw CrossTenantAccessException::forKey($model::class, $field, $key, $acting);
    }

    /**
     * Run a read whose tenant bypass is already stated at the call site, with the
     * retrieval check suspended for its hydration only.
     *
     * The seams that use it — `withoutTenantScope()`, `forTenant()`, and relations
     * that call `withoutGlobalScope(TenantScope::class)` — are the sanctioned,
     * greppable bypasses of task 0.3. Nesting is counted, so an inner frame cannot
     * re-arm an outer one, and the counter is restored even if the callback throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutRetrievalGuard(callable $callback): mixed
    {
        $this->retrievalSuspensions++;

        try {
            return $callback();
        } finally {
            $this->retrievalSuspensions--;
        }
    }

    /**
     * The tenant this code is acting as, or `null` when there is nothing to compare
     * against (platform mode, or no tenant bound).
     */
    private function actingTenantId(): ?string
    {
        // Req 1.5 / A1: the audited platform bypass is a bypass here too — it is
        // announced on the event bus, so it stays visible to the audit trail.
        if ($this->context->actingAsPlatform()) {
            return null;
        }

        return $this->context->currentId();
    }

    /**
     * The tenant named by the model's current attributes.
     */
    private function ownerTenantId(Model $model): ?string
    {
        return $this->stringifyId($model->getAttribute(TenantScope::COLUMN));
    }

    /**
     * The tenant named by the row as it is stored, before any in-memory change.
     */
    private function storedOwnerTenantId(Model $model): ?string
    {
        if (! $model->exists) {
            return null;
        }

        return $this->stringifyId($model->getRawOriginal(TenantScope::COLUMN));
    }

    /**
     * Identifiers are ULIDs or integers; anything else is not an id we can compare,
     * and pretending otherwise would make the comparison lie.
     */
    private function stringifyId(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) ? (string) $value : null;
    }
}
