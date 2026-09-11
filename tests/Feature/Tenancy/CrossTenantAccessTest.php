<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Models\TenantUsage;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Defense-in-depth ownership checks (Req 1.3 / A1, Correctness Property 1)
|--------------------------------------------------------------------------
| `TenantScope` (task 0.3) constrains queries; these tests cover the four seams a
| query scope structurally cannot reach, each of which must now deny with a
| `CrossTenantAccessException` (403):
|
|  1. writes through an already-loaded instance (Eloquent builds them without scopes);
|  2. relation loads reached through a foreign parent (including the relations that
|     drop the scope deliberately);
|  3. an explicit, forged `tenant_id` on create;
|  4. find-by-id and route-model binding naming another tenant's row.
|
| The last block asserts the *legitimate* paths still work — the audited platform
| bypass, `runFor()`, `withoutTenantScope()`, `forTenant()`, tenant resolution, and
| provisioning — because a guard that breaks those is a guard that gets removed.
*/

/**
 * Two tenants with identically shaped data and a member each, seeded with **no**
 * tenant bound (the provisioning state).
 *
 * @return array{0: Tenant, 1: Tenant}
 */
function seedRivalTenants(): array
{
    $tenants = [];

    foreach (['acme' => 10, 'globex' => 11] as $slug => $used) {
        $tenant = Tenant::factory()->create(['slug' => $slug, 'subdomain' => $slug]);

        foreach ([QuotaKind::MessagesMonthly, QuotaKind::MessagesDaily] as $kind) {
            TenantUsage::factory()->ofKind($kind)->create(['tenant_id' => $tenant->id, 'used' => $used]);
        }

        TenantApiToken::factory()->create(['tenant_id' => $tenant->id]);
        TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $tenants[] = $tenant;
    }

    return [$tenants[0], $tenants[1]];
}

/**
 * One of the other tenant's usage rows, read through the sanctioned `forTenant()`
 * seam — the realistic way a foreign instance ends up in a caller's hands.
 */
function foreignUsageOf(Tenant $tenant): TenantUsage
{
    return TenantUsage::forTenant($tenant)->orderBy('id')->firstOrFail();
}

/**
 * The stored `used` counter of a row, read without hydrating it through the scope.
 */
function storedUsed(TenantUsage $usage): ?int
{
    $value = DB::table('tenant_usage')->where('id', $usage->id)->value('used');

    return is_int($value) ? $value : null;
}

/*
|--------------------------------------------------------------------------
| Gap 1 — writes through an already-loaded instance
|--------------------------------------------------------------------------
*/

it('refuses to save, update, or delete an instance owned by another tenant', function (): void {
    [$acme, $globex] = seedRivalTenants();
    app(TenantContext::class)->set($acme);

    $foreign = foreignUsageOf($globex);
    $foreign->used = 999;

    expect(fn () => $foreign->save())->toThrow(CrossTenantAccessException::class)
        ->and(fn () => $foreign->update(['used' => 998]))->toThrow(CrossTenantAccessException::class)
        ->and(fn () => $foreign->delete())->toThrow(CrossTenantAccessException::class)
        // Nothing was written and nothing was removed.
        ->and(storedUsed($foreign))->toBe(11)
        ->and(TenantUsage::forTenant($globex)->count())->toBe(2);
});

it('refuses to move an owned row to another tenant', function (): void {
    [$acme, $globex] = seedRivalTenants();
    app(TenantContext::class)->set($acme);

    $own = TenantUsage::query()->orderBy('id')->firstOrFail();
    $own->tenant_id = $globex->id;

    expect(fn () => $own->save())->toThrow(CrossTenantAccessException::class)
        ->and(TenantUsage::forTenant($globex)->count())->toBe(2)
        ->and(TenantUsage::query()->count())->toBe(2);
});

it('offers assertBelongsToCurrentTenant() for instances Eloquent cannot vouch for', function (): void {
    [$acme, $globex] = seedRivalTenants();
    app(TenantContext::class)->set($acme);

    $own = TenantUsage::query()->orderBy('id')->firstOrFail();
    $foreign = foreignUsageOf($globex);

    $own->assertBelongsToCurrentTenant();

    expect(fn () => $foreign->assertBelongsToCurrentTenant())->toThrow(CrossTenantAccessException::class);
});

/*
|--------------------------------------------------------------------------
| Gap 2 — relation loads through a foreign or unscoped parent
|--------------------------------------------------------------------------
*/

it('refuses to reach another tenants children through a foreign tenant instance', function (): void {
    [$acme, $globex] = seedRivalTenants();
    app(TenantContext::class)->set($acme);

    expect(fn () => $globex->usage()->get())->toThrow(CrossTenantAccessException::class)
        ->and(fn () => $globex->usage)->toThrow(CrossTenantAccessException::class)
        ->and(fn () => $globex->apiTokens()->count())->toThrow(CrossTenantAccessException::class)
        ->and(fn () => $globex->tenantUsers()->get())->toThrow(CrossTenantAccessException::class)
        ->and(fn () => $globex->users()->get())->toThrow(CrossTenantAccessException::class)
        // Eager loading does not call the relation method on the *parent* instance, so
        // it is the retrieval check that stops it — the reason a relation dropping the
        // tenant scope does not also drop the ownership check.
        ->and(fn () => Tenant::query()->whereKey($globex->id)->with('usage')->first())
        ->toThrow(CrossTenantAccessException::class)
        // ...and the acting tenant's own children are untouched by the guard, lazily
        // and eagerly.
        ->and($acme->usage()->count())->toBe(2)
        ->and($acme->apiTokens()->count())->toBe(1)
        ->and($acme->tenantUsers()->count())->toBe(1)
        ->and(Tenant::query()->whereKey($acme->id)->with('usage')->firstOrFail()->usage)->toHaveCount(2);
});

it('refuses to re-read a foreign instance through an unscoped refresh', function (): void {
    [$acme, $globex] = seedRivalTenants();
    app(TenantContext::class)->set($acme);

    $foreign = foreignUsageOf($globex);

    // fresh()/refresh() build their query without scopes, so the retrieval guard is
    // the only thing standing between them and another tenant's row.
    expect(fn () => $foreign->fresh())->toThrow(CrossTenantAccessException::class)
        ->and(fn () => $foreign->refresh())->toThrow(CrossTenantAccessException::class);
});

/*
|--------------------------------------------------------------------------
| Gap 3 — a forged tenant_id on create
|--------------------------------------------------------------------------
*/

it('refuses a create that names a tenant other than the acting one', function (): void {
    [$acme, $globex] = seedRivalTenants();
    app(TenantContext::class)->set($acme);

    expect(fn () => TenantUsage::create([
        'tenant_id' => $globex->id,
        'kind' => QuotaKind::Contacts,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
    ]))->toThrow(CrossTenantAccessException::class)
        ->and(fn () => TenantApiToken::create([
            'tenant_id' => $globex->id,
            'name' => 'forged',
            'token' => TenantApiToken::hashSecret('forged-secret'),
        ]))->toThrow(CrossTenantAccessException::class)
        // Nothing reached the database...
        ->and(TenantUsage::forTenant($globex)->count())->toBe(2)
        ->and(TenantApiToken::forTenant($globex)->count())->toBe(1);

    // ...while naming your own tenant explicitly is still perfectly fine.
    $own = TenantUsage::create([
        'tenant_id' => $acme->id,
        'kind' => QuotaKind::Contacts,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
    ]);

    expect($own->tenant_id)->toBe($acme->id);
});

it('still lets platform, provisioning, and runFor writes name their tenant', function (): void {
    [$acme, $globex] = seedRivalTenants();
    $context = app(TenantContext::class);
    $context->set($acme);

    // The audited platform bypass (Req 1.5) — e.g. an admin-panel or import write.
    $platformWrite = $context->asPlatform('admin: seed a tenant quota', fn (): TenantUsage => TenantUsage::create([
        'tenant_id' => $globex->id,
        'kind' => QuotaKind::Contacts,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
        'limit' => 25,
    ]));

    // Explicitly doing work *as* another tenant (schedulers, queued jobs).
    $scopedWrite = $context->runFor($globex, fn (): TenantUsage => TenantUsage::create([
        'kind' => QuotaKind::Sessions,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
    ]));

    // Provisioning: no tenant bound at all.
    $context->forget();
    $issued = TenantApiToken::issue($globex, 'ci pipeline');
    $provisioned = TenantUsage::create([
        'tenant_id' => $globex->id,
        'kind' => QuotaKind::CampaignsConcurrent,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
    ]);

    expect($platformWrite->tenant_id)->toBe($globex->id)
        ->and($scopedWrite->tenant_id)->toBe($globex->id)
        ->and($provisioned->tenant_id)->toBe($globex->id)
        ->and($issued->token->tenant_id)->toBe($globex->id)
        ->and($globex->usage()->count())->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Gap 4 — find-by-id and route-model binding
|--------------------------------------------------------------------------
*/

it('denies a find-by-id that names another tenants record, and only that', function (): void {
    [$acme, $globex] = seedRivalTenants();
    app(TenantContext::class)->set($acme);

    $foreignUsageId = foreignUsageOf($globex)->id;
    $foreignTokenId = TenantApiToken::forTenant($globex)->firstOrFail()->id;
    $ownUsageId = TenantUsage::query()->orderBy('id')->firstOrFail()->id;

    expect(fn () => TenantUsage::query()->find($foreignUsageId))->toThrow(CrossTenantAccessException::class)
        ->and(fn () => TenantUsage::query()->findOrFail($foreignUsageId))->toThrow(CrossTenantAccessException::class)
        ->and(fn () => TenantUsage::find($foreignUsageId))->toThrow(CrossTenantAccessException::class)
        // ULID keys behave exactly the same as integer ones.
        ->and(fn () => TenantApiToken::query()->find($foreignTokenId))->toThrow(CrossTenantAccessException::class)
        // A record that exists nowhere is a miss, not a denial: "no such row" is not a
        // cross-tenant access attempt.
        ->and(TenantUsage::query()->find(9_999_999))->toBeNull()
        ->and(fn () => TenantUsage::query()->findOrFail(9_999_999))->toThrow(ModelNotFoundException::class)
        ->and(TenantApiToken::query()->find('01hzzzzzzzzzzzzzzzzzzzzzzz'))->toBeNull()
        // ...and the acting tenant's own rows are still found by id.
        ->and(TenantUsage::query()->find($ownUsageId)?->id)->toBe($ownUsageId)
        ->and(TenantUsage::query()->findOrFail($ownUsageId)->id)->toBe($ownUsageId);
});

it('denies route-model binding on another tenants record with a 403', function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);

    Route::middleware(['resolve.tenant', SubstituteBindings::class])
        ->get('/_test/usage/{tenantUsage}', fn (TenantUsage $tenantUsage) => response()->json([
            'id' => $tenantUsage->id,
        ]));

    [$acme, $globex] = seedRivalTenants();
    $acting = ['Authorization' => 'Bearer '.TenantApiToken::issue($acme, 'ci')->plainText];
    $ownId = TenantUsage::forTenant($acme)->orderBy('id')->firstOrFail()->id;
    $foreignId = foreignUsageOf($globex)->id;

    // The tenant's own record binds and is served.
    thisTest()->getJson('http://app.example.test/_test/usage/'.$ownId, $acting)
        ->assertOk()
        ->assertJson(['id' => $ownId]);

    // Another tenant's record is a 403 — not the 404 a scoped miss would have given.
    $denied = thisTest()->getJson('http://app.example.test/_test/usage/'.$foreignId, $acting);

    $denied->assertForbidden()->assertJson([
        'message' => CrossTenantAccessException::PUBLIC_MESSAGE,
        'error' => CrossTenantAccessException::ERROR_CODE,
    ]);

    // A record that does not exist at all is still an ordinary 404.
    thisTest()->getJson('http://app.example.test/_test/usage/9999999', $acting)->assertNotFound();
});

it('renders a cross-tenant denial as a 403 that leaks nothing about the record', function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);

    Route::middleware('resolve.tenant')->get('/_test/usage-raw/{id}', fn (string $id) => response()->json([
        'id' => TenantUsage::query()->findOrFail((int) $id)->id,
    ]));

    [$acme, $globex] = seedRivalTenants();
    $acting = ['Authorization' => 'Bearer '.TenantApiToken::issue($acme, 'ci')->plainText];
    $foreign = foreignUsageOf($globex);

    foreach ([
        thisTest()->getJson('http://app.example.test/_test/usage-raw/'.$foreign->id, $acting),
        // A panel (HTML) request is a 403 too, through the exception's own status.
        thisTest()->get('http://app.example.test/_test/usage-raw/'.$foreign->id, $acting),
    ] as $response) {
        $response->assertForbidden();

        expect($response->getContent())->toBeString()
            ->and((string) $response->getContent())->not->toContain($globex->id)
            ->and((string) $response->getContent())->not->toContain($acme->id);
    }
});

/*
|--------------------------------------------------------------------------
| The guard must not break the paths that are allowed to cross tenants
|--------------------------------------------------------------------------
*/

it('leaves the audited platform bypass able to read and write any tenant', function (): void {
    [$acme, $globex] = seedRivalTenants();
    $context = app(TenantContext::class);
    $context->set($acme);

    $context->asPlatform('admin: cross-tenant maintenance', function () use ($globex): void {
        $row = TenantUsage::query()->where('tenant_id', $globex->id)->orderBy('id')->firstOrFail();
        $row->used = 42;
        $row->save();

        expect(TenantUsage::query()->count())->toBe(4)
            ->and($globex->usage()->count())->toBe(2)
            ->and($globex->apiTokens()->count())->toBe(1);

        $row->delete();
    });

    expect(storedUsed(foreignUsageOf($globex)))->toBe(11)
        ->and(TenantUsage::forTenant($globex)->count())->toBe(1)
        // ...and the acting tenant is back once the frame closes.
        ->and(TenantUsage::query()->pluck('tenant_id')->unique()->values()->all())->toBe([$acme->id]);
});

it('leaves runFor() able to read and write the tenant it names', function (): void {
    [$acme, $globex] = seedRivalTenants();
    $context = app(TenantContext::class);
    $context->set($acme);

    $updated = $context->runFor($globex, function (): int {
        $row = TenantUsage::query()->orderBy('id')->firstOrFail();
        $row->used = 77;
        $row->save();

        return $row->used;
    });

    expect($updated)->toBe(77)
        ->and(storedUsed(foreignUsageOf($globex)))->toBe(77);
});

it('leaves the explicit read hatches, resolution, and tenant-root reads working', function (): void {
    [$acme, $globex] = seedRivalTenants();
    $context = app(TenantContext::class);
    $issued = TenantApiToken::issue($globex, 'zapier');
    $context->set($acme);

    // The two sanctioned bypasses of task 0.3 are reads, and they still read.
    expect(TenantUsage::withoutTenantScope()->count())->toBe(4)
        ->and(TenantUsage::forTenant($globex)->count())->toBe(2)
        ->and(TenantUsage::withoutTenantScope()->findOrFail(foreignUsageOf($globex)->id)->used)->toBe(11)
        ->and(TenantApiToken::withoutTenantScope()->count())->toBe(3);

    // Tenant resolution runs before any tenant is bound and must stay unguarded:
    // it is the code that decides who is acting.
    $context->forget();

    expect(app(App\Services\Tenancy\TenantTokenRepository::class)->resolve($issued->plainText)?->tenant->id)
        ->toBe($globex->id)
        ->and(TenantUser::query()->where('user_id', $globex->tenantUsers()->firstOrFail()->user_id)->count())->toBe(1)
        // Reading a tenant's own children from an unbound context (console, scheduler,
        // admin screens) keeps working for either tenant.
        ->and($acme->usage()->count())->toBe(2)
        ->and($globex->usage()->count())->toBe(2)
        ->and($globex->apiTokens()->count())->toBe(2);
});
