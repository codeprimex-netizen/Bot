<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One tenant-owned model, described in the only terms the isolation property needs: how
 * to write a row of it for a named tenant, how to read the table across tenants through
 * the sanctioned bypass, how many rows one tenant may have, whether a row can ever be
 * written again, and which columns an aggregate and a write can be aimed at.
 *
 * The *list* of subjects is never written by hand — `TenantIsolationProbe::subjects()`
 * derives it from the live schema. What lives here is the part a schema walk cannot
 * infer: `abuse_events` needs a `GuardAction`, `encryption_keys` needs a real sealed DEK
 * and at most one `ACTIVE` version per lineage, `quota_holds` needs a unique dedup key,
 * `tenant_tiers` allows exactly one row per tenant. A generic
 * `insert(['tenant_id' => ...])` would violate every one of those constraints, and a
 * generator that quietly skipped the models it could not build would be exactly the
 * vacuous property test this file exists to avoid.
 *
 * The reader closure exists for a narrower reason: `withoutTenantScope()` and
 * `forTenant()` are declared on the `BelongsToTenant` trait, so they are only visible to
 * static analysis where the concrete class is known — which is here, in the writer
 * table, and not in a loop over `class-string<Model>`.
 */
final readonly class TenantOwnedSubject
{
    /**
     * @param  class-string<Model>  $model
     * @param  int  $maxRowsPerTenant  ceiling imposed by the table's unique constraints
     * @param  bool  $appendOnly  rows may be inserted and read, never updated or deleted
     * @param  string|null  $sumColumn  a numeric column, so `sum()` is part of the property
     * @param  string  $mutableColumn  a column a cross-tenant write can aim at (any scalar goes)
     * @param  Closure(Tenant, int, TenantIsolationProbe): Model  $writer  writes row `$index` for `$tenant`
     * @param  Closure(?string): list<Model>  $reader  the table through `withoutTenantScope()`, optionally
     *                                                 one tenant's rows through `forTenant()`
     */
    public function __construct(
        public string $model,
        public int $maxRowsPerTenant,
        public bool $appendOnly,
        public ?string $sumColumn,
        public string $mutableColumn,
        private Closure $writer,
        private Closure $reader,
    ) {}

    public function table(): string
    {
        return (new $this->model)->getTable();
    }

    public function keyName(): string
    {
        return (new $this->model)->getKeyName();
    }

    /**
     * Short name for failure messages.
     */
    public function label(): string
    {
        return class_basename($this->model);
    }

    /**
     * Write one row, through the production path that owns that table.
     */
    public function write(Tenant $tenant, int $index, TenantIsolationProbe $probe): Model
    {
        return ($this->writer)($tenant, $index, $probe);
    }

    /**
     * The rows this table holds, read through the sanctioned cross-tenant hatch — the
     * ground truth the property's anti-vacuity control is measured against.
     *
     * @return list<Model>
     */
    public function rowsOf(?Tenant $tenant = null): array
    {
        return ($this->reader)($tenant?->id);
    }

    /**
     * One stored row by key, or null — read across tenants, so it reports what the
     * database holds rather than what the acting tenant is allowed to see.
     */
    public function storedRow(Tenant $tenant, string $key): ?Model
    {
        foreach ($this->rowsOf($tenant) as $row) {
            if ((string) $row->getKey() === $key) {
                return $row;
            }
        }

        return null;
    }
}
