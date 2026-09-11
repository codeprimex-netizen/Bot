<?php

declare(strict_types=1);

use App\Enums\PlanFeature;
use App\Enums\TenantStatus;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The declarative surfaces of plan gating (Req 22.2 / C5, Property 7)
|--------------------------------------------------------------------------
| Req 22.2 says a feature not in the plan is *hidden or disabled* — so the panels need
| a boolean (`Gate::allows`) as much as the actions need a refusal
| (`plan.feature:{key}`). Both are exercised here through the real container wiring and
| a real request, so the alias, the ability registration, and the JSON envelope are all
| covered rather than assumed.
*/

beforeEach(function (): void {
    Cache::flush();
    config()->set('wa.tenancy.apexes', ['app.example.test']);

    // Feature-gated routes as Phase C will declare them: tenant resolution first, plan
    // gate second.
    Route::middleware(['resolve.tenant', 'plan.feature:ai'])
        ->get('/_test/ai', fn (): array => ['ok' => true]);

    Route::middleware(['resolve.tenant', 'plan.feature:flows,integrations'])
        ->get('/_test/flow-actions', fn (): array => ['ok' => true]);

    Route::middleware(['resolve.tenant', 'plan.feature:not_a_feature'])
        ->get('/_test/typo', fn (): array => ['ok' => true]);
});

/**
 * A tenant reachable at `{subdomain}.app.example.test`, on a plan granting $features.
 *
 * @param  array<string, bool>  $features
 */
function gatedTenant(array $features, string $subdomain = 'acme'): Tenant
{
    $plan = Plan::factory()->create(['slug' => 'tenant-plan-'.$subdomain, 'features' => $features]);

    return Tenant::factory()->create(['plan_id' => $plan->id, 'subdomain' => $subdomain]);
}

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('lets a request through when the plan includes the feature', function (): void {
    gatedTenant(['ai' => true]);

    thisTest()->getJson('http://acme.app.example.test/_test/ai')
        ->assertOk()
        ->assertExactJson(['ok' => true]);
});

it('refuses a feature-gated route with 402 and an actionable envelope', function (): void {
    gatedTenant(['ai' => false]);
    Plan::factory()->create(['slug' => 'growth', 'features' => ['ai' => true]]);

    thisTest()->getJson('http://acme.app.example.test/_test/ai')
        ->assertStatus(402)
        ->assertExactJson([
            'message' => 'AI smart replies is not included in your current plan. Upgrade to enable it.',
            'error' => FeatureNotInPlanException::ERROR_CODE,
            'feature' => 'ai',
            'upgrade_plans' => ['growth'],
        ]);
});

it('refuses with 403 and no upgrade target when nothing sells the feature', function (): void {
    gatedTenant(['ai' => false]);

    thisTest()->getJson('http://acme.app.example.test/_test/ai')
        ->assertStatus(403)
        ->assertExactJson([
            'message' => 'AI smart replies is not available on your account.',
            'error' => FeatureNotInPlanException::ERROR_CODE_UNAVAILABLE,
            'feature' => 'ai',
        ]);
});

it('requires every feature a route names', function (): void {
    gatedTenant(['flows' => true, 'integrations' => false]);
    Plan::factory()->create(['slug' => 'growth', 'features' => ['flows' => true, 'integrations' => true]]);

    thisTest()->getJson('http://acme.app.example.test/_test/flow-actions')
        ->assertStatus(402)
        ->assertJson(['feature' => 'integrations', 'upgrade_plans' => ['growth']]);
});

it('refuses a feature-gated route that resolves no tenant', function (): void {
    // No tenant behind this host: a plan gate with no plan grants nothing.
    thisTest()->getJson('http://nope.app.example.test/_test/ai')
        ->assertStatus(403)
        ->assertJson(['error' => FeatureNotInPlanException::ERROR_CODE_UNAVAILABLE, 'feature' => 'ai']);
});

it('fails loudly on a route that names a key outside the catalogue', function (): void {
    gatedTenant(['ai' => true]);

    thisTest()->withoutExceptionHandling();

    // A misconfigured route must not silently deny a working screen for every tenant.
    expect(fn (): mixed => thisTest()->getJson('http://acme.app.example.test/_test/typo'))
        ->toThrow(InvalidArgumentException::class, 'Unknown plan feature key "not_a_feature"');
});

it('denies a suspended tenant nothing extra — status is a separate guard', function (): void {
    $tenant = gatedTenant(['ai' => true]);
    $tenant->update(['status' => TenantStatus::Suspended]);

    // Plan gating answers *what was bought*; `tenant.mutate` / `tenant.send` answer
    // *whether the account may act*. Conflating them would let a plan change quietly
    // re-enable a suspended tenant, or a suspension look like a billing problem.
    thisTest()->getJson('http://acme.app.example.test/_test/ai')->assertOk();

    expect(Gate::denies('tenant.mutate', $tenant->fresh()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The ability (the boolean form a panel renders with)
|--------------------------------------------------------------------------
*/

it('answers the ability for the bound tenant', function (): void {
    $tenant = gatedTenant(['ai' => true, 'campaigns' => false]);
    Plan::factory()->create(['slug' => 'growth', 'features' => ['campaigns' => true]]);

    app(TenantContext::class)->set($tenant);

    expect(Gate::allows('plan.feature', PlanFeature::Ai))->toBeTrue()
        ->and(Gate::allows('plan.feature', 'ai'))->toBeTrue()
        ->and(Gate::denies('plan.feature', PlanFeature::Campaigns))->toBeTrue()
        ->and(Gate::inspect('plan.feature', PlanFeature::Campaigns)->status())->toBe(402)
        ->and(Gate::inspect('plan.feature', PlanFeature::Campaigns)->message())
        ->toBe('Bulk campaigns is not included in your current plan. Upgrade to enable it.')
        ->and(Gate::inspect('plan.feature', PlanFeature::Campaigns)->code())
        ->toBe(FeatureNotInPlanException::ERROR_CODE);
});

it('answers the ability for an explicitly named tenant', function (): void {
    $acme = gatedTenant(['ai' => true], subdomain: 'acme');
    $globex = gatedTenant(['ai' => false], subdomain: 'globex');

    expect(Gate::allows('plan.feature', [PlanFeature::Ai, $acme]))->toBeTrue()
        ->and(Gate::denies('plan.feature', [PlanFeature::Ai, $globex]))->toBeTrue();
});

it('denies the ability when no tenant is bound, including in platform mode', function (): void {
    gatedTenant(['ai' => true]);
    $context = app(TenantContext::class);
    $context->forget();

    expect(Gate::denies('plan.feature', PlanFeature::Ai))->toBeTrue();

    $inPlatformMode = $context->asPlatform('admin review', fn (): bool => Gate::allows('plan.feature', PlanFeature::Ai));

    expect($inPlatformMode)->toBeFalse()
        ->and(Gate::inspect('plan.feature', PlanFeature::Ai)->status())->toBe(403);
});

it('reflects a revoked feature on the ability immediately', function (): void {
    $tenant = gatedTenant(['ai' => true]);
    app(TenantContext::class)->set($tenant);

    expect(Gate::allows('plan.feature', PlanFeature::Ai))->toBeTrue();

    $plan = $tenant->plan;
    assert($plan instanceof Plan);
    $plan->update(['features' => [...$plan->features(), 'ai' => false]]);

    expect(Gate::allows('plan.feature', PlanFeature::Ai))->toBeFalse();
});
