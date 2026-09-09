<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('creates a tenant with a ULID primary key', function (): void {
    $tenant = Tenant::create([
        'name' => 'Acme Retail',
        'slug' => 'acme-retail',
        'status' => TenantStatus::Trial,
    ]);

    expect($tenant->getKeyName())->toBe('id')
        ->and($tenant->getIncrementing())->toBeFalse()
        ->and(Str::isUlid($tenant->id))->toBeTrue();
});

it('casts status to the TenantStatus enum on read back from the database', function (): void {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);

    $fresh = Tenant::query()->findOrFail($tenant->id);

    expect($fresh->status)->toBe(TenantStatus::Suspended)
        ->and($fresh->isOperational())->toBeFalse();
});

it('round-trips every TenantStatus value through the database', function (): void {
    foreach (TenantStatus::cases() as $case) {
        $tenant = Tenant::factory()->create(['status' => $case]);

        $stored = DB::table('tenants')->where('id', $tenant->id)->value('status');

        expect($stored)->toBe($case->value)
            ->and(Tenant::query()->findOrFail($tenant->id)->status)->toBe($case);
    }
});

it('defaults status to TRIAL, timezone to UTC and locale to en', function (): void {
    Tenant::query()->insert([
        'id' => (string) Str::ulid(),
        'name' => 'Defaults Co',
        'slug' => 'defaults-co',
    ]);

    $tenant = Tenant::query()->where('slug', 'defaults-co')->firstOrFail();

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->timezone)->toBe('UTC')
        ->and($tenant->locale)->toBe('en');
});

it('casts trial_ends_at to a datetime', function (): void {
    $tenant = Tenant::factory()->trial()->create();

    expect($tenant->fresh()->trial_ends_at)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});

it('rejects a duplicate slug', function (): void {
    Tenant::factory()->create(['slug' => 'duplicate-slug']);

    expect(fn () => Tenant::factory()->create(['slug' => 'duplicate-slug']))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate subdomain but allows many tenants without one', function (): void {
    Tenant::factory()->create(['subdomain' => 'acme']);

    expect(fn () => Tenant::factory()->create(['subdomain' => 'acme']))
        ->toThrow(QueryException::class);

    Tenant::factory()->create(['subdomain' => null]);
    Tenant::factory()->create(['subdomain' => null]);

    expect(Tenant::query()->whereNull('subdomain')->count())->toBe(2);
});

it('accepts a nullable plan_id until the plans table arrives', function (): void {
    $tenant = Tenant::factory()->create();

    expect($tenant->plan_id)->toBeNull();
});

it('namespaces its storage under the per-tenant prefix', function (): void {
    $tenant = Tenant::factory()->create();

    expect($tenant->storagePrefix())->toBe('tenants/'.$tenant->id);
});
