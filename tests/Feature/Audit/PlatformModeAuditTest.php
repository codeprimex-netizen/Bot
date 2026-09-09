<?php

declare(strict_types=1);

use App\Enums\AuditActorType;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Tests\Fixtures\Audit;

/*
|--------------------------------------------------------------------------
| The audited tenant-scope bypass (Req 1.5 / A1; Req 24.2 / D1)
|--------------------------------------------------------------------------
| Req 1.5 permits exactly one way to read across tenants — `actingAsPlatform()` — and
| requires that it be *audited*. Task 0.2 emits `PlatformModeEntered`/`Exited` for
| precisely this purpose; these tests assert the other half: that every frame is
| recorded on the platform chain, with the reason, the prior tenant, the nesting depth,
| and the acting admin where one can be resolved.
|
| No `Event::fake()` anywhere in this file: faking the events would test the listener
| in isolation and leave the actual wiring — the thing Req 1.5 depends on —
| unverified.
*/

/**
 * The platform chain's entries, oldest first.
 *
 * @return list<AuditLog>
 */
function platformEntries(): array
{
    return AuditLog::withoutTenantScope()
        ->where('chain_key', AuditLog::PLATFORM_CHAIN)
        ->orderBy('sequence')
        ->get()
        ->all();
}

it('records entering and leaving platform mode, in order, on the platform chain', function (): void {
    Audit::tenancy()->asPlatform('cross-tenant revenue report', fn (): bool => true);

    $entries = platformEntries();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->action)->toBe('platform_mode.entered')
        ->and($entries[0]->payload['reason'])->toBe('cross-tenant revenue report')
        ->and($entries[0]->payload['depth'])->toBe(1)
        ->and($entries[0]->payload['prior_tenant_id'])->toBeNull()
        ->and($entries[0]->tenant_id)->toBeNull()
        ->and($entries[1]->action)->toBe('platform_mode.exited')
        ->and($entries[1]->payload['reason'])->toBe('cross-tenant revenue report')
        ->and($entries[1]->payload['forced'])->toBeFalse()
        ->and($entries[1]->payload['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and(Audit::service()->verify()->isIntact())->toBeTrue();
});

it('records the tenant that was bound before the bypass, without leaving that chain empty-handed', function (): void {
    $tenant = Tenant::factory()->create();
    Audit::tenancy()->set($tenant);

    Audit::tenancy()->asPlatform('support drill-down', fn (): bool => true);

    $entries = platformEntries();

    // The bypass is a platform act, so it lives on the platform chain — but the tenant
    // it stepped out of is recorded, so "who was this admin looking at?" is answerable.
    expect($entries[0]->payload['prior_tenant_id'])->toBe($tenant->id)
        ->and($entries[0]->chain_key)->toBe(AuditLog::PLATFORM_CHAIN)
        ->and($entries[0]->tenant_id)->toBeNull()
        ->and(AuditLog::withoutTenantScope()->where('chain_key', $tenant->id)->count())->toBe(0);
});

it('records nesting depth for a bypass opened inside a bypass', function (): void {
    Audit::tenancy()->asPlatform('outer', function (): void {
        Audit::tenancy()->asPlatform('inner', fn (): bool => true);
    });

    $depths = array_map(
        fn (AuditLog $entry): array => [$entry->action, $entry->payload['reason'], $entry->payload['depth'] ?? null],
        platformEntries(),
    );

    expect($depths)->toBe([
        ['platform_mode.entered', 'outer', 1],
        ['platform_mode.entered', 'inner', 2],
        ['platform_mode.exited', 'inner', null],
        ['platform_mode.exited', 'outer', null],
    ]);
});

it('marks a frame closed by a request or job boundary as forced', function (): void {
    Audit::tenancy()->enterPlatformMode('left open by a handler');
    Audit::tenancy()->forget();

    $entries = platformEntries();

    expect($entries[1]->action)->toBe('platform_mode.exited')
        ->and($entries[1]->payload['forced'])->toBeTrue();
});

it('attributes the bypass to the acting admin when the request has one', function (): void {
    $admin = User::factory()->create();
    thisTest()->actingAs($admin);

    Audit::tenancy()->asPlatform('impersonation setup', fn (): bool => true);

    $entries = platformEntries();

    // Until the `platform-admin` guard exists (task 30.1) an authenticated session that
    // opened the bypass is recorded as an admin, because nothing else can open it.
    expect($entries[0]->actor_type)->toBe(AuditActorType::Admin)
        ->and($entries[0]->actor_id)->toBe((string) $admin->id)
        ->and($entries[0]->actor_label)->toBe($admin->email);
});

it('falls back to a system actor for platform-wide maintenance with no request', function (): void {
    Audit::tenancy()->asPlatform('nightly retention sweep', fn (): bool => true);

    expect(platformEntries()[0]->actor_type)->toBe(AuditActorType::System);
});

it('does not recurse: writing about a bypass never opens one', function (): void {
    // The listener writes through `withoutTenantScope()`, not `asPlatform()` — if it did
    // the latter, each entry would emit another event and the chain would never end.
    Audit::tenancy()->asPlatform('one frame only', fn (): bool => true);

    expect(platformEntries())->toHaveCount(2);
});

it('keeps the platform chain verifiable across many bypasses', function (): void {
    foreach (range(1, 10) as $index) {
        Audit::tenancy()->asPlatform('sweep '.$index, fn (): bool => true);
    }

    $result = Audit::service()->verify();

    // Also a float-in-the-hash test: `duration_ms` is a float, and the canonical
    // serializer hashes floats by their exact bits so this cannot become flaky.
    expect($result->isIntact())->toBeTrue($result->summary())
        ->and($result->entriesChecked)->toBe(20);
});

it('audits a cross-tenant read performed under the bypass', function (): void {
    [$first, $second] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $names = Audit::tenancy()->asPlatform(
        'admin lists all tenants',
        fn (): array => Tenant::query()->orderBy('name')->pluck('id')->all(),
    );

    expect($names)->toHaveCount(2)
        ->and($names)->toContain($first->id, $second->id)
        // The read that Req 1.2 would otherwise forbid leaves a trace of itself.
        ->and(platformEntries()[0]->payload['reason'])->toBe('admin lists all tenants');
});
