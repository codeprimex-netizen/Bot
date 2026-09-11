<?php

declare(strict_types=1);

use App\Exceptions\Dispatch\InvalidDispatchGateException;
use App\Models\Tenant;
use App\Services\Dispatch\DispatchEligibility;
use App\Services\Dispatch\Eligibility\CompositeDispatchEligibility;
use App\Services\Dispatch\Eligibility\LifecycleDispatchEligibility;
use Tests\Fixtures\Dispatch;
use Tests\Fixtures\FakeRateCapGate;

/*
|--------------------------------------------------------------------------
| Dispatch eligibility — the quota-aware-dispatch seam (Req 30.6 / NFR1)
|--------------------------------------------------------------------------
| The chain the scheduler consults before putting a tenant in the rotation. What is
| pinned here is the shape of the seam tasks 2.3 / 9.6 / 36.5 plug into, and the one
| thing no deployment may configure away: a suspended tenant is not dispatched.
*/

beforeEach(function (): void {
    FakeRateCapGate::reset();
    Dispatch::rebuild();
});

it('always applies the suspension gate, whatever config says', function (): void {
    config(['wa.dispatch.eligibility.gates' => []]);
    Dispatch::rebuild();

    $gate = app(DispatchEligibility::class);

    expect($gate)->toBeInstanceOf(CompositeDispatchEligibility::class)
        ->and($gate->gateClasses())->toBe([LifecycleDispatchEligibility::class]);
});

it('appends configured gates after the suspension gate, without duplicating it', function (): void {
    config(['wa.dispatch.eligibility.gates' => [LifecycleDispatchEligibility::class, FakeRateCapGate::class]]);
    Dispatch::rebuild();

    $gate = app(DispatchEligibility::class);

    expect($gate)->toBeInstanceOf(CompositeDispatchEligibility::class)
        ->and($gate->gateClasses())->toBe([LifecycleDispatchEligibility::class, FakeRateCapGate::class]);
});

it('refuses a gate it cannot resolve rather than silently dropping a cap', function (): void {
    foreach ([['App\\Nope\\MissingGate'], [Tenant::class], [42]] as $gates) {
        config(['wa.dispatch.eligibility.gates' => $gates]);
        Dispatch::rebuild();

        expect(static fn () => app(DispatchEligibility::class))
            ->toThrow(InvalidDispatchGateException::class);
    }
});

it('answers no for a tenant that may not send, and yes for one that may', function (): void {
    $gate = app(LifecycleDispatchEligibility::class);

    expect($gate->canDispatch(Tenant::factory()->create()))->toBeTrue()
        ->and($gate->canDispatch(Tenant::factory()->trial()->create()))->toBeTrue()
        ->and($gate->canDispatch(Tenant::factory()->suspended()->create()))->toBeFalse()
        ->and($gate->canDispatch(Tenant::factory()->cancelled()->create()))->toBeFalse();
});

it('needs every gate in the chain to agree', function (): void {
    $tenant = Tenant::factory()->create();
    $chain = new CompositeDispatchEligibility([
        app(LifecycleDispatchEligibility::class),
        new FakeRateCapGate,
    ]);

    expect($chain->canDispatch($tenant))->toBeTrue()
        ->and($chain->count())->toBe(2);

    FakeRateCapGate::cap($tenant);

    expect($chain->canDispatch($tenant))->toBeFalse();
});
