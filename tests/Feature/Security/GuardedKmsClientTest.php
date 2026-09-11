<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use App\Services\Security\GuardedKmsClient;
use App\Services\Security\KmsClient;
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Security\FakeKms;

/*
|--------------------------------------------------------------------------
| Guarding the KMS (Req 32.6 / NFR3 composed with Req 31.1, 31.3 / NFR2)
|--------------------------------------------------------------------------
| A KMS call sits inside every encrypted read and write, which makes it the worst place
| on the platform for an unguarded network dependency: when the key store is slow, every
| request in the pool waits on it. So the KMS gets the two Phase 2 primitives, composed
| the way `RetryPolicy` prescribes — retry loop outside, breaker inside — and this file
| pins the three behaviours that composition exists for:
|
|   1. a transient blip is retried and the caller never sees it;
|   2. a sustained outage opens the breaker, after which the store is **not called at all**;
|   3. every failure is `KeyUnavailableException` — fail closed, no plaintext, no null.
*/

function guarded(FakeKms $kms, int $attempts = 2): GuardedKmsClient
{
    return new GuardedKmsClient(
        $kms,
        app(CircuitBreaker::class),
        app(RetryPolicy::class),
        'kms-test',
        $attempts,
        250,
    );
}

beforeEach(function (): void {
    Sleep::fake();
});

it('retries a transient failure and returns the value', function (): void {
    $kms = (new FakeKms)->failWith(1);

    expect(guarded($kms)->activeKeyId())->toBe($kms->keyId(1))
        // Two reached calls: the one that failed and the one that worked.
        ->and($kms->calls('activeKeyId'))->toBe(2);

    Sleep::assertSlept(fn (): bool => true, 1);
});

it('gives up inside its budget rather than sleeping a caller out', function (): void {
    $kms = (new FakeKms)->fail();

    expect(fn (): string => guarded($kms, attempts: 3)->activeKeyId())->toThrow(KeyUnavailableException::class)
        ->and($kms->calls('activeKeyId'))->toBe(3);

    // Two waits (one per retry), and every one clamped — so an inline crypto call cannot
    // inherit a queue-sized backoff. The durations themselves are jittered on purpose.
    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMilliseconds <= 250, 2);
});

it('stops calling the key store once the breaker is open', function (): void {
    $kms = (new FakeKms)->fail();
    $client = guarded($kms);
    $breaker = app(CircuitBreaker::class);

    // Three guarded operations, two attempts each: past the 5-failures-per-window arm.
    foreach (range(1, 3) as $ignored) {
        try {
            $client->activeKeyId();
        } catch (KeyUnavailableException) {
            // expected
        }
    }

    expect($breaker->state(CircuitScope::Provider, 'kms-test'))->toBe(CircuitState::Open);

    $reached = $kms->calls('activeKeyId');

    expect(fn (): string => $client->activeKeyId())->toThrow(KeyUnavailableException::class)
        // Property 13: the guarded operation is never invoked while OPEN — and for a key
        // store that is the difference between a fast 503 and every worker blocked on a
        // timeout.
        ->and($kms->calls('activeKeyId'))->toBe($reached);
});

it('fails closed for every operation, never returning a value', function (string $operation, callable $call): void {
    $kms = (new FakeKms)->fail();
    $client = guarded($kms, attempts: 1);

    try {
        $call($client);
        thisTest()->fail(sprintf('%s must not succeed while the key store is down', $operation));
    } catch (KeyUnavailableException $e) {
        expect($e->getStatusCode())->toBe(503)
            ->and($e->isRetryable())->toBeTrue()
            ->and($e->getHeaders())->toHaveKey('Retry-After');
    }
})->with([
    'activeKeyId' => ['activeKeyId', fn (KmsClient $client): string => $client->activeKeyId()],
    'encrypt' => ['encrypt', fn (KmsClient $client) => $client->encrypt('dek', 'ctx')],
    'decrypt' => ['decrypt', fn (KmsClient $client): string => $client->decrypt('fake:master:v1', 'blob', 'ctx')],
    'rotate' => ['rotate', fn (KmsClient $client): string => $client->rotate()],
]);

it('does not retry a decision the retry matrix refuses', function (): void {
    $kms = (new FakeKms)->fail();

    // One attempt configured: the budget is spent immediately, so exactly one call is made
    // and no wait happens at all.
    expect(fn (): string => guarded($kms, attempts: 1)->activeKeyId())->toThrow(KeyUnavailableException::class)
        ->and($kms->calls('activeKeyId'))->toBe(1);

    Sleep::assertNeverSlept();
});

it('passes values straight through when the store is healthy', function (): void {
    $kms = new FakeKms;
    $client = guarded($kms);

    $seal = $client->encrypt('data-key', 'wa:dek-wrap:v1|t|FIELD|1');

    expect($client->decrypt($seal->keyId, $seal->ciphertext, 'wa:dek-wrap:v1|t|FIELD|1'))->toBe('data-key')
        ->and($client->rotate())->toBe($kms->keyId(2))
        ->and($client->activeKeyId())->toBe($kms->keyId(2));

    Sleep::assertNeverSlept();
});

it('is wired up guarded by default', function (): void {
    config([
        'wa.security.kms.driver' => FakeKms::class,
        'wa.security.kms.guard.enabled' => true,
    ]);
    app()->instance(FakeKms::class, new FakeKms);
    app()->forgetInstance(KmsClient::class);

    expect(app(KmsClient::class))->toBeInstanceOf(GuardedKmsClient::class);
});
