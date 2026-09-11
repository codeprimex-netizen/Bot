<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Services\Tenancy\TenantOwnershipGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The query builder every `BelongsToTenant` model uses.
 *
 * It exists for two reasons, both of them ownership checks that need to know
 * something about the *query* and therefore cannot live in a model hook:
 *
 * 1. **`find()` on a foreign id is a denial, not a miss.** With `TenantScope`
 *    applied, naming another tenant's id simply matches no row — `find()` would
 *    return `null` and `findOrFail()` would raise a 404. Req 1.3 asks for a
 *    `CrossTenantAccessException` (403), so a miss is re-checked once, without the
 *    scope: if the row exists under another tenant the caller is denied, and if it
 *    exists nowhere the ordinary miss stands.
 *
 * 2. **A stated bypass is not a violation.** `withoutTenantScope()` and
 *    `forTenant()` are the two sanctioned, greppable bypasses of task 0.3; they mark
 *    their query with `withSanctionedTenantBypass()`, and only such a query hydrates
 *    inside `TenantOwnershipGuard::withoutRetrievalGuard()`.
 *
 *    The marker is deliberately *not* inferred from "the scope was removed". Removing
 *    the scope is also how a relation drops it (`Tenant::usage()`), how `fresh()` and
 *    `refresh()` are built, and how a future call site might do it by hand — and in
 *    each of those cases the rows that come back must still be checked against the
 *    acting tenant. So the two helpers opt in, and everything else stays guarded.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class TenantScopedBuilder extends Builder
{
    /**
     * Whether the caller declared its tenant bypass through one of the two sanctioned
     * helpers on `BelongsToTenant`.
     */
    private bool $sanctionedTenantBypass = false;

    /**
     * Mark this query as a declared, reviewed cross-tenant read.
     *
     * Called only by `BelongsToTenant::withoutTenantScope()` and `forTenant()` — the
     * two bypasses task 0.3 sanctioned. Grep for either of those to enumerate every
     * cross-tenant read in the codebase.
     */
    public function withSanctionedTenantBypass(): static
    {
        $this->sanctionedTenantBypass = true;

        return $this;
    }

    /**
     * Find a model by its primary key, denying a foreign one with a typed 403.
     *
     * @param  mixed  $id
     * @param  array<int, string>|string  $columns
     * @return ($id is (\Illuminate\Contracts\Support\Arrayable<array-key, mixed>|array<mixed>) ? \Illuminate\Database\Eloquent\Collection<int, TModel> : TModel|null)
     */
    public function find($id, $columns = ['*'])
    {
        $result = parent::find($id, $columns);

        if ($result === null) {
            $model = $this->getModel();

            $this->ownershipGuard()->assertKeyNotOwnedByAnotherTenant($model, $model->getKeyName(), $id);
        }

        return $result;
    }

    /**
     * Hydrate the query's results, suspending the retrieval check when this query
     * stated its tenant bypass explicitly.
     *
     * @param  array<int, string>|string  $columns
     * @return array<int, TModel>
     */
    public function getModels($columns = ['*'])
    {
        if (! $this->sanctionedTenantBypass) {
            return parent::getModels($columns);
        }

        /** @var array<int, TModel> $models */
        $models = $this->ownershipGuard()->withoutRetrievalGuard(fn (): array => parent::getModels($columns));

        return $models;
    }

    private function ownershipGuard(): TenantOwnershipGuard
    {
        return app(TenantOwnershipGuard::class);
    }
}
