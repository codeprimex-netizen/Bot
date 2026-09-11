<?php

declare(strict_types=1);

use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Security\ConfigMasterKeyWrapper;

/*
|--------------------------------------------------------------------------
| The KMS seam's default implementation (Req 32.5 / NFR3)
|--------------------------------------------------------------------------
| The wrapper is the inner half of envelope encryption: it seals per-tenant DEKs
| and opens them again. What matters here is that the sealing is *authenticated*
| and *context-bound* — a wrapped DEK must not be movable to another tenant's row —
| and that every failure is a fail-closed KeyUnavailableException rather than a
| null, an empty string, or the input.
*/

/**
 * @param  array<string, string>  $keys
 */
function masterKeyWrapper(array $keys = ['app' => 'sq6dPZ1Xk3mBw8yTn0aE2fJ7hL5cV4rQ'], string $activeId = 'app', ?string $appKey = null): ConfigMasterKeyWrapper
{
    return new ConfigMasterKeyWrapper($keys, $activeId, 'app', $appKey);
}

it('seals a data key and opens it again under the same context', function (): void {
    $wrapper = masterKeyWrapper();
    $dataKey = random_bytes(32);

    $wrapped = $wrapper->wrap($dataKey, 'wa:dek-wrap:v1|tenant-a|FIELD|1');

    expect($wrapped->keyId)->toBe('app')
        ->and($wrapped->algorithm)->toBe(ConfigMasterKeyWrapper::ALGORITHM)
        ->and($wrapped->blob)->not->toContain(base64_encode($dataKey))
        ->and($wrapper->unwrap($wrapped->keyId, $wrapped->blob, 'wa:dek-wrap:v1|tenant-a|FIELD|1'))->toBe($dataKey);
});

it('never stores the data key in the blob', function (): void {
    $dataKey = random_bytes(32);

    $blob = base64_decode(masterKeyWrapper()->wrap($dataKey, 'ctx')->blob, true);

    expect($blob)->toBeString()
        ->and(str_contains((string) $blob, $dataKey))->toBeFalse();
});

it('refuses to open a blob presented with a different context', function (): void {
    $wrapper = masterKeyWrapper();
    $wrapped = $wrapper->wrap(random_bytes(32), 'wa:dek-wrap:v1|tenant-a|FIELD|1');

    // Same blob, another tenant's row: the wrap is bound to its context, so it does
    // not open. This is what stops a DEK being re-pointed at another tenant.
    expect(fn () => $wrapper->unwrap($wrapped->keyId, $wrapped->blob, 'wa:dek-wrap:v1|tenant-b|FIELD|1'))
        ->toThrow(KeyUnavailableException::class);
});

it('refuses to open a tampered or truncated blob', function (): void {
    $wrapper = masterKeyWrapper();
    $wrapped = $wrapper->wrap(random_bytes(32), 'ctx');
    $raw = (string) base64_decode($wrapped->blob, true);

    $tampered = base64_encode(substr($raw, 0, -1).chr((ord(substr($raw, -1)) + 1) % 256));

    expect(fn () => $wrapper->unwrap('app', $tampered, 'ctx'))->toThrow(KeyUnavailableException::class)
        ->and(fn () => $wrapper->unwrap('app', base64_encode(substr($raw, 0, 8)), 'ctx'))
        ->toThrow(KeyUnavailableException::class)
        ->and(fn () => $wrapper->unwrap('app', 'not base64 at all !!', 'ctx'))
        ->toThrow(KeyUnavailableException::class);
});

it('derives unrelated wrapping keys for different key ids', function (): void {
    $material = 'sq6dPZ1Xk3mBw8yTn0aE2fJ7hL5cV4rQ';
    $shared = masterKeyWrapper(['app' => $material, 'other' => $material]);
    $wrapped = $shared->wrap(random_bytes(32), 'ctx');

    // Same material, different id: HKDF's info string separates them, so a blob
    // sealed under "app" does not open under "other".
    expect(fn () => $shared->unwrap('other', $wrapped->blob, 'ctx'))->toThrow(KeyUnavailableException::class);
});

it('keeps opening blobs sealed under a rotated-out key id', function (): void {
    $old = masterKeyWrapper(['retired-2024' => 'sq6dPZ1Xk3mBw8yTn0aE2fJ7hL5cV4rQ'], 'retired-2024');
    $wrapped = $old->wrap($dataKey = random_bytes(32), 'ctx');

    // The active id moved on, but the old id is still configured — the overlap window
    // master-key rotation needs (task 4.2).
    $rotated = masterKeyWrapper([
        'app' => 'Xn2wQ7rT4yV9bM1kL6cJ0hF3sD8gA5pZ',
        'retired-2024' => 'sq6dPZ1Xk3mBw8yTn0aE2fJ7hL5cV4rQ',
    ]);

    expect($rotated->activeKeyId())->toBe('app')
        ->and($rotated->unwrap('retired-2024', $wrapped->blob, 'ctx'))->toBe($dataKey);
});

it('falls back to APP_KEY only for the designated key id', function (): void {
    $wrapper = masterKeyWrapper([], 'app', 'base64:'.base64_encode(random_bytes(32)));

    $wrapped = $wrapper->wrap($dataKey = random_bytes(32), 'ctx');

    expect($wrapper->unwrap('app', $wrapped->blob, 'ctx'))->toBe($dataKey)
        ->and(fn () => masterKeyWrapper([], 'kms-primary', 'base64:'.base64_encode(random_bytes(32)))->activeKeyId())
        ->toThrow(KeyUnavailableException::class);
});

it('fails closed when no master key material exists at all', function (): void {
    $wrapper = masterKeyWrapper([], 'app', null);

    expect(fn () => $wrapper->activeKeyId())->toThrow(KeyUnavailableException::class)
        ->and(fn () => $wrapper->wrap(random_bytes(32), 'ctx'))->toThrow(KeyUnavailableException::class)
        ->and(fn () => $wrapper->unwrap('app', 'anything', 'ctx'))->toThrow(KeyUnavailableException::class);
});

it('refuses master key material too weak to protect a DEK', function (): void {
    expect(fn () => masterKeyWrapper(['app' => 'short'])->activeKeyId())
        ->toThrow(KeyUnavailableException::class, 'at least 16 are required');
});

it('parses the WA_MASTER_KEYS environment format', function (): void {
    expect(ConfigMasterKeyWrapper::parseKeyList('a=one,b=two'))->toBe(['a' => 'one', 'b' => 'two'])
        ->and(ConfigMasterKeyWrapper::parseKeyList(' a = one , , b=two , broken '))->toBe(['a' => 'one', 'b' => 'two'])
        ->and(ConfigMasterKeyWrapper::parseKeyList('a=base64:AAAA=='))->toBe(['a' => 'base64:AAAA=='])
        ->and(ConfigMasterKeyWrapper::parseKeyList(''))->toBe([]);
});

it('keeps derived wrapping keys out of dumps', function (): void {
    $wrapper = masterKeyWrapper();
    $wrapper->wrap(random_bytes(32), 'ctx');

    $dumped = print_r($wrapper->__debugInfo(), true);

    expect($dumped)->toContain('redacted')
        ->and($dumped)->not->toContain('sq6dPZ1Xk3mBw8yTn0aE2fJ7hL5cV4rQ');
});
