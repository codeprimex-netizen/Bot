<?php

declare(strict_types=1);

use App\Enums\TenantTier;
use App\Models\Tenant;
use App\Models\TenantTierAssignment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| tenant_tiers schema and model invariants (Req 1.6 / A1)
|--------------------------------------------------------------------------
| One row per tenant, stating something explicit: its tier, optionally its pinned
| lane weight, its region, and where its shard lives. Reads that deliberately cross
| tenants say so with the `withoutTenantScope()` / `forTenant()` hatches.
*/

it('stores one tier assignment per tenant', function (): void {
    $tenant = Tenant::factory()->create();

    $assignment = TenantTierAssignment::create([
        'tenant_id' => $tenant->id,
        'tier' => TenantTier::DedicatedDb,
        'lane_weight' => 25,
        'data_region' => 'eu-central-1',
        'shard_key' => 'shard-eu-1',
        'dedicated_conn' => ['connection' => 'tenant_shard_eu'],
    ]);

    $fresh = TenantTierAssignment::withoutTenantScope()->findOrFail($assignment->id);

    expect($fresh->tier)->toBe(TenantTier::DedicatedDb)
        ->and($fresh->lane_weight)->toBe(25)
        ->and($fresh->data_region)->toBe('eu-central-1')
        ->and($fresh->shard_key)->toBe('shard-eu-1')
        ->and($fresh->dedicated_conn)->toBe(['connection' => 'tenant_shard_eu'])
        ->and($fresh->connectionName())->toBe('tenant_shard_eu')
        ->and($fresh->tenant->is($tenant))->toBeTrue();
});

it('defaults to the shared tier with nothing pinned', function (): void {
    $tenant = Tenant::factory()->create();

    TenantTierAssignment::create(['tenant_id' => $tenant->id]);

    $fresh = TenantTierAssignment::withoutTenantScope()->firstOrFail();

    expect($fresh->tier)->toBe(TenantTier::Shared)
        ->and($fresh->lane_weight)->toBeNull()
        ->and($fresh->shard_key)->toBeNull()
        ->and($fresh->dedicated_conn)->toBeNull()
        ->and($fresh->connectionName())->toBeNull();
});

it('round-trips every tier value through the database', function (): void {
    foreach (TenantTier::cases() as $tier) {
        $assignment = TenantTierAssignment::factory()->onTier($tier)->create();

        expect(DB::table('tenant_tiers')->where('id', $assignment->id)->value('tier'))->toBe($tier->value)
            ->and(TenantTierAssignment::withoutTenantScope()->findOrFail($assignment->id)->tier)->toBe($tier);
    }
});

it('reads only a connection name out of dedicated_conn, ignoring junk', function (): void {
    $assignment = TenantTierAssignment::factory()->create(['dedicated_conn' => ['connection' => '  ']]);

    expect($assignment->connectionName())->toBeNull();

    $assignment->update(['dedicated_conn' => ['region' => 'eu']]);

    expect($assignment->connectionName())->toBeNull();

    $assignment->update(['dedicated_conn' => ['connection' => ' tenant_shard_eu ']]);

    expect($assignment->connectionName())->toBe('tenant_shard_eu');
});

it('allows only one tier row per tenant', function (): void {
    $tenant = Tenant::factory()->create();

    TenantTierAssignment::factory()->create(['tenant_id' => $tenant->id]);

    expect(fn () => TenantTierAssignment::factory()->create(['tenant_id' => $tenant->id]))
        ->toThrow(QueryException::class);
});

it('leads its tenant index with tenant_id', function (): void {
    $leading = collect(Schema::getIndexes('tenant_tiers'))
        ->filter(fn (array $index): bool => ($index['columns'][0] ?? null) === 'tenant_id');

    expect($leading)->not->toBeEmpty()
        // The uniqueness of that index is what makes "the tenant's tier" one fact.
        ->and($leading->contains(fn (array $index): bool => $index['unique'] === true))->toBeTrue();
});

it('scopes reads to the acting tenant like every other tenant table', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    TenantTierAssignment::factory()->dedicatedWorkers()->create(['tenant_id' => $acme->id]);
    TenantTierAssignment::factory()->onTier(TenantTier::DedicatedDb)->create(['tenant_id' => $globex->id]);

    $context = app(TenantContext::class);
    $context->set($acme);

    expect(TenantTierAssignment::query()->count())->toBe(1)
        ->and(TenantTierAssignment::query()->firstOrFail()->tier)->toBe(TenantTier::DedicatedWorker)
        ->and(TenantTierAssignment::withoutTenantScope()->count())->toBe(2)
        ->and(TenantTierAssignment::forTenant($globex)->firstOrFail()->tier)->toBe(TenantTier::DedicatedDb);
});

it('drops a tenant tier row when the tenant is offboarded', function (): void {
    $tenant = Tenant::factory()->create();
    TenantTierAssignment::factory()->create(['tenant_id' => $tenant->id]);

    $tenant->delete();

    expect(TenantTierAssignment::withoutTenantScope()->count())->toBe(0);
});
