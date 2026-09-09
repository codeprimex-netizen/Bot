<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;

/**
 * The one way a migration declares that a table holds tenant data
 * (Req 1.1, 1.2 / A1).
 *
 * ```php
 * Schema::create('campaigns', function (Blueprint $table): void {
 *     $table->ulid('id')->primary();
 *     TenantSchema::tenantId($table, 'status');          // column + idx(tenant_id, status)
 *     $table->string('name');
 *     $table->timestamps();
 * });
 * ```
 *
 * **Developer rule — every tenant-owned table, every phase.** A new table that
 * holds tenant data MUST get its tenancy column from this helper, and its model
 * MUST use `App\Models\Concerns\BelongsToTenant`. Doing it by hand is how a table
 * ends up with the column but no leading index (every scoped query then scans), or
 * with the index but no `BelongsToTenant` (every query then leaks). Both halves are
 * enforced automatically by `TenantOwnedModelsGuardTest`, which walks the live
 * schema — so a table added in phase 12 is held to the same rule as one added here,
 * without anyone having to remember it.
 *
 * The column is a ULID foreign key onto `tenants` with `ON DELETE CASCADE`
 * (offboarding a tenant removes its rows), and the index always **leads with
 * `tenant_id`** because every query the platform issues against these tables is
 * already constrained by it via `TenantScope`.
 */
final class TenantSchema
{
    /**
     * MySQL's hard limit on an index identifier.
     */
    private const int MAX_INDEX_NAME_LENGTH = 64;

    /**
     * Add the `tenant_id` column plus its leading composite index in one call.
     *
     * @param  list<string>|string  $then  further columns of the index, in order, after `tenant_id`
     * @param  string|null  $indexName  explicit index name; generated (and length-safe) when null
     * @param  bool  $unique  make the index a unique constraint — for "one row per tenant per X" tables
     * @param  bool  $constrained  add the foreign key onto `tenants`; false only for tables on another
     *                             connection/shard (tenant tier `DEDICATED_DB`, Req 1.6), where a
     *                             cross-database FK is impossible
     * @return ForeignIdColumnDefinition the column, so callers can add storage hints; the foreign key
     *                                   and the index are already registered on the blueprint
     */
    public static function tenantId(
        Blueprint $table,
        array|string $then = [],
        ?string $indexName = null,
        bool $unique = false,
        bool $constrained = true,
    ): ForeignIdColumnDefinition {
        $column = $table->foreignUlid(TenantScope::COLUMN);

        if ($constrained) {
            $column->constrained('tenants')->cascadeOnDelete();
        }

        $columns = self::indexColumns($then);

        // The index is always declared explicitly rather than left to the foreign
        // key: MySQL only auto-creates one when no suitable index exists (so this
        // duplicates nothing), while SQLite — the test connection — creates none at
        // all, and an index that only exists in production is an index nobody tests.
        if ($unique) {
            $table->unique($columns, $indexName ?? self::indexName($table->getTable(), $columns, 'unique'));
        } else {
            $table->index($columns, $indexName ?? self::indexName($table->getTable(), $columns, 'index'));
        }

        return $column;
    }

    /**
     * The index name this helper would generate — exposed so a migration that needs
     * to drop an index later can name it without hard-coding the convention.
     *
     * @param  list<string>  $columns
     */
    public static function indexName(string $table, array $columns, string $type = 'index'): string
    {
        $name = self::sanitize(strtolower($table.'_'.implode('_', $columns).'_'.$type));

        if (strlen($name) <= self::MAX_INDEX_NAME_LENGTH) {
            return $name;
        }

        // Long table/column names are truncated around a digest of the full name, so
        // the result stays unique, deterministic, and inside MySQL's 64-char limit.
        return substr(self::sanitize(strtolower($table)), 0, 30).'_'.substr(sha1($name), 0, 12).'_'.$type;
    }

    /**
     * The index columns: `tenant_id` first, then the caller's, de-duplicated.
     *
     * @param  list<string>|string  $then
     * @return non-empty-list<string>
     */
    private static function indexColumns(array|string $then): array
    {
        $trailing = array_values(array_filter(
            is_string($then) ? [$then] : $then,
            static fn (string $column): bool => $column !== '' && $column !== TenantScope::COLUMN,
        ));

        return [TenantScope::COLUMN, ...array_values(array_unique($trailing))];
    }

    private static function sanitize(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_]+/', '_', $name);
    }
}
