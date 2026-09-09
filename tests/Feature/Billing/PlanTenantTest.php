<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| tenants.plan_id -> plans.id (Req 25.1 / D2)
|--------------------------------------------------------------------------
| The column has existed since task 0.1 but could hold any 26 characters, because
| `plans` did not exist yet. These tests are about the constraint that closes that
| gap, and about the behaviour chosen for it: retiring a plan must not delete or
| block the tenants on it.
*/

it('constrains plan_id to a real plan', function (): void {
    $foreignKeys = collect(Schema::getForeignKeys('tenants'))
        ->filter(fn (array $key): bool => $key['columns'] === ['plan_id']);

    expect($foreignKeys)->toHaveCount(1);

    $key = $foreignKeys->first();

    expect($key['foreign_table'])->toBe('plans')
        ->and($key['foreign_columns'])->toBe(['id'])
        ->and(strtolower((string) $key['on_delete']))->toBe('set null');
});

it('indexes plan_id so "who is on this plan?" is not a scan', function (): void {
    expect(collect(Schema::getIndexes('tenants'))
        ->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === 'plan_id'))->toBeTrue();
});

it('keeps the tenants table intact after adding the constraint', function (): void {
    // The constraint is added by rebuilding the table on SQLite, so the rest of the
    // tenants schema has to come out the other side unchanged.
    $indexes = collect(Schema::getIndexes('tenants'));

    expect($indexes->firstWhere('name', 'tenants_status_index'))->not->toBeNull()
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['slug'] && $index['unique']))->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['subdomain'] && $index['unique']))->toBeTrue()
        ->and(Schema::hasColumns('tenants', ['id', 'name', 'slug', 'subdomain', 'status', 'plan_id', 'trial_ends_at', 'timezone', 'locale']))->toBeTrue();
});

it('rejects a plan_id that names no plan', function (): void {
    expect(fn (): Tenant => Tenant::factory()->create(['plan_id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);
});

it('still allows a tenant with no plan at all', function (): void {
    $tenant = Tenant::factory()->create(['plan_id' => null]);

    expect($tenant->plan_id)->toBeNull()
        ->and($tenant->plan)->toBeNull();
});

it('relates a tenant to its plan in both directions', function (): void {
    $plan = Plan::factory()->create();
    $tenants = Tenant::factory()->count(2)->create(['plan_id' => $plan->id]);
    Tenant::factory()->create();

    expect($tenants[0]->plan?->is($plan))->toBeTrue()
        ->and($plan->tenants()->pluck('id')->sort()->values()->all())
        ->toBe($tenants->pluck('id')->sort()->values()->all());
});

it('drops the tenants on a retired plan back to no plan, without deleting them', function (): void {
    $plan = Plan::factory()->create();
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    $plan->delete();

    expect(Tenant::query()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and(DB::table('tenants')->where('id', $tenant->id)->value('plan_id'))->toBeNull()
        ->and($tenant->refresh()->plan)->toBeNull();
});
