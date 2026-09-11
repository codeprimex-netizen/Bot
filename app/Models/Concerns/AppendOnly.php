<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\Audit\AppendOnlyViolationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Write-once semantics for one model: rows may be inserted and read, never
 * changed or removed (Req 24.2 / D1; Req 34.1 / NFR5).
 *
 * ```php
 * class AuditLog extends Model
 * {
 *     use AppendOnly;   // + AppendOnlyTable::guard('audit_logs') in its migration
 * }
 * ```
 *
 * This is the innermost of three layers, and the only one that produces a *useful
 * message*:
 *
 * 1. **this trait** — `updating`/`deleting` events, so `save()` and `delete()` on a
 *    hydrated instance fail with a sentence telling the developer to append a
 *    correcting entry instead;
 * 2. **`AppendOnlyBuilder`** — the mass `update()`/`delete()`/`upsert()` paths, which
 *    fire no model events at all;
 * 3. **`AppendOnlyTable`** — `BEFORE UPDATE`/`BEFORE DELETE` triggers plus the
 *    production `REVOKE UPDATE, DELETE` grants, which hold for raw SQL, a SQL
 *    console, and `saveQuietly()`.
 *
 * Layer 3 is the invariant; layers 1 and 2 exist so a violation is caught early and
 * explained. `TenantOwnedModelsGuardTest`'s sibling — `AuditLogAppendOnlyTest` —
 * exercises all three on the same connection the rest of the suite uses.
 *
 * @phpstan-require-extends Model
 */
trait AppendOnly
{
    /**
     * Booted once per model class by Eloquent's trait-boot convention.
     */
    protected static function bootAppendOnly(): void
    {
        static::updating(static function (Model $model): void {
            throw AppendOnlyViolationException::forUpdate($model::class);
        });

        static::deleting(static function (Model $model): void {
            throw AppendOnlyViolationException::forDelete($model::class);
        });
    }

    /**
     * Append-only rows are never dirty-saved, so Eloquent's `updated_at` handling
     * has nothing to maintain. Only `created_at` is kept (declared by the migration).
     */
    public function usesTimestamps(): bool
    {
        return false;
    }
}
