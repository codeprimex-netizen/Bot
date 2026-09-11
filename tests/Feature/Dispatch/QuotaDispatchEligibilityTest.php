<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Services\Dispatch\DispatchEligibility;
use App\Services\Dispatch\DispatchLane;
use App\Services\Dispatch\Eligibility\LifecycleDispatchEligibility;
use App\Services\Dispatch\Eligibility\QuotaDispatchEligibility;
use Tests\Fixtures\Dispatch;
use Tests\Fixtures\Quota;

/*
|--------------------------------------------------------------------------
| Quota-aware dispatch (Req 3.4 / A3; Req 30.6 / NFR1)
|--------------------------------------------------------------------------
| A tenant with no allowance left is skipped in the dispatch loop rather than granted a
| share it would only waste. What is pinned here is that the gate is wired by default,
| that it reads and never spends, and that it fails closed.
*/

beforeEach(function (): void {
    Dispatch::rebuild();
});

it('is part of the shipped eligibility chain, after the mandatory suspension gate', function (): void {
    expect(app(DispatchEligibility::class)->gateClasses())
        ->toBe([LifecycleDispatchEligibility::class, QuotaDispatchEligibility::class]);
});

it('consults the message counters by default', function (): void {
    expect(app(QuotaDispatchEligibility::class)->kinds())
        ->toBe([QuotaKind::MessagesMonthly, QuotaKind::MessagesDaily]);
});

it('dispatches a tenant with allowance and skips one without', function (): void {
    $gate = app(QuotaDispatchEligibility::class);

    $spare = Quota::tenant([QuotaKind::MessagesMonthly->value => 10, QuotaKind::MessagesDaily->value => 10]);
    $exhausted = Quota::tenant([QuotaKind::MessagesMonthly->value => 2, QuotaKind::MessagesDaily->value => 10]);
    Quota::guard()->consume($exhausted, QuotaKind::MessagesMonthly, 'msg-1', 2);

    expect($gate->canDispatch($spare))->toBeTrue()
        ->and($gate->canDispatch($exhausted))->toBeFalse();
});

it('needs every configured kind to allow, so a spent daily cap parks a tenant with monthly left', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 1_000, QuotaKind::MessagesDaily->value => 3]);
    $gate = app(QuotaDispatchEligibility::class);

    expect($gate->canDispatch($tenant))->toBeTrue();

    Quota::guard()->consume($tenant, QuotaKind::MessagesDaily, 'msg-1', 3);

    expect($gate->canDispatch($tenant))->toBeFalse();
});

it('skips a tenant whose plan prices nothing, and one with no plan at all', function (): void {
    $gate = app(QuotaDispatchEligibility::class);

    expect($gate->canDispatch(Quota::tenant([])))->toBeFalse()
        ->and($gate->canDispatch(Quota::tenantWithoutPlan()))->toBeFalse();
});

it('lets an unlimited tenant through', function (): void {
    expect(app(QuotaDispatchEligibility::class)->canDispatch(Quota::tenant([
        QuotaKind::MessagesMonthly->value => null,
        QuotaKind::MessagesDaily->value => null,
    ])))->toBeTrue();
});

it('never consumes: the scheduler asks speculatively', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 5, QuotaKind::MessagesDaily->value => 5]);
    $gate = app(QuotaDispatchEligibility::class);

    foreach (range(1, 20) as $ignored) {
        $gate->canDispatch($tenant);
    }

    expect(Quota::bucketCount())->toBe(0)
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(0)
        ->and(Quota::ledgerCount($tenant, QuotaKind::MessagesMonthly))->toBe(0);
});

it('takes a quota-capped tenant out of the rotation without failing its work', function (): void {
    $spare = Quota::tenant([QuotaKind::MessagesMonthly->value => null, QuotaKind::MessagesDaily->value => null]);
    $capped = Quota::tenant([QuotaKind::MessagesMonthly->value => 1, QuotaKind::MessagesDaily->value => null]);
    Quota::guard()->consume($capped, QuotaKind::MessagesMonthly, 'msg-1');

    $result = Dispatch::window([DispatchLane::for($spare, 20), DispatchLane::for($capped, 20)], 10);

    expect($result->unitsFor($spare))->toBe(10)
        ->and($result->unitsFor($capped))->toBe(0)
        ->and($result->skipped)->toContain($capped->id)
        // Skipped, not drained: the capped tenant's backlog is untouched and is picked up
        // again the moment the period rolls.
        ->and($result->total())->toBe(10);
});

it('honours an empty configured kind list as "do not pace dispatch by quota"', function (): void {
    config(['wa.dispatch.eligibility.quota.kinds' => []]);

    $tenant = Quota::tenantWithoutPlan();

    expect(app(QuotaDispatchEligibility::class)->kinds())->toBe([])
        ->and(app(QuotaDispatchEligibility::class)->canDispatch($tenant))->toBeTrue();
});

it('ignores a kind it cannot resolve instead of stalling dispatch on a config typo', function (): void {
    config(['wa.dispatch.eligibility.quota.kinds' => ['MESSAGES_MONTLY', QuotaKind::MessagesDaily->value, 42]]);

    expect(app(QuotaDispatchEligibility::class)->kinds())->toBe([QuotaKind::MessagesDaily]);
});
