<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Exceptions\Audit\AppendOnlyViolationException;
use Illuminate\Database\Eloquent\Model;

/**
 * The query builder for append-only models: it refuses the **mass** write paths
 * that model events never see (Req 24.2 / D1; Req 34.1 / NFR5).
 *
 * `App\Models\Concerns\AppendOnly` hooks `updating`/`deleting`, which covers
 * `$model->save()` and `$model->delete()`. It cannot cover
 * `AuditLog::query()->where(...)->update([...])`: a builder-level write never
 * hydrates a model, so no event fires. Those are precisely the calls that would
 * rewrite history in bulk, so they are refused here — by class, before a single row
 * is touched.
 *
 * It extends `TenantScopedBuilder` so an append-only model can also be
 * tenant-scoped (`audit_logs` is both), inheriting the typed-403 `find()` behaviour
 * and the sanctioned-bypass handling.
 *
 * The database triggers installed by `App\Support\Database\AppendOnlyTable` remain
 * the backstop for everything that never goes through Eloquent at all.
 *
 * @template TModel of Model
 *
 * @extends TenantScopedBuilder<TModel>
 */
class AppendOnlyBuilder extends TenantScopedBuilder
{
    /**
     * @param  array<string, mixed>  $values
     * @return never
     */
    public function update(array $values)
    {
        throw AppendOnlyViolationException::forUpdate($this->getModel()::class);
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     * @return never
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw AppendOnlyViolationException::forUpdate($this->getModel()::class);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return never
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        throw AppendOnlyViolationException::forUpdate($this->getModel()::class);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     * @return never
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw AppendOnlyViolationException::forUpdate($this->getModel()::class);
    }

    /**
     * @return never
     */
    public function delete()
    {
        throw AppendOnlyViolationException::forDelete($this->getModel()::class);
    }

    /**
     * @return never
     */
    public function forceDelete()
    {
        throw AppendOnlyViolationException::forDelete($this->getModel()::class);
    }
}
