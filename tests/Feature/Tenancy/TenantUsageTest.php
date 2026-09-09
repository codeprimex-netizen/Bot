<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Models\Tenant;
use App\Models\TenantUsage;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| tenant_usage schema and model invariants
|--------------------------------------------------------------------------
| These tests are about the counter itself — casts, buckets, unique constraint,
| cascade — so they read rows across tenants and say so with the explicit
| `withoutTenantScope()` hatch. The scope those reads step around is covered on its
| own terms in `BelongsToTenantTest` (Correctness Property 1).
*/

it('stores a period-bucketed counter for a tenant', function (): void {
    $tenant = Tenant::factory()->create();

    $usage = TenantUsage::create([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::MessagesMonthly,
        'period_key' => '2025-06',
        'used' => 120,
        'limit' => 1000,
    ]);

    $fresh = TenantUsage::withoutTenantScope()->findOrFail($usage->id);

    expect($fresh->kind)->toBe(QuotaKind::MessagesMonthly)
        ->and($fresh->period_key)->toBe('2025-06')
        ->and($fresh->used)->toBe(120)
        ->and($fresh->limit)->toBe(1000)
        ->and($fresh->remaining())->toBe(880)
        ->and($fresh->isExhausted())->toBeFalse()
        ->and($fresh->tenant->is($tenant))->toBeTrue();
});

it('round-trips every QuotaKind value through the database', function (): void {
    $tenant = Tenant::factory()->create();

    foreach (QuotaKind::cases() as $case) {
        $usage = TenantUsage::factory()->ofKind($case)->create(['tenant_id' => $tenant->id]);

        $stored = DB::table('tenant_usage')->where('id', $usage->id)->value('kind');

        expect($stored)->toBe($case->value)
            ->and(TenantUsage::withoutTenantScope()->findOrFail($usage->id)->kind)->toBe($case);
    }
});

it('enforces uniq(tenant_id, kind, period_key)', function (): void {
    $tenant = Tenant::factory()->create();

    TenantUsage::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::MessagesDaily,
        'period_key' => '2025-06-14',
    ]);

    expect(fn () => TenantUsage::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::MessagesDaily,
        'period_key' => '2025-06-14',
    ]))->toThrow(QueryException::class);
});

it('keeps separate buckets per period, per kind and per tenant', function (): void {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();

    TenantUsage::factory()->create([
        'tenant_id' => $acme->id,
        'kind' => QuotaKind::MessagesDaily,
        'period_key' => '2025-06-14',
    ]);
    // Same tenant + kind, different period.
    TenantUsage::factory()->create([
        'tenant_id' => $acme->id,
        'kind' => QuotaKind::MessagesDaily,
        'period_key' => '2025-06-15',
    ]);
    // Same tenant + period, different kind.
    TenantUsage::factory()->create([
        'tenant_id' => $acme->id,
        'kind' => QuotaKind::MessagesMonthly,
        'period_key' => '2025-06-14',
    ]);
    // Different tenant, otherwise identical to the first row.
    TenantUsage::factory()->create([
        'tenant_id' => $globex->id,
        'kind' => QuotaKind::MessagesDaily,
        'period_key' => '2025-06-14',
    ]);

    expect(TenantUsage::withoutTenantScope()->count())->toBe(4)
        ->and($acme->usage)->toHaveCount(3)
        ->and($globex->usage)->toHaveCount(1);
});

it('narrows to a single bucket with the forBucket scope', function (): void {
    $tenant = Tenant::factory()->create();

    TenantUsage::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::AiCredits,
        'period_key' => '2025-06',
        'used' => 7,
    ]);
    TenantUsage::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::AiCredits,
        'period_key' => '2025-05',
        'used' => 99,
    ]);

    $bucket = $tenant->usage()->forBucket(QuotaKind::AiCredits, '2025-06')->sole();

    expect($bucket->used)->toBe(7);
});

it('reports an exhausted bucket and never a negative remainder', function (): void {
    $usage = TenantUsage::factory()->create(['limit' => 10, 'used' => 10]);

    expect($usage->isExhausted())->toBeTrue()
        ->and($usage->remaining())->toBe(0);

    $usage->used = 25;

    expect($usage->remaining())->toBe(0);
});

it('defaults a fresh counter to zero used', function (): void {
    $tenant = Tenant::factory()->create();

    TenantUsage::withoutTenantScope()->insert([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::Contacts->value,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
    ]);

    $usage = TenantUsage::forTenant($tenant)->sole();

    expect($usage->used)->toBe(0)
        ->and($usage->limit)->toBe(0);
});

it('removes usage counters when the tenant is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    TenantUsage::factory()->create(['tenant_id' => $tenant->id]);

    $tenant->delete();

    expect(TenantUsage::forTenant($tenant)->exists())->toBeFalse();
});
