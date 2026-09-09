<?php

declare(strict_types=1);

use App\Enums\BackoffShape;
use App\Enums\ErrorClass;
use App\Enums\RetryDisposition;

/*
|--------------------------------------------------------------------------
| The error taxonomy (design.md § Error Handling; ROADMAP Phase 23)
|--------------------------------------------------------------------------
| No config, no container: these are the *structural* halves of the retry matrix — the
| parts `wa.reliability.retry` is deliberately unable to change.
*/

it('carries the classification of the design and the roadmap, and nothing else', function (): void {
    // ROADMAP Phase 23's nine, plus the three rows design.md's matrix needs that none of
    // them can express (its own budget for a timeout, a period-measured quota defer, and a
    // circuit-open fast-fail that hands over to the fallback chain).
    expect(ErrorClass::values())->toBe([
        'AUTH',
        'RATE_LIMIT',
        'NOT_ON_WHATSAPP',
        'MEDIA',
        'NETWORK',
        'BRIDGE',
        'PERMISSION',
        'VALIDATION',
        'TIMEOUT',
        'QUOTA',
        'CIRCUIT_OPEN',
        'UNKNOWN',
    ]);
});

it('splits the classes into exactly the three dispositions the design describes', function (): void {
    $byDisposition = [
        RetryDisposition::Retry->value => [],
        RetryDisposition::Defer->value => [],
        RetryDisposition::FailFast->value => [],
    ];

    foreach (ErrorClass::cases() as $class) {
        $byDisposition[$class->disposition()->value][] = $class->value;
    }

    expect($byDisposition[RetryDisposition::Retry->value])
        ->toBe(['MEDIA', 'NETWORK', 'BRIDGE', 'TIMEOUT', 'UNKNOWN'])
        // Both defers wait for somebody else's clock: a provider's Retry-After, a plan period.
        ->and($byDisposition[RetryDisposition::Defer->value])->toBe(['RATE_LIMIT', 'QUOTA'])
        // Nothing about waiting changes any of these answers.
        ->and($byDisposition[RetryDisposition::FailFast->value])
        ->toBe(['AUTH', 'NOT_ON_WHATSAPP', 'PERMISSION', 'VALIDATION', 'CIRCUIT_OPEN']);
});

it('gives every class exactly one backoff shape, and only a fail-fast class gets none', function (): void {
    foreach (ErrorClass::cases() as $class) {
        $hasNoBackoff = $class->backoff() === BackoffShape::None;

        expect($hasNoBackoff)->toBe($class->disposition()->isFailFast())
            ->and($class->isRetryableByNature())->toBe(! $hasNoBackoff)
            ->and($class->label())->not->toBe('');
    }
});

it('honours an external wait for exactly the two deferring classes', function (): void {
    $honouring = array_values(array_filter(
        ErrorClass::cases(),
        static fn (ErrorClass $class): bool => $class->backoff()->honoursRetryAfter(),
    ));

    expect($honouring)->toBe([ErrorClass::RateLimit, ErrorClass::Quota])
        ->and(ErrorClass::RateLimit->backoff())->toBe(BackoffShape::RetryAfterElseExponential)
        ->and(ErrorClass::Quota->backoff())->toBe(BackoffShape::PeriodReset)
        ->and(ErrorClass::Quota->isDeferrable())->toBeTrue()
        // A jittered class computes its own wait and must not be pinned to a provider's.
        ->and(ErrorClass::Network->backoff()->usesJitter())->toBeTrue()
        ->and(ErrorClass::Network->backoff()->honoursRetryAfter())->toBeFalse();
});

it('reads a class name tolerantly but never guesses one', function (): void {
    expect(ErrorClass::tryFromName('network'))->toBe(ErrorClass::Network)
        ->and(ErrorClass::tryFromName('  Rate_Limit '))->toBe(ErrorClass::RateLimit)
        ->and(ErrorClass::tryFromName('almost-network'))->toBeNull()
        ->and(ErrorClass::tryFromName(''))->toBeNull()
        ->and(ErrorClass::tryFromName(null))->toBeNull();
});

it('keeps work on a retry or a defer, and ends it only on a fail-fast', function (): void {
    // Mirrors QuotaOutcome::keepsWork(): the same question about the same job.
    expect(RetryDisposition::Retry->keepsWork())->toBeTrue()
        ->and(RetryDisposition::Defer->keepsWork())->toBeTrue()
        ->and(RetryDisposition::FailFast->keepsWork())->toBeFalse()
        ->and(RetryDisposition::values())->toBe(['RETRY', 'DEFER', 'FAIL_FAST'])
        ->and(BackoffShape::values())->toBe([
            'EXPONENTIAL_FULL_JITTER',
            'RETRY_AFTER_ELSE_EXPONENTIAL',
            'PERIOD_RESET',
            'NONE',
        ]);
});
