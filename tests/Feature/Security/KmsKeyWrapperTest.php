<?php

declare(strict_types=1);

use App\Enums\KeyPurpose;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\EncryptionKey;
use App\Models\Tenant;
use App\Services\Security\FieldCipher;
use App\Services\Security\KeyWrapper;
use App\Services\Security\KmsClient;
use App\Services\Security\KmsKeyWrapper;
use App\Services\Security\KmsSeal;
use App\Services\Security\MasterKeyRotator;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Security\FakeKms;

/*
|--------------------------------------------------------------------------
| The KMS seam below KeyWrapper (Req 32.6 / NFR3)
|--------------------------------------------------------------------------
| `KmsKeyWrapper` is meant to be invisible: pointing `wa.security.encryption.wrapper`
| at it must change *where the master key lives* and nothing else. So the tests here
| are about the four things that could go wrong in that swap:
|
|   1. envelope encryption keeps working end to end, through the real cipher;
|   2. the tenant binding survives — a wrapped DEK does not open in another row;
|   3. a key store failure fails **closed**, with nothing written and no plaintext;
|   4. the master key can be advanced without breaking what it already sealed.
*/

it('encrypts and decrypts through a KMS-backed wrapper', function (): void {
    FakeKms::bind();

    $tenant = Tenant::factory()->create();
    $cipher = app(FieldCipher::class);

    $ciphertext = $cipher->encrypt($tenant, 'EAAG1x-cloud-api-access-token');

    expect(app(KeyWrapper::class))->toBeInstanceOf(KmsKeyWrapper::class)
        ->and($ciphertext)->not->toContain('EAAG1x-cloud-api-access-token')
        ->and($cipher->decrypt($tenant, $ciphertext))->toBe('EAAG1x-cloud-api-access-token');
});

it('records the KMS key id and never the key material on the row', function (): void {
    $kms = FakeKms::bind();

    $tenant = Tenant::factory()->create();
    app(FieldCipher::class)->encrypt($tenant, 'secret');

    $key = EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    $stored = (string) DB::table('encryption_keys')->where('id', $key->id)->value('wrapped_dek');

    expect($key->kms_key_id)->toBe($kms->keyId(1))
        ->and($key->algorithm)->toBe(FakeKms::ALGORITHM)
        // The blob is what the KMS returned, and it is not the DEK: it cannot be turned
        // back into one without the key store.
        ->and($stored)->toStartWith('fake:v1:')
        ->and($key->toArray())->not->toHaveKey('wrapped_dek');
});

it('binds a wrapped data key to its tenant, purpose and version', function (): void {
    FakeKms::bind();

    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $cipher = app(FieldCipher::class);

    $cipher->encrypt($tenant, 'secret');
    $cipher->encrypt($other, 'secret');

    $mine = EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    $theirs = EncryptionKey::withoutTenantScope()->where('tenant_id', $other->id)->firstOrFail();

    // Move my sealed DEK into their row, exactly as an attacker with write access would.
    DB::table('encryption_keys')->where('id', $theirs->id)->update(['wrapped_dek' => $mine->wrapped_dek]);

    app(FieldCipher::class)->forgetKeys();

    expect(fn (): string => app(FieldCipher::class)->encrypt($other, 'anything'))
        ->toThrow(KeyUnavailableException::class);
});

it('fails closed and writes nothing when the key store is unavailable', function (): void {
    $kms = FakeKms::bind();
    $tenant = Tenant::factory()->create();

    $kms->fail();

    expect(fn (): string => app(FieldCipher::class)->encrypt($tenant, 'secret'))
        ->toThrow(KeyUnavailableException::class)
        // No half-provisioned lineage: sealing failed, so nothing was persisted.
        ->and(EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('fails closed when a client throws something of its own', function (): void {
    $wrapper = new KmsKeyWrapper(new class implements KmsClient
    {
        public function activeKeyId(): string
        {
            throw new RuntimeException('vault sdk: connection reset while POSTing {"plaintext":"…"}');
        }

        public function encrypt(string $plaintext, string $context): KmsSeal
        {
            throw new RuntimeException('vault sdk: connection reset');
        }

        public function decrypt(string $keyId, string $ciphertext, string $context): string
        {
            throw new RuntimeException('vault sdk: connection reset');
        }

        public function rotate(): string
        {
            throw new RuntimeException('vault sdk: connection reset');
        }
    });

    try {
        $wrapper->wrap(random_bytes(32), 'ctx');
        $this->fail('a failing key store must not produce a wrapped key');
    } catch (KeyUnavailableException $e) {
        // The SDK's message quoted a request payload; none of it may survive.
        expect($e->getMessage())->not->toContain('connection reset')
            ->and($e->getMessage())->not->toContain('sdk')
            ->and($e->getStatusCode())->toBe(503)
            ->and($e->isRetryable())->toBeTrue();
    }
});

it('refuses to seal an empty data key', function (): void {
    FakeKms::bind();

    expect(fn () => app(KeyWrapper::class)->wrap('', 'ctx'))->toThrow(KeyUnavailableException::class);
});

it('refuses to unwrap without a key id or a blob', function (): void {
    FakeKms::bind();

    expect(fn (): string => app(KeyWrapper::class)->unwrap('', 'blob', 'ctx'))
        ->toThrow(KeyUnavailableException::class)
        ->and(fn (): string => app(KeyWrapper::class)->unwrap('fake:master:v1', '', 'ctx'))
        ->toThrow(KeyUnavailableException::class);
});

it('advances the master key without breaking what the previous one sealed', function (): void {
    $kms = FakeKms::bind();

    $tenant = Tenant::factory()->create();
    $cipher = app(FieldCipher::class);
    $ciphertext = $cipher->encrypt($tenant, 'still-readable');

    $wrapper = app(KeyWrapper::class);
    expect($wrapper)->toBeInstanceOf(MasterKeyRotator::class);

    /** @var MasterKeyRotator $wrapper */
    $advanced = $wrapper->rotateMasterKey();

    expect($advanced)->toBe($kms->keyId(2))
        ->and($kms->versions())->toBe([1, 2])
        // The DEK is still sealed under v1, and v1 still opens it: the rotation is
        // non-destructive on its own, before any re-wrap has run.
        ->and(app(FieldCipher::class)->decrypt($tenant, $ciphertext))->toBe('still-readable');
});

it('keeps unwrapping key ids the store no longer issues', function (): void {
    $kms = FakeKms::bind();

    $tenant = Tenant::factory()->create();
    $ciphertext = app(FieldCipher::class)->encrypt($tenant, 'sealed-under-v1');

    $kms->rotate();
    $kms->rotate();

    app(FieldCipher::class)->forgetKeys();

    expect(app(FieldCipher::class)->decrypt($tenant, $ciphertext))->toBe('sealed-under-v1');
});

it('provisions each purpose under its own lineage', function (): void {
    FakeKms::bind();

    $tenant = Tenant::factory()->create();
    $cipher = app(FieldCipher::class);

    $field = $cipher->encrypt($tenant, 'field-value', KeyPurpose::Field);
    $export = $cipher->encrypt($tenant, 'export-value', KeyPurpose::Export);

    expect($cipher->decrypt($tenant, $field, KeyPurpose::Field))->toBe('field-value')
        ->and($cipher->decrypt($tenant, $export, KeyPurpose::Export))->toBe('export-value')
        // Purpose is cryptographic domain separation, not a label.
        ->and(fn (): string => $cipher->decrypt($tenant, $field, KeyPurpose::Export))
        ->toThrow(CiphertextIntegrityException::class);
});
