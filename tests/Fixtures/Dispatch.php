<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Tenant;
use App\Models\TenantTierAssignment;
use App\Services\Dispatch\DeficitLedger;
use App\Services\Dispatch\DispatchEligibility;
use App\Services\Dispatch\DispatchLane;
use App\Services\Dispatch\DispatchRoundResult;
use App\Services\Dispatch\FairScheduler;

/**
 * Shared entry points for the weighted-fair dispatch tests.
 *
 * A class rather than Pest helper functions for the same reason as
 * `Tests\Fixtures\Lifecycle`: Pest loads every test file into one process, so a global
 * `scheduler()` helper would be a name the whole suite has to keep free forever.
 */
final class Dispatch
{
    public static function scheduler(): FairScheduler
    {
        return app(FairScheduler::class);
    }

    public static function ledger(): DeficitLedger
    {
        return app(DeficitLedger::class);
    }

    /**
     * Tenants with pinned lane weights, in the order the weights were given.
     *
     * The weight is pinned on the `tenant_tiers` row rather than set in config, so the
     * tests exercise the same path a real weight change takes — including the resolver
     * cache invalidation that write performs.
     *
     * @param  list<int>  $weights
     * @return list<Tenant>
     */
    public static function tenantsWeighted(array $weights): array
    {
        $tenants = [];

        foreach ($weights as $weight) {
            $tenant = Tenant::factory()->create();
            TenantTierAssignment::factory()->withLaneWeight($weight)->create(['tenant_id' => $tenant->id]);
            $tenants[] = $tenant;
        }

        return $tenants;
    }

    /**
     * An unsaved tenant with a forced id, for the unit tests that only need an identity
     * (no database, no factory, no faker).
     */
    public static function stubTenant(string $id): Tenant
    {
        return (new Tenant)->forceFill(['id' => $id]);
    }

    /**
     * `$count` random lane weights, for the Property 19 generators.
     *
     * Kept well below `lane_weight_max` so a window of a hundred-odd units still spans
     * several rounds — the property is about shares across a window, and a weight larger
     * than the window would only ever be measuring a single partial round.
     *
     * @return list<int>
     */
    public static function randomWeights(int $count): array
    {
        $weights = [];

        foreach (range(1, max(1, $count)) as $ignored) {
            $weights[] = random_int(1, 8);
        }

        return $weights;
    }

    /**
     * Lanes for `$tenants` with the matching backlog from `$backlogs` (positional).
     *
     * @param  list<Tenant>  $tenants
     * @param  list<int>  $backlogs
     * @return list<DispatchLane>
     */
    public static function lanes(array $tenants, array $backlogs): array
    {
        $lanes = [];

        foreach ($tenants as $index => $tenant) {
            $lanes[] = DispatchLane::for($tenant, $backlogs[$index] ?? 0);
        }

        return $lanes;
    }

    /**
     * Dispatch a window in which every granted unit is accepted.
     *
     * @param  iterable<array-key, DispatchLane|Tenant>  $lanes
     */
    public static function window(iterable $lanes, int $budget, string $queueLane = FairScheduler::DEFAULT_QUEUE_LANE): DispatchRoundResult
    {
        return self::scheduler()->dispatchRound($lanes, $budget, static fn (): bool => true, $queueLane);
    }

    /**
     * The order tenants were served in, one entry per dispatched unit.
     *
     * @param  iterable<array-key, DispatchLane|Tenant>  $lanes
     * @return list<string>
     */
    public static function order(iterable $lanes, int $budget, string $queueLane = FairScheduler::DEFAULT_QUEUE_LANE): array
    {
        $order = [];

        self::scheduler()->dispatchRound($lanes, $budget, static function (Tenant $tenant) use (&$order): bool {
            $order[] = $tenant->id;

            return true;
        }, $queueLane);

        /** @var list<string> $order */
        return $order;
    }

    /**
     * Put `$gate` in the eligibility chain and rebuild the singletons that captured the
     * old one.
     *
     * @param  class-string<DispatchEligibility>  $gate
     */
    public static function withGate(string $gate): void
    {
        config(['wa.dispatch.eligibility.gates' => [$gate]]);

        self::rebuild();
    }

    /**
     * Drop the resolved dispatch singletons, so the next resolution reads current config.
     *
     * `TierResolver` is deliberately *not* dropped: it is the instance
     * `TenantTierAssignment` invalidates on write, so replacing it would leave the
     * scheduler holding a resolver whose memo nobody clears.
     */
    public static function rebuild(): void
    {
        app()->forgetInstance(DispatchEligibility::class);
        app()->forgetInstance(FairScheduler::class);
    }
}
