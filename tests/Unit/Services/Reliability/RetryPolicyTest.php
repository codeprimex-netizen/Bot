<?php

declare(strict_types=1);

use App\Enums\ErrorClass;
use App\Enums\PlanFeature;
use App\Enums\RetryDisposition;
use App\Enums\TenantStatus;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Exceptions\Tenancy\TenantNotOperationalException;
use App\Services\Reliability\CompositeErrorClassifier;
use App\Services\Reliability\ErrorClassifier;
use App\Services\Reliability\RetryDecision;
use App\Services\Reliability\RetryMatrix;
use App\Services\Reliability\RetryPolicy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Reliability\FakeProviderErrorClassifier;
use Tests\Fixtures\Reliability\FakeProviderException;
use Tests\Fixtures\Reliability\Retries;

/*
|--------------------------------------------------------------------------
| Exponential backoff with full jitter (design.md § Error Handling; Req 31.1 / NFR2)
|--------------------------------------------------------------------------
| base 250ms, `delay = rand(0, min(cap, base·2^n))`, cap 30s — and the rule that no path
| through a decision may ever silently discard the work.
*/

/**
 * The policy as the container builds it, optionally over a classifier the caller can
 * register its own provider errors on.
 */
function retryPolicy(?ErrorClassifier $classifier = null): RetryPolicy
{
    return new RetryPolicy($classifier ?? new CompositeErrorClassifier(app()), new RetryMatrix);
}

/*
|--------------------------------------------------------------------------
| The jitter
|--------------------------------------------------------------------------
*/

it('draws every delay from inside the full-jitter window, and never past the cap', function (): void {
    $jittered = array_values(array_filter(
        ErrorClass::cases(),
        static fn (ErrorClass $class): bool => $class->backoff()->usesJitter(),
    ));

    expect($jittered)->not->toBeEmpty();

    foreach ($jittered as $class) {
        $rule = retryPolicy()->rule($class);

        // Well past the attempt at which the window reaches the cap, so the flat tail of the
        // series is covered too.
        for ($attempt = 1; $attempt <= 14; $attempt++) {
            $window = $rule->window($attempt);

            for ($i = 0; $i < 40; $i++) {
                $delay = retryPolicy()->delay($attempt, $class);

                expect($delay)->toBeGreaterThanOrEqual(0)
                    ->and($delay)->toBeLessThanOrEqual($window)
                    ->and($delay)->toBeLessThanOrEqual($rule->capMs);
            }
        }
    }
});

it('actually randomises the delay instead of always waiting the same', function (): void {
    // The whole point of full jitter: N workers that failed together must not wake together.
    // At attempt 8 the window is the full 30s cap, so 60 draws colliding is impossible in
    // practice unless the multiplier is fixed.
    $draws = [];

    for ($i = 0; $i < 60; $i++) {
        $draws[] = retryPolicy()->delay(8, ErrorClass::Network);
    }

    $window = retryPolicy()->rule(ErrorClass::Network)->window(8);

    expect(count(array_unique($draws)))->toBeGreaterThan(30)
        ->and(max($draws))->toBeLessThanOrEqual($window)
        ->and(min($draws))->toBeGreaterThanOrEqual(0)
        // Drawn from the *whole* window, not from a narrow band at the top of it.
        ->and(min($draws))->toBeLessThan((int) ($window / 2));
});

it('grows the window exponentially from the design\'s base', function (): void {
    // The bound is what is asserted (the value inside it is random by design), across enough
    // draws that a wrong exponent would show up as a bound violation.
    foreach ([1 => 250, 2 => 500, 3 => 1_000, 4 => 2_000, 5 => 4_000] as $attempt => $expectedWindow) {
        expect(retryPolicy()->rule(ErrorClass::Network)->window($attempt))->toBe($expectedWindow);

        for ($i = 0; $i < 25; $i++) {
            expect(retryPolicy()->delay($attempt, ErrorClass::Network))->toBeLessThanOrEqual($expectedWindow);
        }
    }
});

it('waits for nothing at all on a class that is never retried', function (): void {
    foreach ([ErrorClass::Auth, ErrorClass::Permission, ErrorClass::Validation, ErrorClass::NotOnWhatsApp, ErrorClass::CircuitOpen] as $class) {
        expect(retryPolicy()->delay(1, $class))->toBe(0)
            ->and(retryPolicy()->delay(9, $class))->toBe(0)
            ->and(retryPolicy()->shouldRetry(1, $class))->toBeFalse()
            ->and(retryPolicy()->backoffSeconds($class))->toBe([]);
    }
});

it('re-checks an unexplained quota defer at the cap rather than spinning', function (): void {
    // A defer with no wait to honour must still be a wait: a zero-delay release is a worker
    // burning a lane on a full quota.
    expect(retryPolicy()->delay(1, ErrorClass::Quota))->toBe(30_000)
        ->and(retryPolicy()->delay(7, ErrorClass::Quota))->toBe(30_000);
});

/*
|--------------------------------------------------------------------------
| The matrix drives the budget
|--------------------------------------------------------------------------
*/

it('retries a class exactly as many times as the matrix allows', function (): void {
    // NETWORK ships 5 total attempts: attempts 1-4 may be followed by another, 5 may not.
    expect(retryPolicy()->shouldRetry(1, ErrorClass::Network))->toBeTrue()
        ->and(retryPolicy()->shouldRetry(4, ErrorClass::Network))->toBeTrue()
        ->and(retryPolicy()->shouldRetry(5, ErrorClass::Network))->toBeFalse()
        ->and(retryPolicy()->shouldRetry(50, ErrorClass::Network))->toBeFalse()
        // TIMEOUT ships 3.
        ->and(retryPolicy()->shouldRetry(2, ErrorClass::Timeout))->toBeTrue()
        ->and(retryPolicy()->shouldRetry(3, ErrorClass::Timeout))->toBeFalse()
        // QUOTA waits as long as it must (Req 31.1: never dropped).
        ->and(retryPolicy()->shouldRetry(1_000, ErrorClass::Quota))->toBeTrue();
});

it('follows a reconfigured budget', function (): void {
    config(['wa.reliability.retry.classes.NETWORK.attempts' => 2]);

    $decision = retryPolicy()->decideFor(ErrorClass::Network, 2);

    expect(retryPolicy()->shouldRetry(1, ErrorClass::Network))->toBeTrue()
        ->and(retryPolicy()->shouldRetry(2, ErrorClass::Network))->toBeFalse()
        ->and($decision->shouldRetry)->toBeFalse()
        ->and($decision->reason)->toBe(RetryDecision::REASON_ATTEMPTS_EXHAUSTED)
        ->and($decision->maxAttempts)->toBe(2);
});

it('follows a reconfigured window', function (): void {
    config([
        'wa.reliability.retry.base_ms' => 1_000,
        'wa.reliability.retry.cap_ms' => 4_000,
    ]);

    $rule = retryPolicy()->rule(ErrorClass::Bridge);

    expect($rule->window(1))->toBe(1_000)
        ->and($rule->window(3))->toBe(4_000)
        ->and(retryPolicy()->delay(9, ErrorClass::Bridge))->toBeLessThanOrEqual(4_000);
});

it('exposes the matrix for operators', function (): void {
    $matrix = retryPolicy()->matrix();

    expect(array_keys($matrix))->toBe(ErrorClass::values())
        ->and($matrix['NETWORK']->toArray())->toBe([
            'err_class' => 'NETWORK',
            'disposition' => 'RETRY',
            'backoff' => 'EXPONENTIAL_FULL_JITTER',
            'retryable' => true,
            'max_attempts' => 5,
            'base_ms' => 250,
            'cap_ms' => 30_000,
        ]);
});

/*
|--------------------------------------------------------------------------
| decide(): what a job or the relay acts on
|--------------------------------------------------------------------------
*/

it('keeps the work on a retryable failure, with a bounded jittered wait', function (): void {
    $decision = retryPolicy()->decide(new HttpException(502, 'bad gateway'), 2);

    expect($decision->class)->toBe(ErrorClass::Network)
        ->and($decision->disposition)->toBe(RetryDisposition::Retry)
        ->and($decision->shouldRetry)->toBeTrue()
        ->and($decision->keepsWork())->toBeTrue()
        ->and($decision->mustFailExplicitly())->toBeFalse()
        ->and($decision->attempt)->toBe(2)
        ->and($decision->maxAttempts)->toBe(5)
        ->and($decision->reason)->toBe(RetryDecision::REASON_BACKOFF)
        ->and($decision->delayMs)->toBeLessThanOrEqual(500)
        // release() takes seconds and rounds up, so a sub-second window still waits.
        ->and($decision->delaySeconds())->toBeLessThanOrEqual(1);
});

it('honours a deferrable quota refusal\'s own Retry-After instead of inventing a delay', function (): void {
    $decision = retryPolicy()->decide(Retries::deferrableQuota(900), 1);

    expect($decision->class)->toBe(ErrorClass::Quota)
        ->and($decision->disposition)->toBe(RetryDisposition::Defer)
        ->and($decision->isDeferred())->toBeTrue()
        ->and($decision->shouldRetry)->toBeTrue()
        // The exact period reset — not the 30s cap, and not a jittered fraction of it.
        ->and($decision->delayMs)->toBe(900_000)
        ->and($decision->delaySeconds())->toBe(900)
        ->and($decision->reason)->toBe(RetryDecision::REASON_PERIOD_RESET)
        // No budget: a campaign parked until the monthly allowance rolls is normal operation.
        ->and($decision->maxAttempts)->toBeNull();

    // …and it keeps honouring it however many times the job comes round.
    $late = retryPolicy()->decide(Retries::deferrableQuota(43_200), 97);

    expect($late->shouldRetry)->toBeTrue()
        ->and($late->delayMs)->toBe(43_200_000)
        ->and($late->attempt)->toBe(97);
});

it('blocks a quota refusal that no reset will clear, rather than releasing it for ever', function (): void {
    $decision = retryPolicy()->decide(Retries::blockedQuota(), 1);

    expect($decision->class)->toBe(ErrorClass::Quota)
        ->and($decision->shouldRetry)->toBeFalse()
        ->and($decision->mustFailExplicitly())->toBeTrue()
        ->and($decision->reason)->toBe(RetryDecision::REASON_BLOCKED)
        ->and($decision->delayMs)->toBe(0)
        ->and($decision->delaySeconds())->toBe(0)
        // Req 31.1's other half: blocked *explicitly*, which is not the same as dropped.
        ->and($decision->describe())->toContain('recorded');
});

it('never retries a suspended tenant or a plan-feature refusal, at any attempt', function (): void {
    $blocked = [
        TenantNotOperationalException::outboundBlocked('tenant-1', TenantStatus::Suspended),
        TenantNotOperationalException::outboundBlocked('tenant-1', TenantStatus::Cancelled),
        FeatureNotInPlanException::upgradeRequired(PlanFeature::Ai, 'tenant-1', ['pro']),
        FeatureNotInPlanException::notAvailable(PlanFeature::Stt, 'tenant-1'),
    ];

    foreach ($blocked as $exception) {
        foreach ([1, 2, 7, 500] as $attempt) {
            $decision = retryPolicy()->decide($exception, $attempt);

            expect($decision->class)->toBe(ErrorClass::Permission)
                ->and($decision->shouldRetry)->toBeFalse()
                ->and($decision->mustFailExplicitly())->toBeTrue()
                ->and($decision->isExhausted())->toBeFalse()
                ->and($decision->reason)->toBe(RetryDecision::REASON_NOT_RETRYABLE)
                ->and($decision->delayMs)->toBe(0);
        }
    }
});

it('honours a provider\'s numeric Retry-After on a 429, and ignores a nonsense one', function (): void {
    $decision = retryPolicy()->decide(new HttpException(429, 'slow down', null, ['Retry-After' => '47']), 1);

    expect($decision->class)->toBe(ErrorClass::RateLimit)
        ->and($decision->disposition)->toBe(RetryDisposition::Defer)
        ->and($decision->delayMs)->toBe(47_000)
        ->and($decision->reason)->toBe(RetryDecision::REASON_RETRY_AFTER)
        ->and($decision->maxAttempts)->toBe(8);

    // An HTTP-date Retry-After is ignored rather than parsed against a clock we do not trust,
    // so the class falls back to its own (wider) jittered window.
    $dated = retryPolicy()->decide(new HttpException(429, 'slow down', null, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']), 1);

    expect($dated->shouldRetry)->toBeTrue()
        ->and($dated->reason)->toBe(RetryDecision::REASON_BACKOFF)
        ->and($dated->delayMs)->toBeLessThanOrEqual(1_000);

    // And an absurd one is clamped rather than parking the job past anybody's attention span.
    $absurd = retryPolicy()->decide(new HttpException(429, 'slow down', null, ['Retry-After' => '999999999']), 1);

    expect($absurd->delayMs)->toBe(RetryPolicy::MAX_HONOURED_SECONDS * 1000);
});

it('ignores a Retry-After on a class that computes its own wait', function (): void {
    // A fixed 30s from a 503, obeyed by every worker at once, is precisely the synchronised
    // herd full jitter exists to prevent — so this one is jittered, not honoured.
    $decision = retryPolicy()->decide(new HttpException(503, 'unavailable', null, ['Retry-After' => '30']), 1);

    expect($decision->class)->toBe(ErrorClass::Network)
        ->and($decision->disposition)->toBe(RetryDisposition::Retry)
        ->and($decision->delayMs)->toBeLessThanOrEqual(250);
});

it('gives up explicitly when the budget runs out — never a silent drop', function (): void {
    $decision = retryPolicy()->decide(new HttpException(502), 5);

    expect($decision->shouldRetry)->toBeFalse()
        ->and($decision->keepsWork())->toBeFalse()
        // The obligation, spelled out: the caller must fail the job so the failure reaches
        // failed_jobs and the tenant's error dashboard (Req 7.1).
        ->and($decision->mustFailExplicitly())->toBeTrue()
        ->and($decision->isExhausted())->toBeTrue()
        ->and($decision->reason)->toBe(RetryDecision::REASON_ATTEMPTS_EXHAUSTED)
        ->and($decision->delayMs)->toBe(0)
        ->and($decision->attempt)->toBe(5)
        ->and($decision->maxAttempts)->toBe(5)
        ->and($decision->describe())->toContain('not discarded')
        ->and($decision->toArray())->toBe([
            'err_class' => 'NETWORK',
            'outcome' => 'FAIL_FAST',
            'retry' => false,
            'attempt' => 5,
            'max_attempts' => 5,
            'delay_ms' => 0,
            'reason' => 'attempts_exhausted',
        ]);
});

it('always ends in one of the two endings Req 31.1 allows', function (): void {
    $failures = [
        new HttpException(502),
        new HttpException(429),
        new HttpException(403),
        Retries::deferrableQuota(),
        Retries::blockedQuota(),
        TenantNotOperationalException::outboundBlocked('t', TenantStatus::Suspended),
        new RuntimeException('unclassified'),
        new FakeProviderException(500),
    ];

    foreach ($failures as $failure) {
        foreach ([1, 3, 9, 1_000] as $attempt) {
            $decision = retryPolicy()->decide($failure, $attempt);

            // Kept, or ended loudly. There is no third answer; an ending carries no wait
            // (which would read as "released" to a careless caller), and a defer always
            // carries one (a zero-second release is a spin).
            expect($decision->keepsWork())->toBe(! $decision->mustFailExplicitly())
                ->and($decision->mustFailExplicitly() ? $decision->delayMs === 0 : $decision->delayMs >= 0)->toBeTrue()
                ->and($decision->isDeferred() ? $decision->delaySeconds() >= 1 : true)->toBeTrue()
                ->and($decision->reason)->not->toBe('');
        }
    }
});

/*
|--------------------------------------------------------------------------
| Unclassified failures and the extension seam
|--------------------------------------------------------------------------
*/

it('gives an unmapped throwable the documented default budget', function (): void {
    $unmapped = new RuntimeException('nobody has classified this');

    $first = retryPolicy()->decide($unmapped, 1);
    $last = retryPolicy()->decide($unmapped, 3);

    expect(retryPolicy()->classify($unmapped))->toBe(ErrorClass::Unknown)
        ->and($first->shouldRetry)->toBeTrue()
        ->and($first->maxAttempts)->toBe(RetryMatrix::DEFAULT_ATTEMPTS)
        ->and($first->delayMs)->toBeLessThanOrEqual(250)
        ->and($last->shouldRetry)->toBeFalse()
        ->and($last->reason)->toBe(RetryDecision::REASON_ATTEMPTS_EXHAUSTED);
});

it('lets a deployment choose a stricter default for what it has not classified', function (): void {
    config(['wa.reliability.retry.default_class' => 'VALIDATION']);

    $decision = retryPolicy()->decide(new RuntimeException('unclassified'), 1);

    expect($decision->class)->toBe(ErrorClass::Validation)
        ->and($decision->shouldRetry)->toBeFalse()
        ->and($decision->reason)->toBe(RetryDecision::REASON_NOT_RETRYABLE);
});

it('decides a caller-registered provider error on that class\'s budget', function (): void {
    $classifier = new CompositeErrorClassifier(app());
    $classifier->register(new FakeProviderErrorClassifier);
    $policy = retryPolicy($classifier);

    $rateLimited = $policy->decide(new FakeProviderException(429), 1);
    $unroutable = $policy->decide(new FakeProviderException(131_026), 1);

    expect($rateLimited->class)->toBe(ErrorClass::RateLimit)
        ->and($rateLimited->disposition)->toBe(RetryDisposition::Defer)
        ->and($rateLimited->shouldRetry)->toBeTrue()
        ->and($rateLimited->maxAttempts)->toBe(8)
        // No Retry-After from this provider, so the class's own wider first window applies —
        // jittered inside it, and never a zero-second release.
        ->and($rateLimited->delayMs)->toBeGreaterThanOrEqual(1)
        ->and($rateLimited->delayMs)->toBeLessThanOrEqual(1_000)
        ->and($rateLimited->delaySeconds())->toBe(1)
        // A number that is not on WhatsApp is never retried (ROADMAP: NOT_ON_WHATSAPP 0).
        ->and($unroutable->class)->toBe(ErrorClass::NotOnWhatsApp)
        ->and($unroutable->shouldRetry)->toBeFalse()
        ->and($unroutable->reason)->toBe(RetryDecision::REASON_NOT_RETRYABLE);
});

/*
|--------------------------------------------------------------------------
| Laravel queue interop
|--------------------------------------------------------------------------
*/

it('translates a class into $tries and backoff() for a job that prefers the framework\'s accounting', function (): void {
    expect(retryPolicy()->triesFor(ErrorClass::Network))->toBe(5)
        ->and(retryPolicy()->triesFor(ErrorClass::Timeout))->toBe(3)
        // The queue spells "unlimited" as 0 — which is what a quota defer needs (Req 31.1).
        ->and(retryPolicy()->triesFor(ErrorClass::Quota))->toBe(0)
        // One attempt, then failed: never retried by the worker's default.
        ->and(retryPolicy()->triesFor(ErrorClass::Permission))->toBe(1);

    $backoff = retryPolicy()->backoffSeconds(ErrorClass::Network);

    expect($backoff)->toHaveCount(4);                       // 5 attempts = 4 retries

    foreach ($backoff as $seconds) {
        expect($seconds)->toBeGreaterThanOrEqual(0)
            ->and($seconds)->toBeLessThanOrEqual(30);
    }
});
