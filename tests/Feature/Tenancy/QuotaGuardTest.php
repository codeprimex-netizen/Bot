<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Enums\QuotaOutcome;
use App\Enums\QuotaReason;
use App\Exceptions\Tenancy\QuotaExceededException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Services\Tenancy\QuotaGuard;
use App\Services\Tenancy\QuotaVerdict;
use Illuminate\Support\Carbon;
use Tests\Fixtures\Quota;

/*
|--------------------------------------------------------------------------
| QuotaGuard — plan allowances checked and spent in one place (Req 3.4, 3.5 / A3)
|--------------------------------------------------------------------------
| Three things are pinned here: the defer/block rule Req 3.4 asks for, the
| consume-once guarantee Req 3.5 asks for, and the bucketing that decides *which*
| counter a unit lands in. Property 4's interleaving argument is in
| `QuotaPropertyTest`.
*/

/*
|--------------------------------------------------------------------------
| verdict() — where the ceiling comes from
|--------------------------------------------------------------------------
*/

it('allows a request that fits and reports the numbers behind the decision', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);

    $verdict = Quota::guard()->verdict($tenant, QuotaKind::MessagesMonthly, 10);

    expect($verdict->outcome())->toBe(QuotaOutcome::Allow)
        ->and($verdict->reason)->toBe(QuotaReason::WithinAllowance)
        ->and($verdict->used)->toBe(0)
        ->and($verdict->limit)->toBe(100)
        ->and($verdict->remaining())->toBe(100)
        ->and($verdict->shortfall())->toBe(0)
        ->and($verdict->periodKey)->toBe(Carbon::now('UTC')->format('Y-m'));
});

it('treats an explicit null limit as unlimited and an omitted kind as zero', function (): void {
    $unlimited = Quota::tenant([QuotaKind::MessagesMonthly->value => null]);
    $omitted = Quota::tenant([QuotaKind::MessagesDaily->value => 10]);   // MESSAGES_MONTHLY not mentioned
    $zero = Quota::tenant([QuotaKind::MessagesMonthly->value => 0]);

    $guard = Quota::guard();

    expect($guard->verdict($unlimited, QuotaKind::MessagesMonthly)->reason)->toBe(QuotaReason::Unlimited)
        ->and($guard->verdict($unlimited, QuotaKind::MessagesMonthly)->isUnlimited())->toBeTrue()
        ->and($guard->remaining($unlimited, QuotaKind::MessagesMonthly))->toBe(QuotaVerdict::UNLIMITED)
        // Absence grants nothing: only an explicit null is unlimited (PlanLimits).
        ->and($guard->verdict($omitted, QuotaKind::MessagesMonthly)->reason)->toBe(QuotaReason::NotPriced)
        ->and($guard->verdict($omitted, QuotaKind::MessagesMonthly)->isBlocked())->toBeTrue()
        ->and($guard->remaining($omitted, QuotaKind::MessagesMonthly))->toBe(0)
        // A deliberate 0 is told apart from an omission, and blocks just the same.
        ->and($guard->verdict($zero, QuotaKind::MessagesMonthly)->reason)->toBe(QuotaReason::MeteredToZero)
        ->and($guard->verdict($zero, QuotaKind::MessagesMonthly)->isBlocked())->toBeTrue();
});

it('grants no allowance at all to a tenant on no plan', function (): void {
    $tenant = Quota::tenantWithoutPlan();

    $verdict = Quota::guard()->verdict($tenant, QuotaKind::MessagesMonthly);

    expect($verdict->reason)->toBe(QuotaReason::NoPlan)
        ->and($verdict->isBlocked())->toBeTrue()
        ->and(Quota::guard()->remaining($tenant, QuotaKind::MessagesMonthly))->toBe(0);
});

it('fails closed when the plan limits cannot be read', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);

    // Written past the observer's validation, the way a bad import or a hand-edited row
    // arrives; the guard must refuse rather than guess an allowance.
    Plan::query()->whereKey($tenant->plan_id)->update(['limits' => json_encode(['MESSAGES_MONTHLY' => 'lots'])]);
    app(App\Services\Billing\PlanRepository::class)->flush();

    expect(Quota::guard()->verdict($tenant->fresh() ?? $tenant, QuotaKind::MessagesMonthly)->reason)
        ->toBe(QuotaReason::PlanUnreadable);
});

/*
|--------------------------------------------------------------------------
| Req 3.4 — defer or block, never drop
|--------------------------------------------------------------------------
*/

it('defers an exhausted period quota until the period resets', function (): void {
    Carbon::setTestNow('2025-06-14 12:00:00');

    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 5]);
    TenantUsage::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::MessagesMonthly,
        'period_key' => '2025-06',
        'used' => 5,
        'limit' => 5,
    ]);

    $verdict = Quota::guard()->verdict($tenant, QuotaKind::MessagesMonthly);

    expect($verdict->outcome())->toBe(QuotaOutcome::Defer)
        ->and($verdict->reason)->toBe(QuotaReason::PeriodExhausted)
        ->and($verdict->reason->isTransient())->toBeTrue()
        ->and($verdict->outcome()->keepsWork())->toBeTrue()
        // 2025-07-01 00:00 UTC is 16 days and 12 hours away.
        ->and($verdict->secondsUntilPeriodReset())->toBe(((16 * 24) + 12) * 3600)
        ->and($verdict->explanation())->toContain('16 days');

    Carbon::setTestNow();
});

it('blocks a request larger than a whole period allowance rather than deferring it for ever', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);

    $verdict = Quota::guard()->verdict($tenant, QuotaKind::MessagesMonthly, 250);

    expect($verdict->reason)->toBe(QuotaReason::ExceedsPeriodLimit)
        ->and($verdict->isBlocked())->toBeTrue()
        ->and($verdict->secondsUntilPeriodReset())->toBe(0)
        ->and($verdict->shortfall())->toBe(150);
});

it('blocks a gauge at capacity, because its bucket never rolls over', function (): void {
    $tenant = Quota::tenant([QuotaKind::Sessions->value => 2]);
    Quota::guard()->observe($tenant, QuotaKind::Sessions, 2);

    $verdict = Quota::guard()->verdict($tenant, QuotaKind::Sessions);

    expect($verdict->reason)->toBe(QuotaReason::GaugeAtCapacity)
        ->and($verdict->isBlocked())->toBeTrue()
        ->and($verdict->secondsUntilPeriodReset())->toBe(0)
        ->and($verdict->periodKey)->toBe(QuotaKind::GAUGE_PERIOD_KEY);
});

it('raises a 429 with a Retry-After only when waiting would help', function (): void {
    $exhausted = Quota::tenant([QuotaKind::MessagesMonthly->value => 1]);
    Quota::guard()->consume($exhausted, QuotaKind::MessagesMonthly, 'msg-1');

    $unpriced = Quota::tenant([QuotaKind::MessagesDaily->value => 5]);

    $deferred = null;
    $blocked = null;

    try {
        Quota::guard()->authorize($exhausted, QuotaKind::MessagesMonthly);
    } catch (QuotaExceededException $e) {
        $deferred = $e;
    }

    try {
        Quota::guard()->authorize($unpriced, QuotaKind::MessagesMonthly);
    } catch (QuotaExceededException $e) {
        $blocked = $e;
    }

    expect($deferred)->toBeInstanceOf(QuotaExceededException::class)
        ->and($deferred?->getStatusCode())->toBe(429)
        ->and($deferred?->isDeferrable())->toBeTrue()
        ->and($deferred?->retryAfterSeconds())->toBeGreaterThan(0)
        ->and($deferred?->getHeaders())->toHaveKey('Retry-After')
        ->and($deferred?->publicMessage())->toContain('Messages per month')
        ->and($blocked)->toBeInstanceOf(QuotaExceededException::class)
        ->and($blocked?->isDeferrable())->toBeFalse()
        ->and($blocked?->retryAfterSeconds())->toBeNull()
        ->and($blocked?->getHeaders())->toBe([])
        ->and($blocked?->suggestsUpgrade())->toBeTrue();
});

it('lets an allowed request through authorize untouched', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 10]);

    expect(Quota::guard()->authorize($tenant, QuotaKind::MessagesMonthly, 3)->isAllowed())->toBeTrue()
        ->and(Quota::bucketCount())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| verdict() writes nothing
|--------------------------------------------------------------------------
*/

it('never writes anything when merely asked', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 1]);

    foreach (range(1, 5) as $ignored) {
        Quota::guard()->verdict($tenant, QuotaKind::MessagesMonthly, 1);
        Quota::guard()->remaining($tenant, QuotaKind::MessagesMonthly);
    }

    expect(Quota::bucketCount())->toBe(0)
        ->and(Quota::ledgerCount($tenant, QuotaKind::MessagesMonthly))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Req 3.5 — consume once, keyed by the idempotency key
|--------------------------------------------------------------------------
*/

it('counts confirmed work and stamps the plan ceiling on the bucket it creates', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);

    $receipt = Quota::guard()->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 3);

    expect($receipt->applied)->toBe(3)
        ->and($receipt->used)->toBe(3)
        ->and($receipt->isReplay())->toBeFalse()
        ->and($receipt->wasCapped())->toBeFalse()
        ->and($receipt->remaining())->toBe(97)
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(3)
        ->and(Quota::stampedLimit($tenant, QuotaKind::MessagesMonthly))->toBe(100)
        ->and(Quota::ledgerCount($tenant, QuotaKind::MessagesMonthly))->toBe(1);
});

it('is a no-op on a retry with the same idempotency key, and returns the original outcome', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $guard = Quota::guard();

    $first = $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 4);
    $retry = $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 4);
    $thirdAttempt = $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 4);

    expect($first->isReplay())->toBeFalse()
        ->and($retry->isReplay())->toBeTrue()
        ->and($retry->applied)->toBe($first->applied)
        ->and($retry->used)->toBe($first->used)
        ->and($retry->limit)->toBe(100)
        ->and($thirdAttempt->isReplay())->toBeTrue()
        // The counter moved exactly once, however many attempts there were.
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(4)
        ->and(Quota::ledgerCount($tenant, QuotaKind::MessagesMonthly))->toBe(1);
});

it('counts each distinct unit of work once', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $guard = Quota::guard();

    foreach (['a', 'b', 'c'] as $key) {
        $guard->consume($tenant, QuotaKind::MessagesMonthly, $key, 2);
        $guard->consume($tenant, QuotaKind::MessagesMonthly, $key, 2);   // every send is retried once
    }

    expect(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(6)
        ->and(Quota::ledgerCount($tenant, QuotaKind::MessagesMonthly))->toBe(3);
});

it('keeps one tenant\'s idempotency keys out of another\'s counter', function (): void {
    $acme = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $globex = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $guard = Quota::guard();

    // The same natural key on both tenants — an upstream id collision must not make one
    // tenant's send look like a duplicate of the other's.
    $guard->consume($acme, QuotaKind::MessagesMonthly, 'shared-key', 5);
    $globexReceipt = $guard->consume($globex, QuotaKind::MessagesMonthly, 'shared-key', 5);

    expect($globexReceipt->isReplay())->toBeFalse()
        ->and(Quota::used($acme, QuotaKind::MessagesMonthly))->toBe(5)
        ->and(Quota::used($globex, QuotaKind::MessagesMonthly))->toBe(5);
});

it('records what fits and reports the rest rather than pushing a counter past the ceiling', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 5]);
    $guard = Quota::guard();

    $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 4);
    $overflow = $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-2', 3);

    expect($overflow->requested)->toBe(3)
        ->and($overflow->applied)->toBe(1)
        ->and($overflow->wasCapped())->toBeTrue()
        ->and($overflow->refused())->toBe(2)
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(5);
});

it('lets a plan that sells overage spend past its ceiling, and says so', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 5], [QuotaGuard::OVERAGE_FEATURE => true]);
    $guard = Quota::guard();

    $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 5);

    expect($guard->verdict($tenant, QuotaKind::MessagesMonthly)->reason)->toBe(QuotaReason::Overage)
        ->and($guard->verdict($tenant, QuotaKind::MessagesMonthly)->isAllowed())->toBeTrue();

    $extra = $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-2', 3);

    expect($extra->applied)->toBe(3)
        ->and($extra->wasCapped())->toBeFalse()
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(8);
});

it('still consumes exactly once when the cache store supports no locks', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $guard = Quota::locklessGuard();

    $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 7);
    $replay = $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 7);

    expect($replay->isReplay())->toBeTrue()
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(7);
});

it('refuses a consume that could not be deduplicated or is not a spend', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100, QuotaKind::Sessions->value => 5]);
    $guard = Quota::guard();

    expect(fn () => $guard->consume($tenant, QuotaKind::MessagesMonthly, '   ', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->verdict($tenant, QuotaKind::MessagesMonthly, -1))->toThrow(InvalidArgumentException::class)
        // A gauge is measured, not accrued.
        ->and(fn () => $guard->consume($tenant, QuotaKind::Sessions, 'session-1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->observe($tenant, QuotaKind::MessagesMonthly, 3))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guard->observe($tenant, QuotaKind::Sessions, -1))->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Bucketing: monthly, daily, gauge — in the tenant's timezone
|--------------------------------------------------------------------------
*/

it('buckets each kind on its own period key', function (): void {
    Carbon::setTestNow('2025-06-14 09:00:00');

    $tenant = Quota::tenant([
        QuotaKind::MessagesMonthly->value => 100,
        QuotaKind::MessagesDaily->value => 10,
        QuotaKind::AiCredits->value => 50,
        QuotaKind::Contacts->value => 20,
    ]);
    $guard = Quota::guard();

    expect($guard->periodKeyFor($tenant, QuotaKind::MessagesMonthly))->toBe('2025-06')
        ->and($guard->periodKeyFor($tenant, QuotaKind::MessagesDaily))->toBe('2025-06-14')
        ->and($guard->periodKeyFor($tenant, QuotaKind::AiCredits))->toBe('2025-06')
        ->and($guard->periodKeyFor($tenant, QuotaKind::Contacts))->toBe(QuotaKind::GAUGE_PERIOD_KEY);

    $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1');
    $guard->consume($tenant, QuotaKind::MessagesDaily, 'msg-1');

    // Same message, two counters, two buckets — and the shared natural key is not
    // mistaken for a duplicate, because the dedup scope names the kind.
    expect(Quota::used($tenant, QuotaKind::MessagesMonthly, '2025-06'))->toBe(1)
        ->and(Quota::used($tenant, QuotaKind::MessagesDaily, '2025-06-14'))->toBe(1);

    Carbon::setTestNow();
});

it('rolls a daily bucket at the tenant\'s local midnight, not at UTC midnight', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesDaily->value => 1], [], 'Asia/Kolkata');
    $guard = Quota::guard();

    // 18:45 UTC on the 14th is already 00:15 on the 15th in Kolkata (UTC+5:30).
    Carbon::setTestNow('2025-06-14 17:00:00');
    expect($guard->periodKeyFor($tenant, QuotaKind::MessagesDaily))->toBe('2025-06-14');

    $guard->consume($tenant, QuotaKind::MessagesDaily, 'msg-1');
    expect($guard->verdict($tenant, QuotaKind::MessagesDaily)->isDeferred())->toBeTrue()
        // …and the wait is until *local* midnight: 22:30 IST -> 00:00 is 1h30m.
        ->and($guard->verdict($tenant, QuotaKind::MessagesDaily)->secondsUntilPeriodReset())->toBe(90 * 60);

    Carbon::setTestNow('2025-06-14 18:45:00');

    expect($guard->periodKeyFor($tenant, QuotaKind::MessagesDaily))->toBe('2025-06-15')
        // A fresh local day is a fresh bucket, so the tenant may send again…
        ->and($guard->verdict($tenant, QuotaKind::MessagesDaily)->isAllowed())->toBeTrue()
        // …but yesterday's counter is untouched.
        ->and(Quota::used($tenant, QuotaKind::MessagesDaily, '2025-06-14'))->toBe(1)
        ->and(Quota::used($tenant, QuotaKind::MessagesDaily, '2025-06-15'))->toBe(0);

    Carbon::setTestNow();
});

it('does not consume a second time when a retry crosses a period boundary', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesDaily->value => 10]);
    $guard = Quota::guard();

    Carbon::setTestNow('2025-06-14 23:59:00');
    $guard->consume($tenant, QuotaKind::MessagesDaily, 'msg-1', 2);

    // The queue retries the job four minutes later, in a brand-new bucket. The dedup
    // scope deliberately excludes the period key, so this is still the same send.
    Carbon::setTestNow('2025-06-15 00:03:00');
    $retry = $guard->consume($tenant, QuotaKind::MessagesDaily, 'msg-1', 2);

    expect($retry->isReplay())->toBeTrue()
        ->and($retry->periodKey)->toBe('2025-06-14')
        ->and(Quota::used($tenant, QuotaKind::MessagesDaily, '2025-06-14'))->toBe(2)
        ->and(Quota::bucket($tenant, QuotaKind::MessagesDaily, '2025-06-15'))->toBeNull();

    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Gauges are measured, not accrued
|--------------------------------------------------------------------------
*/

it('meters a gauge by recount, so it can go down and cannot drift', function (): void {
    $tenant = Quota::tenant([QuotaKind::Sessions->value => 5]);
    $guard = Quota::guard();

    $guard->observe($tenant, QuotaKind::Sessions, 3);

    expect(Quota::used($tenant, QuotaKind::Sessions))->toBe(3)
        ->and($guard->remaining($tenant, QuotaKind::Sessions))->toBe(2);

    // Idempotent: the same recount twice is the same as once (no key needed).
    $guard->observe($tenant, QuotaKind::Sessions, 3);
    expect(Quota::used($tenant, QuotaKind::Sessions))->toBe(3);

    // A deletion lowers it — the thing an increment-only counter could never do.
    $guard->observe($tenant, QuotaKind::Sessions, 1);
    expect(Quota::used($tenant, QuotaKind::Sessions))->toBe(1)
        ->and($guard->remaining($tenant, QuotaKind::Sessions))->toBe(4)
        // One standing bucket throughout.
        ->and(Quota::bucketCount())->toBe(1);
});

it('keeps a gauge honest when a downgrade leaves the tenant over its new limit', function (): void {
    $tenant = Quota::tenant([QuotaKind::Contacts->value => 20]);
    $guard = Quota::guard();

    $guard->observe($tenant, QuotaKind::Contacts, 12);
    Quota::repricePlan($tenant, [QuotaKind::Contacts->value => 10]);

    $measured = $guard->observe($tenant, QuotaKind::Contacts, 12);

    // The count is a measurement: it is not clamped to the new ceiling, because the
    // tenant has to be able to see 12 of 10 to know what to delete.
    expect($measured->used)->toBe(12)
        ->and(Quota::used($tenant, QuotaKind::Contacts))->toBe(12)
        ->and(Quota::stampedLimit($tenant, QuotaKind::Contacts))->toBe(10)
        ->and($guard->verdict($tenant, QuotaKind::Contacts)->reason)->toBe(QuotaReason::GaugeAtCapacity)
        ->and($guard->remaining($tenant, QuotaKind::Contacts))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A ceiling that changes mid-period
|--------------------------------------------------------------------------
*/

it('applies a mid-period upgrade immediately and re-stamps the bucket', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 5]);
    $guard = Quota::guard();

    $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 5);
    expect($guard->verdict($tenant, QuotaKind::MessagesMonthly)->isDeferred())->toBeTrue();

    Quota::repricePlan($tenant, [QuotaKind::MessagesMonthly->value => 50]);

    expect($guard->verdict($tenant, QuotaKind::MessagesMonthly)->isAllowed())->toBeTrue()
        ->and($guard->verdict($tenant, QuotaKind::MessagesMonthly)->limit)->toBe(50)
        // The plan is authoritative from the moment it changes; the stamped value on the
        // row catches up on the next write.
        ->and(Quota::stampedLimit($tenant, QuotaKind::MessagesMonthly))->toBe(5);

    $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-2', 2);

    expect(Quota::stampedLimit($tenant, QuotaKind::MessagesMonthly))->toBe(50)
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(7);
});

it('never rewrites usage when a downgrade lands mid-period', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $guard = Quota::guard();

    $guard->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 30);
    Quota::repricePlan($tenant, [QuotaKind::MessagesMonthly->value => 10]);

    $verdict = $guard->verdict($tenant, QuotaKind::MessagesMonthly);

    expect(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(30)
        ->and($verdict->limit)->toBe(10)
        ->and($verdict->remaining())->toBe(0)
        // Deferred, not billed backwards: the spend stands and the tenant waits for the
        // period to roll.
        ->and($verdict->reason)->toBe(QuotaReason::PeriodExhausted);
});

it('stamps an unlimited ceiling as a sentinel rather than as zero', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => null]);

    $receipt = Quota::guard()->consume($tenant, QuotaKind::MessagesMonthly, 'msg-1', 9);

    expect($receipt->limit)->toBeNull()
        ->and($receipt->remaining())->toBe(QuotaVerdict::UNLIMITED)
        ->and(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(9)
        ->and(Quota::stampedLimit($tenant, QuotaKind::MessagesMonthly))->toBe(QuotaVerdict::UNLIMITED)
        // …so a bucket on an unlimited plan does not read as exhausted.
        ->and(Quota::bucket($tenant, QuotaKind::MessagesMonthly)?->isExhausted())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------
*/

it('meters the tenant it was handed, whatever context happens to be bound', function (): void {
    $acme = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $globex = Quota::tenant([QuotaKind::MessagesMonthly->value => 100]);
    $guard = Quota::guard();

    app(App\Services\Tenancy\TenantContext::class)->set($acme);

    $guard->consume($globex, QuotaKind::MessagesMonthly, 'msg-1', 6);

    expect(Quota::used($globex, QuotaKind::MessagesMonthly))->toBe(6)
        ->and(Quota::used($acme, QuotaKind::MessagesMonthly))->toBe(0)
        // …and the context it was called in is put back.
        ->and(app(App\Services\Tenancy\TenantContext::class)->currentId())->toBe($acme->id);
});

it('counts a bucket per tenant even when the plan is shared', function (): void {
    $plan = Plan::factory()->withoutLimits()->withLimits([QuotaKind::MessagesMonthly->value => 10])->create();
    $acme = Tenant::factory()->for($plan)->create();
    $globex = Tenant::factory()->for($plan)->create();
    $guard = Quota::guard();

    $guard->consume($acme, QuotaKind::MessagesMonthly, 'msg-1', 10);

    expect($guard->verdict($acme, QuotaKind::MessagesMonthly)->isDeferred())->toBeTrue()
        ->and($guard->verdict($globex, QuotaKind::MessagesMonthly)->isAllowed())->toBeTrue()
        ->and(Quota::used($globex, QuotaKind::MessagesMonthly))->toBe(0);
});
