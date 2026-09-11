<?php

declare(strict_types=1);

use App\Console\Commands\ResumeQuotaPausedWork;
use App\Enums\QuotaHoldStatus;
use App\Enums\QuotaKind;
use App\Events\Tenancy\QuotaHoldResumed;
use App\Events\Tenancy\TenantQuotaExhausted;
use App\Events\Tenancy\TenantQuotaRestored;
use App\Models\QuotaHold;
use App\Models\Tenant;
use App\Services\Tenancy\QuotaHoldSubject;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Quota;
use Tests\Fixtures\QuotaHolds;

/*
|--------------------------------------------------------------------------
| `wa:quota:resume-paused` — the period-reset sweep on the scheduler
|--------------------------------------------------------------------------
| The platform runs one cron entry (`schedule:run`, task 39.2), so what matters here
| is that the command is *on* the schedule, that a tick with nothing to do is cheap
| and silent, and that running it repeatedly is safe (Req 20.3 / C3, Req 31.1 / NFR2).
*/

beforeEach(function (): void {
    Event::fake([TenantQuotaExhausted::class, TenantQuotaRestored::class, QuotaHoldResumed::class]);
});

/**
 * A tenant with a spent monthly allowance and one campaign parked on it.
 *
 * @return array{0: Tenant, 1: QuotaHold}
 */
function parkedCampaign(string $dedupKey = 'campaign:42'): array
{
    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 5]);

    QuotaHolds::exhaust($tenant, QuotaKind::MessagesMonthly, 5);

    $hold = QuotaHolds::parkingLot()->park(
        $tenant,
        QuotaHolds::verdict($tenant, QuotaKind::MessagesMonthly),
        QuotaHoldSubject::named($dedupKey, resumer: 'campaign'),
    );

    expect($hold)->not->toBeNull();

    return [$tenant, $hold];
}

it('is on the scheduler every minute, guarded against overlap and duplicate servers', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'wa:quota:resume-paused'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('* * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('says nothing interesting when no parked work is due', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    parkedCampaign();

    thisTest()->artisan(ResumeQuotaPausedWork::class)
        ->expectsOutputToContain('No quota-paused work is due.')
        ->assertSuccessful();
});

it('resumes due work when the period has rolled', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [, $hold] = parkedCampaign();
    $resumer = QuotaHolds::registerResumer('campaign');

    Carbon::setTestNow('2025-07-01 00:00:30');

    thisTest()->artisan(ResumeQuotaPausedWork::class)
        ->expectsOutputToContain('resumed')
        ->assertSuccessful();

    expect($resumer->calls())->toBe(1)
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed);

    // Idempotent: the very next scheduled tick finds nothing to do.
    thisTest()->artisan(ResumeQuotaPausedWork::class)
        ->expectsOutputToContain('No quota-paused work is due.')
        ->assertSuccessful();

    expect($resumer->calls())->toBe(1);
});

it('sweeps a single tenant on demand, pulling its parked work forward', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [$tenant, $hold] = parkedCampaign();
    [, $otherHold] = parkedCampaign('campaign:99');
    $resumer = QuotaHolds::registerResumer('campaign');

    // The operator raised this tenant's allowance by hand (the manual equivalent of task
    // 10.4's top-up) and wants their campaigns moving now, not at the next reset.
    Quota::repricePlan($tenant, [QuotaKind::MessagesMonthly->value => 500]);

    thisTest()->artisan(ResumeQuotaPausedWork::class, ['--tenant' => $tenant->id])->assertSuccessful();

    expect($resumer->resumed)->toBe([$hold->id])
        ->and(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::Resumed)
        // The other tenant's work is untouched — a targeted sweep stays targeted.
        ->and(QuotaHolds::fresh($otherHold)?->status)->toBe(QuotaHoldStatus::QuotaPaused);
});

it('accepts a tenant by slug and refuses one that names nothing', function (): void {
    [$tenant] = parkedCampaign();

    thisTest()->artisan(ResumeQuotaPausedWork::class, ['--tenant' => $tenant->slug])->assertSuccessful();

    thisTest()->artisan(ResumeQuotaPausedWork::class, ['--tenant' => 'no-such-tenant'])
        ->expectsOutputToContain('No tenant matches "no-such-tenant".')
        ->assertExitCode(2);
});

it('refuses a limit that is not a positive integer', function (): void {
    thisTest()->artisan(ResumeQuotaPausedWork::class, ['--limit' => '0'])
        ->expectsOutputToContain('--limit must be a positive integer.')
        ->assertExitCode(2);
});

it('honours --limit so a backlog drains over several ticks', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => 5]);
    QuotaHolds::exhaust($tenant, QuotaKind::MessagesMonthly, 5);
    $verdict = QuotaHolds::verdict($tenant, QuotaKind::MessagesMonthly);

    foreach (range(1, 3) as $campaign) {
        QuotaHolds::parkingLot()->park($tenant, $verdict, QuotaHoldSubject::named('campaign:'.$campaign, resumer: 'campaign'));
    }

    $resumer = QuotaHolds::registerResumer('campaign');

    Carbon::setTestNow('2025-07-01 00:00:30');

    thisTest()->artisan(ResumeQuotaPausedWork::class, ['--limit' => '2'])->assertSuccessful();

    expect($resumer->calls())->toBe(2);

    thisTest()->artisan(ResumeQuotaPausedWork::class, ['--limit' => '2'])->assertSuccessful();

    expect($resumer->calls())->toBe(3)
        ->and(QuotaHolds::forTenant($tenant)->every(
            fn ($hold): bool => $hold->status === QuotaHoldStatus::Resumed,
        ))->toBeTrue();
});

it('prunes finished holds as part of the sweep, unless told not to', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [, $hold] = parkedCampaign();
    QuotaHolds::registerResumer('campaign');

    Carbon::setTestNow('2025-07-01 00:00:30');
    thisTest()->artisan(ResumeQuotaPausedWork::class)->assertSuccessful();

    Carbon::setTestNow('2025-09-01 00:00:00');

    thisTest()->artisan(ResumeQuotaPausedWork::class, ['--no-prune' => true])->assertSuccessful();

    expect(QuotaHolds::fresh($hold))->not->toBeNull();

    thisTest()->artisan(ResumeQuotaPausedWork::class)->assertSuccessful();

    expect(QuotaHolds::fresh($hold))->toBeNull();
});

it('reports a failed hand-back without failing the scheduled run', function (): void {
    Carbon::setTestNow('2025-06-15 12:00:00');

    [, $hold] = parkedCampaign();
    QuotaHolds::registerResumer('campaign', failWith: 'bridge unavailable');

    Carbon::setTestNow('2025-07-01 00:00:30');

    thisTest()->artisan(ResumeQuotaPausedWork::class)
        ->expectsOutputToContain('could not be handed back and stay paused')
        // Exit 0 on purpose: the work is retried, so this is not a scheduler failure.
        ->assertSuccessful();

    expect(QuotaHolds::fresh($hold)?->status)->toBe(QuotaHoldStatus::QuotaPaused);
});
