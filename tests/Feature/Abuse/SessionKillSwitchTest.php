<?php

declare(strict_types=1);

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Exceptions\Security\SessionKilledException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Abuse\GuardContext;
use App\Services\Abuse\SessionKillSwitch;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Abuse;

/*
|--------------------------------------------------------------------------
| The per-session kill-switch (Req 32.7 / NFR3; design § Abuse: "kill-switch for
| offending sessions"; design § Admin Panel row 26)
|--------------------------------------------------------------------------
| A kill-switch nobody reads is decoration, so the tests are about the two properties that
| make it real: **a killed session is refused on the paths that matter**, and **the state
| is durable** — a cache flush must not quietly bring a stopped session back.
*/

beforeEach(function (): void {
    app(TenantContext::class)->set(Tenant::factory()->create());
});

/**
 * The container's kill-switch, rebuilt so it picks up config a test has just changed.
 */
function killSwitch(): SessionKillSwitch
{
    return Abuse::killSwitch();
}

it('refuses a killed session and records the attempt', function (): void {
    killSwitch()->kill('session-1', 'ToS: bulk unsolicited messaging');

    expect(killSwitch()->isKilled('session-1'))->toBeTrue();

    expect(fn () => killSwitch()->assertUsable('session-1'))
        ->toThrow(SessionKilledException::class);

    $attempt = collect(Abuse::events())->first(
        fn ($event): bool => in_array(AbuseSignal::SessionKilled, $event->signals(), true),
    );

    expect($attempt)->not->toBeNull()
        ->and($attempt->session_key)->toBe('session-1')
        ->and($attempt->vector)->toBe(AbuseVector::SessionRisk);
});

it('lets a live session through', function (): void {
    expect(killSwitch()->isKilled('session-live'))->toBeFalse();

    killSwitch()->assertUsable('session-live');

    expect(Abuse::events())->toBe([]);
});

it('survives losing the cache, because the record is in the table', function (): void {
    killSwitch()->kill('session-2', 'ToS review');

    // A cache-only kill-switch would fail *open* here — which for a control whose job is to
    // stop a session that is getting the tenant banned is the wrong direction.
    Cache::flush();

    expect(Abuse::killSwitch()->isKilled('session-2'))->toBeTrue();
});

it('releases after review and keeps both flips on the record', function (): void {
    killSwitch()->kill('session-3', 'suspected ToS breach');
    killSwitch()->release('session-3', 'reviewed: false positive');

    expect(killSwitch()->isKilled('session-3'))->toBeFalse();

    Cache::flush();

    expect(Abuse::killSwitch()->isKilled('session-3'))->toBeFalse();

    $history = collect(killSwitch()->history('session-3'))
        ->flatMap(fn ($event): array => $event->signals())
        ->all();

    expect($history)->toContain(AbuseSignal::KillSwitchEngaged)
        ->and($history)->toContain(AbuseSignal::KillSwitchReleased);
});

it('lifts a bounded kill by itself when it expires', function (): void {
    killSwitch()->kill('session-4', 'automatic', seconds: 60);

    expect(killSwitch()->isKilled('session-4'))->toBeTrue();

    thisTest()->travel(61)->seconds();

    expect(killSwitch()->isKilled('session-4'))->toBeFalse()
        ->and(Abuse::killSwitch()->isKilled('session-4'))->toBeFalse();
});

it('keeps an operator kill until it is released', function (): void {
    killSwitch()->kill('session-5', 'ToS: repeated complaints');

    thisTest()->travel(30)->days();

    expect(killSwitch()->isKilled('session-5'))->toBeTrue()
        ->and(killSwitch()->killedUntil('session-5'))->toBeNull();
});

it('writes the flip to the hash-chained audit trail as well', function (): void {
    killSwitch()->kill('session-6', 'ToS: bulk unsolicited messaging');
    killSwitch()->release('session-6', 'reviewed');

    $actions = AuditLog::query()->pluck('action')->all();

    expect($actions)->toContain('session.kill_switch.engaged')
        ->and($actions)->toContain('session.kill_switch.released');

    $engaged = AuditLog::query()->where('action', 'session.kill_switch.engaged')->first();

    // The session id is fingerprinted in the audit payload: it names a WhatsApp connection,
    // so the trail carries a digest rather than the credential-adjacent value.
    expect($engaged?->payload['session'] ?? null)->not->toBe('session-6')
        ->and($engaged?->payload['reason'] ?? null)->toBe('ToS: bulk unsolicited messaging');
});

it('throttles how often a repeated attempt is recorded, but never the refusal', function (): void {
    config()->set('wa.security.abuse.kill_switch.attempt_record_seconds', 300);

    $killSwitch = Abuse::killSwitch();
    $killSwitch->kill('session-7', 'ToS review');

    $refusals = 0;

    foreach (range(1, 5) as $ignored) {
        try {
            $killSwitch->assertUsable('session-7');
        } catch (SessionKilledException) {
            $refusals++;
        }
    }

    $attempts = collect(Abuse::events())->filter(
        fn ($event): bool => in_array(AbuseSignal::SessionKilled, $event->signals(), true),
    );

    expect($refusals)->toBe(5)
        ->and($attempts)->toHaveCount(1);

    // Past the throttle window the next attempt is recorded again.
    thisTest()->travel(301)->seconds();

    try {
        $killSwitch->assertUsable('session-7');
    } catch (SessionKilledException) {
        // expected
    }

    expect(collect(Abuse::events())->filter(
        fn ($event): bool => in_array(AbuseSignal::SessionKilled, $event->signals(), true),
    ))->toHaveCount(2);
});

it('is checked by the guardrail on every inbound inspection', function (): void {
    killSwitch()->kill('session-8', 'ToS review');

    $verdict = Abuse::guardrail()->inspectInput('hello, are you there?', GuardContext::forSession('session-8'));

    expect($verdict->blocks())->toBeTrue()
        ->and($verdict->has(AbuseSignal::SessionKilled))->toBeTrue();
});

it('is scoped per session, never platform-wide', function (): void {
    killSwitch()->kill('session-9', 'ToS review');

    expect(killSwitch()->isKilled('session-9'))->toBeTrue()
        ->and(killSwitch()->isKilled('session-10'))->toBeFalse();
});

it('ignores an empty session key rather than killing everything', function (): void {
    killSwitch()->kill('', 'nonsense');

    expect(killSwitch()->isKilled(''))->toBeFalse()
        ->and(Abuse::events())->toBe([]);
});
