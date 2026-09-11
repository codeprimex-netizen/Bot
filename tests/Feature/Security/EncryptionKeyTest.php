<?php

declare(strict_types=1);

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Models\EncryptionKey;
use App\Models\Tenant;
use App\Services\Security\FieldCipher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| encryption_keys schema and model invariants (Req 32.5 / NFR3)
|--------------------------------------------------------------------------
| This table holds key material, so its constraints are security controls: one
| active version per lineage (or "which key did we write under?" has no answer),
| one row per version, and a cascade so offboarding a tenant destroys its keys —
| the cheapest possible crypto-shredding.
|
| Reads here cross tenants deliberately (they are about the table, not about one
| tenant's data) and say so with the explicit `withoutTenantScope()` hatch.
*/

it('stores a wrapped key with its lineage metadata', function (): void {
    $tenant = Tenant::factory()->create();

    $key = app(FieldCipher::class)->provision($tenant, KeyPurpose::Export);
    $row = DB::table('encryption_keys')->where('id', $key->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe($tenant->id)
        ->and($row->purpose)->toBe(KeyPurpose::Export->value)
        ->and($row->status)->toBe(KeyStatus::Active->value)
        ->and($row->version)->toBe(1)
        ->and($row->active_flag)->toBe(1)
        ->and($row->kms_key_id)->toBe('app')
        ->and($row->algorithm)->toBe('aes-256-gcm')
        ->and($row->wrapped_dek)->not->toBe('')
        ->and($row->rotated_at)->toBeNull();
});

it('casts its enums, version and timestamps', function (): void {
    $tenant = Tenant::factory()->create();
    $cipher = app(FieldCipher::class);
    $cipher->provision($tenant);
    $cipher->rotate($tenant);

    $retiring = EncryptionKey::atVersion($tenant->id, KeyPurpose::Field, 1);

    expect($retiring?->purpose)->toBe(KeyPurpose::Field)
        ->and($retiring?->status)->toBe(KeyStatus::Retiring)
        ->and($retiring?->version)->toBe(1)
        ->and($retiring?->rotated_at)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($retiring?->canDecrypt())->toBeTrue()
        ->and($retiring?->canEncrypt())->toBeFalse();
});

it('permits only one active version per tenant and purpose', function (): void {
    $tenant = Tenant::factory()->create();
    EncryptionKey::factory()->create(['tenant_id' => $tenant->id]);

    // A second active row in the same lineage is refused by the database, which is
    // what makes a concurrent double-rotation safe.
    expect(fn () => EncryptionKey::factory()->version(2)->create(['tenant_id' => $tenant->id]))
        ->toThrow(QueryException::class);
});

it('permits many retiring versions alongside the active one', function (): void {
    $tenant = Tenant::factory()->create();

    EncryptionKey::factory()->retiring()->version(1)->create(['tenant_id' => $tenant->id]);
    EncryptionKey::factory()->retiring()->version(2)->create(['tenant_id' => $tenant->id]);
    EncryptionKey::factory()->version(3)->create(['tenant_id' => $tenant->id]);

    expect(EncryptionKey::lineage($tenant->id, KeyPurpose::Field)->pluck('version')->all())->toBe([3, 2, 1])
        ->and(EncryptionKey::activeFor($tenant->id, KeyPurpose::Field)?->version)->toBe(3)
        ->and(EncryptionKey::latestVersion($tenant->id, KeyPurpose::Field))->toBe(3);
});

it('permits one active version per purpose', function (): void {
    $tenant = Tenant::factory()->create();

    EncryptionKey::factory()->ofPurpose(KeyPurpose::Field)->create(['tenant_id' => $tenant->id]);
    EncryptionKey::factory()->ofPurpose(KeyPurpose::Export)->create(['tenant_id' => $tenant->id]);
    EncryptionKey::factory()->ofPurpose(KeyPurpose::Backup)->create(['tenant_id' => $tenant->id]);

    foreach (KeyPurpose::cases() as $purpose) {
        expect(EncryptionKey::activeFor($tenant->id, $purpose)?->purpose)->toBe($purpose);
    }
});

it('enforces one row per version', function (): void {
    $tenant = Tenant::factory()->create();
    EncryptionKey::factory()->retiring()->version(1)->create(['tenant_id' => $tenant->id]);

    expect(fn () => EncryptionKey::factory()->retiring()->version(1)->create(['tenant_id' => $tenant->id]))
        ->toThrow(QueryException::class);
});

it('derives the active flag from the status rather than trusting the caller', function (): void {
    $tenant = Tenant::factory()->create();
    $key = EncryptionKey::factory()->create(['tenant_id' => $tenant->id, 'active_flag' => 1]);

    $key->forceFill(['status' => KeyStatus::Retiring, 'active_flag' => 1])->save();

    expect(DB::table('encryption_keys')->where('id', $key->id)->value('active_flag'))->toBeNull()
        ->and(EncryptionKey::activeFor($tenant->id, KeyPurpose::Field))->toBeNull();
});

it('never exposes the wrapped key through serialisation', function (): void {
    $tenant = Tenant::factory()->create();
    $key = app(FieldCipher::class)->provision($tenant);

    expect($key->toArray())->not->toHaveKey('wrapped_dek')
        ->and(array_keys($key->fresh()?->toArray() ?? []))->not->toContain('wrapped_dek')
        // Still readable in code — it is what KeyWrapper::unwrap() is given.
        ->and($key->toWrappedKey()->blob)->toBe($key->wrapped_dek);
});

it('is scoped to its tenant like every other tenant-owned model', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $cipher = app(FieldCipher::class);
    $cipher->provision($a);
    $cipher->provision($b);

    $visible = app(TenantContext::class)->runFor($a, fn (): array => EncryptionKey::query()->pluck('tenant_id')->all());

    expect($visible)->toBe([$a->id])
        ->and(EncryptionKey::withoutTenantScope()->count())->toBe(2);
});

it('is crypto-shredded when its tenant is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    app(FieldCipher::class)->provision($tenant);

    $tenant->delete();

    expect(DB::table('encryption_keys')->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('indexes the lookups it is queried by', function (): void {
    $indexes = collect(Schema::getIndexes('encryption_keys'));

    expect($indexes->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'purpose', 'status']))->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'purpose', 'version'] && $index['unique']))->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'purpose', 'active_flag'] && $index['unique']))->toBeTrue()
        // "Every DEK still wrapped under master key X" — the rotation sweep of task 4.2.
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['kms_key_id']))->toBeTrue();
});
