<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Models\TenantUsage;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| The tenancy rule, enforced automatically (Req 1.1, 1.2 / A1)
|--------------------------------------------------------------------------
| Isolation only holds if *every* tenant-owned table follows the same two steps:
| `TenantSchema::tenantId()` in the migration, `BelongsToTenant` on the model. The
| engine tables of later phases (`sessions_wa`, `messages`, `contacts`, `campaigns`,
| `chatbots`, `orders`, ...) do not exist yet — so instead of trusting a reviewer to
| remember the rule when they arrive, these tests derive the list of tenant-owned
| tables from the *live schema* and hold every one of them to it.
|
| A phase-12 migration that adds `tenant_id` without the trait fails here, on the
| task that added it.
*/

/**
 * Tables that carry `tenant_id` but deliberately do not get `BelongsToTenant`,
 * with the reason. Every entry is a reviewed decision, not an omission — and the
 * last test in this file keeps the list honest.
 *
 * @return array<class-string<Model>, string>
 */
function tenantScopeExemptions(): array
{
    return [
        // The table that answers "which tenant is acting?" cannot be filtered by the
        // answer: tenant resolution reads it by user_id before any tenant is bound.
        // See the TenantUser docblock for the full reasoning.
        TenantUser::class => 'identity/resolution tier: read by user_id before a tenant exists',
    ];
}

/**
 * Every concrete Eloquent model under `app/Models`.
 *
 * @return list<class-string<Model>>
 */
function eloquentModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
        /** @var class-string<Model> $class */
        $class = 'App\\Models\\'.str_replace(
            '/',
            '\\',
            Str::before($file->getRelativePathname(), '.php')
        );

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $models[] = $class;
    }

    sort($models);

    return $models;
}

/**
 * Models whose table carries a `tenant_id` column.
 *
 * @return list<class-string<Model>>
 */
function tenantOwnedModels(): array
{
    return array_values(array_filter(eloquentModels(), static function (string $class): bool {
        $table = (new $class)->getTable();

        return Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id');
    }));
}

it('finds the models it is meant to guard', function (): void {
    // A discovery bug would make every other test in this file vacuously pass, so the
    // models that exist today are named explicitly.
    expect(eloquentModels())->toContain(Tenant::class, TenantUsage::class, TenantApiToken::class, TenantUser::class)
        ->and(tenantOwnedModels())->toContain(TenantUsage::class, TenantApiToken::class, TenantUser::class)
        ->and(tenantOwnedModels())->not->toContain(Tenant::class, User::class);
});

it('uses BelongsToTenant on every model whose table carries tenant_id', function (): void {
    $exempt = tenantScopeExemptions();
    $missing = [];

    foreach (tenantOwnedModels() as $class) {
        if (array_key_exists($class, $exempt)) {
            continue;
        }

        if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], sprintf(
        'These models own tenant data but do not use BelongsToTenant, so their queries are not '
        .'isolated: %s. Add the trait, or record a reviewed exemption in tenantScopeExemptions().',
        implode(', ', $missing),
    ));
});

it('indexes every tenant_id column with an index that leads with it', function (): void {
    $unindexed = [];

    foreach (tenantOwnedModels() as $class) {
        $table = (new $class)->getTable();

        $leads = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === 'tenant_id');

        if (! $leads) {
            $unindexed[] = $table;
        }
    }

    expect($unindexed)->toBe([], sprintf(
        'These tables have a tenant_id column but no index leading with it, so every scoped query '
        .'scans them: %s. Declare the column with TenantSchema::tenantId().',
        implode(', ', $unindexed),
    ));
});

it('never scopes the tenant itself, the root of the ownership tree', function (): void {
    expect(class_uses_recursive(Tenant::class))->not->toContain(BelongsToTenant::class)
        ->and(Schema::hasColumn('tenants', 'tenant_id'))->toBeFalse();
});

it('keeps the exemption list honest', function (): void {
    foreach (tenantScopeExemptions() as $class => $reason) {
        $table = (new $class)->getTable();

        expect($reason)->not->toBe('')
            ->and(Schema::hasColumn($table, 'tenant_id'))->toBeTrue()
            // An exempt model that has since gained the trait should leave the list,
            // so the list only ever describes reality.
            ->and(class_uses_recursive($class))->not->toContain(BelongsToTenant::class);
    }
});
