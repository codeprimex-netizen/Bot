<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enums\QuotaKind;
use App\Models\QuotaHold;
use App\Models\Tenant;
use App\Services\Tenancy\QuotaParkingLot;
use App\Services\Tenancy\QuotaVerdict;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared entry points for the quota-paused-work tests (Req 3.4 / A3, Req 20.3 / C3,
 * Req 31.1 / NFR2).
 *
 * A class rather than global Pest helpers, for the same reason as `Tests\Fixtures\Quota`.
 */
final class QuotaHolds
{
    public static function parkingLot(): QuotaParkingLot
    {
        return app(QuotaParkingLot::class);
    }

    /**
     * Register a resumer under $key and return the double the sweep will call.
     *
     * Bound as a container instance so the registry — which resolves by class name — hands
     * the sweep the *same* object the test holds.
     */
    public static function registerResumer(string $key, ?string $failWith = null): RecordingQuotaResumer
    {
        $resumer = new RecordingQuotaResumer($failWith);

        app()->instance(RecordingQuotaResumer::class, $resumer);
        config()->set('wa.tenancy.quota.holds.resumers', [$key => RecordingQuotaResumer::class]);

        return $resumer;
    }

    /**
     * The verdict a tenant gets for $units of $kind right now — the input `park()` takes.
     */
    public static function verdict(Tenant $tenant, QuotaKind $kind, int $units = 1): QuotaVerdict
    {
        return Quota::guard()->verdict($tenant, $kind, $units);
    }

    /**
     * Spend a tenant's whole allowance for $kind, so the next verdict defers.
     *
     * Written through `QuotaGuard::consume()` rather than by touching `tenant_usage`
     * directly: the tests then start from a state the production path really produces.
     */
    public static function exhaust(Tenant $tenant, QuotaKind $kind, int $units): void
    {
        Quota::guard()->consume($tenant, $kind, 'exhaust:'.$kind->value.':'.uniqid('', true), $units);
    }

    /**
     * One tenant's holds, newest first, read across tenants so a test can look at a tenant
     * it is not acting as.
     *
     * @return Collection<int, QuotaHold>
     */
    public static function forTenant(Tenant $tenant): Collection
    {
        return QuotaHold::forTenant($tenant)->orderByDesc('created_at')->get();
    }

    /**
     * The hold for one unit of work, or null when it was never parked.
     */
    public static function find(Tenant $tenant, string $dedupKey): ?QuotaHold
    {
        return QuotaHold::forTenant($tenant)->where('dedup_key', $dedupKey)->first();
    }

    /**
     * A hold refreshed from the database, bypassing the model's identity map.
     */
    public static function fresh(QuotaHold $hold): ?QuotaHold
    {
        return QuotaHold::withoutTenantScope()->whereKey($hold->getKey())->first();
    }

    /**
     * How many hold rows exist in total — the assertion that a refusal parked nothing.
     */
    public static function count(): int
    {
        return (int) DB::table('quota_holds')->count();
    }

    /**
     * How many notice claims a tenant has for $kind — the durable half of "told once".
     */
    public static function noticeCount(Tenant $tenant, QuotaKind $kind): int
    {
        return (int) DB::table('idempotency_keys')
            ->where('scope', 'like', 'quota-notice:'.$tenant->id.':'.$kind->value)
            ->count();
    }
}
