<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The global scope that makes cross-tenant reads structurally impossible
 * (Req 1.2 / A1, Correctness Property 1).
 *
 * Applied to every model that uses `BelongsToTenant`, it resolves the acting
 * tenant from `TenantContext` at **query time** (not at boot time), so a job that
 * binds its tenant inside `handle()` is scoped correctly, and one query in a
 * request cannot inherit a stale tenant from another.
 *
 * Three outcomes, and only three:
 *
 * | Context                          | Behaviour                                  |
 * |----------------------------------|--------------------------------------------|
 * | tenant bound                     | `where tenant_id = <that tenant>`          |
 * | platform mode open (audited)     | no constraint at all (Req 1.5 / A1)        |
 * | neither                          | `MissingTenantContextException` — closed   |
 *
 * The third row is the important one: an unresolved context is a bug, and a bug
 * must not be allowed to become a cross-tenant leak. See the exception's docblock
 * for why it throws instead of returning an empty set.
 *
 * The scope constrains **reads and mass writes** (`update()`/`delete()` on a
 * builder). Saving or deleting an already-loaded instance goes through
 * `Model::newModelQuery()`, which Eloquent deliberately builds without global
 * scopes; guarding *which* instance a caller was allowed to load in the first
 * place is the defense-in-depth ownership check of task 0.4.
 */
final class TenantScope implements Scope
{
    /**
     * The single definition of the tenancy column name. `TenantSchema` (the
     * migration helper) and `BelongsToTenant` both read it from here, so the
     * schema and the scope can never disagree.
     */
    public const string COLUMN = 'tenant_id';

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        // Req 1.5 / A1: the audited platform-admin bypass is the only bypass that
        // needs no code change at the call site — and it is announced on the event
        // bus by TenantContext, so the audit trail sees it.
        if ($context->actingAsPlatform()) {
            return;
        }

        $tenantId = $context->currentId();

        if ($tenantId === null) {
            throw MissingTenantContextException::forQuery($model::class);
        }

        $builder->where($model->qualifyColumn(self::COLUMN), '=', $tenantId);
    }
}
