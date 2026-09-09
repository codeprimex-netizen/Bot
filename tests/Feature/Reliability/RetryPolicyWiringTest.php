<?php

declare(strict_types=1);

use App\Enums\ErrorClass;
use App\Services\Reliability\CompositeErrorClassifier;
use App\Services\Reliability\ErrorClassifier;
use App\Services\Reliability\RetryDecision;
use App\Services\Reliability\RetryMatrix;
use App\Services\Reliability\RetryPolicy;
use Tests\Fixtures\Reliability\FakeProviderErrorClassifier;
use Tests\Fixtures\Reliability\FakeProviderException;
use Tests\Fixtures\Reliability\Retries;

/*
|--------------------------------------------------------------------------
| How a job gets the policy (Req 31.1 / NFR2)
|--------------------------------------------------------------------------
| The container wiring a queued job depends on: one shared classifier (so a registration
| made in a provider's boot is still there in the worker), and a policy that reads live
| config.
*/

it('resolves one shared policy, matrix, and classifier', function (): void {
    expect(app(RetryPolicy::class))->toBe(app(RetryPolicy::class))
        ->and(app(RetryMatrix::class))->toBe(app(RetryMatrix::class))
        ->and(app(ErrorClassifier::class))->toBeInstanceOf(CompositeErrorClassifier::class)
        ->and(app(ErrorClassifier::class))->toBe(app(CompositeErrorClassifier::class));
});

it('is injectable into a job and answers with the shipped matrix', function (): void {
    // The shape of a real handler: catch, decide, release or fail.
    $decide = static function (Throwable $e, int $attempt, RetryPolicy $policy): RetryDecision {
        return $policy->decide($e, $attempt);
    };

    $deferred = $decide(Retries::deferrableQuota(600), 1, app(RetryPolicy::class));

    expect($deferred->shouldRetry)->toBeTrue()
        ->and($deferred->delaySeconds())->toBe(600)
        ->and($deferred->class)->toBe(ErrorClass::Quota);
});

it('keeps a classifier registered at boot for the rest of the process', function (): void {
    // What a later phase's service provider does in boot(); the singleton binding is what
    // makes it stick for every job the worker then runs.
    app(CompositeErrorClassifier::class)->register(new FakeProviderErrorClassifier);

    $decision = app(RetryPolicy::class)->decide(new FakeProviderException(429), 1);

    expect($decision->class)->toBe(ErrorClass::RateLimit)
        ->and($decision->shouldRetry)->toBeTrue();
});

it('reads config live, so a matrix change needs no cache clear or restart', function (): void {
    $policy = app(RetryPolicy::class);

    expect($policy->triesFor(ErrorClass::Bridge))->toBe(5);

    config(['wa.reliability.retry.classes.BRIDGE.attempts' => 2]);

    expect($policy->triesFor(ErrorClass::Bridge))->toBe(2);
});
