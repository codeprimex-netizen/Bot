<?php

declare(strict_types=1);

use App\Enums\PlanFeature;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Exceptions\Billing\MalformedPlanException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Plan feature gating — Correctness Property 7 (Req 11.3 / B2, Req 22.2 / C5)
|--------------------------------------------------------------------------
| Property 7: ∀ tenant t, ∀ feature not in t.plan → the feature is inaccessible and
| its pipeline stage is skipped. The gate is the only place that answers, so these
| tests are the specification of "not in the plan": every fail-closed edge (no plan,
| unknown key, corrupt JSON, platform mode) is pinned here, because each one is a way
| the invariant could be lost *silently*.
*/

beforeEach(function (): void {
    Cache::flush();
});

function planGate(): PlanGate
{
    return app(PlanGate::class);
}

/**
 * A tenant on a plan whose feature map is exactly $features — nothing else is granted.
 *
 * @param  array<string, bool>  $features
 */
function tenantOnPlan(array $features, string $slug = 'tenant-plan', bool $active = true): Tenant
{
    $plan = planWithFeatures($features, $slug, $active);

    return Tenant::factory()->create(['plan_id' => $plan->id]);
}

/**
 * @param  array<string, bool>  $features
 */
function planWithFeatures(array $features, string $slug, bool $active = true, int $sort = 0): Plan
{
    return Plan::factory()->create([
        'slug' => $slug,
        'active' => $active,
        'sort' => $sort,
        'features' => $features,
    ]);
}

/*
|--------------------------------------------------------------------------
| The core question
|--------------------------------------------------------------------------
*/

it('allows a feature the plan grants and denies one it does not', function (): void {
    $tenant = tenantOnPlan(['ai' => true, 'campaigns' => false]);

    expect(planGate()->allows($tenant, PlanFeature::Ai))->toBeTrue()
        ->and(planGate()->denies($tenant, PlanFeature::Ai))->toBeFalse()
        // Explicitly disabled and simply absent are the same answer.
        ->and(planGate()->allows($tenant, PlanFeature::Campaigns))->toBeFalse()
        ->and(planGate()->allows($tenant, PlanFeature::Handoff))->toBeFalse()
        ->and(planGate()->denies($tenant, PlanFeature::Handoff))->toBeTrue();
});

it('accepts the raw key form a stage or route carries', function (): void {
    $tenant = tenantOnPlan(['ai' => true]);

    expect(planGate()->allows($tenant, 'ai'))->toBeTrue()
        ->and(planGate()->allows($tenant, ' AI '))->toBeTrue()
        ->and(planGate()->allows($tenant, 'campaigns'))->toBeFalse();
});

it('throws from authorize for a denied feature and passes silently for an allowed one', function (): void {
    $tenant = tenantOnPlan(['ai' => true]);

    planGate()->authorize($tenant, PlanFeature::Ai);

    expect(planGate()->denial($tenant, PlanFeature::Ai))->toBeNull()
        ->and(function () use ($tenant): void {
            planGate()->authorize($tenant, PlanFeature::Campaigns);
        })->toThrow(FeatureNotInPlanException::class);
});

it('lists exactly the granted catalogue features for a panel', function (): void {
    $tenant = tenantOnPlan(['ai' => true, 'flows' => true, 'campaigns' => false]);

    expect(planGate()->granted($tenant))->toBe([PlanFeature::Ai, PlanFeature::Flows]);
});

/*
|--------------------------------------------------------------------------
| No plan at all ⇒ denied (never "skip the gate")
|--------------------------------------------------------------------------
*/

it('denies every feature to a tenant with no plan', function (): void {
    $tenant = Tenant::factory()->create(['plan_id' => null]);

    foreach (PlanFeature::cases() as $feature) {
        expect(planGate()->allows($tenant, $feature))->toBeFalse();
    }

    expect(planGate()->granted($tenant))->toBe([]);
});

it('denies every feature when the plan was retired under the tenant', function (): void {
    $tenant = tenantOnPlan(['ai' => true]);

    // `tenants.plan_id` is nullOnDelete: deleting the plan leaves the tenant planless
    // rather than deleting it, and a planless tenant has bought nothing.
    $tenant->plan?->delete();
    Cache::flush();

    expect(planGate()->allows($tenant->refresh(), PlanFeature::Ai))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| An unknown feature key fails loudly
|--------------------------------------------------------------------------
*/

it('refuses an unknown feature key instead of answering false', function (): void {
    $tenant = tenantOnPlan(['ai' => true]);

    // A typo'd gate key would otherwise deny every tenant on every plan forever, and
    // no test could ever fail for it.
    expect(fn (): bool => planGate()->allows($tenant, 'a_i'))
        ->toThrow(InvalidArgumentException::class, 'Unknown plan feature key "a_i"')
        ->and(fn (): bool => planGate()->allows($tenant, ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ?FeatureNotInPlanException => planGate()->denial($tenant, 'smart_replies'))
        ->toThrow(InvalidArgumentException::class);
});

it('grants nothing for a plan flag that is outside the catalogue', function (): void {
    // Storage stays permissive (an older deploy's key is readable), but a stray key
    // gates nothing: it is not a gate key at all.
    $tenant = tenantOnPlan(['legacy_beta_thing' => true]);

    expect(PlanFeature::tryFromKey('legacy_beta_thing'))->toBeNull()
        ->and(planGate()->granted($tenant))->toBe([])
        ->and($tenant->plan?->allows('legacy_beta_thing'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Malformed plan JSON denies rather than grants
|--------------------------------------------------------------------------
*/

it('refuses to grant a feature from a plan whose JSON cannot be read', function (): void {
    $tenant = tenantOnPlan(['ai' => true]);

    // `PlanObserver` rejects this on write, so the only way in is out-of-band SQL —
    // a hand-edited row, a restore from an older schema, a bad import.
    DB::table('plans')->where('id', $tenant->plan_id)->update(['features' => '["ai"]']);
    Cache::flush();

    $tenant = $tenant->fresh();
    assert($tenant instanceof Tenant);

    // Loud, and — the point of Property 7 — never `true`.
    expect(fn (): bool => planGate()->allows($tenant, PlanFeature::Ai))
        ->toThrow(MalformedPlanException::class)
        ->and(fn (): array => planGate()->granted($tenant))
        ->toThrow(MalformedPlanException::class);
});

it('does not let one corrupt catalogue row turn another tenant refusal into a 500', function (): void {
    $tenant = tenantOnPlan(['ai' => false], slug: 'starter');
    $corrupt = planWithFeatures(['ai' => true], 'corrupt');
    DB::table('plans')->where('id', $corrupt->id)->update(['features' => '["ai"]']);
    Cache::flush();

    // The corrupt plan grants nothing, so it is not offered as an upgrade target — and
    // the tenant's own (readable) plan still produces a clean refusal.
    expect(planGate()->upgradePlans(PlanFeature::Ai))->toBe([])
        ->and(planGate()->denial($tenant, PlanFeature::Ai)?->getStatusCode())
        ->toBe(FeatureNotInPlanException::STATUS_NOT_AVAILABLE);
});

/*
|--------------------------------------------------------------------------
| 402 vs 403
|--------------------------------------------------------------------------
*/

it('refuses with 402 when an active plan sells the feature', function (): void {
    $tenant = tenantOnPlan(['ai' => false], slug: 'starter');
    planWithFeatures(['ai' => true], 'growth', sort: 20);
    planWithFeatures(['ai' => true], 'scale', sort: 30);

    $denial = planGate()->denial($tenant, PlanFeature::Ai);

    expect($denial)->toBeInstanceOf(FeatureNotInPlanException::class)
        ->and($denial?->getStatusCode())->toBe(402)
        ->and($denial?->upgradeable)->toBeTrue()
        ->and($denial?->errorCode())->toBe(FeatureNotInPlanException::ERROR_CODE)
        ->and($denial?->feature)->toBe(PlanFeature::Ai)
        ->and($denial?->upgradePlans)->toBe(['growth', 'scale'])
        ->and($denial?->publicMessage())->toContain('AI smart replies')
        ->and($denial?->publicMessage())->toContain('Upgrade')
        ->and(planGate()->isPurchasable(PlanFeature::Ai))->toBeTrue();
});

it('refuses with 403 when no active plan sells the feature', function (): void {
    $tenant = tenantOnPlan(['ab_testing' => false], slug: 'starter');
    // Only a retired (inactive) plan ever granted it: no amount of money buys it now,
    // so an upgrade CTA would be a lie.
    planWithFeatures(['ab_testing' => true], 'legacy', active: false);

    $denial = planGate()->denial($tenant, PlanFeature::AbTesting);

    expect($denial?->getStatusCode())->toBe(403)
        ->and($denial?->upgradeable)->toBeFalse()
        ->and($denial?->errorCode())->toBe(FeatureNotInPlanException::ERROR_CODE_UNAVAILABLE)
        ->and($denial?->upgradePlans)->toBe([])
        ->and($denial?->publicMessage())->toBe('A/B testing is not available on your account.')
        ->and($denial?->publicMessage())->not->toContain('Upgrade')
        ->and(planGate()->isPurchasable(PlanFeature::AbTesting))->toBeFalse();
});

it('turns a 403 into a 402 as soon as the platform launches a plan that sells the feature', function (): void {
    $tenant = tenantOnPlan(['handoff' => false], slug: 'starter');

    expect(planGate()->denial($tenant, PlanFeature::Handoff)?->getStatusCode())->toBe(403);

    planWithFeatures(['handoff' => true], 'pro');

    // Derived from the live catalogue, so the promise "paying fixes this" stays true
    // without a code change.
    expect(planGate()->denial($tenant, PlanFeature::Handoff)?->getStatusCode())->toBe(402);
});

it('keeps a tenant id out of the public sentence', function (): void {
    $tenant = tenantOnPlan(['ai' => false], slug: 'starter');
    $denial = planGate()->denial($tenant, PlanFeature::Ai);

    expect($denial?->publicMessage())->not->toContain($tenant->id)
        ->and($denial?->publicMessage())->not->toContain('starter')
        // The internal message is the operator's, and names the tenant's own plan.
        ->and($denial?->getMessage())->toContain($tenant->id)
        ->and($denial?->getMessage())->toContain('starter');
});

/*
|--------------------------------------------------------------------------
| A plan edit is visible immediately (no stale grant, no stale denial)
|--------------------------------------------------------------------------
*/

it('reflects a plan edit on the very next question', function (): void {
    $tenant = tenantOnPlan(['ai' => false]);
    $plan = $tenant->plan;
    assert($plan instanceof Plan);

    // Warm the cache with the denial, then grant the feature.
    expect(planGate()->allows($tenant, PlanFeature::Ai))->toBeFalse();

    $plan->update(['features' => [...$plan->features(), 'ai' => true]]);

    expect(planGate()->allows($tenant, PlanFeature::Ai))->toBeTrue();

    // ...and revoking it takes effect just as fast, which is the direction that matters.
    $plan->update(['features' => [...$plan->features(), 'ai' => false]]);

    expect(planGate()->allows($tenant, PlanFeature::Ai))->toBeFalse()
        ->and(planGate()->denial($tenant, PlanFeature::Ai))->not->toBeNull();
});

it('follows the tenant when it is moved to another plan', function (): void {
    $tenant = tenantOnPlan(['ai' => false], slug: 'starter');
    $growth = planWithFeatures(['ai' => true], 'growth');

    expect(planGate()->allows($tenant, PlanFeature::Ai))->toBeFalse();

    $tenant->update(['plan_id' => $growth->id]);

    expect(planGate()->allows($tenant->refresh(), PlanFeature::Ai))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Platform mode does not grant entitlements
|--------------------------------------------------------------------------
*/

it('still applies the tenant plan inside platform mode and impersonation', function (): void {
    $tenant = tenantOnPlan(['ai' => false]);
    $context = app(TenantContext::class);

    // Req 1.5's bypass is about tenant *data* isolation, not about handing a tenant
    // features it has not bought.
    $allowedAsPlatform = $context->asPlatform('support review', fn (): bool => planGate()->allows($tenant, PlanFeature::Ai));

    $allowedImpersonating = $context->runFor($tenant, fn (): bool => planGate()->allows($tenant, PlanFeature::Ai));

    expect($allowedAsPlatform)->toBeFalse()
        ->and($allowedImpersonating)->toBeFalse()
        ->and(function () use ($context, $tenant): void {
            $context->asPlatform('support review', function () use ($tenant): void {
                planGate()->authorize($tenant, PlanFeature::Ai);
            });
        })->toThrow(FeatureNotInPlanException::class);
});

/*
|--------------------------------------------------------------------------
| The catalogue itself
|--------------------------------------------------------------------------
*/

it('names every feature the design gates, with one key per name', function (): void {
    $keys = PlanFeature::keys();

    expect($keys)->toContain('ai', 'flows', 'channels', 'groups', 'extraction', 'campaigns')
        ->and($keys)->toContain('templates', 'handoff', 'ab_testing', 'integrations', 'stt')
        ->and($keys)->toBe(array_values(array_unique($keys)))
        ->and(PlanFeature::options())->toHaveKey('ai')
        ->and(PlanFeature::options()['ai'])->toBe('AI smart replies');

    foreach ($keys as $key) {
        // Keys are the literal `plans.features` JSON keys, so they must survive a
        // round trip through the plan editor unchanged.
        expect($key)->toBe(mb_strtolower(trim($key)))
            ->and(PlanFeature::fromKey($key)->value)->toBe($key);
    }
});

it('gates every feature the seeded catalogue advertises', function (): void {
    // The seeder is what a fresh install ships with: if it grants a flag no gate key
    // names, that feature is unreachable and the plan is selling nothing.
    $seeded = [];

    foreach (PlanSeeder::plans() as $attributes) {
        $features = $attributes['features'];
        assert(is_array($features));
        $seeded = [...$seeded, ...array_keys($features)];
    }

    foreach (array_unique($seeded) as $key) {
        assert(is_string($key));
        expect(PlanFeature::tryFromKey($key))->not->toBeNull("seeded feature \"{$key}\" has no gate key");
    }
});
