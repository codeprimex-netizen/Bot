<?php

declare(strict_types=1);

use App\Enums\ErrorClass;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Bridge\UnknownSessionException;
use App\Services\Bridge\BridgeErrorClassifier;
use App\Services\Reliability\RetryPolicy;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Classifying bridge failures (Req 7.1 / A7; Req 31.1 / NFR2)
|--------------------------------------------------------------------------
| `BridgeRequestFailedException` carries the sidecar's status and error code without
| interpreting them, because the interpretation is a policy and belongs in one place. This
| file is that place's test — and it asserts the mapping twice over: once directly, and
| once through `RetryPolicy`, because the mapping is only worth anything if the platform's
| retry decision actually follows it.
*/

function bridgeClassifier(): BridgeErrorClassifier
{
    return new BridgeErrorClassifier;
}

it('treats an unreachable bridge as retryable, because the outcome is unknown', function (): void {
    expect(bridgeClassifier()->classify(BridgeUnreachableException::transportFailed('message.text')))
        ->toBe(ErrorClass::Bridge);
});

it('treats a nonexistent session as terminal, because a missing row will not appear', function (): void {
    expect(bridgeClassifier()->classify(UnknownSessionException::forId('01HZZZZZZZZZZZZZZZZZZZZZZZ')))
        ->toBe(ErrorClass::Validation)
        ->and(bridgeClassifier()->classify(UnknownSessionException::malformed('nope')))
        ->toBe(ErrorClass::Validation);
});

it('declines to classify a failure that is not the bridge\'s', function (): void {
    // "No opinion" is what lets the chain work: returning UNKNOWN here would make this
    // classifier the answer for every failure in the platform.
    expect(bridgeClassifier()->classify(new RuntimeException('something else entirely')))->toBeNull();
});

it('reads the sidecar error code in preference to its status', function (string $code, ErrorClass $expected): void {
    // The code is the sidecar's precise statement; the status is its rough one. A
    // `409 session_not_connected` must be retried on the bridge budget rather than parked
    // as a generic 4xx.
    $failure = BridgeRequestFailedException::refused('message.text', 409, $code);

    expect(bridgeClassifier()->classify($failure))->toBe($expected);
})->with([
    'not connected' => [BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED, ErrorClass::Bridge],
    'unknown to the bridge' => [BridgeRequestFailedException::CODE_SESSION_UNKNOWN, ErrorClass::Bridge],
    'logged out' => [BridgeRequestFailedException::CODE_SESSION_LOGGED_OUT, ErrorClass::Auth],
    'not on whatsapp' => [BridgeRequestFailedException::CODE_NOT_ON_WHATSAPP, ErrorClass::NotOnWhatsApp],
    'media transfer' => [BridgeRequestFailedException::CODE_MEDIA_FAILED, ErrorClass::Media],
    'rate limited' => [BridgeRequestFailedException::CODE_RATE_LIMITED, ErrorClass::RateLimit],
]);

it('falls back to the status when the sidecar sent no code it knows', function (int $status, ErrorClass $expected): void {
    expect(bridgeClassifier()->classify(BridgeRequestFailedException::refused('session.start', $status)))
        ->toBe($expected);
})->with([
    'unauthorized' => [401, ErrorClass::Auth],
    'forbidden' => [403, ErrorClass::Permission],
    'request timeout' => [408, ErrorClass::Timeout],
    'gateway timeout' => [504, ErrorClass::Timeout],
    'too many requests' => [429, ErrorClass::RateLimit],
    'bad request' => [400, ErrorClass::Validation],
    'unprocessable' => [422, ErrorClass::Validation],
    'server error' => [500, ErrorClass::Bridge],
    'bad gateway' => [502, ErrorClass::Bridge],
]);

it('attributes an unrecognised refusal to the bridge rather than to nothing', function (): void {
    // A code from a newer sidecar build is definitely *about the bridge*, so attributing it
    // there keeps the error dashboard honest — and the slightly longer budget risks a
    // duplicate (which the send pipeline's idempotency key absorbs) rather than a drop.
    $failure = BridgeRequestFailedException::refused('message.text', 599, 'quantum_flux');

    expect(bridgeClassifier()->classify($failure))->toBe(ErrorClass::Bridge);
});

/*
|--------------------------------------------------------------------------
| The mapping is only worth anything if the retry decision follows it
|--------------------------------------------------------------------------
*/

it('is registered in the platform classifier chain', function (): void {
    expect(config('wa.reliability.retry.classifiers'))->toContain(BridgeErrorClassifier::class);
});

it('keeps a transport failure, so a lost send is never dropped', function (): void {
    $decision = app(RetryPolicy::class)->decide(BridgeUnreachableException::transportFailed('message.text'), 1);

    expect($decision->class)->toBe(ErrorClass::Bridge)
        ->and($decision->shouldRetry)->toBeTrue()
        ->and($decision->delayMs)->toBeGreaterThanOrEqual(0);
});

it('gives up on a recipient who is not on whatsapp instead of retrying five times', function (): void {
    $failure = BridgeRequestFailedException::refused(
        'message.text',
        422,
        BridgeRequestFailedException::CODE_NOT_ON_WHATSAPP,
    );

    $decision = app(RetryPolicy::class)->decide($failure, 1);

    expect($decision->class)->toBe(ErrorClass::NotOnWhatsApp)
        ->and($decision->shouldRetry)->toBeFalse()
        ->and($decision->mustFailExplicitly())->toBeTrue();
});

it('defers a rate-limited session on the wait the bridge named', function (): void {
    $failure = BridgeRequestFailedException::refused(
        'message.text',
        429,
        BridgeRequestFailedException::CODE_RATE_LIMITED,
        retryAfterSeconds: 42,
    );

    $decision = app(RetryPolicy::class)->decide($failure, 1);

    // Honoured verbatim rather than jittered: jittering an authoritative wait down
    // guarantees the next attempt is refused as well.
    expect($decision->class)->toBe(ErrorClass::RateLimit)
        ->and($decision->shouldRetry)->toBeTrue()
        ->and($decision->delayMs)->toBe(42_000);
});

it('refuses to retry a session whose credentials are void', function (): void {
    $failure = BridgeRequestFailedException::refused(
        'session.start',
        409,
        BridgeRequestFailedException::CODE_SESSION_LOGGED_OUT,
    );

    $decision = app(RetryPolicy::class)->decide($failure, 1);

    // Only a human re-pairing the device fixes this; retrying hides the session that needs
    // their attention.
    expect($decision->class)->toBe(ErrorClass::Auth)
        ->and($decision->shouldRetry)->toBeFalse();
});
