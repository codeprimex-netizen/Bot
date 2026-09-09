<?php

declare(strict_types=1);

use App\Enums\KeyPurpose;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\Tenant;
use App\Services\Security\FieldCipher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\SecretRecord;

/*
|--------------------------------------------------------------------------
| Declaring a column encrypted (Req 32.5 / NFR3; the API task 6.4 will use)
|--------------------------------------------------------------------------
| The casts are the ergonomic path onto `FieldCipher`: the tenant comes from the
| row's own `tenant_id`, so no call site can pass the wrong one — or forget. What
| is tested here is that the column really is ciphertext at rest, that it decrypts
| for its own tenant and nobody else's, and that the casts inherit the same
| fail-closed behaviour rather than softening it on the way through.
*/

beforeEach(function (): void {
    SecretRecord::migrate();
});

it('writes ciphertext to the column and reads plaintext back', function (): void {
    $tenant = Tenant::factory()->create();

    $record = app(TenantContext::class)->runFor($tenant, fn (): SecretRecord => SecretRecord::create([
        'label' => 'cloud-api',
        'access_token' => 'EAAG1x-secret-token',
        'secret_config' => ['verify_token' => 'vt-123', 'waba_id' => '99'],
    ]));

    $raw = DB::table('test_secret_records')->where('id', $record->id)->first();

    expect($raw->access_token)->toStartWith('wac1.FIELD.1.')
        ->and($raw->access_token)->not->toContain('EAAG1x-secret-token')
        ->and($raw->secret_config)->toStartWith('wac1.FIELD.1.')
        ->and($raw->secret_config)->not->toContain('vt-123')
        // ...and the label, which is not cast, stays readable — only what is declared
        // encrypted is encrypted.
        ->and($raw->label)->toBe('cloud-api');

    $fresh = app(TenantContext::class)->runFor($tenant, fn (): ?SecretRecord => SecretRecord::query()->find($record->id));

    expect($fresh?->access_token)->toBe('EAAG1x-secret-token')
        ->and($fresh?->secret_config)->toBe(['verify_token' => 'vt-123', 'waba_id' => '99']);
});

it('keeps null null', function (): void {
    $tenant = Tenant::factory()->create();

    $record = app(TenantContext::class)->runFor($tenant, fn (): SecretRecord => SecretRecord::create([
        'label' => 'no-credentials-yet',
        'access_token' => null,
        'secret_config' => null,
    ]));

    // "No token configured" must stay distinguishable from "a token exists", so an
    // absent value is not encrypted into something that looks present.
    expect(DB::table('test_secret_records')->where('id', $record->id)->value('access_token'))->toBeNull()
        ->and($record->fresh()?->access_token)->toBeNull()
        ->and($record->fresh()?->secret_config)->toBeNull();
});

it('round-trips an empty secret and a nested config', function (): void {
    $tenant = Tenant::factory()->create();

    $record = app(TenantContext::class)->runFor($tenant, fn (): SecretRecord => SecretRecord::create([
        'access_token' => '',
        'secret_config' => ['nested' => ['api_key' => 'k', 'flags' => [1, 2, 3]], 'unicode' => 'ключ'],
    ]));

    $fresh = $record->fresh();

    expect($fresh?->access_token)->toBe('')
        ->and($fresh?->secret_config)->toBe(['nested' => ['api_key' => 'k', 'flags' => [1, 2, 3]], 'unicode' => 'ключ']);
});

it('re-encrypts on write, so a rotation drains lazily', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = app(FieldCipher::class);

    $record = app(TenantContext::class)->runFor($tenant, fn (): SecretRecord => SecretRecord::create([
        'access_token' => 'v1-token',
    ]));

    $cipher->rotate($tenant);

    $stale = (string) DB::table('test_secret_records')->where('id', $record->id)->value('access_token');
    expect($cipher->isStale($tenant, $stale))->toBeTrue()
        // The old value is still readable — the rotation broke nothing.
        ->and($record->fresh()?->access_token)->toBe('v1-token');

    $record->fresh()?->forceFill(['access_token' => 'v1-token'])->save();

    $refreshed = (string) DB::table('test_secret_records')->where('id', $record->id)->value('access_token');
    expect($cipher->isStale($tenant, $refreshed))->toBeFalse()
        ->and($refreshed)->toStartWith('wac1.FIELD.2.');
});

it('cannot decrypt another tenant row even with the tenant scope removed', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $cipher = app(FieldCipher::class);
    $cipher->provision($b);

    $record = app(TenantContext::class)->runFor($a, fn (): SecretRecord => SecretRecord::create([
        'access_token' => 'tenant-a-token',
    ]));

    // Move the ciphertext onto a row tenant B owns — the database has no idea it is
    // wrong, and the tenancy scope is bypassed. The AEAD binding is the only control
    // left, and it holds.
    $stolen = app(TenantContext::class)->runFor($b, fn (): SecretRecord => SecretRecord::create([
        'access_token' => 'placeholder',
    ]));

    DB::table('test_secret_records')
        ->where('id', $stolen->id)
        ->update(['access_token' => DB::table('test_secret_records')->where('id', $record->id)->value('access_token')]);

    expect(fn (): ?string => SecretRecord::withoutTenantScope()->find($stolen->id)?->access_token)
        ->toThrow(CiphertextIntegrityException::class);
});

it('refuses to encrypt a value on a row with no tenant', function (): void {
    $record = new SecretRecord;
    $record->setAttribute('label', 'orphan');

    // Per-tenant crypto has no tenant-less mode: there is no global key to fall back
    // to, so the write is refused rather than silently done under somebody's key.
    expect(fn () => $record->setAttribute('access_token', 'secret'))
        ->toThrow(KeyUnavailableException::class, 'carries no tenant_id');
});

it('refuses to read a plaintext value that was written around the cast', function (): void {
    $tenant = Tenant::factory()->create();

    $record = app(TenantContext::class)->runFor($tenant, fn (): SecretRecord => SecretRecord::create([
        'access_token' => 'properly-encrypted',
    ]));

    DB::table('test_secret_records')->where('id', $record->id)->update(['access_token' => 'raw-plaintext-token']);

    expect(fn (): ?string => $record->fresh()?->access_token)
        ->toThrow(CiphertextIntegrityException::class);
});

it('refuses a config value that decrypts to something that is not JSON', function (): void {
    $tenant = Tenant::factory()->create();

    $record = app(TenantContext::class)->runFor($tenant, fn (): SecretRecord => SecretRecord::create([
        'secret_config' => ['a' => 'b'],
    ]));

    // Properly encrypted, correct tenant, correct version — but not a document. That
    // is still a refusal, because "unreadable credentials" must not look like "no
    // credentials".
    DB::table('test_secret_records')->where('id', $record->id)->update([
        'secret_config' => app(FieldCipher::class)->encrypt($tenant, 'not-json', KeyPurpose::Field),
    ]);

    expect(fn (): ?array => $record->fresh()?->secret_config)
        ->toThrow(CiphertextIntegrityException::class, 'not a JSON object');
});
