<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantTierAssignment;
use App\Services\Dispatch\CacheDeficitLedger;
use App\Services\Dispatch\DeficitLedger;
use App\Services\Dispatch\DeficitRoundRobinScheduler;
use App\Services\Dispatch\DispatchEligibility;
use App\Services\Dispatch\DispatchLane;
use App\Services\Dispatch\FairScheduler;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantLifecycle;
use App\Services\Tenancy\TierResolver;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Tests\Fixtures\Dispatch;
use Tests\Fixtures\FakeRateCapGate;
use Tests\Fixtures\LocklessStore;

/*
|--------------------------------------------------------------------------
| Weighted-fair dispatch (Req 1.7 / A1; Req 30.2, 30.6 / NFR1)
|--------------------------------------------------------------------------
| The scheduler's job is to answer "whose turn is it?" so that shares follow
| `lane_weight`, nobody starves, and a tenant that cannot be dispatched right now is
| stepped over rather than spun on.
|
| None of these tests bind a tenant context: the scheduler is the thing that *decides*
| which tenant to become, so it must work with nothing bound — asserted explicitly
| below.
*/

beforeEach(function (): void {
    FakeRateCapGate::reset();
    Dispatch::rebuild();
});

it('splits a window in proportion to lane weight', function (): void {
    [$small, $large] = Dispatch::tenantsWeighted([1, 5]);

    // Backlogs far larger than the window, so nothing drains and the only thing
    // deciding the split is the weight.
    $result = Dispatch::window(Dispatch::lanes([$small, $large], [1000, 1000]), 60);

    expect($result->total())->toBe(60)
        ->and($result->unitsFor($small))->toBe(10)
        ->and($result->unitsFor($large))->toBe(50);
});

it('interleaves tenants instead of letting one send its whole quantum first', function (): void {
    [$small, $large] = Dispatch::tenantsWeighted([1, 3]);

    $order = Dispatch::order(Dispatch::lanes([$small, $large], [100, 100]), 4);

    // The small tenant's single unit lands in the first pass, not behind the large
    // tenant's three: latency fairness, not just share fairness.
    expect(array_slice($order, 0, 2))->toContain($small->id)
        ->and(array_count_values($order)[$large->id])->toBe(3);
});

it('gives every backlogged tenant a unit before anyone gets a second', function (): void {
    // No starvation, structurally: the weight floor is 1, so the first pass of a round
    // reaches every tenant — even the one whose weight is a fifth of its neighbour's.
    $tenants = Dispatch::tenantsWeighted([1, 1, 1, 5, 9]);

    $result = Dispatch::window(Dispatch::lanes($tenants, [50, 50, 50, 50, 50]), count($tenants));

    foreach ($tenants as $tenant) {
        expect($result->unitsFor($tenant))->toBe(1, sprintf('tenant %s was starved', $tenant->id));
    }
});

it('does not let a huge backlog monopolise the window', function (): void {
    // Same weight, wildly different backlogs — the noisy-neighbour case of Req 30.2.
    [$noisy, $quiet] = Dispatch::tenantsWeighted([1, 1]);

    $result = Dispatch::window(Dispatch::lanes([$noisy, $quiet], [10_000, 20]), 40);

    expect($result->unitsFor($noisy))->toBe(20)
        ->and($result->unitsFor($quiet))->toBe(20)
        // The small tenant's whole backlog went out inside a window the big tenant
        // could have swallowed forty times over.
        ->and($result->total())->toBe(40);
});

it('releases the rest of the window when a lane runs dry', function (): void {
    [$short, $long] = Dispatch::tenantsWeighted([1, 1]);

    // `false` from the closure is "this tenant has nothing left" — the stale-backlog
    // case, where the count was right when it was read and is not any more.
    $served = [];
    $result = Dispatch::scheduler()->dispatchRound(
        Dispatch::lanes([$short, $long], [100, 100]),
        20,
        function (Tenant $tenant) use (&$served, $short): bool {
            $served[$tenant->id] = ($served[$tenant->id] ?? 0) + 1;

            return ! ($tenant->id === $short->id && $served[$short->id] > 3);
        },
    );

    expect($result->unitsFor($short))->toBe(3)
        ->and($result->wasDrained($short))->toBeTrue()
        // The 17 units the dry lane could not use went to the tenant that could.
        ->and($result->unitsFor($long))->toBe(17);
});

it('skips a suspended tenant and picks it up again once reactivated', function (): void {
    [$active, $suspended] = Dispatch::tenantsWeighted([1, 1]);
    app(TenantLifecycle::class)->suspend($suspended, 'non-payment');

    $lanes = Dispatch::lanes([$active, $suspended], [100, 100]);
    $blocked = Dispatch::window($lanes, 20);

    expect($blocked->wasSkipped($suspended))->toBeTrue()
        ->and($blocked->unitsFor($suspended))->toBe(0)
        // Skipping frees the units for somebody who can use them rather than wasting them.
        ->and($blocked->unitsFor($active))->toBe(20);

    app(TenantLifecycle::class)->reactivate($suspended);

    $resumed = Dispatch::window($lanes, 20);

    expect($resumed->wasSkipped($suspended))->toBeFalse()
        // Back on its normal share — and only its normal share: being skipped for a
        // window is not compensated with a burst afterwards.
        ->and($resumed->unitsFor($suspended))->toBe(10)
        ->and($resumed->unitsFor($active))->toBe(10);
});

it('skips a rate-capped tenant without shifting its share permanently', function (): void {
    // The seam tasks 2.3 / 9.6 / 36.5 plug into: one more gate in the chain, and the
    // scheduler steps over the tenant while the cap holds.
    Dispatch::withGate(FakeRateCapGate::class);

    [$capped, $free] = Dispatch::tenantsWeighted([3, 1]);
    $lanes = Dispatch::lanes([$capped, $free], [500, 500]);

    FakeRateCapGate::cap($capped);
    $whileCapped = Dispatch::window($lanes, 40);

    expect($whileCapped->wasSkipped($capped))->toBeTrue()
        ->and($whileCapped->unitsFor($capped))->toBe(0)
        ->and($whileCapped->unitsFor($free))->toBe(40);

    FakeRateCapGate::release($capped);
    $afterCap = Dispatch::window($lanes, 40);

    // 3:1 again, immediately — the share came back with the tenant.
    expect($afterCap->unitsFor($capped))->toBe(30)
        ->and($afterCap->unitsFor($free))->toBe(10);
});

it('applies a changed lane weight to the next round', function (): void {
    [$tenant, $other] = Dispatch::tenantsWeighted([1, 1]);
    $lanes = Dispatch::lanes([$tenant, $other], [500, 500]);

    expect(Dispatch::window($lanes, 20)->unitsFor($tenant))->toBe(10);

    // The write invalidates the resolver's cache for this tenant, so the very next
    // claim reads the new weight — escalating a tenant is a row change, not a restart.
    TenantTierAssignment::forTenant($tenant->id)->firstOrFail()->update(['lane_weight' => 9]);

    $result = Dispatch::window($lanes, 20);

    expect($result->unitsFor($tenant))->toBe(18)
        ->and($result->unitsFor($other))->toBe(2)
        ->and(Dispatch::scheduler()->quantumFor($tenant))->toBe(9);
});

it('rotates the head of the rotation between equally weighted tenants', function (): void {
    $tenants = Dispatch::tenantsWeighted([1, 1, 1]);
    $lanes = Dispatch::lanes($tenants, [10, 10, 10]);

    // A window of one unit cannot reach everybody, so the advantage of being first has
    // to move — otherwise the lowest tenant id would win every single-unit window.
    $served = [];

    foreach (range(1, 3) as $ignored) {
        $served[] = Dispatch::scheduler()->nextTenant($lanes)?->id;
    }

    expect(array_unique(array_filter($served)))->toHaveCount(3);
});

it('gives each tenant exactly its quantum in one round', function (): void {
    [$one, $four] = Dispatch::tenantsWeighted([1, 4]);

    $result = Dispatch::scheduler()->eachEligibleTenant(
        Dispatch::lanes([$one, $four], [100, 2]),
        static fn (): bool => true,
    );

    expect($result->unitsFor($one))->toBe(1)
        // Capped by its own backlog, not by its weight of four.
        ->and($result->unitsFor($four))->toBe(2)
        ->and($result->rounds)->toBe(1);
});

it('runs with no tenant bound and lets the caller enter each tenant context', function (): void {
    $tenants = Dispatch::tenantsWeighted([1, 1]);
    $context = app(TenantContext::class);
    $context->forget();

    $seen = [];

    Dispatch::scheduler()->dispatchRound(
        Dispatch::lanes($tenants, [5, 5]),
        4,
        function (Tenant $tenant) use ($context, &$seen): bool {
            // Nothing is bound when the scheduler calls back: binding the tenant is the
            // caller's job, exactly as `Saga::resumable()` documents.
            expect($context->current())->toBeNull()
                ->and($context->actingAsPlatform())->toBeFalse();

            $seen[] = $context->runFor($tenant, static fn (Tenant $bound): string => $bound->id);

            return true;
        },
    );

    expect($seen)->toHaveCount(4)
        ->and($context->current())->toBeNull();
});

it('keeps shares proportional when several workers dispatch from the same lane', function (): void {
    // Two scheduler instances stand in for two Supervisor workers: they hold no state of
    // their own, so the only thing keeping them fair is the shared deficit ledger. True
    // parallelism is not reproducible in-process (and the lock is the part that MySQL/Redis
    // enforce in production), but interleaved claims through one ledger are.
    [$small, $large] = Dispatch::tenantsWeighted([1, 4]);
    $lanes = Dispatch::lanes([$small, $large], [1000, 1000]);

    $workers = [
        new DeficitRoundRobinScheduler(app(TierResolver::class), app(DispatchEligibility::class), app(DeficitLedger::class)),
        new DeficitRoundRobinScheduler(app(TierResolver::class), app(DispatchEligibility::class), app(DeficitLedger::class)),
    ];

    $units = [];

    foreach (range(1, 10) as $tick) {
        $worker = $workers[$tick % 2];
        $worker->dispatchRound($lanes, 5, function (Tenant $tenant) use (&$units): bool {
            $units[$tenant->id] = ($units[$tenant->id] ?? 0) + 1;

            return true;
        });
    }

    expect(array_sum($units))->toBe(50)
        // 1:4 over the combined window, within one quantum.
        ->and($units[$small->id] ?? 0)->toBeGreaterThanOrEqual(9)
        ->and($units[$small->id] ?? 0)->toBeLessThanOrEqual(11)
        ->and($units[$large->id] ?? 0)->toBeGreaterThanOrEqual(39);
});

it('still dispatches when the cache store cannot provide a lock', function (): void {
    // The documented degradation: no lock driver means an unlocked read-modify-write, not
    // a refusal to dispatch. Fairness loses at most a quantum of precision per race.
    $scheduler = new DeficitRoundRobinScheduler(
        app(TierResolver::class),
        app(DispatchEligibility::class),
        new CacheDeficitLedger(new class implements CacheFactory
        {
            private ?CacheRepository $repository = null;

            public function store($name = null): CacheRepository
            {
                return $this->repository ??= new CacheRepository(new LocklessStore);
            }
        }),
    );

    [$small, $large] = Dispatch::tenantsWeighted([1, 3]);

    $result = $scheduler->dispatchRound(Dispatch::lanes([$small, $large], [100, 100]), 20, static fn (): bool => true);

    expect($result->total())->toBe(20)
        ->and($result->unitsFor($small))->toBe(5)
        ->and($result->unitsFor($large))->toBe(15);
});

it('forgets the credit of a tenant that goes quiet', function (): void {
    [$busy, $quiet] = Dispatch::tenantsWeighted([1, 9]);

    // The heavy tenant builds up carry while it has work…
    Dispatch::window([DispatchLane::for($quiet, 100), DispatchLane::for($busy, 100)], 5);
    expect(Dispatch::ledger()->credits(FairScheduler::DEFAULT_QUEUE_LANE)->get($quiet->id))->toBeGreaterThan(0);

    // …and loses it the moment it has none, so its return is not a burst.
    Dispatch::window([DispatchLane::for($busy, 100)], 5);

    expect(Dispatch::ledger()->credits(FairScheduler::DEFAULT_QUEUE_LANE)->get($quiet->id))->toBe(0);
});

it('dispatches nothing without candidates, without budget, or with everyone ineligible', function (): void {
    $tenant = Tenant::factory()->suspended()->create();

    expect(Dispatch::window([], 10)->total())->toBe(0)
        ->and(Dispatch::window([DispatchLane::pending($tenant)], 10)->total())->toBe(0)
        ->and(Dispatch::scheduler()->nextTenant([DispatchLane::pending($tenant)]))->toBeNull()
        ->and(Dispatch::window([DispatchLane::for($tenant, 0)], 10)->total())->toBe(0);

    $active = Tenant::factory()->create();

    expect(Dispatch::window([DispatchLane::pending($active)], 0)->total())->toBe(0)
        ->and(Dispatch::window([DispatchLane::pending($active)], -5)->total())->toBe(0);
});

it('keeps each queue lane on its own counters', function (): void {
    [$small, $large] = Dispatch::tenantsWeighted([1, 4]);
    $lanes = Dispatch::lanes([$small, $large], [100, 100]);

    // Budget smaller than one round, so the heavy tenant ends the window with credit
    // carried in the campaign lane specifically.
    Dispatch::window($lanes, 3, 'campaign');

    // A busy campaign lane must not shift anybody's position in the ai-reply lane: the
    // two are different worker pools and fairness is a property of each.
    expect(Dispatch::ledger()->credits('campaign')->all())->toHaveKey($large->id)
        ->and(Dispatch::ledger()->credits('ai-reply')->all())->toBe([])
        ->and(Dispatch::ledger()->credits('ai-reply')->cursor())->toBeNull();
});
