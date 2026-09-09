<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantLifecycle;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared entry points for the tenant-lifecycle tests.
 *
 * A class rather than Pest helper functions for the same reason as
 * `Tests\Fixtures\Audit`: Pest loads every test file into one process, so a global
 * `lifecycle()` helper would be a name the whole suite has to keep free forever.
 */
final class Lifecycle
{
    public static function service(): TenantLifecycle
    {
        return app(TenantLifecycle::class);
    }

    public static function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Every audit entry on one tenant's chain, oldest first.
     *
     * `withoutTenantScope()` because the assertions run from whatever context the test
     * left behind — platform mode, another tenant, or nothing bound — and the chain is
     * named explicitly here rather than inferred.
     *
     * @return Collection<int, AuditLog>
     */
    public static function trail(Tenant $tenant): Collection
    {
        return AuditLog::withoutTenantScope()
            ->where('chain_key', $tenant->id)
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Lifecycle entries that leaked onto the platform chain — always expected to be
     * empty: a tenant's history belongs on the tenant's chain.
     *
     * @return Collection<int, AuditLog>
     */
    public static function platformTrail(): Collection
    {
        return AuditLog::withoutTenantScope()
            ->where('chain_key', AuditLog::PLATFORM_CHAIN)
            ->where('action', 'like', 'tenant.%')
            ->orderBy('sequence')
            ->get();
    }
}
