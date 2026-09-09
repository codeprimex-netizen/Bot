<?php

declare(strict_types=1);

use App\Enums\BackoffShape;
use App\Enums\ErrorClass;
use App\Services\Reliability\RetryMatrix;
use App\Services\Reliability\RetryRule;

/*
|--------------------------------------------------------------------------
| The retry/backoff matrix as data (design.md § Error Handling; Req 31.1 / NFR2)
|--------------------------------------------------------------------------
| The shipped numbers, what config may change about them, and what it may not.
*/

function retryMatrix(): RetryMatrix
{
    return new RetryMatrix;
}

it('ships the attempt counts the design and the roadmap record', function (): void {
    $attempts = [];

    foreach (retryMatrix()->all() as $value => $rule) {
        $attempts[$value] = $rule->isUnlimited() ? 'until reset' : $rule->maxAttempts;
    }

    expect($attempts)->toBe([
        'AUTH' => 0,
        'RATE_LIMIT' => 8,          // design: "Yes (defer) | 8 | honor Retry-After else exp"
        'NOT_ON_WHATSAPP' => 0,     // ROADMAP Phase 23: NOT_ON_WHATSAPP 0
        'MEDIA' => 3,
        'NETWORK' => 5,             // design: transient network / 5xx = 5; ROADMAP: NETWORK 5
        'BRIDGE' => 5,              // ROADMAP: BRIDGE 5
        'PERMISSION' => 0,
        'VALIDATION' => 0,          // design: validation / 4xx = 0
        'TIMEOUT' => 3,             // design: timeout = 3, shorter cap
        'QUOTA' => 'until reset',   // design: "until reset", never dropped (Req 31.1)
        'CIRCUIT_OPEN' => 0,        // design: fast-fail, the fallback chain handles it
        'UNKNOWN' => 3,
    ]);
});

it('ships the design\'s base and cap, with a shorter cap for a timeout', function (): void {
    expect(retryMatrix()->baseMs())->toBe(250)
        ->and(retryMatrix()->capMs())->toBe(30_000)
        ->and(retryMatrix()->for(ErrorClass::Network)->baseMs)->toBe(250)
        ->and(retryMatrix()->for(ErrorClass::Network)->capMs)->toBe(30_000)
        // "shorter cap" — a call that already burned its timeout budget re-attempts sooner.
        ->and(retryMatrix()->for(ErrorClass::Timeout)->capMs)->toBe(5_000)
        // The engine's rate-limit "cool-down", expressed as a wider first window rather than
        // as a floor on the jitter, so the low edge of the window stays decorrelated.
        ->and(retryMatrix()->for(ErrorClass::RateLimit)->baseMs)->toBe(1_000);
});

it('lets config change a retryable budget', function (): void {
    config(['wa.reliability.retry.classes.NETWORK.attempts' => 9]);

    expect(retryMatrix()->for(ErrorClass::Network)->maxAttempts)->toBe(9)
        ->and(retryMatrix()->for(ErrorClass::Network)->isRetryable())->toBeTrue();
});

it('lets config take a budget away entirely, turning a class into a fail-fast', function (): void {
    config(['wa.reliability.retry.classes.BRIDGE.attempts' => 0]);

    $rule = retryMatrix()->for(ErrorClass::Bridge);

    expect($rule->maxAttempts)->toBe(0)
        ->and($rule->isRetryable())->toBeFalse()
        ->and($rule->allowsAnotherAttemptAfter(1))->toBeFalse();
});

it('refuses to let config make a non-retryable class retryable', function (): void {
    // The whole point of the asymmetry: config can make the platform more cautious, never
    // less. A plan-feature refusal, a suspended tenant, a number that is not on WhatsApp —
    // none of them become retryable because somebody typed a number.
    config([
        'wa.reliability.retry.classes.PERMISSION.attempts' => 5,
        'wa.reliability.retry.classes.VALIDATION.attempts' => 99,
        'wa.reliability.retry.classes.NOT_ON_WHATSAPP.attempts' => 3,
        'wa.reliability.retry.classes.AUTH.attempts' => null,
        'wa.reliability.retry.classes.CIRCUIT_OPEN.attempts' => 7,
    ]);

    foreach ([ErrorClass::Permission, ErrorClass::Validation, ErrorClass::NotOnWhatsApp, ErrorClass::Auth, ErrorClass::CircuitOpen] as $class) {
        $rule = retryMatrix()->for($class);

        expect($rule->maxAttempts)->toBe(0)
            ->and($rule->isRetryable())->toBeFalse()
            ->and($rule->backoff)->toBe(BackoffShape::None)
            ->and($rule->window(1))->toBe(0);
    }
});

it('treats a null budget as "until reset", which only quota uses by default', function (): void {
    $quota = retryMatrix()->for(ErrorClass::Quota);

    expect($quota->isUnlimited())->toBeTrue()
        ->and($quota->maxAttempts)->toBe(RetryRule::UNLIMITED_ATTEMPTS)
        ->and($quota->allowsAnotherAttemptAfter(10_000))->toBeTrue()
        ->and($quota->toArray()['max_attempts'])->toBeNull();
});

it('falls back to the documented default for a class config says nothing about', function (): void {
    config(['wa.reliability.retry.classes' => []]);

    expect(retryMatrix()->for(ErrorClass::Network)->maxAttempts)->toBe(RetryMatrix::DEFAULT_ATTEMPTS)
        ->and(retryMatrix()->for(ErrorClass::Unknown)->maxAttempts)->toBe(RetryMatrix::DEFAULT_ATTEMPTS);

    config(['wa.reliability.retry.default_attempts' => 6]);

    expect(retryMatrix()->for(ErrorClass::Network)->maxAttempts)->toBe(6);
});

it('degrades a malformed matrix instead of throwing inside a failure handler', function (): void {
    config([
        'wa.reliability.retry.base_ms' => 'not a number',
        'wa.reliability.retry.cap_ms' => -1,
        'wa.reliability.retry.default_attempts' => 'nonsense',
        'wa.reliability.retry.classes' => 'not an array',
        'wa.reliability.retry.default_class' => 'NOT_A_CLASS',
    ]);

    $rule = retryMatrix()->for(ErrorClass::Network);

    expect($rule->baseMs)->toBe(RetryMatrix::DEFAULT_BASE_MS)
        ->and($rule->capMs)->toBe(RetryMatrix::DEFAULT_CAP_MS)
        ->and($rule->maxAttempts)->toBe(RetryMatrix::DEFAULT_ATTEMPTS)
        ->and(retryMatrix()->defaultClass())->toBe(ErrorClass::Unknown);
});

it('clamps an absurd budget and a cap below the base', function (): void {
    config([
        'wa.reliability.retry.classes.NETWORK.attempts' => 10_000_000,
        'wa.reliability.retry.classes.BRIDGE.attempts' => -5,
        'wa.reliability.retry.classes.MEDIA.base_ms' => 4_000,
        'wa.reliability.retry.classes.MEDIA.cap_ms' => 100,
    ]);

    $media = retryMatrix()->for(ErrorClass::Media);

    expect(retryMatrix()->for(ErrorClass::Network)->maxAttempts)->toBe(RetryRule::MAX_CONFIGURABLE_ATTEMPTS)
        ->and(retryMatrix()->for(ErrorClass::Bridge)->maxAttempts)->toBe(0)
        // A cap under the base would quietly make the first window narrower than the base.
        ->and($media->capMs)->toBe(4_000)
        ->and($media->window(1))->toBe(4_000);
});

it('doubles the window per attempt and then flattens at the cap, without overflowing', function (): void {
    $rule = retryMatrix()->for(ErrorClass::Network);

    expect($rule->window(1))->toBe(250)
        ->and($rule->window(2))->toBe(500)
        ->and($rule->window(3))->toBe(1_000)
        ->and($rule->window(4))->toBe(2_000)
        ->and($rule->window(8))->toBe(30_000)      // 250·2^7 = 32 000, clamped
        ->and($rule->window(9))->toBe(30_000)
        // A caller passing a wild attempt number gets the cap, never a negative from an
        // integer overflow.
        ->and($rule->window(900))->toBe(30_000)
        ->and($rule->window(PHP_INT_MAX))->toBe(30_000)
        // Attempt numbers below 1 are read as the first attempt.
        ->and($rule->window(0))->toBe(250)
        ->and($rule->window(-7))->toBe(250);
});

it('lets config name the class an unclassified failure falls back to', function (): void {
    config(['wa.reliability.retry.default_class' => 'VALIDATION']);

    expect(retryMatrix()->defaultClass())->toBe(ErrorClass::Validation);
});
