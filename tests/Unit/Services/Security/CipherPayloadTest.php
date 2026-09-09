<?php

declare(strict_types=1);

use App\Enums\KeyPurpose;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Services\Security\CipherPayload;

/*
|--------------------------------------------------------------------------
| The stored envelope format (Req 32.5 / NFR3)
|--------------------------------------------------------------------------
| The format carries the key *version*, which is what makes rotation
| non-breaking, and nothing else that a reader needs to be told — the tenant is
| authenticated rather than stored. Parsing is strict on purpose: a value that is
| not exactly one of our envelopes is refused, never guessed at, because guessing
| is how a plaintext column starts being served as if it were encrypted.
*/

it('round-trips every part of an envelope', function (): void {
    $payload = new CipherPayload(KeyPurpose::Export, 7, random_bytes(12), random_bytes(16), random_bytes(48));

    $parsed = CipherPayload::parse($payload->encode());

    expect($parsed->purpose)->toBe(KeyPurpose::Export)
        ->and($parsed->keyVersion)->toBe(7)
        ->and($parsed->iv)->toBe($payload->iv)
        ->and($parsed->tag)->toBe($payload->tag)
        ->and($parsed->ciphertext)->toBe($payload->ciphertext);
});

it('encodes to a single printable token that names its key version', function (): void {
    $encoded = (new CipherPayload(KeyPurpose::Field, 3, random_bytes(12), random_bytes(16), random_bytes(16)))->encode();

    expect($encoded)->toStartWith('wac1.FIELD.3.')
        ->and($encoded)->toMatch('/^[A-Za-z0-9._-]+$/')
        ->and(CipherPayload::looksLikeEnvelope($encoded))->toBeTrue();
});

it('round-trips an empty ciphertext', function (): void {
    // AES-GCM of zero bytes is zero bytes plus a tag: an empty secret is a legitimate
    // value and must survive the format.
    $encoded = (new CipherPayload(KeyPurpose::Field, 1, random_bytes(12), random_bytes(16), ''))->encode();

    expect(CipherPayload::parse($encoded)->ciphertext)->toBe('');
});

it('rejects anything that is not one of our envelopes', function (string $value): void {
    expect(CipherPayload::looksLikeEnvelope($value))->toBeFalse()
        ->and(fn () => CipherPayload::parse($value))->toThrow(CiphertextIntegrityException::class);
})->with([
    'plaintext' => 'a-perfectly-ordinary-secret',
    'empty' => '',
    'laravel encrypter payload' => 'eyJpdiI6ImFiYyIsInZhbHVlIjoiZGVmIn0=',
    'wrong marker' => 'wac0.FIELD.1.AAAA.BBBB.CCCC',
]);

it('rejects a malformed envelope', function (string $value): void {
    expect(fn () => CipherPayload::parse($value))->toThrow(CiphertextIntegrityException::class);
})->with([
    'too few segments' => 'wac1.FIELD.1.AAAA.BBBB',
    'too many segments' => 'wac1.FIELD.1.AAAA.BBBB.CCCC.DDDD',
    'unknown purpose' => 'wac1.NONSENSE.1.AAAA.BBBB.CCCC',
    'zero version' => 'wac1.FIELD.0.AAAA.BBBB.CCCC',
    'non-numeric version' => 'wac1.FIELD.x.AAAA.BBBB.CCCC',
    'version with trailing junk' => 'wac1.FIELD.1x.AAAA.BBBB.CCCC',
    'empty nonce' => 'wac1.FIELD.1..BBBB.CCCC',
    'nonce is not base64url' => 'wac1.FIELD.1.!!!!.BBBB.CCCC',
]);

it('never puts the value it rejected into the exception message', function (): void {
    $secret = 'super-secret-access-token-value';

    try {
        CipherPayload::parse($secret);
    } catch (CiphertextIntegrityException $e) {
        expect($e->getMessage())->not->toContain($secret)
            ->and($e->getMessage())->toContain(strlen($secret).' bytes');

        return;
    }

    $this->fail('Expected the parse to be refused.');
});
