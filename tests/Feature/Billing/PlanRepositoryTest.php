<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\PlanRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Cached plan reads with version-bump invalidation (Req 25.1 / D2, Req 30.4 / NFR1)
|--------------------------------------------------------------------------
| PlanGate and QuotaGuard resolve a plan on every inbound message, so these reads
| are cached. The tests that matter are the ones proving the cache cannot outlive
| the plan it describes.
*/

beforeEach(function (): void {
    Cache::flush();
});

/**
 * Queries issued while $work runs.
 */
function planQueriesDuring(Closure $work): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $work();

    return $queries;
}

it('finds a plan by id and by slug', function (): void {
    $plan = Plan::factory()->create(['slug' => 'growth']);
    $repository = app(PlanRepository::class);

    expect($repository->find($plan->id)?->id)->toBe($plan->id)
        ->and($repository->findBySlug('growth')?->id)->toBe($plan->id)
        ->and($repository->find((string) Str::ulid()))->toBeNull()
        ->and($repository->findBySlug('nope'))->toBeNull()
        ->and($repository->find(''))->toBeNull()
        ->and($repository->findBySlug(''))->toBeNull();
});

it('rehydrates a cached plan as a fully working model', function (): void {
    $plan = Plan::factory()->create([
        'slug' => 'growth',
        'features' => ['ai' => true],
        'limits' => [QuotaKind::MessagesMonthly->value => null, QuotaKind::Sessions->value => 3],
    ]);

    $repository = app(PlanRepository::class);
    $repository->findBySlug('growth');

    // Second call comes from the cache — casts, accessors and identity must all
    // behave exactly as on a queried row.
    $cached = $repository->findBySlug('growth');

    expect($cached)->not->toBeNull()
        ->and($cached?->exists)->toBeTrue()
        ->and($cached?->is($plan))->toBeTrue()
        ->and($cached?->interval)->toBe($plan->interval)
        ->and($cached?->active)->toBeTrue()
        ->and($cached?->allows('ai'))->toBeTrue()
        ->and($cached?->limitFor(QuotaKind::Sessions))->toBe(3)
        ->and($cached?->isUnlimited(QuotaKind::MessagesMonthly))->toBeTrue();
});

it('serves repeat lookups without querying again', function (): void {
    $plan = Plan::factory()->create(['slug' => 'growth']);
    $repository = app(PlanRepository::class);

    $repository->find($plan->id);
    $repository->findBySlug('growth');

    $queries = planQueriesDuring(function () use ($repository, $plan): void {
        $repository->find($plan->id);
        $repository->findBySlug('growth');
    });

    expect($queries)->toBe(0);
});

it('cannot serve a plan as it was before an update', function (): void {
    $plan = Plan::factory()->create(['slug' => 'growth', 'features' => ['ai' => false]]);
    $repository = app(PlanRepository::class);

    expect($repository->findBySlug('growth')?->allows('ai'))->toBeFalse();

    $versionBefore = $repository->version();

    $plan->update(['features' => ['ai' => true], 'limits' => [QuotaKind::Sessions->value => 9]]);

    // The observer bumped the namespace, so the reader now composes a key the stale
    // entry does not live under — the pre-update plan is unreachable, not merely
    // expiring.
    expect($repository->version())->toBeGreaterThan($versionBefore)
        ->and($repository->findBySlug('growth')?->allows('ai'))->toBeTrue()
        ->and($repository->find($plan->id)?->limitFor(QuotaKind::Sessions))->toBe(9);
});

it('cannot serve a plan that has been deleted', function (): void {
    $plan = Plan::factory()->create(['slug' => 'growth']);
    $repository = app(PlanRepository::class);

    $repository->find($plan->id);
    $repository->findBySlug('growth');

    $plan->delete();

    expect($repository->find($plan->id))->toBeNull()
        ->and($repository->findBySlug('growth'))->toBeNull();
});

it('invalidates the cached catalogue when a plan is created', function (): void {
    $repository = app(PlanRepository::class);

    Plan::factory()->create(['slug' => 'starter', 'sort' => 10]);

    expect($repository->active()->pluck('slug')->all())->toBe(['starter']);

    Plan::factory()->create(['slug' => 'growth', 'sort' => 20]);
    Plan::factory()->inactive()->create(['slug' => 'legacy', 'sort' => 5]);

    expect($repository->active()->pluck('slug')->all())->toBe(['starter', 'growth']);
});

it('serves the catalogue from the cache on repeat reads', function (): void {
    Plan::factory()->count(2)->create();
    $repository = app(PlanRepository::class);

    $repository->active();

    expect(planQueriesDuring(fn () => $repository->active()))->toBe(0)
        ->and($repository->active()->every(fn (Plan $plan): bool => $plan->exists))->toBeTrue();
});

it('resolves the plan of a tenant, and nothing for a tenant without one', function (): void {
    $plan = Plan::factory()->create();
    $onPlan = Tenant::factory()->create(['plan_id' => $plan->id]);
    $unplanned = Tenant::factory()->create(['plan_id' => null]);

    $repository = app(PlanRepository::class);

    expect($repository->forTenant($onPlan)?->is($plan))->toBeTrue()
        ->and($repository->forTenant($unplanned))->toBeNull();
});

it('resolves the plan new tenants are provisioned onto', function (): void {
    Plan::factory()->create(['slug' => 'starter']);
    Plan::factory()->create(['slug' => 'growth']);

    config(['wa.tenancy.default_plan_slug' => 'starter']);

    expect(app(PlanRepository::class)->defaultPlan()?->slug)->toBe('starter');

    config(['wa.tenancy.default_plan_slug' => 'growth']);

    expect(app(PlanRepository::class)->defaultPlan()?->slug)->toBe('growth');
});

it('can be invalidated by hand after a write that bypasses model events', function (): void {
    $plan = Plan::factory()->create(['slug' => 'growth', 'features' => ['ai' => false]]);
    $repository = app(PlanRepository::class);

    $repository->findBySlug('growth');

    // A mass update fires no model events, so nothing bumped the version.
    Plan::query()->whereKey($plan->id)->update(['features' => json_encode(['ai' => true])]);

    expect($repository->findBySlug('growth')?->allows('ai'))->toBeFalse();

    $repository->flush();

    expect($repository->findBySlug('growth')?->allows('ai'))->toBeTrue();
});
