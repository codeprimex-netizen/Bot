<?php

declare(strict_types=1);

use App\Enums\ErrorClass;
use App\Enums\PlanFeature;
use App\Enums\TenantStatus;
use App\Exceptions\Audit\AuditChainBusyException;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\MediaRejectedException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Exceptions\Tenancy\TenantNotOperationalException;
use App\Models\Tenant;
use App\Services\Reliability\CompositeErrorClassifier;
use App\Services\Reliability\ErrorClassifier;
use App\Services\Reliability\PlatformErrorClassifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Reliability\ExplodingErrorClassifier;
use Tests\Fixtures\Reliability\FakeProviderErrorClassifier;
use Tests\Fixtures\Reliability\FakeProviderException;
use Tests\Fixtures\Reliability\Retries;

/*
|--------------------------------------------------------------------------
| Throwable -> ErrorClass (design.md § Error Handling; Req 33.2 / NFR4)
|--------------------------------------------------------------------------
| The platform's typed exceptions already carry "is this worth retrying"; this is where
| that meaning is read once, so no two call sites can disagree about it.
*/

function errorClassifier(): CompositeErrorClassifier
{
    return new CompositeErrorClassifier(app());
}

it('classifies a quota refusal as a defer, whichever half of Req 31.1 it is', function (): void {
    $classifier = errorClassifier();

    // Both are QUOTA; it is `RetryPolicy` that turns "not deferrable" into an explicit
    // block, because the class of the failure is the same either way.
    expect($classifier->classify(Retries::deferrableQuota()))->toBe(ErrorClass::Quota)
        ->and($classifier->classify(Retries::blockedQuota()))->toBe(ErrorClass::Quota);
});

it('classifies plan, suspension, and isolation refusals as never-retryable', function (): void {
    $classifier = errorClassifier();

    expect($classifier->classify(FeatureNotInPlanException::upgradeRequired(PlanFeature::Ai, 'tenant-1', ['pro'])))
        ->toBe(ErrorClass::Permission)
        ->and($classifier->classify(FeatureNotInPlanException::notAvailable(PlanFeature::Stt, 'tenant-1')))
        ->toBe(ErrorClass::Permission)
        // Task 1.3 settled this: a suspended tenant is a block, never a defer.
        ->and($classifier->classify(TenantNotOperationalException::outboundBlocked('tenant-1', TenantStatus::Suspended)))
        ->toBe(ErrorClass::Permission)
        ->and($classifier->classify(TenantNotOperationalException::mutationBlocked('tenant-1', TenantStatus::Cancelled, 'edit flow')))
        ->toBe(ErrorClass::Permission)
        ->and($classifier->classify(CrossTenantAccessException::forRetrieval(Tenant::class, 'a', 'b')))
        ->toBe(ErrorClass::Permission);
});

it('classifies a transient dependency failure as retryable', function (): void {
    $classifier = errorClassifier();

    // The key store being unreachable (503, retryable by its own admission), losing the race
    // for the audit chain's next position, and a dead socket all behave identically.
    expect($classifier->classify(KeyUnavailableException::unknownMasterKey('app')))->toBe(ErrorClass::Network)
        ->and($classifier->classify(AuditChainBusyException::lockTimedOut('tenant-1', 5)))->toBe(ErrorClass::Network)
        ->and($classifier->classify(AuditChainBusyException::positionContended('tenant-1', 3)))->toBe(ErrorClass::Network)
        ->and($classifier->classify(new ConnectionException('connection reset')))->toBe(ErrorClass::Network);
});

it('consults KeyUnavailableException::isRetryable() rather than assuming it', function (): void {
    $exception = KeyUnavailableException::unwrapFailed('app', 'aes-256-gcm');

    // Written so that a future non-retryable key failure follows the exception's own answer
    // instead of being retried five times.
    expect($exception->isRetryable())->toBeTrue()
        ->and((new PlatformErrorClassifier)->classify($exception))->toBe(ErrorClass::Network);
});

it('classifies a deterministic failure as terminal', function (): void {
    $classifier = errorClassifier();

    expect($classifier->classify(CiphertextIntegrityException::malformed('no prefix', 'plain')))
        ->toBe(ErrorClass::Validation)
        // A programming error: the fix is a code change, so a retry can only fail identically.
        ->and($classifier->classify(MissingTenantContextException::forQuery(Tenant::class)))
        ->toBe(ErrorClass::Validation)
        // A *refused* media payload, as opposed to a media transfer that failed.
        ->and($classifier->classify(MediaRejectedException::tooLarge(20_000_000, 16_777_216)))
        ->toBe(ErrorClass::Validation)
        ->and($classifier->classify(ValidationException::withMessages(['to' => 'invalid'])))
        ->toBe(ErrorClass::Validation)
        ->and($classifier->classify(new TypeError('argument 1 must be int')))
        ->toBe(ErrorClass::Validation);
});

it('falls back to the HTTP status when nothing else recognises the exception', function (): void {
    $classifier = errorClassifier();

    $byStatus = [
        401 => ErrorClass::Auth,
        402 => ErrorClass::Permission,
        403 => ErrorClass::Permission,
        408 => ErrorClass::Timeout,
        429 => ErrorClass::RateLimit,
        422 => ErrorClass::Validation,
        400 => ErrorClass::Validation,
        500 => ErrorClass::Network,
        502 => ErrorClass::Network,
        503 => ErrorClass::Network,
        504 => ErrorClass::Timeout,
    ];

    foreach ($byStatus as $status => $expected) {
        expect($classifier->classify(new HttpException($status)))->toBe($expected);
    }

    // A 3xx is not a failure class; the policy's configured default takes over.
    expect($classifier->classify(new HttpException(302)))->toBeNull();
});

it('has no opinion about an exception nobody has classified', function (): void {
    $classifier = errorClassifier();

    // Declining is the point: a classifier that answered UNKNOWN here would become the
    // answer for every unrecognised failure in the platform.
    expect($classifier->classify(new FakeProviderException(429)))->toBeNull()
        ->and($classifier->classify(new RuntimeException('who knows')))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The extension seam — how later phases classify their own provider errors
|--------------------------------------------------------------------------
*/

it('accepts a classifier registered at runtime', function (): void {
    $classifier = errorClassifier();

    $classifier->register(new FakeProviderErrorClassifier);

    expect($classifier->classify(new FakeProviderException(429)))->toBe(ErrorClass::RateLimit)
        ->and($classifier->classify(new FakeProviderException(131_026)))->toBe(ErrorClass::NotOnWhatsApp)
        // It declined this one, so the chain moved on and nobody claimed it.
        ->and($classifier->classify(new FakeProviderException(1)))->toBeNull();
});

it('accepts a closure and a one-line exception mapping', function (): void {
    $classifier = errorClassifier();

    $classifier->register(
        static fn (Throwable $e): ?ErrorClass => $e instanceof LogicException ? ErrorClass::Bridge : null,
    );
    $classifier->map(FakeProviderException::class, ErrorClass::Timeout);

    expect($classifier->classify(new LogicException('bridge said no')))->toBe(ErrorClass::Bridge)
        ->and($classifier->classify(new FakeProviderException(7)))->toBe(ErrorClass::Timeout);
});

it('accepts classifiers and exception maps from config', function (): void {
    $classifier = errorClassifier();

    config([
        'wa.reliability.retry.classifiers' => [FakeProviderErrorClassifier::class],
        'wa.reliability.retry.exceptions' => [LogicException::class => 'bridge'],
    ]);

    expect($classifier->classify(new FakeProviderException(429)))->toBe(ErrorClass::RateLimit)
        // Case-insensitive on purpose: a config file should not fail over capitalisation.
        ->and($classifier->classify(new LogicException('nope')))->toBe(ErrorClass::Bridge);
});

it('ignores a config entry that names nothing usable', function (): void {
    $classifier = errorClassifier();

    config([
        'wa.reliability.retry.exceptions' => [
            'Not\\A\\Real\\Exception' => 'NETWORK',
            LogicException::class => 'NOT_AN_ERROR_CLASS',
        ],
    ]);

    expect($classifier->classify(new LogicException('nope')))->toBeNull();
});

it('lets a registration outrank a built-in, but never removes the platform map', function (): void {
    $classifier = errorClassifier();

    $keyFailure = KeyUnavailableException::unknownMasterKey('app');

    expect($classifier->classify($keyFailure))->toBe(ErrorClass::Network);

    // Reclassifying one exception during an incident should not need a release…
    $classifier->map(KeyUnavailableException::class, ErrorClass::Bridge);

    expect($classifier->classify($keyFailure))->toBe(ErrorClass::Bridge)
        // …and everything else still gets its documented platform answer.
        ->and($classifier->classify(TenantNotOperationalException::outboundBlocked('t', TenantStatus::Suspended)))
        ->toBe(ErrorClass::Permission);
});

it('survives a broken classifier instead of turning one failure into two', function (): void {
    $classifier = errorClassifier();

    // Classification runs while something is already failing, so a bad classifier must not
    // be able to crash the worker that was handling the original error.
    config(['wa.reliability.retry.classifiers' => [
        ExplodingErrorClassifier::class,
        FakeProviderErrorClassifier::class,
        'Not\\A\\Real\\Class',
        Tenant::class,                        // resolvable, but not a classifier
        42,                                   // not even a class name
    ]]);
    $classifier->register(new ExplodingErrorClassifier);

    expect($classifier->classify(new FakeProviderException(429)))->toBe(ErrorClass::RateLimit)
        ->and($classifier->classify(Retries::deferrableQuota()))->toBe(ErrorClass::Quota);
});

it('is one shared instance, so a runtime registration is not lost', function (): void {
    $classifier = app(CompositeErrorClassifier::class);
    $classifier->register(new FakeProviderErrorClassifier);

    expect(app(ErrorClassifier::class))->toBe($classifier)
        ->and(app(ErrorClassifier::class)->classify(new FakeProviderException(429)))->toBe(ErrorClass::RateLimit);
});
