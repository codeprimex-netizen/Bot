<?php

declare(strict_types=1);

use App\Enums\QuotaHoldStatus;
use App\Enums\QuotaKind;
use App\Enums\QuotaReason;
use App\Events\Tenancy\QuotaHoldResumed;
use App\Events\Tenancy\TenantQuotaExhausted;
use App\Events\Tenancy\TenantQuotaRestored;
use App\Models\Plan;
use App\Models\QuotaHold;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Services\Tenancy\QuotaHoldSubject;
use App\Services\Tenancy\QuotaVerdict;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Quota;
use Tests\Fixtures\QuotaHolds;

/*
|--------------------------------------------------------------------------
| QUOTA_PAUSED work: parked on a deferrable refusal, resumed when the allowance
| returns (Req 3.4 / A3, Req 20.3 / C3, Req 31.1 / NFR2)
|--------------------------------------------------------------------------
| Three guarantees are pinned here, and they are the three clauses of Req 20.3:
| work is *paused* rather than dropped, it *auto-resumes* at the next period reset
| **or** after an upgrade/top-up, and a refusal that time cannot fix is never
| auto-resumed. Req 3.4's "notify the tenant" is pinned alongside them — once per
| period, not once per job.
|
| `TenantApiToken` stands in for task 26.2's `campaigns` row wherever a test needs a
| real tenant-owned model to park; the `campaigns` table does not exist yet.
*/

beforeEach(function (): void {
    Event::fake([TenantQuotaExhausted::class, TenantQuotaRestored::class, QuotaHoldResumed::class]);
});

/**
 * A tenant whose monthly allowance is entirely spent, plus the deferring verdict.
 *
 * @return array{0: Tenant, 1: QuotaVerdict}
 */
function exhaustedTenant(int $limit = 5, string $timezone = 'UTC', QuotaKind $kind = QuotaKind::MessagesMonthly): array
{
    $tenant = Quota::tenant([$kind->value => $limit], timezone: $timezone);

    QuotaHolds::exhaust($tenant, $kind, $limit);

    $verdict = QuotaHolds::verdict($tenant, $kind);

    expect($verdict->isDeferred())->toBeTrue();

    return [$tenant, $verdict];
}

/*
|--------------------------------------------------------------------------
| Parking — a deferrable refusal, and only a deferrable one
|--------------------------------------------------------------------------
*/

it('parks work refused by a deferrable verdict and records what it is owed', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();

    $hold = QuotaHolds::parkingLot()->park(
        $tenant,
        $verdict,
        QuotaHoldSubject::named('campaign:42', resumer: 'campaign', payload: ['cursor' => 120], units: 3),
    );

    expect($hold)->toBeInstanceOf(QuotaHold::class)
        ->and($hold->status)->toBe(QuotaHoldStatus::QuotaPaused)
        ->and($hold->status->value)->toBe('QUOTA_PAUSED')          // the literal Req 20.3 names
        ->and($hold->reason)->toBe(QuotaReason::PeriodExhausted)
        ->and($hold->quota_kind)->toBe(QuotaKind::MessagesMonthly)
        ->and($hold->period_key)->toBe('2025-06')
        ->and($hold->units)->toBe(3)
        ->and($hold->resumer)->toBe('campaign')
        ->and($hold->payload)->toBe(['cursor' => 120])
        ->and($hold->tenant_id)->toBe($tenant->id)
        ->and($hold->pause_count)->toBe(1)
        // Owed a resume the moment the bucket rolls — taken from the verdict, not
        // re-derived here.
        ->and($hold->resume_at->toDateTimeString())->toBe('2025-07-01 00:00:00');
});

it('parks a row polymorphically so its owner can ask whether it is paused', function (): void {
    [$tenant, $verdict] = exhaustedTenant();
    $work = TenantApiToken::factory()->create(['tenant_id' => $tenant->id]);

    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::for($work, resumer: 'campaign'));

    expect($hold?->holdable_type)->toBe($work->getMorphClass())
        ->and($hold?->holdable_id)->toBe((string) $work->getKey())
        ->and($hold?->dedup_key)->toBe($work->getMorphClass().':'.$work->getKey());

    app(TenantContext::class)->runFor($tenant, function () use ($work, $hold): void {
        expect(QuotaHolds::parkingLot()->isParked($work))->toBeTrue()
            ->and(QuotaHolds::parkingLot()->heldFor($work)?->id)->toBe($hold?->id);
    });
});

it('parks nothing for a blocked refusal but still notifies the tenant', function (): void {
    // MESSAGES_MONTHLY is not priced at all: no reset will ever make it fit, so a hold
    // would promise a resume that is never coming (Req 3.4's block half).
    $tenant = Quota::tenant([QuotaKind::MessagesDaily->value => 10]);
    $verdict = QuotaHolds::verdict($tenant, QuotaKind::MessagesMonthly);

    expect($verdict->isBlocked())->toBeTrue();

    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:7', resumer: 'campaign'));

    expect($hold)->toBeNull()
        ->and(QuotaHolds::count())->toBe(0);

    Event::assertDispatched(
        TenantQuotaExhausted::class,
        fn (TenantQuotaExhausted $event): bool => $event->tenantId === $tenant->id
            && $event->reason === QuotaReason::NotPriced
            && $event->isTransient() === false
            && $event->holdId === null
            && $event->message === $verdict->explanation(),
    );
});

it('parks nothing when the verdict allows', function (): void {
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 10]);

    $hold = QuotaHolds::parkingLot()->park(
        $tenant,
        QuotaHolds::verdict($tenant, QuotaKind::MessagesMonthly),
        QuotaHoldSubject::named('campaign:1'),
    );

    expect($hold)->toBeNull()
        ->and(QuotaHolds::count())->toBe(0);

    Event::assertNotDispatched(TenantQuotaExhausted::class);
});

it('keeps one hold per unit of work however many jobs are refused', function (): void {
    [$tenant, $verdict] = exhaustedTenant();
    $subject = QuotaHoldSubject::named('campaign:42', resumer: 'campaign');

    $first = QuotaHolds::parkingLot()->park($tenant, $verdict, $subject);
    QuotaHolds::parkingLot()->park($tenant, $verdict, $subject);
    QuotaHolds::parkingLot()->park($tenant, $verdict, $subject);

    expect(QuotaHolds::count())->toBe(1)
        ->and(QuotaHolds::find($tenant, 'campaign:42')?->id)->toBe($first?->id)
        // Re-parking work that is already parked is not a new pause.
        ->and(QuotaHolds::find($tenant, 'campaign:42')?->pause_count)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Req 3.4 — the tenant is told, once
|--------------------------------------------------------------------------
*/

it('notifies the tenant once per quota per period, not once per parked job', function (): void {
    [$tenant, $verdict] = exhaustedTenant();

    foreach (range(1, 5) as $campaign) {
        QuotaHolds::parkingLot()->park(
            $tenant,
            $verdict,
            QuotaHoldSubject::named('campaign:'.$campaign, resumer: 'campaign'),
        );
    }

    expect(QuotaHolds::count())->toBe(5)
        ->and(QuotaHolds::noticeCount($tenant, QuotaKind::MessagesMonthly))->toBe(1)
        // Only the hold that caused the notice records it.
        ->and(QuotaHold::forTenant($tenant)->whereNotNull('notified_at')->count())->toBe(1);

    Event::assertDispatchedTimes(TenantQuotaExhausted::class, 1);

    Event::assertDispatched(
        TenantQuotaExhausted::class,
        fn (TenantQuotaExhausted $event): bool => $event->message === $verdict->explanation()
            && $event->isTransient()
            && $event->resumeSeconds === $verdict->secondsUntilPeriodReset(),
    );
});

it('tells two tenants separately about the same quota', function (): void {
    [$first] = exhaustedTenant();
    [$second] = exhaustedTenant();

    QuotaHolds::parkingLot()->park($first, QuotaHolds::verdict($first, QuotaKind::MessagesMonthly), QuotaHoldSubject::named('campaign:1'));
    QuotaHolds::parkingLot()->park($second, QuotaHolds::verdict($second, QuotaKind::MessagesMonthly), QuotaHoldSubject::named('campaign:1'));

    Event::assertDispatchedTimes(TenantQuotaExhausted::class, 2);

    expect(QuotaHolds::noticeCount($first, QuotaKind::MessagesMonthly))->toBe(1)
        ->and(QuotaHolds::noticeCount($second, QuotaKind::MessagesMonthly))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Resuming at the period reset
|--------------------------------------------------------------------------
*/

it('resumes parked work exactly when the period rolls, and not a second before', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');

    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    // One second before the month rolls: nothing is even claimed.
    Carbon::setTestNow('2025-06-30 23:59:59');

    $report = QuotaHolds::parkingLot()->resumeDue();

    expect($report->claimed())->toBe(0)
        ->and($report->resumed())->toBe(0)
        ->and($resumer->calls())->toBe(0)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::QuotaPaused);

    // One second after: the bucket is fresh, so the allowance covers the work again.
    Carbon::setTestNow('2025-07-01 00:00:01');

    $report = QuotaHolds::parkingLot()->resumeDue();

    expect($report->claimed())->toBe(1)
        ->and($report->resumed())->toBe(1)
        ->and($report->resumedHolds())->toBe([$hold?->id])
        ->and($resumer->calls())->toBe(1)
        ->and($resumer->tenantIds)->toBe([$tenant->id])   // handed back inside its own tenant
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed)
        ->and(QuotaHolds::fresh($hold)?->resumed_at)->not->toBeNull()
        ->and(QuotaHolds::fresh($hold)?->claimed_by)->toBeNull();

    Event::assertDispatched(
        QuotaHoldResumed::class,
        fn (QuotaHoldResumed $event): bool => $event->holdId === $hold?->id
            && $event->dedupKey === 'campaign:42'
            && $event->trigger === TenantQuotaRestored::TRIGGER_PERIOD_RESET,
    );

    Event::assertDispatched(
        TenantQuotaRestored::class,
        fn (TenantQuotaRestored $event): bool => $event->tenantId === $tenant->id
            && $event->trigger === TenantQuotaRestored::TRIGGER_PERIOD_RESET
            && $event->resumedHolds === 1,
    );
});

it('rolls a daily allowance at the tenant midnight, not at UTC midnight', function (): void {
    // 17:30 in Asia/Kolkata (UTC+5:30) on 10 June: the tenant's day ends at 18:30 UTC,
    // six hours before UTC's does.
    Carbon::setTestNow('2025-06-10 12:00:00');

    [$tenant, $verdict] = exhaustedTenant(2, 'Asia/Kolkata', QuotaKind::MessagesDaily);
    $resumer = QuotaHolds::registerResumer('campaign');

    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:tz', resumer: 'campaign'));

    expect($hold?->period_key)->toBe('2025-06-10')
        ->and($hold?->resume_at->toDateTimeString())->toBe('2025-06-10 18:30:00');

    Carbon::setTestNow('2025-06-10 18:00:00');

    expect(QuotaHolds::parkingLot()->resumeDue()->claimed())->toBe(0);

    Carbon::setTestNow('2025-06-10 18:31:00');

    // The tenant's day has rolled; UTC's has not — which is the whole point.
    expect(Quota::guard()->periodKeyFor($tenant, QuotaKind::MessagesDaily))->toBe('2025-06-11')
        ->and(Carbon::now('UTC')->format('Y-m-d'))->toBe('2025-06-10');

    $report = QuotaHolds::parkingLot()->resumeDue();

    expect($report->resumed())->toBe(1)
        ->and($resumer->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);
});

it('is safe to run every minute: repeated sweeps resume the same work once', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    Carbon::setTestNow('2025-07-01 00:01:00');

    expect(QuotaHolds::parkingLot()->resumeDue()->resumed())->toBe(1);

    // Four more ticks of the same minute-by-minute schedule.
    foreach (range(1, 4) as $tick) {
        Carbon::setTestNow('2025-07-01 00:0'.($tick + 1).':00');

        $report = QuotaHolds::parkingLot()->resumeDue();

        expect($report->claimed())->toBe(0)
            ->and($report->isEmpty())->toBeTrue();
    }

    expect($resumer->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);

    Event::assertDispatchedTimes(QuotaHoldResumed::class, 1);
    Event::assertDispatchedTimes(TenantQuotaRestored::class, 1);
});

it('keeps waiting when the fresh period is spent again before the sweep runs', function (): void {
    Carbon::setTestNow('2025-06-10 12:00:00');

    [$tenant, $verdict] = exhaustedTenant(2, 'UTC', QuotaKind::MessagesDaily);
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    // The day rolls — and the tenant immediately spends the new day's allowance on
    // something else before the sweep gets there.
    Carbon::setTestNow('2025-06-11 00:05:00');
    QuotaHolds::exhaust($tenant, QuotaKind::MessagesDaily, 2);

    $report = QuotaHolds::parkingLot()->resumeDue();

    expect($report->claimed())->toBe(1)
        ->and($report->deferred())->toBe(1)
        ->and($report->resumed())->toBe(0)
        ->and($resumer->calls())->toBe(0);

    $fresh = QuotaHolds::fresh($hold);

    // Still parked, now waiting for the *next* roll — and the recorded period moved with
    // it, so an operator sees which bucket it is waiting on.
    expect($fresh?->status)->toBe(QuotaHoldStatus::QuotaPaused)
        ->and($fresh?->period_key)->toBe('2025-06-11')
        ->and($fresh?->resume_at->toDateTimeString())->toBe('2025-06-12 00:00:00')
        ->and($fresh?->claimed_by)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Resuming after an upgrade / top-up, mid-period
|--------------------------------------------------------------------------
*/

it('resumes parked work as soon as the tenant moves to a bigger plan, mid-period', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    $bigger = Plan::factory()->withoutLimits()->withLimits([QuotaKind::MessagesMonthly->value => 500])->create();

    // The upgrade itself — no sweep, no clock change, still 15 June.
    $tenant->update(['plan_id' => $bigger->id]);

    expect($resumer->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);

    Event::assertDispatched(
        TenantQuotaRestored::class,
        fn (TenantQuotaRestored $event): bool => $event->trigger === TenantQuotaRestored::TRIGGER_PLAN_CHANGE
            && $event->tenantId === $tenant->id,
    );
});

it('pulls parked work forward when a plan limit is raised, and the sweep resumes it in-period', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    expect(QuotaHolds::fresh($hold)?->resume_at->toDateTimeString())->toBe('2025-07-01 00:00:00');

    // An admin raises the plan's limits. Thousands of tenants may be on it, so the edit
    // only pulls holds forward — it does not resume inline.
    Quota::repricePlan($tenant, [QuotaKind::MessagesMonthly->value => 500]);

    expect($resumer->calls())->toBe(0)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::QuotaPaused)
        ->and(QuotaHolds::fresh($hold)?->resume_at->toDateTimeString())->toBe('2025-06-15 12:00:00');

    $report = QuotaHolds::parkingLot()->resumeDue();

    expect($report->resumed())->toBe(1)
        ->and($resumer->calls())->toBe(1);

    // The allowance came back *inside* the period, so the sweep reports why correctly.
    Event::assertDispatched(
        TenantQuotaRestored::class,
        fn (TenantQuotaRestored $event): bool => $event->trigger === TenantQuotaRestored::TRIGGER_PLAN_CHANGE,
    );
});

it('exposes one seam for a wallet top-up to resume a single tenant', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    // Stand-in for task 10.4: the credit lands, the ceiling rises, and WalletService calls
    // the one seam below.
    Quota::repricePlan($tenant, [QuotaKind::MessagesMonthly->value => 50]);

    $report = QuotaHolds::parkingLot()->allowanceChanged($tenant, TenantQuotaRestored::TRIGGER_TOP_UP);

    expect($report->resumed())->toBe(1)
        ->and($resumer->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);

    Event::assertDispatched(
        TenantQuotaRestored::class,
        fn (TenantQuotaRestored $event): bool => $event->trigger === TenantQuotaRestored::TRIGGER_TOP_UP,
    );
});

it('leaves another tenant untouched when one tenant regains allowance', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$mine, $myVerdict] = exhaustedTenant();
    [$theirs, $theirVerdict] = exhaustedTenant();

    $resumer = QuotaHolds::registerResumer('campaign');

    $myHold = QuotaHolds::parkingLot()->park($mine, $myVerdict, QuotaHoldSubject::named('campaign:1', resumer: 'campaign'));
    $theirHold = QuotaHolds::parkingLot()->park($theirs, $theirVerdict, QuotaHoldSubject::named('campaign:1', resumer: 'campaign'));

    Quota::repricePlan($mine, [QuotaKind::MessagesMonthly->value => 500]);

    QuotaHolds::parkingLot()->allowanceChanged($mine);

    expect($resumer->resumed)->toBe([$myHold?->id])
        ->and(QuotaHolds::fresh($myHold)?->status)->toBe(QuotaHoldStatus::Resumed)
        ->and(QuotaHolds::fresh($theirHold)?->status)->toBe(QuotaHoldStatus::QuotaPaused);
});

/*
|--------------------------------------------------------------------------
| A refusal time cannot fix is never auto-resumed
|--------------------------------------------------------------------------
*/

it('never auto-resumes work whose refusal has stopped being transient', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    // A downgrade removes the quota from the plan entirely: NOT_PRICED, which blocks.
    Quota::repricePlan($tenant, [QuotaKind::MessagesDaily->value => 10]);

    // Even well past the period reset the clock alone changes nothing.
    Carbon::setTestNow('2025-08-01 00:00:00');

    $report = QuotaHolds::parkingLot()->resumeDue();

    $fresh = QuotaHolds::fresh($hold);

    expect($report->claimed())->toBe(1)
        ->and($report->blocked())->toBe(1)
        ->and($report->resumed())->toBe(0)
        ->and($resumer->calls())->toBe(0)
        // The work is still held — not dropped, not resumed — and the recorded reason now
        // says what the tenant has to do about it.
        ->and($fresh?->status)->toBe(QuotaHoldStatus::QuotaPaused)
        ->and($fresh?->reason)->toBe(QuotaReason::NotPriced)
        ->and($fresh?->reason->isTransient())->toBeFalse()
        ->and($fresh?->resume_at->isFuture())->toBeTrue();

    Event::assertNotDispatched(QuotaHoldResumed::class);
    Event::assertNotDispatched(TenantQuotaRestored::class);

    // ...and the tenant is told that waiting will not help.
    Event::assertDispatched(
        TenantQuotaExhausted::class,
        fn (TenantQuotaExhausted $event): bool => $event->reason === QuotaReason::NotPriced,
    );
});

it('resumes blocked work once a plan change makes it fit again', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    Quota::repricePlan($tenant, [QuotaKind::MessagesDaily->value => 10]);
    Carbon::setTestNow('2025-08-01 00:00:00');
    QuotaHolds::parkingLot()->resumeDue();

    expect(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::QuotaPaused);

    // The tenant upgrades: the hold is pulled forward and handed back.
    Quota::repricePlan($tenant, [QuotaKind::MessagesMonthly->value => 100]);

    expect(QuotaHolds::parkingLot()->resumeDue()->resumed())->toBe(1)
        ->and($resumer->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);
});

/*
|--------------------------------------------------------------------------
| Nothing is ever dropped
|--------------------------------------------------------------------------
*/

it('keeps work parked when its hand-back fails, and retries it later', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $broken = QuotaHolds::registerResumer('campaign', failWith: 'bridge unavailable');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    Carbon::setTestNow('2025-07-01 00:00:01');

    $report = QuotaHolds::parkingLot()->resumeDue();
    $fresh = QuotaHolds::fresh($hold);

    expect($report->failed())->toBe(1)
        ->and($report->resumed())->toBe(0)
        ->and($broken->calls())->toBe(1)
        ->and($fresh?->status)->toBe(QuotaHoldStatus::QuotaPaused)
        ->and($fresh?->isOpen())->toBeTrue()
        ->and($fresh?->resume_attempts)->toBe(1)
        ->and($fresh?->last_error)->toContain('bridge unavailable')
        // Backed off rather than retried on the next tick.
        ->and($fresh?->resume_at->toDateTimeString())->toBe('2025-07-01 00:05:01');

    Event::assertNotDispatched(TenantQuotaRestored::class);

    // The deployment is fixed; the work was never lost.
    $fixed = QuotaHolds::registerResumer('campaign');
    Carbon::setTestNow('2025-07-01 00:10:00');

    expect(QuotaHolds::parkingLot()->resumeDue()->resumed())->toBe(1)
        ->and($fixed->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);
});

it('keeps work parked when its resumer is not registered at all', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    config()->set('wa.tenancy.quota.holds.resumers', []);

    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    Carbon::setTestNow('2025-07-01 00:00:01');

    $report = QuotaHolds::parkingLot()->resumeDue();
    $fresh = QuotaHolds::fresh($hold);

    expect($report->failed())->toBe(1)
        ->and($fresh?->status)->toBe(QuotaHoldStatus::QuotaPaused)
        ->and($fresh?->last_error)->toContain('No quota resumer is registered under "campaign"');

    Event::assertNotDispatched(QuotaHoldResumed::class);
});

it('hands back a hold with no resumer through the event alone', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42'));

    Carbon::setTestNow('2025-07-01 00:00:01');

    expect(QuotaHolds::parkingLot()->resumeDue()->resumed())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);

    Event::assertDispatched(
        QuotaHoldResumed::class,
        fn (QuotaHoldResumed $event): bool => $event->holdId === $hold?->id && $event->dedupKey === 'campaign:42',
    );
});

it('accounts for every claimed hold, so no parked work can go missing', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    $resumer = QuotaHolds::registerResumer('campaign');

    // One that will resume, one whose plan stops pricing the quota, one whose hand-back
    // fails — all in the same sweep.
    [$resumable, $resumableVerdict] = exhaustedTenant();
    [$blocked, $blockedVerdict] = exhaustedTenant();

    QuotaHolds::parkingLot()->park($resumable, $resumableVerdict, QuotaHoldSubject::named('campaign:ok', resumer: 'campaign'));
    QuotaHolds::parkingLot()->park($blocked, $blockedVerdict, QuotaHoldSubject::named('campaign:blocked', resumer: 'campaign'));
    QuotaHolds::parkingLot()->park($blocked, $blockedVerdict, QuotaHoldSubject::named('campaign:no-resumer', resumer: 'missing'));

    Quota::repricePlan($blocked, [QuotaKind::MessagesDaily->value => 10]);

    Carbon::setTestNow('2025-07-01 00:00:01');

    $report = QuotaHolds::parkingLot()->resumeDue();
    $buckets = $report->resumed() + $report->deferred() + $report->blocked()
        + $report->failed() + $report->contended() + $report->orphaned();

    expect($report->claimed())->toBe(3)
        ->and($buckets)->toBe($report->claimed())
        ->and($report->resumed())->toBe(1)
        ->and($report->blocked())->toBe(2)
        // Nothing was deleted: every hold is still on the books, resumed or waiting.
        ->and(QuotaHolds::count())->toBe(3)
        ->and($resumer->calls())->toBe(1);
});

it('leaves a hold another worker is handing back alone, and retakes it only once that worker is presumed dead', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    $resumer = QuotaHolds::registerResumer('campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42', resumer: 'campaign'));

    Carbon::setTestNow('2025-07-01 00:00:01');

    // Another worker on another host claimed it a moment ago (this is exactly what its
    // compare-and-set writes).
    QuotaHold::withoutTenantScope()->whereKey($hold?->getKey())->update([
        'status' => QuotaHoldStatus::Resuming,
        'claimed_at' => now(),
        'claimed_by' => 'other-host:4242',
    ]);

    $report = QuotaHolds::parkingLot()->resumeDue();

    expect($report->claimed())->toBe(0)
        ->and($resumer->calls())->toBe(0)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resuming);

    // That worker never came back. Once its lease (300s) has expired the work is retaken
    // rather than left parked for ever.
    Carbon::setTestNow('2025-07-01 00:06:00');

    expect(QuotaHolds::parkingLot()->resumeDue()->resumed())->toBe(1)
        ->and($resumer->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);
});

it('cancels a hold only once, and only while it is open', function (): void {
    [$tenant, $verdict] = exhaustedTenant();
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:42'));

    expect($hold)->not->toBeNull();

    expect(QuotaHolds::parkingLot()->cancel($hold, 'campaign deleted'))->toBeTrue()
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Cancelled)
        ->and(QuotaHolds::fresh($hold)?->isOpen())->toBeFalse()
        // Cancelling twice is harmless and says so.
        ->and(QuotaHolds::parkingLot()->cancel($hold, 'campaign deleted'))->toBeFalse();
});

it('re-opens the same hold when the work is parked again in a later period', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    QuotaHolds::registerResumer('campaign');
    $subject = QuotaHoldSubject::named('campaign:42', resumer: 'campaign');
    $hold = QuotaHolds::parkingLot()->park($tenant, $verdict, $subject);

    Carbon::setTestNow('2025-07-01 00:00:01');
    QuotaHolds::parkingLot()->resumeDue();

    // July's allowance is spent too, and the same campaign is refused again.
    QuotaHolds::exhaust($tenant, QuotaKind::MessagesMonthly, 5);
    $july = QuotaHolds::verdict($tenant, QuotaKind::MessagesMonthly);
    QuotaHolds::parkingLot()->park($tenant, $july, $subject);

    $fresh = QuotaHolds::fresh($hold);

    expect(QuotaHolds::count())->toBe(1)
        ->and($fresh?->status)->toBe(QuotaHoldStatus::QuotaPaused)
        ->and($fresh?->period_key)->toBe('2025-07')
        ->and($fresh?->pause_count)->toBe(2)
        ->and($fresh?->resumed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Retention
|--------------------------------------------------------------------------
*/

it('prunes finished holds past their retention horizon and never open ones', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $verdict] = exhaustedTenant();
    QuotaHolds::registerResumer('campaign');

    $resumed = QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:done', resumer: 'campaign'));

    Carbon::setTestNow('2025-07-01 00:00:01');
    QuotaHolds::parkingLot()->resumeDue();

    // ...and July's allowance goes the same way, so a second campaign is parked and stays
    // parked.
    QuotaHolds::exhaust($tenant, QuotaKind::MessagesMonthly, 5);
    $stillPaused = QuotaHolds::parkingLot()->park(
        $tenant,
        QuotaHolds::verdict($tenant, QuotaKind::MessagesMonthly),
        QuotaHoldSubject::named('campaign:waiting', resumer: 'campaign'),
    );

    expect(QuotaHolds::fresh($resumed)?->status)->toBe(QuotaHoldStatus::Resumed)
        ->and(QuotaHolds::fresh($stillPaused)?->status)->toBe(QuotaHoldStatus::QuotaPaused);

    // Long past the 30-day retention default.
    Carbon::setTestNow('2025-09-01 00:00:00');

    expect(QuotaHolds::parkingLot()->prune())->toBe(1)
        ->and(QuotaHolds::fresh($resumed))->toBeNull()
        ->and(QuotaHolds::fresh($stillPaused))->not->toBeNull();
});
