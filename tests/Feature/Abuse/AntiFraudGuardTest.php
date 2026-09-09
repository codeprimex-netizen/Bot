<?php

declare(strict_types=1);

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;
use App\Exceptions\Security\AntiFraudBlockedException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Tenant;
use App\Services\Abuse\AntiFraudGuard;
use App\Services\Abuse\IdentityDigest;
use App\Services\Abuse\SignupAttempt;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Tests\Fixtures\Abuse;

/*
|--------------------------------------------------------------------------
| Anti-fraud: signup velocity, OTP, device/IP (Req 32.7 / NFR3)
|--------------------------------------------------------------------------
| design § Abuse / anti-fraud row 1 — free-trial farming. Four things have to be true at
| once, and each has its own group below:
|
|   1. the heuristics **count** and refuse (not trivially bypassable: `+tag` aliases, dot
|      aliases, and host-bit rotation all collapse onto one counter);
|   2. no block is permanent — every refusal carries the remainder of its window, and a
|      shared address recovers by itself;
|   3. every decision is **explainable from stored data** — counters, limits, and windows
|      land in `abuse_events`, identities do not;
|   4. the whole path runs with **no tenant bound** and writes `tenant_id = NULL`, without
|      raising `MissingTenantContextException`.
*/

beforeEach(function (): void {
    // The registration path is anonymous: nothing is bound, deliberately.
    app(TenantContext::class)->forget();

    config()->set('wa.security.anti_fraud.signup.identity', ['max' => 2, 'window_seconds' => 3600]);
    config()->set('wa.security.anti_fraud.signup.device', ['max' => 3, 'window_seconds' => 86400]);
    config()->set('wa.security.anti_fraud.signup.ip', ['max' => 4, 'window_seconds' => 3600]);
    config()->set('wa.security.anti_fraud.signup.subnet', ['max' => 6, 'window_seconds' => 3600]);
    config()->set('wa.security.anti_fraud.otp.request_identity', ['max' => 2, 'window_seconds' => 3600]);
    config()->set('wa.security.anti_fraud.otp.request_ip', ['max' => 10, 'window_seconds' => 3600]);
    config()->set('wa.security.anti_fraud.otp.failure', ['max' => 3, 'window_seconds' => 900]);

});

/**
 * The container's anti-fraud guard, rebuilt so it picks up the limits a test has set.
 */
function fraudGuard(): AntiFraudGuard
{
    return Abuse::antiFraud();
}

/**
 * A signup attempt with sensible defaults.
 */
function attempt(
    ?string $email = 'ada@example.com',
    ?string $phone = '919876543210',
    ?string $ip = '203.0.113.7',
    ?string $device = 'device-a',
): SignupAttempt {
    return new SignupAttempt($email, $phone, $ip, $device, 'Mozilla/5.0');
}

/*
|--------------------------------------------------------------------------
| 1. Counting and refusing
|--------------------------------------------------------------------------
*/

it('allows the first attempts and refuses the ones past the limit', function (): void {
    expect(fraudGuard()->inspectSignup(attempt())->isAllowed())->toBeTrue()
        ->and(fraudGuard()->inspectSignup(attempt())->isAllowed())->toBeTrue();

    $blocked = fraudGuard()->inspectSignup(attempt());

    expect($blocked->blocks())->toBeTrue()
        ->and($blocked->has(AbuseSignal::SignupIdentityVelocity))->toBeTrue();
});

it('counts attempts, not successes, so abandoning the form gives nothing back', function (): void {
    // Two inspections with no signup completed at all.
    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());

    expect(fraudGuard()->inspectSignup(attempt())->blocks())->toBeTrue();
});

it('collapses plus-tag and dot email aliases onto one counter', function (): void {
    fraudGuard()->inspectSignup(attempt(email: 'a.d.a+one@gmail.com', phone: null, device: 'd1'));
    fraudGuard()->inspectSignup(attempt(email: 'ada+two@gmail.com', phone: null, device: 'd2'));

    $blocked = fraudGuard()->inspectSignup(attempt(email: 'ada@gmail.com', phone: null, device: 'd3'));

    expect($blocked->has(AbuseSignal::SignupIdentityVelocity))->toBeTrue();
});

it('counts a rotating host bit against the same subnet', function (): void {
    // Fresh identity and device each time, so only the address counters can catch this.
    foreach (range(1, 6) as $index) {
        fraudGuard()->inspectSignup(attempt(
            email: "farmer{$index}@example.com",
            phone: "91987654321{$index}",
            ip: "203.0.113.{$index}",
            device: "device-{$index}",
        ));
    }

    $blocked = fraudGuard()->inspectSignup(attempt(
        email: 'farmer7@example.com',
        phone: '919876543217',
        ip: '203.0.113.99',
        device: 'device-7',
    ));

    expect($blocked->blocks())->toBeTrue()
        ->and($blocked->has(AbuseSignal::SignupSubnetVelocity))->toBeTrue();
});

it('counts a rotating identity against the same device', function (): void {
    foreach (range(1, 3) as $index) {
        fraudGuard()->inspectSignup(attempt(
            email: "farmer{$index}@example.com",
            phone: "91987654321{$index}",
            ip: "198.51.100.{$index}",
            device: 'one-browser',
        ));
    }

    $blocked = fraudGuard()->inspectSignup(attempt(
        email: 'farmer9@example.com',
        phone: '919876543219',
        ip: '198.51.100.9',
        device: 'one-browser',
    ));

    expect($blocked->has(AbuseSignal::SignupDeviceVelocity))->toBeTrue();
});

it('refuses a disposable email domain and says what will work instead', function (): void {
    $verdict = fraudGuard()->inspectSignup(attempt(email: 'throwaway@mailinator.com'));

    expect($verdict->blocks())->toBeTrue()
        ->and($verdict->has(AbuseSignal::DisposableEmailDomain))->toBeTrue()
        // "Try again later" would send a real customer away to wait for something that is
        // never going to change.
        ->and($verdict->explanation())->toContain('permanent email address');
});

it('throws a 429 with a Retry-After for callers that want an exception', function (): void {
    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());

    try {
        fraudGuard()->assertSignup(attempt());
        thisTest()->fail('expected the attempt to be refused');
    } catch (AntiFraudBlockedException $exception) {
        expect($exception->getStatusCode())->toBe(429)
            ->and((int) $exception->getHeaders()['Retry-After'])->toBeGreaterThan(0)
            ->and($exception->isRetryable())->toBeTrue()
            // No identity in the message, ever.
            ->and($exception->getMessage())->not->toContain('ada@example.com')
            ->and($exception->getMessage())->not->toContain('919876543210');
    }
});

/*
|--------------------------------------------------------------------------
| 2. Bounded, never permanent
|--------------------------------------------------------------------------
*/

it('lets a refused attempt through again once the window rolls', function (): void {
    // No device fingerprint, so the hour-long identity window is the one under test rather
    // than the day-long device one.
    fraudGuard()->inspectSignup(attempt(device: null));
    fraudGuard()->inspectSignup(attempt(device: null));

    $blocked = fraudGuard()->inspectSignup(attempt(device: null));
    expect($blocked->blocks())->toBeTrue();

    thisTest()->travel($blocked->retryAfterSeconds() + 1)->seconds();

    expect(Abuse::antiFraud()->inspectSignup(attempt(device: null))->isAllowed())->toBeTrue();
});

it('always reports a positive, bounded wait', function (): void {
    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());

    $blocked = fraudGuard()->inspectSignup(attempt());

    expect($blocked->retryAfterSeconds())->toBeGreaterThan(0)
        ->and($blocked->retryAfterSeconds())->toBeLessThanOrEqual(3600)
        ->and($blocked->explanation())->toContain('try again');
});

it('skips the address counters for an allowlisted shared egress', function (): void {
    config()->set('wa.security.anti_fraud.trusted_ips', ['203.0.113.']);

    $guard = Abuse::antiFraud();

    // Ten attempts from the same office range, each a different person.
    foreach (range(1, 10) as $index) {
        $verdict = $guard->inspectSignup(attempt(
            email: "colleague{$index}@example.com",
            phone: "91987654{$index}210",
            ip: '203.0.113.42',
            device: "laptop-{$index}",
        ));

        expect($verdict->isAllowed())->toBeTrue("attempt {$index} was refused");
    }

    // …and the allowlist is not an unlimited signup source: identity counting still holds.
    $guard->inspectSignup(attempt(ip: '203.0.113.42', device: 'laptop-x'));
    $guard->inspectSignup(attempt(ip: '203.0.113.42', device: 'laptop-x'));

    expect($guard->inspectSignup(attempt(ip: '203.0.113.42', device: 'laptop-x'))->blocks())->toBeTrue();
});

it('can have any counter switched off', function (): void {
    config()->set('wa.security.anti_fraud.signup.identity', ['max' => 0, 'window_seconds' => 3600]);

    $guard = Abuse::antiFraud();

    foreach (range(1, 3) as $ignored) {
        expect($guard->inspectSignup(attempt(ip: null, device: null))->isAllowed())->toBeTrue();
    }

    expect(Abuse::lastEvent())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 3. Explainable from stored data, and holding no identities
|--------------------------------------------------------------------------
*/

it('stores the counters, limits and windows the decision was made from', function (): void {
    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());

    $event = Abuse::lastEvent();
    $counters = $event?->evidence['counters'] ?? [];

    expect($event?->vector)->toBe(AbuseVector::SignupAbuse)
        ->and($event?->action)->toBe(GuardAction::Block)
        ->and($event?->surface)->toBe('signup')
        ->and($counters['signup.identity']['hits'] ?? null)->toBe(3)
        ->and($counters['signup.identity']['limit'] ?? null)->toBe(2)
        ->and($counters['signup.identity']['window_seconds'] ?? null)->toBe(3600)
        ->and($counters['signup.identity']['resets_in_seconds'] ?? null)->toBeGreaterThan(0)
        // Allowed counters are stored too: they are what explains the *next* refusal.
        ->and($counters['signup.ip']['hits'] ?? null)->toBe(3);
});

it('stores a keyed digest of the identity, never the identity', function (): void {
    fraudGuard()->inspectSignup(attempt(email: 'ada@example.com', phone: '919876543210'));
    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());

    $event = Abuse::lastEvent();
    $stored = json_encode(Abuse::rawRows());

    expect($event?->subject_hash)->toBe(app(IdentityDigest::class)->phone('919876543210'))
        ->and($stored)->not->toContain('919876543210')
        ->and($stored)->not->toContain('ada@example.com')
        ->and($stored)->not->toContain('device-a');
});

/*
|--------------------------------------------------------------------------
| 4. No tenant, no leak
|--------------------------------------------------------------------------
*/

it('runs with no tenant bound and attributes nothing to a tenant', function (): void {
    expect(app(TenantContext::class)->currentId())->toBeNull();

    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());
    fraudGuard()->inspectSignup(attempt());

    $event = Abuse::lastEvent();

    expect($event)->not->toBeNull()
        ->and($event->tenant_id)->toBeNull();
});

it('never raises MissingTenantContextException on the signup path', function (): void {
    $calls = [
        fn () => fraudGuard()->inspectSignup(attempt()),
        fn () => fraudGuard()->inspectOtpRequest(attempt()),
        fn () => fraudGuard()->recordOtpFailure(attempt()),
        fn () => fraudGuard()->clearOtpFailures(attempt()),
    ];

    foreach ($calls as $call) {
        try {
            $call();
        } catch (MissingTenantContextException $exception) {
            thisTest()->fail('the anonymous signup path must not require a tenant: '.$exception->getMessage());
        }
    }

    expect(true)->toBeTrue();
});

it('keeps a signup event invisible to every tenant', function (): void {
    fraudGuard()->inspectSignup(attempt(email: 'throwaway@mailinator.com'));

    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    expect(App\Models\AbuseEvent::query()->count())->toBe(0)
        ->and(App\Models\AbuseEvent::withoutTenantScope()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| OTP
|--------------------------------------------------------------------------
*/

it('limits how many one-time codes an identity can ask for', function (): void {
    expect(fraudGuard()->inspectOtpRequest(attempt())->isAllowed())->toBeTrue()
        ->and(fraudGuard()->inspectOtpRequest(attempt())->isAllowed())->toBeTrue();

    $blocked = fraudGuard()->inspectOtpRequest(attempt());

    expect($blocked->blocks())->toBeTrue()
        ->and($blocked->has(AbuseSignal::OtpRequestVelocity))->toBeTrue()
        ->and(Abuse::lastEvent()?->vector)->toBe(AbuseVector::OtpAbuse)
        ->and(Abuse::lastEvent()?->surface)->toBe('otp.request');
});

it('stops code guessing after the configured number of wrong codes', function (): void {
    // Three wrong codes are permitted (`otp.failure.max`), the fourth is refused.
    foreach (range(1, 3) as $ignored) {
        expect(fraudGuard()->recordOtpFailure(attempt())->isAllowed())->toBeTrue();
    }

    $blocked = fraudGuard()->recordOtpFailure(attempt());

    expect($blocked->blocks())->toBeTrue()
        ->and($blocked->has(AbuseSignal::OtpFailureVelocity))->toBeTrue()
        ->and($blocked->retryAfterSeconds())->toBeLessThanOrEqual(900)
        ->and(Abuse::lastEvent()?->surface)->toBe('otp.verify');
});

it('forgets the failure counter after a correct code', function (): void {
    fraudGuard()->recordOtpFailure(attempt());
    fraudGuard()->recordOtpFailure(attempt());

    // A customer who mistypes twice and then gets it right must not stay locked out for the
    // rest of the window.
    fraudGuard()->clearOtpFailures(attempt());

    expect(fraudGuard()->recordOtpFailure(attempt())->isAllowed())->toBeTrue();
});

it('counts wrong codes against the address when no identity was supplied', function (): void {
    $anonymous = new SignupAttempt(null, null, '198.51.100.20', null, null);

    foreach (range(1, 3) as $ignored) {
        fraudGuard()->recordOtpFailure($anonymous);
    }

    expect(fraudGuard()->recordOtpFailure($anonymous)->blocks())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Attempts with nothing to count
|--------------------------------------------------------------------------
*/

it('flags but never blocks an attempt with nothing to key a counter on', function (): void {
    // Console provisioning, imports, tests: no address, no fingerprint, no identity.
    $verdict = fraudGuard()->inspectSignup(new SignupAttempt);

    expect($verdict->permits())->toBeTrue()
        ->and($verdict->has(AbuseSignal::UncountableSignup))->toBeTrue()
        ->and(Abuse::lastEvent()?->action)->toBe(GuardAction::Flag);
});

it('builds an attempt from a request without trusting the body for the address', function (): void {
    $request = Request::create('/register', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.55',
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
    ]);

    $built = SignupAttempt::fromRequest($request, email: ' Ada@Example.com ', phone: '', deviceFingerprint: 'fp-1');

    expect($built->ip)->toBe('203.0.113.55')
        ->and($built->email)->toBe('Ada@Example.com')
        ->and($built->phone)->toBeNull()
        ->and($built->deviceFingerprint)->toBe('fp-1')
        ->and($built->userAgent)->toBe('Mozilla/5.0');
});
