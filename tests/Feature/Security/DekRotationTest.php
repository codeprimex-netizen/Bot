<?php

declare(strict_types=1);

use App\Console\Commands\RotateTenantKeys;
use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Models\AuditLog;
use App\Models\EncryptionKey;
use App\Models\Tenant;
use App\Services\Security\DekRotator;
use App\Services\Security\FieldCipher;
use Illuminate\Support\Carbon;
use Tests\Fixtures\Security\FakeKms;

/*
|--------------------------------------------------------------------------
| Scheduled per-tenant DEK rotation (Req 32.6 / NFR3)
|--------------------------------------------------------------------------
| Rotation is worth scheduling only if it is safe to run unattended, and "safe" here has
| one meaning: **values written before the rotation must still decrypt after it.** So the
| shape of almost every test is encrypt → age the key → rotate → read the old value.
|
| The second thing under test is the *decision*: a lineage is due when its ACTIVE version
| is older than the interval, and nothing else. A rotation that fires early burns key
| versions; one that never fires is a compliance finding.
*/

/**
 * A tenant with an encrypted value whose key lineage looks `$days` old.
 *
 * The clock is moved rather than the row edited, so the same `created_at` the sweep reads
 * is the one the encryption actually happened under.
 *
 * @return array{0: Tenant, 1: string}
 */
function agedLineage(int $days, string $plaintext = 'lead@example.com'): array
{
    Carbon::setTestNow(Carbon::parse('2025-06-01 09:00:00')->subDays($days));

    $tenant = Tenant::factory()->create();
    $ciphertext = app(FieldCipher::class)->encrypt($tenant, $plaintext);

    Carbon::setTestNow('2025-06-01 09:00:00');

    return [$tenant, $ciphertext];
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rotates a lineage past the interval and keeps the old ciphertext readable', function (): void {
    FakeKms::bind();
    [$tenant, $ciphertext] = agedLineage(120);

    thisTest()->artisan(RotateTenantKeys::class)
        ->expectsOutputToContain('rotated')
        ->assertSuccessful();

    $versions = EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('status', 'version');

    expect($versions[1])->toBe(KeyStatus::Retiring)
        ->and($versions[2])->toBe(KeyStatus::Active)
        // The whole requirement, in one assertion: a value written under v1 still decrypts.
        ->and(app(FieldCipher::class)->decrypt($tenant, $ciphertext))->toBe('lead@example.com');
});

it('writes new values under the new version after rotating', function (): void {
    FakeKms::bind();
    [$tenant, $old] = agedLineage(120, 'written-under-v1');

    thisTest()->artisan(RotateTenantKeys::class)->assertSuccessful();

    $cipher = app(FieldCipher::class);
    $cipher->forgetKeys();
    $new = $cipher->encrypt($tenant, 'written-under-v2');

    expect($cipher->isStale($tenant, $old))->toBeTrue()
        ->and($cipher->isStale($tenant, $new))->toBeFalse()
        ->and($cipher->decrypt($tenant, $old))->toBe('written-under-v1')
        ->and($cipher->decrypt($tenant, $new))->toBe('written-under-v2')
        // And the lazy path moves a value forward without changing what it means.
        ->and($cipher->decrypt($tenant, $cipher->reencrypt($tenant, $old)))->toBe('written-under-v1');
});

it('leaves a lineage inside the interval alone', function (): void {
    FakeKms::bind();
    [$tenant] = agedLineage(10);

    thisTest()->artisan(RotateTenantKeys::class)
        ->expectsOutputToContain('No data keys are due for rotation.')
        ->assertSuccessful();

    expect(EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('never retires a version, because retired versions cannot decrypt', function (): void {
    FakeKms::bind();
    [$tenant] = agedLineage(400);

    // Four rotations over a long-lived tenant: every superseded version stays RETIRING, so
    // every value ever written for this tenant is still readable.
    foreach (range(1, 4) as $ignored) {
        app(DekRotator::class)->rotate($tenant);
    }

    $statuses = EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->pluck('status')->all();

    expect($statuses)->not->toContain(KeyStatus::Retired)
        ->and(array_filter($statuses, fn (KeyStatus $s): bool => $s === KeyStatus::Active))->toHaveCount(1);
});

it('rotates one named tenant on demand, and only with --force when it is not due', function (): void {
    FakeKms::bind();
    [$tenant] = agedLineage(5);

    thisTest()->artisan(RotateTenantKeys::class, ['--tenant' => $tenant->id])
        ->expectsOutputToContain('not due')
        ->assertSuccessful();

    expect(EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);

    thisTest()->artisan(RotateTenantKeys::class, ['--tenant' => $tenant->slug, '--force' => true])
        ->expectsOutputToContain('version 2')
        ->assertSuccessful();

    expect(EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('rotates one purpose without touching the others', function (): void {
    FakeKms::bind();

    Carbon::setTestNow('2025-01-01 00:00:00');
    $tenant = Tenant::factory()->create();
    $field = app(FieldCipher::class)->encrypt($tenant, 'field', KeyPurpose::Field);
    $export = app(FieldCipher::class)->encrypt($tenant, 'export', KeyPurpose::Export);
    Carbon::setTestNow('2025-06-01 00:00:00');

    thisTest()->artisan(RotateTenantKeys::class, [
        '--tenant' => $tenant->id,
        '--purpose' => 'export',
    ])->assertSuccessful();

    expect(EncryptionKey::activeFor($tenant->id, KeyPurpose::Export)?->version)->toBe(2)
        ->and(EncryptionKey::activeFor($tenant->id, KeyPurpose::Field)?->version)->toBe(1)
        ->and(app(FieldCipher::class)->decrypt($tenant, $export, KeyPurpose::Export))->toBe('export')
        ->and(app(FieldCipher::class)->decrypt($tenant, $field, KeyPurpose::Field))->toBe('field');
});

it('records every rotation on the tenant own audit chain, without key material', function (): void {
    FakeKms::bind();
    [$tenant] = agedLineage(120);

    thisTest()->artisan(RotateTenantKeys::class)->assertSuccessful();

    $entry = AuditLog::withoutTenantScope()->where('action', 'security.dek.rotated')->firstOrFail();

    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->payload['version'])->toBe(2)
        ->and($entry->payload['previous_version'])->toBe(1)
        ->and($entry->payload['purpose'])->toBe(KeyPurpose::Field->value)
        // The sealed DEK is not in the payload, and neither is anything derived from it.
        ->and(json_encode($entry->payload))->not->toContain('fake:v');
});

it('keeps sweeping when one tenant key store refuses, leaving that lineage usable', function (): void {
    $kms = FakeKms::bind();
    [$tenant, $ciphertext] = agedLineage(120);

    $kms->fail();

    thisTest()->artisan(RotateTenantKeys::class)
        ->expectsOutputToContain('could not be rotated')
        // Exit 0: the lineage is untouched, the tenant is unaffected, tomorrow retries.
        ->assertSuccessful();

    $kms->recover();
    app(FieldCipher::class)->forgetKeys();

    expect(EncryptionKey::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(EncryptionKey::activeFor($tenant->id, KeyPurpose::Field)?->version)->toBe(1)
        ->and(app(FieldCipher::class)->decrypt($tenant, $ciphertext))->toBe('lead@example.com');
});

it('counts what is due without rotating on a dry run', function (): void {
    FakeKms::bind();
    agedLineage(120);

    thisTest()->artisan(RotateTenantKeys::class, ['--dry-run' => true])
        ->expectsOutputToContain('due')
        ->assertSuccessful();

    expect(EncryptionKey::withoutTenantScope()->count())->toBe(1)
        ->and(app(DekRotator::class)->dueCount())->toBe(1);
});

it('honours the batch limit and picks the oldest lineages first', function (): void {
    FakeKms::bind();

    [$oldest] = agedLineage(300);
    [$newer] = agedLineage(120);

    thisTest()->artisan(RotateTenantKeys::class, ['--limit' => '1'])->assertSuccessful();

    expect(EncryptionKey::activeFor($oldest->id, KeyPurpose::Field)?->version)->toBe(2)
        ->and(EncryptionKey::activeFor($newer->id, KeyPurpose::Field)?->version)->toBe(1);
});

it('rejects nonsense options', function (array $options): void {
    FakeKms::bind();

    thisTest()->artisan(RotateTenantKeys::class, $options)->assertExitCode(RotateTenantKeys::INVALID);
})->with([
    'bad limit' => [['--limit' => '0']],
    'unknown purpose' => [['--purpose' => 'passwords']],
    'unknown tenant' => [['--tenant' => 'nobody']],
]);
