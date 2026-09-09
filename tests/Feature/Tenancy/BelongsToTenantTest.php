<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Models\TenantUsage;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantTokenRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
|--------------------------------------------------------------------------
| Row-level tenant isolation (Req 1.1, 1.2, 1.5 / A1)
|--------------------------------------------------------------------------
| Groundwork for Correctness Property 1: two tenants are seeded with identical
| shapes of data, and every read path is asserted to be disjoint. The formal
| property test is task 0.6; these are the example-based guarantees it builds on.
|
| Both tenant-owned models that exist today are covered — `TenantUsage` (integer
| key) and `TenantApiToken` (ULID key) — because the scope must not care.
*/

/**
 * One tenant with three usage buckets and two API keys.
 */
function seedTenantWithRows(string $slug, int $used): Tenant
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'subdomain' => $slug]);

    foreach ([QuotaKind::MessagesMonthly, QuotaKind::MessagesDaily, QuotaKind::AiCredits] as $kind) {
        TenantUsage::factory()->ofKind($kind)->create([
            'tenant_id' => $tenant->id,
            'used' => $used,
        ]);
    }

    TenantApiToken::factory()->count(2)->create(['tenant_id' => $tenant->id]);

    return $tenant;
}

/**
 * Two tenants with identically shaped data, so every read path can be asserted
 * disjoint (Correctness Property 1).
 *
 * @return array{0: Tenant, 1: Tenant}
 */
function seedTwoTenants(): array
{
    return [seedTenantWithRows('acme', 10), seedTenantWithRows('globex', 11)];
}

it('returns only the acting tenant rows on every read path, for either tenant', function (): void {
    [$acme, $globex] = seedTwoTenants();
    $context = app(TenantContext::class);

    foreach ([[$acme, $globex], [$globex, $acme]] as [$acting, $other]) {
        $context->forget();
        $context->set($acting);

        $otherUsage = TenantUsage::forTenant($other)->firstOrFail();
        $otherToken = TenantApiToken::forTenant($other)->firstOrFail();

        expect(TenantUsage::query()->count())->toBe(3)
            ->and(TenantUsage::query()->pluck('tenant_id')->unique()->values()->all())->toBe([$acting->id])
            ->and(TenantUsage::query()->get()->pluck('tenant_id')->unique()->values()->all())->toBe([$acting->id])
            ->and(TenantUsage::query()->where('kind', QuotaKind::AiCredits)->count())->toBe(1)
            ->and(TenantUsage::query()->exists())->toBeTrue()
            ->and(TenantApiToken::query()->count())->toBe(2)
            ->and(TenantApiToken::query()->pluck('tenant_id')->unique()->values()->all())->toBe([$acting->id])
            // ...and the other tenant's rows are simply not there in bulk...
            ->and(TenantUsage::query()->where('tenant_id', $other->id)->count())->toBe(0)
            // ...while naming one of them by id is denied outright rather than silently
            // missing: the typed 403 of Req 1.3, covered on its own terms in
            // CrossTenantAccessTest.
            ->and(fn () => TenantUsage::query()->find($otherUsage->id))->toThrow(CrossTenantAccessException::class)
            ->and(fn () => TenantApiToken::query()->find($otherToken->id))->toThrow(CrossTenantAccessException::class)
            ->and(fn () => TenantUsage::query()->findOrFail($otherUsage->id))->toThrow(CrossTenantAccessException::class)
            // An id that exists nowhere stays an ordinary miss.
            ->and(TenantUsage::query()->find(9_999_999))->toBeNull()
            ->and(fn () => TenantUsage::query()->findOrFail(9_999_999))->toThrow(ModelNotFoundException::class);
    }
});

it('stamps the acting tenant on create', function (): void {
    [$acme] = seedTwoTenants();
    app(TenantContext::class)->set($acme);

    $usage = TenantUsage::create([
        'kind' => QuotaKind::Contacts,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
        'used' => 4,
        'limit' => 50,
    ]);
    $token = TenantApiToken::create([
        'name' => 'stamped',
        'token' => TenantApiToken::hashSecret('secret-value'),
    ]);

    expect($usage->tenant_id)->toBe($acme->id)
        ->and($usage->tenant->is($acme))->toBeTrue()
        ->and($token->tenant_id)->toBe($acme->id)
        ->and(TenantUsage::query()->whereKey($usage->id)->exists())->toBeTrue();
});

it('keeps an explicitly supplied tenant_id instead of overwriting it', function (): void {
    // Provisioning, imports, and platform writes all have to name their tenant — so an
    // explicit tenant_id is honoured rather than overwritten, as long as it names the
    // tenant the caller is acting as. Naming another one is forgery and is denied by
    // the ownership guard (Req 1.3), covered in CrossTenantAccessTest.
    [$acme] = seedTwoTenants();
    app(TenantContext::class)->set($acme);

    $usage = TenantUsage::create([
        'tenant_id' => $acme->id,
        'kind' => QuotaKind::Sessions,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
    ]);

    expect($usage->tenant_id)->toBe($acme->id)
        ->and(TenantUsage::query()->whereKey($usage->id)->exists())->toBeTrue();
});

it('refuses to create a row that cannot be attributed to a tenant', function (): void {
    seedTwoTenants();

    expect(fn () => TenantUsage::create([
        'kind' => QuotaKind::Contacts,
        'period_key' => QuotaKind::GAUGE_PERIOD_KEY,
    ]))->toThrow(MissingTenantContextException::class);

    expect(TenantUsage::withoutTenantScope()->count())->toBe(6);
});

it('fails closed when no tenant is bound and platform mode is closed', function (): void {
    seedTwoTenants();

    // An unresolved context is a bug; it must not silently become a cross-tenant
    // read, and must not silently become an empty one either.
    expect(fn () => TenantUsage::query()->count())->toThrow(MissingTenantContextException::class)
        ->and(fn () => TenantUsage::query()->get())->toThrow(MissingTenantContextException::class)
        ->and(fn () => TenantUsage::query()->first())->toThrow(MissingTenantContextException::class)
        ->and(fn () => TenantUsage::query()->exists())->toThrow(MissingTenantContextException::class)
        ->and(fn () => TenantUsage::query()->update(['used' => 1]))->toThrow(MissingTenantContextException::class)
        ->and(fn () => TenantUsage::query()->delete())->toThrow(MissingTenantContextException::class)
        ->and(fn () => TenantApiToken::query()->count())->toThrow(MissingTenantContextException::class);

    expect(TenantUsage::withoutTenantScope()->count())->toBe(6);
});

it('bypasses the scope only inside the audited platform mode', function (): void {
    [$acme] = seedTwoTenants();
    $context = app(TenantContext::class);

    $context->set($acme);
    expect(TenantUsage::query()->count())->toBe(3);

    $context->enterPlatformMode('admin: platform-wide usage overview');
    expect(TenantUsage::query()->count())->toBe(6)
        ->and(TenantApiToken::query()->count())->toBe(4);
    $context->exitPlatformMode();

    // The bypass is exactly as wide as the frame that opened it.
    expect(TenantUsage::query()->count())->toBe(3)
        ->and($context->asPlatform('admin: count', fn (): int => TenantUsage::query()->count()))->toBe(6)
        ->and(TenantUsage::query()->count())->toBe(3);
});

it('scopes a runFor() block to that tenant and restores the caller context', function (): void {
    [$acme, $globex] = seedTwoTenants();
    $context = app(TenantContext::class);
    $context->set($acme);

    $seen = $context->runFor($globex, fn (): array => TenantUsage::query()->pluck('tenant_id')->unique()->values()->all());

    expect($seen)->toBe([$globex->id])
        ->and(TenantUsage::query()->pluck('tenant_id')->unique()->values()->all())->toBe([$acme->id]);

    // runFor() suspends platform mode, so the scope really does apply inside it.
    $inside = $context->asPlatform(
        'admin: per-tenant drill-down',
        fn (): int => $context->runFor($globex, fn (): int => TenantUsage::query()->count()),
    );

    expect($inside)->toBe(3);
});

it('fails closed at a queued-job boundary rather than inheriting the previous tenant', function (): void {
    [$acme] = seedTwoTenants();
    $context = app(TenantContext::class);
    $context->set($acme);

    $context->isolate('job-1');
    expect(fn () => TenantUsage::query()->count())->toThrow(MissingTenantContextException::class);

    $context->release('job-1');
    expect(TenantUsage::query()->count())->toBe(3);
});

it('confines mass updates and deletes to the acting tenant', function (): void {
    [$acme, $globex] = seedTwoTenants();
    $context = app(TenantContext::class);
    $context->set($acme);

    TenantUsage::query()->update(['used' => 999]);

    expect(TenantUsage::forTenant($globex)->pluck('used')->unique()->values()->all())->toBe([11]);

    TenantUsage::query()->delete();

    expect(TenantUsage::query()->count())->toBe(0)
        ->and(TenantUsage::forTenant($globex)->count())->toBe(3)
        ->and(TenantUsage::withoutTenantScope()->count())->toBe(3);
});

it('offers withoutTenantScope() and forTenant() as the only explicit bypasses', function (): void {
    [$acme, $globex] = seedTwoTenants();
    app(TenantContext::class)->set($acme);

    expect(TenantUsage::withoutTenantScope()->count())->toBe(6)
        ->and(TenantUsage::forTenant($globex)->count())->toBe(3)
        ->and(TenantUsage::forTenant($globex->id)->pluck('tenant_id')->unique()->values()->all())->toBe([$globex->id])
        ->and(TenantApiToken::withoutTenantScope()->count())->toBe(4)
        ->and(TenantApiToken::forTenant($globex)->count())->toBe(2);
});

it('resolves the acting tenant when the query runs, not when it is built', function (): void {
    [$acme, $globex] = seedTwoTenants();
    $context = app(TenantContext::class);

    $builder = TenantUsage::query();

    $context->set($acme);
    expect($builder->count())->toBe(3);

    $context->forget();
    $context->set($globex);
    expect(TenantUsage::query()->pluck('tenant_id')->unique()->values()->all())->toBe([$globex->id]);
});

it('reads one tenants children through the tenant root without a bound context', function (): void {
    // Relations from the tenant root already name their tenant, so they stay usable
    // from platform screens, console commands, and schedulers.
    [$acme, $globex] = seedTwoTenants();

    expect(app(TenantContext::class)->hasTenant())->toBeFalse()
        ->and($acme->usage()->count())->toBe(3)
        ->and($acme->usage->pluck('tenant_id')->unique()->values()->all())->toBe([$acme->id])
        ->and($acme->apiTokens()->count())->toBe(2)
        ->and($globex->usage()->count())->toBe(3)
        ->and($acme->usage()->forBucket(QuotaKind::AiCredits, QuotaKind::AiCredits->periodKey())->count())->toBe(1);
});

it('keeps tenant resolution working, because the token lookup declares its bypass', function (): void {
    [$acme] = seedTwoTenants();
    $issued = TenantApiToken::issue($acme, 'ci pipeline');

    // No tenant bound: this is exactly the state tenant resolution runs in.
    expect(app(TenantContext::class)->hasTenant())->toBeFalse();

    $resolved = app(TenantTokenRepository::class)->tenantForToken($issued->plainText);

    expect($resolved?->id)->toBe($acme->id);
});
