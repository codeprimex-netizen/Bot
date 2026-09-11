<?php

declare(strict_types=1);

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\EncryptionKey;
use App\Models\Tenant;
use App\Services\Security\ConfigMasterKeyWrapper;
use App\Services\Security\EnvelopeFieldCipher;
use App\Services\Security\FieldCipher;
use App\Services\Security\KeyWrapper;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Envelope encryption end to end (Req 32.5 / NFR3)
|--------------------------------------------------------------------------
| The four properties that make this worth having, each tested for its own sake:
|
|   1. what goes in comes out — and only through the key of the tenant it was
|      written for (cross-tenant ciphertext is rejected, not decrypted);
|   2. a modified byte is a failure, not a plausible plaintext;
|   3. rotating a key does not break what was written before it;
|   4. when the key store is unavailable, the platform *stops* — there is no path
|      through this class that returns plaintext, an empty string, or null.
|
| Plus the one property with no observable behaviour: a DEK is never written or
| logged in plaintext, which is asserted against the raw column and the dumps.
*/

const MASTER_KEY = 'sq6dPZ1Xk3mBw8yTn0aE2fJ7hL5cV4rQ';

const OTHER_MASTER_KEY = 'Xn2wQ7rT4yV9bM1kL6cJ0hF3sD8gA5pZ';

/**
 * A cipher over a real master key — the production wiring, with the key supplied
 * explicitly so a test can also build one that *cannot* open what another wrote.
 *
 * @param  array<string, string>  $masterKeys
 */
function fieldCipher(array $masterKeys = ['app' => MASTER_KEY], string $activeKeyId = 'app'): EnvelopeFieldCipher
{
    return new EnvelopeFieldCipher(new ConfigMasterKeyWrapper($masterKeys, $activeKeyId, 'app', null));
}

/**
 * The sealed DEK as it sits in the database, straight from the driver — no model,
 * no casts, no accessors.
 */
function storedWrappedDek(EncryptionKey $key): string
{
    return (string) DB::table('encryption_keys')->where('id', $key->id)->value('wrapped_dek');
}

/*
|--------------------------------------------------------------------------
| 1. Round trip and per-tenant isolation
|--------------------------------------------------------------------------
*/

it('round-trips a value through a tenant key', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $ciphertext = $cipher->encrypt($tenant, 'EAAG1x-cloud-api-access-token');

    expect($ciphertext)->not->toContain('EAAG1x-cloud-api-access-token')
        ->and($cipher->decrypt($tenant, $ciphertext))->toBe('EAAG1x-cloud-api-access-token');
});

it('round-trips values a naive implementation would mangle', function (string $plaintext): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    expect($cipher->decrypt($tenant, $cipher->encrypt($tenant, $plaintext)))->toBe($plaintext);
})->with([
    'empty' => '',
    'single byte' => 'x',
    'unicode' => 'Ωमराठी — 日本語 🔐',
    'null bytes' => "before\0after",
    'newlines and dots' => "line1\nline2.wac1.FIELD",
    'json' => '{"access_token":"abc","verify_token":"def"}',
    'long' => 'x-very-long-secret-x',
]);

it('produces a different ciphertext every time so equal secrets are not correlatable', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $first = $cipher->encrypt($tenant, 'same-secret');
    $second = $cipher->encrypt($tenant, 'same-secret');

    expect($first)->not->toBe($second)
        ->and($cipher->decrypt($tenant, $first))->toBe($cipher->decrypt($tenant, $second));
});

it('gives each tenant its own data key', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $cipher = fieldCipher();

    $keyA = $cipher->provision($a);
    $keyB = $cipher->provision($b);

    expect($keyA->id)->not->toBe($keyB->id)
        ->and(storedWrappedDek($keyA))->not->toBe(storedWrappedDek($keyB))
        ->and($keyA->tenant_id)->toBe($a->id)
        ->and($keyB->tenant_id)->toBe($b->id);
});

it('provisions a lineage on first use and is idempotent about it', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $first = $cipher->provision($tenant);
    $again = $cipher->provision($tenant);
    $cipher->encrypt($tenant, 'secret');

    expect($again->id)->toBe($first->id)
        ->and($first->version)->toBe(EncryptionKey::FIRST_VERSION)
        ->and($first->status)->toBe(KeyStatus::Active)
        ->and(EncryptionKey::forTenant($tenant->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 2. Cross-tenant rejection and tamper detection (the AEAD binding)
|--------------------------------------------------------------------------
*/

it('refuses to decrypt one tenant ciphertext with another tenant key', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $cipher = fieldCipher();

    // Both tenants are fully provisioned, so the failure is the cryptographic
    // binding — not a missing key.
    $cipher->provision($b);
    $ciphertext = $cipher->encrypt($a, 'tenant-a-only');

    expect(fn () => $cipher->decrypt($b, $ciphertext))
        ->toThrow(CiphertextIntegrityException::class, 'failed authentication');
});

it('refuses a ciphertext copied into another tenant row', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $cipher = fieldCipher();
    $cipher->provision($b);

    // Same key version, same purpose, same algorithm: the only difference is which
    // tenant the value is being read for, and that is authenticated.
    $stolen = $cipher->encrypt($a, 'lead phone +15551234567');

    expect(fn () => $cipher->decrypt($b, $stolen))->toThrow(CiphertextIntegrityException::class)
        ->and($cipher->decrypt($a, $stolen))->toBe('lead phone +15551234567');
});

it('detects tampering with any part of the envelope', function (int $segment): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $parts = explode('.', $cipher->encrypt($tenant, 'do-not-modify-me'));

    // Flip exactly one base64url character of the chosen segment — the smallest
    // change an attacker could make.
    $original = $parts[$segment];
    $parts[$segment] = ($original[0] === 'A' ? 'B' : 'A').substr($original, 1);

    expect(fn () => $cipher->decrypt($tenant, implode('.', $parts)))
        ->toThrow(CiphertextIntegrityException::class);
})->with([
    'nonce' => [3],
    'tag' => [4],
    'ciphertext' => [5],
]);

it('refuses a value whose key version was edited to another existing version', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $ciphertext = $cipher->encrypt($tenant, 'written-under-v1');
    $cipher->rotate($tenant);

    // Version 2 exists and can be unwrapped, so this is the interesting case: the
    // version is part of the authenticated context, so the value still will not open.
    $repointed = str_replace('wac1.FIELD.1.', 'wac1.FIELD.2.', $ciphertext);

    expect(fn () => $cipher->decrypt($tenant, $repointed))->toThrow(CiphertextIntegrityException::class);
});

it('keeps key purposes as separate lineages', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $field = $cipher->encrypt($tenant, 'field-secret', KeyPurpose::Field);
    $export = $cipher->encrypt($tenant, 'export-secret', KeyPurpose::Export);

    expect($cipher->decrypt($tenant, $export, KeyPurpose::Export))->toBe('export-secret')
        ->and(fn () => $cipher->decrypt($tenant, $field, KeyPurpose::Export))
        ->toThrow(CiphertextIntegrityException::class, 'separate key lineages')
        ->and(storedWrappedDek($cipher->provision($tenant, KeyPurpose::Field)))
        ->not->toBe(storedWrappedDek($cipher->provision($tenant, KeyPurpose::Export)));
});

/*
|--------------------------------------------------------------------------
| 3. Rotation
|--------------------------------------------------------------------------
*/

it('reads values written before a rotation and writes new ones under the new version', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $old = $cipher->encrypt($tenant, 'written-before-rotation');
    $newKey = $cipher->rotate($tenant);
    $new = $cipher->encrypt($tenant, 'written-after-rotation');

    expect($newKey->version)->toBe(2)
        ->and($old)->toStartWith('wac1.FIELD.1.')
        ->and($new)->toStartWith('wac1.FIELD.2.')
        // The whole point: no re-encryption was needed for the old value to stay readable.
        ->and($cipher->decrypt($tenant, $old))->toBe('written-before-rotation')
        ->and($cipher->decrypt($tenant, $new))->toBe('written-after-rotation');
});

it('leaves exactly one active version behind, with the previous one retiring', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $first = $cipher->provision($tenant);
    $second = $cipher->rotate($tenant);
    $third = $cipher->rotate($tenant);

    $lineage = EncryptionKey::lineage($tenant->id, KeyPurpose::Field)->get();

    expect($lineage->pluck('version')->all())->toBe([3, 2, 1])
        ->and($lineage->where('status', KeyStatus::Active)->pluck('id')->all())->toBe([$third->id])
        ->and($first->fresh()?->status)->toBe(KeyStatus::Retiring)
        ->and($second->fresh()?->status)->toBe(KeyStatus::Retiring)
        ->and($first->fresh()?->rotated_at)->not->toBeNull()
        ->and($third->fresh()?->rotated_at)->toBeNull();
});

it('re-encrypts a stale value forward on demand', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $old = $cipher->encrypt($tenant, 'lazily-migrated');
    expect($cipher->isStale($tenant, $old))->toBeFalse();

    $cipher->rotate($tenant);
    expect($cipher->isStale($tenant, $old))->toBeTrue();

    $fresh = $cipher->reencrypt($tenant, $old);

    expect($cipher->isStale($tenant, $fresh))->toBeFalse()
        ->and($fresh)->toStartWith('wac1.FIELD.2.')
        ->and($cipher->decrypt($tenant, $fresh))->toBe('lazily-migrated');
});

it('refuses to read a value whose version has been retired', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $ciphertext = $cipher->encrypt($tenant, 'still-referenced');
    $cipher->rotate($tenant);

    // Retiring a version that ciphertext still references is an operator error, and
    // it fails loudly rather than degrading.
    $retired = EncryptionKey::atVersion($tenant->id, KeyPurpose::Field, 1);
    $retired?->forceFill(['status' => KeyStatus::Retired])->save();
    $cipher->forgetKeys();

    expect(fn () => $cipher->decrypt($tenant, $ciphertext))
        ->toThrow(KeyUnavailableException::class, 'RETIRED');
});

it('refuses to read a value naming a version the tenant does not have', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $ciphertext = $cipher->encrypt($tenant, 'from-a-foreign-backup');

    expect(fn () => $cipher->decrypt($tenant, str_replace('wac1.FIELD.1.', 'wac1.FIELD.9.', $ciphertext)))
        ->toThrow(KeyUnavailableException::class, 'no FIELD key at version 9');
});

/*
|--------------------------------------------------------------------------
| 4. Fail closed — no plaintext fallback, ever
|--------------------------------------------------------------------------
*/

it('fails closed when no master key is available, writing nothing', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher([], 'app');

    expect(fn () => $cipher->encrypt($tenant, 'never-written'))
        ->toThrow(KeyUnavailableException::class)
        // Nothing half-provisioned: a key row is only written once its DEK is sealed.
        ->and(EncryptionKey::forTenant($tenant->id)->count())->toBe(0);
});

it('fails closed when the master key cannot open an existing data key', function (): void {
    $tenant = Tenant::factory()->create();
    $ciphertext = fieldCipher()->encrypt($tenant, 'sealed-under-the-old-master-key');

    // The master key was replaced without keeping the old one — the DEK is intact but
    // unopenable, which is a retryable key-store problem, not a data problem.
    $afterLostMasterKey = fieldCipher(['app' => OTHER_MASTER_KEY]);

    try {
        $afterLostMasterKey->decrypt($tenant, $ciphertext);
        $this->fail('Expected the read to fail closed.');
    } catch (KeyUnavailableException $e) {
        expect($e->getStatusCode())->toBe(503)
            ->and($e->isRetryable())->toBeTrue()
            ->and($e->getMessage())->not->toContain('sealed-under-the-old-master-key')
            ->and($e->getMessage())->not->toContain(MASTER_KEY)
            ->and($e->getMessage())->not->toContain($tenant->id);
    }
});

it('never returns a plaintext value that is sitting in an encrypted column', function (string $stored): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();
    $cipher->provision($tenant);

    // The failure mode this whole class exists to prevent: a value that was never
    // encrypted must not be handed back as though it had been.
    expect(fn () => $cipher->decrypt($tenant, $stored))->toThrow(CiphertextIntegrityException::class);
})->with([
    'plaintext secret' => 'EAAG1x-plaintext-token',
    'empty string' => '',
    'laravel encrypter payload' => 'eyJpdiI6IngiLCJ2YWx1ZSI6InkiLCJtYWMiOiJ6In0=',
]);

it('reports a value as an envelope only when it is one', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    expect($cipher->isEnvelope($cipher->encrypt($tenant, 'secret')))->toBeTrue()
        ->and($cipher->isEnvelope('secret'))->toBeFalse();
});

it('refuses a cipher that is not an authenticated mode', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = new EnvelopeFieldCipher(new ConfigMasterKeyWrapper(['app' => MASTER_KEY], 'app'), 'aes-256-cbc');

    // A non-AEAD cipher silently ignores the tag and the tenant binding, so it is
    // refused rather than used with weaker guarantees.
    expect(fn () => $cipher->encrypt($tenant, 'secret'))
        ->toThrow(KeyUnavailableException::class, 'aes-256-cbc');
});

/*
|--------------------------------------------------------------------------
| 5. Key material never lands in the database, a dump, or a log
|--------------------------------------------------------------------------
*/

it('stores the data key only in wrapped form', function (): void {
    $tenant = Tenant::factory()->create();
    $wrapper = new ConfigMasterKeyWrapper(['app' => MASTER_KEY], 'app');
    $cipher = new EnvelopeFieldCipher($wrapper);

    $key = $cipher->provision($tenant);
    $stored = storedWrappedDek($key);
    $dataKey = $wrapper->unwrap($key->kms_key_id, $stored, EnvelopeFieldCipher::wrapContext($tenant->id, KeyPurpose::Field, 1));

    expect(strlen($dataKey))->toBe(32)
        // The raw column contains the sealed form and nothing resembling the DEK.
        ->and($stored)->not->toBe($dataKey)
        ->and($stored)->not->toContain(base64_encode($dataKey))
        ->and(str_contains((string) base64_decode($stored, true), $dataKey))->toBeFalse()
        // ...and the model refuses to serialise even the sealed form.
        ->and($key->toArray())->not->toHaveKey('wrapped_dek')
        ->and(json_encode($key))->not->toContain($stored);
});

it('keeps unwrapped data keys out of dumps', function (): void {
    $tenant = Tenant::factory()->create();
    $wrapper = new ConfigMasterKeyWrapper(['app' => MASTER_KEY], 'app');
    $cipher = new EnvelopeFieldCipher($wrapper);

    $key = $cipher->provision($tenant);
    $cipher->encrypt($tenant, 'secret');
    $dataKey = $wrapper->unwrap(
        $key->kms_key_id,
        storedWrappedDek($key),
        EnvelopeFieldCipher::wrapContext($tenant->id, KeyPurpose::Field, 1),
    );

    $dumped = print_r($cipher->__debugInfo(), true);

    expect($dumped)->toContain('redacted')
        ->and(str_contains($dumped, $dataKey))->toBeFalse()
        ->and(str_contains($dumped, base64_encode($dataKey)))->toBeFalse();
});

it('drops unwrapped data keys on request', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $ciphertext = $cipher->encrypt($tenant, 'secret');
    $cipher->forgetKeys();

    expect($cipher->__debugInfo()['unwrappedDataKeys'])->toBe('0 (redacted)')
        // Forgetting is a cache flush, not a loss: the DEK is re-unwrapped on demand.
        ->and($cipher->decrypt($tenant, $ciphertext))->toBe('secret');
});

/*
|--------------------------------------------------------------------------
| 6. Every context a key has to resolve in
|--------------------------------------------------------------------------
*/

it('resolves keys during provisioning, before any tenant context exists', function (): void {
    $context = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    // TenantLifecycle::provision runs here: no tenant is bound, and a tenant-scoped
    // read would fail closed. EncryptionKey::forTenant() names the tenant instead.
    expect($context->hasTenant())->toBeFalse()
        ->and($context->actingAsPlatform())->toBeFalse();

    $key = $cipher->provision($tenant);

    expect($key->tenant_id)->toBe($tenant->id)
        ->and($cipher->decrypt($tenant, $cipher->encrypt($tenant, 'provisioned')))->toBe('provisioned');
});

it('resolves keys inside the acting tenant context', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();

    $plaintext = app(TenantContext::class)->runFor($tenant, function () use ($cipher, $tenant): string {
        return $cipher->decrypt($tenant, $cipher->encrypt($tenant, 'in-request'));
    });

    expect($plaintext)->toBe('in-request');
});

it('resolves keys from platform mode, for the rotation scheduler', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = fieldCipher();
    $ciphertext = $cipher->encrypt($tenant, 'rotate-me');

    $result = app(TenantContext::class)->asPlatform('scheduled DEK rotation', function () use ($cipher, $tenant, $ciphertext): array {
        $key = $cipher->rotate($tenant);

        return [$key->version, $cipher->decrypt($tenant, $ciphertext), $cipher->reencrypt($tenant, $ciphertext)];
    });

    expect($result[0])->toBe(2)
        ->and($result[1])->toBe('rotate-me')
        ->and($result[2])->toStartWith('wac1.FIELD.2.');
});

it('refuses to mint a key for another tenant from inside a tenant context', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $cipher = fieldCipher();

    // Cross-tenant provisioning is forgery, and the tenancy guard denies it (403)
    // before any key material is generated.
    app(TenantContext::class)->runFor($a, function () use ($cipher, $b): void {
        expect(fn () => $cipher->provision($b))
            ->toThrow(App\Exceptions\Tenancy\CrossTenantAccessException::class);
    });

    expect(EncryptionKey::forTenant($b->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 7. Wiring
|--------------------------------------------------------------------------
*/

it('binds a working cipher out of the box', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = app(FieldCipher::class);

    expect($cipher)->toBeInstanceOf(EnvelopeFieldCipher::class)
        ->and(app(KeyWrapper::class))->toBeInstanceOf(ConfigMasterKeyWrapper::class)
        // No configuration required: the default master key provider really works.
        ->and($cipher->decrypt($tenant, $cipher->encrypt($tenant, 'zero-config')))->toBe('zero-config');
});

it('scopes the cipher to one unit of work so DEKs do not outlive it', function (): void {
    $tenant = Tenant::factory()->create();

    app(FieldCipher::class)->encrypt($tenant, 'secret');

    expect(app(FieldCipher::class))->toBe(app(FieldCipher::class));

    // What a request or job boundary does: scoped instances are rebuilt, so the
    // in-process DEK cache cannot leak into the next unit of work.
    app()->forgetScopedInstances();

    expect(app(FieldCipher::class)->__debugInfo()['unwrappedDataKeys'])->toBe('0 (redacted)');
});
