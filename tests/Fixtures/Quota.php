<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enums\QuotaKind;
use App\Models\IdempotencyKey;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Services\Billing\PlanRepository;
use App\Services\Tenancy\QuotaGuard;
use App\Services\Tenancy\TenantContext;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;

/**
 * Shared entry points for the quota tests (Req 3.4, 3.5 / A3, Correctness Property 4).
 *
 * A class rather than global Pest helpers, for the same reason as `Tests\Fixtures\Dispatch`.
 */
final class Quota
{
    public static function guard(): QuotaGuard
    {
        return app(QuotaGuard::class);
    }

    /**
     * A guard whose cache store supports no locks, to exercise the documented
     * degradation: consumption must still be counted exactly once, on the strength of
     * `uniq(scope, key)` and the SQL increment alone.
     */
    public static function locklessGuard(): QuotaGuard
    {
        return new QuotaGuard(
            app(PlanRepository::class),
            app(TenantContext::class),
            new class implements CacheFactory
            {
                private ?CacheRepository $repository = null;

                public function store($name = null): CacheRepository
                {
                    return $this->repository ??= new CacheRepository(new LocklessStore);
                }
            },
        );
    }

    /**
     * A tenant on a plan with exactly the limits given.
     *
     * `$limits` is keyed by `QuotaKind::value`; a kind left out is left out of the plan
     * too, which is how "not priced" (0) is expressed. Pass `null` for unlimited.
     *
     * @param  array<string, int|null>  $limits
     * @param  array<string, bool>  $features
     */
    public static function tenant(array $limits, array $features = [], string $timezone = 'UTC'): Tenant
    {
        $plan = Plan::factory()->withoutLimits()->withLimits($limits)->withFeatures($features)->create();

        return Tenant::factory()->for($plan)->create(['timezone' => $timezone]);
    }

    /**
     * A tenant on no plan at all — no allowance for anything.
     */
    public static function tenantWithoutPlan(string $timezone = 'UTC'): Tenant
    {
        return Tenant::factory()->create(['timezone' => $timezone]);
    }

    /**
     * The counter row for one bucket, read across tenants so a test can look at a
     * tenant it is not acting as.
     */
    public static function bucket(Tenant $tenant, QuotaKind $kind, ?string $periodKey = null): ?TenantUsage
    {
        return TenantUsage::forTenant($tenant)
            ->where('kind', $kind)
            ->where('period_key', $periodKey ?? self::guard()->periodKeyFor($tenant, $kind))
            ->first();
    }

    /**
     * `tenant_usage.used` for the current bucket, or 0 when nothing has been counted.
     */
    public static function used(Tenant $tenant, QuotaKind $kind, ?string $periodKey = null): int
    {
        $bucket = self::bucket($tenant, $kind, $periodKey);

        return $bucket === null ? 0 : $bucket->used;
    }

    /**
     * `tenant_usage.limit` as stamped on the current bucket, or null when there is no row.
     */
    public static function stampedLimit(Tenant $tenant, QuotaKind $kind, ?string $periodKey = null): ?int
    {
        return self::bucket($tenant, $kind, $periodKey)?->limit;
    }

    /**
     * How many counter rows exist in total — the assertion that a *read* wrote nothing.
     */
    public static function bucketCount(): int
    {
        return (int) DB::table('tenant_usage')->count();
    }

    /**
     * How many consume-once ledger entries exist for one tenant + kind.
     */
    public static function ledgerCount(Tenant $tenant, QuotaKind $kind): int
    {
        return IdempotencyKey::query()
            ->inScope(self::guard()->idempotencyScope($tenant, $kind))
            ->count();
    }

    /**
     * A random set of confirmed sends: `key => units` — the Property 4 generator.
     *
     * @return array<string, int>
     */
    public static function sends(int $count, int $maxUnits = 4): array
    {
        $sends = [];

        foreach (range(1, max(1, $count)) as $index) {
            $sends['msg-'.$index] = random_int(1, max(1, $maxUnits));
        }

        return $sends;
    }

    /**
     * The keys of $sends in a random order, with random retries mixed in.
     *
     * A send pipeline does not fail cleanly: it confirms a send, dies before
     * acknowledging, is retried, and calls `consume()` again with the same message key.
     * Every send here is attempted at least once and up to three times, in any order.
     *
     * @param  array<string, int>  $sends
     * @return list<string>
     */
    public static function attempts(array $sends): array
    {
        $attempts = [];

        foreach (array_keys($sends) as $key) {
            foreach (range(1, random_int(1, 3)) as $ignored) {
                $attempts[] = $key;
            }
        }

        shuffle($attempts);

        return $attempts;
    }

    /**
     * Overwrite a plan's limits after the fact, the way an admin edit does — including
     * the cache invalidation `PlanObserver` performs.
     *
     * @param  array<string, int|null>  $limits
     */
    public static function repricePlan(Tenant $tenant, array $limits): void
    {
        $plan = Plan::query()->findOrFail($tenant->plan_id);
        $plan->limits = $limits;
        $plan->save();
    }
}
