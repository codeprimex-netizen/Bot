<?php

declare(strict_types=1);

use App\Console\Commands\RotateMasterKey;
use App\Models\AuditLog;
use App\Models\EncryptionKey;
use App\Models\SigningSecret;
use App\Models\Tenant;
use App\Services\Security\ConfigMasterKeyWrapper;
use App\Services\Security\FieldCipher;
use App\Services\Security\KeyWrapper;
use App\Services\Security\MasterKeyRewrapper;
use App\Services\Security\SigningSecretStore;
use Tests\Fixtures\Security\FakeKms;

/*
|--------------------------------------------------------------------------
| Master-key rotation (Req 32.6 / NFR3)
|--------------------------------------------------------------------------
| The one property that matters, and the reason every test here ends the same way:
| **a rotation must not make historical data undecryptable.** Re-wrapping changes only
| the seal around a DEK, so every value already stored keeps naming the same key version
| and keeps decrypting. Anything else is data loss, so each test below encrypts, rotates,
| and then reads the *old* ciphertext back.
|
| The second property is completeness: a table left out of the sweep becomes permanently
| unopenable the moment the retired master key is removed. So signing secrets are checked
| alongside DEKs, and the sweep's own report is checked for honesty (`pending`, `failed`).
*/

/**
 * A tenant with one encrypted value, plus a signing secret — the two kinds of material a
 * master-key rotation has to move.
 *
 * @return array{0: Tenant, 1: string, 2: string}
 */
function sealedEstate(string $plaintext = 'lead@example.com'): array
{
    $tenant = Tenant::factory()->create();
    $ciphertext = app(FieldCipher::class)->encrypt($tenant, $plaintext);
    $signature = app(SigningSecretStore::class)->sign('gateway:razorpay', '{"event":"payment.captured"}');

    return [$tenant, $ciphertext, $signature];
}

it('re-wraps every data key and signing secret under the new master key', function (): void {
    $kms = FakeKms::bind();
    [$tenant, $ciphertext, $signature] = sealedEstate();

    expect(EncryptionKey::withoutTenantScope()->where('kms_key_id', $kms->keyId(1))->count())->toBe(1)
        ->and(SigningSecret::query()->where('kms_key_id', $kms->keyId(1))->count())->toBe(1);

    thisTest()->artisan(RotateMasterKey::class)
        ->expectsOutputToContain('Master key advanced: fake:master:v1 -> fake:master:v2.')
        ->assertSuccessful();

    expect(EncryptionKey::withoutTenantScope()->where('kms_key_id', $kms->keyId(2))->count())->toBe(1)
        ->and(SigningSecret::query()->where('kms_key_id', $kms->keyId(2))->count())->toBe(1)
        ->and(EncryptionKey::withoutTenantScope()->where('kms_key_id', $kms->keyId(1))->count())->toBe(0);

    // The point of the whole exercise: nothing became unreadable.
    app(FieldCipher::class)->forgetKeys();
    app(SigningSecretStore::class)->forgetSecrets();

    expect(app(FieldCipher::class)->decrypt($tenant, $ciphertext))->toBe('lead@example.com')
        ->and(app(SigningSecretStore::class)->verify('gateway:razorpay', '{"event":"payment.captured"}', $signature))->toBeTrue();
});

it('changes only the seal — never the key version, status, or DEK', function (): void {
    FakeKms::bind();
    [$tenant, $ciphertext] = sealedEstate('order-details');

    $before = EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    thisTest()->artisan(RotateMasterKey::class)->assertSuccessful();

    $after = EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($after->id)->toBe($before->id)
        // A master-key rotation must not move a lineage's rotation clock or demote it.
        ->and($after->version)->toBe($before->version)
        ->and($after->status)->toBe($before->status)
        ->and($after->wrapped_dek)->not->toBe($before->wrapped_dek)
        ->and(app(FieldCipher::class)->decrypt($tenant, $ciphertext))->toBe('order-details')
        // Exactly one version: re-wrapping is not a rotation of the DEK itself.
        ->and(EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('leaves the overlap window of a rotated signing secret intact', function (): void {
    FakeKms::bind();

    $secrets = app(SigningSecretStore::class);
    $secrets->provision('bridge:session:01H');
    $old = $secrets->sign('bridge:session:01H', 'payload');
    $rotation = $secrets->rotate('bridge:session:01H');

    thisTest()->artisan(RotateMasterKey::class)->assertSuccessful();

    $previous = SigningSecret::atVersion('bridge:session:01H', (int) $rotation->previousVersion);

    expect($previous)->not->toBeNull()
        ->and($previous?->accepted_until?->toIso8601String())->toBe($rotation->acceptedUntil?->toIso8601String())
        // A peer still signing with the old secret is unaffected by the master-key rotation.
        ->and(app(SigningSecretStore::class)->verify('bridge:session:01H', 'payload', $old))->toBeTrue();
});

it('reports rows it cannot open and leaves them byte-for-byte untouched', function (): void {
    $kms = FakeKms::bind();
    [$tenant] = sealedEstate();

    $before = (string) EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->value('wrapped_dek');

    // The master key those rows were sealed under is gone — an operator retired it too
    // early. Overwriting anything now would destroy the only blob that still opens it.
    $kms->rotate();
    $kms->revoke(1);

    thisTest()->artisan(RotateMasterKey::class, ['--rewrap-only' => true])
        ->expectsOutputToContain('could not be opened and were left untouched')
        ->assertFailed();

    expect((string) EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->value('wrapped_dek'))
        ->toBe($before);
});

it('stops after its batch and says what is left', function (): void {
    FakeKms::bind();

    foreach (range(1, 3) as $ignored) {
        app(FieldCipher::class)->encrypt(Tenant::factory()->create(), 'secret');
    }

    thisTest()->artisan(RotateMasterKey::class, ['--limit' => '1'])
        ->expectsOutputToContain('still sealed under an older master key')
        ->assertSuccessful();

    // One per store per pass, and the rest is genuinely reported as outstanding.
    expect(app(MasterKeyRewrapper::class)->pending())->toBe(2);

    thisTest()->artisan(RotateMasterKey::class, ['--rewrap-only' => true, '--passes' => '5'])
        ->expectsOutputToContain('Rotation complete')
        ->assertSuccessful();

    expect(app(MasterKeyRewrapper::class)->pending())->toBe(0);
});

it('audits both halves on the platform chain, with ids and counts only', function (): void {
    FakeKms::bind();
    sealedEstate('lead@example.com');

    thisTest()->artisan(RotateMasterKey::class)->assertSuccessful();

    $actions = AuditLog::withoutTenantScope()->pluck('action')->all();

    expect($actions)->toContain('security.master_key.rotated')
        ->and($actions)->toContain('security.master_key.rewrapped');

    $rewrap = AuditLog::withoutTenantScope()->where('action', 'security.master_key.rewrapped')->firstOrFail();

    expect($rewrap->payload)->toHaveKeys(['key_id', 'rewrapped', 'failed', 'pending'])
        ->and($rewrap->payload['key_id'])->toBe('fake:master:v2')
        ->and(json_encode($rewrap->payload))->not->toContain('fake:v2:');
});

it('says nothing to do when everything is already under the active key', function (): void {
    FakeKms::bind();
    sealedEstate();

    thisTest()->artisan(RotateMasterKey::class)->assertSuccessful();

    thisTest()->artisan(RotateMasterKey::class, ['--rewrap-only' => true])
        ->expectsOutputToContain('already sealed under the active master key')
        ->assertSuccessful();
});

it('reports what is outstanding without changing anything on a dry run', function (): void {
    $kms = FakeKms::bind();
    sealedEstate();
    $kms->rotate();

    thisTest()->artisan(RotateMasterKey::class, ['--dry-run' => true])
        ->expectsOutputToContain('outstanding')
        ->assertSuccessful();

    expect(EncryptionKey::withoutTenantScope()->where('kms_key_id', $kms->keyId(1))->count())->toBe(1)
        ->and($kms->versions())->toBe([1, 2]);
});

it('rejects nonsense options', function (array $options): void {
    FakeKms::bind();

    thisTest()->artisan(RotateMasterKey::class, $options)->assertExitCode(RotateMasterKey::INVALID);
})->with([
    'zero limit' => [['--limit' => '0']],
    'negative passes' => [['--passes' => '-1']],
    'non-numeric limit' => [['--limit' => 'lots']],
]);

/*
|--------------------------------------------------------------------------
| The operator-managed rotation (ConfigMasterKeyWrapper)
|--------------------------------------------------------------------------
| The default wrapper cannot mint master keys — its material is supplied by the
| operator — so "rotation" there means: generate a new master key, add it to
| `master_keys`, point `master_key_id` at it, and keep the old id listed until the sweep
| reports nothing outstanding. That path has to work just as well, and it is the one most
| deployments will actually use.
*/

it('re-wraps under a newly configured master key id, keeping the old one available', function (): void {
    config([
        'wa.security.encryption.wrapper' => ConfigMasterKeyWrapper::class,
        'wa.security.encryption.master_keys' => ['2024' => 'aP3kL9vQ2xR7mN4bT6yC8wZ1sD5gH0jF'],
        'wa.security.encryption.master_key_id' => '2024',
    ]);
    app()->forgetInstance(KeyWrapper::class);
    app()->forgetInstance(FieldCipher::class);
    app()->forgetInstance(MasterKeyRewrapper::class);

    $tenant = Tenant::factory()->create();
    $ciphertext = app(FieldCipher::class)->encrypt($tenant, 'sealed-under-2024');

    // The operator adds the new key and switches to it, keeping the old one listed.
    config([
        'wa.security.encryption.master_keys' => [
            '2024' => 'aP3kL9vQ2xR7mN4bT6yC8wZ1sD5gH0jF',
            '2025' => 'Xn2wQ7rT4yV9bM1kL6cJ0hF3sD8gA5pZ',
        ],
        'wa.security.encryption.master_key_id' => '2025',
    ]);
    app()->forgetInstance(KeyWrapper::class);
    app()->forgetInstance(FieldCipher::class);
    app()->forgetInstance(MasterKeyRewrapper::class);

    thesePassesRewrapOnly();

    expect(EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->value('kms_key_id'))->toBe('2025')
        // Still readable — which is the whole requirement.
        ->and(app(FieldCipher::class)->decrypt($tenant, $ciphertext))->toBe('sealed-under-2024');
});

/**
 * Run the sweep the way the hourly schedule does, and assert it explains that this key
 * store cannot mint keys itself rather than pretending it rotated one.
 */
function thesePassesRewrapOnly(): void
{
    thisTest()->artisan(RotateMasterKey::class)
        ->expectsOutputToContain('cannot mint master keys')
        ->assertSuccessful();
}
