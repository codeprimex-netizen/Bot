<?php

declare(strict_types=1);

use App\Enums\TenantResolutionSource;
use App\Events\Tenancy\PlatformModeEntered;
use App\Events\Tenancy\PlatformModeExited;
use App\Models\Tenant;
use App\Services\Tenancy\RequestTenantContext;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;

it('binds the context as a single instance per unit of work', function (): void {
    expect(app(TenantContext::class))
        ->toBeInstanceOf(RequestTenantContext::class)
        ->toBe(app(TenantContext::class))
        ->toBe(app(RequestTenantContext::class));
});

it('starts empty', function (): void {
    $context = app(TenantContext::class);

    expect($context->current())->toBeNull()
        ->and($context->currentId())->toBeNull()
        ->and($context->hasTenant())->toBeFalse()
        ->and($context->actingAsPlatform())->toBeFalse()
        ->and($context->resolvedVia())->toBe(TenantResolutionSource::None);
});

it('binds and forgets a tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->set($tenant, TenantResolutionSource::Subdomain);

    expect($context->current()?->is($tenant))->toBeTrue()
        ->and($context->currentId())->toBe($tenant->id)
        ->and($context->hasTenant())->toBeTrue()
        ->and($context->resolvedVia())->toBe(TenantResolutionSource::Subdomain);

    $context->forget();

    expect($context->current())->toBeNull()
        ->and($context->resolvedVia())->toBe(TenantResolutionSource::None);
});

it('defaults the resolution source to manual for code-initiated binding', function (): void {
    $context = app(TenantContext::class);

    $context->set(Tenant::factory()->create());

    expect($context->resolvedVia())->toBe(TenantResolutionSource::Manual);
});

it('reports no current tenant while acting as platform', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant);

    $context->enterPlatformMode('admin: cross-tenant session report');

    expect($context->actingAsPlatform())->toBeTrue()
        ->and($context->current())->toBeNull()
        ->and($context->currentId())->toBeNull()
        ->and($context->resolvedVia())->toBe(TenantResolutionSource::None);

    $context->exitPlatformMode();

    expect($context->actingAsPlatform())->toBeFalse()
        ->and($context->current()?->is($tenant))->toBeTrue();
});

it('refuses to bind a tenant while acting as platform', function (): void {
    $context = app(TenantContext::class);
    $context->enterPlatformMode('admin: audit');

    expect(fn () => $context->set(Tenant::factory()->create()))->toThrow(LogicException::class);

    expect($context->actingAsPlatform())->toBeTrue();
});

it('requires a reason for platform mode and rejects an unbalanced exit', function (): void {
    $context = app(TenantContext::class);

    expect(fn () => $context->enterPlatformMode('   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $context->exitPlatformMode())->toThrow(LogicException::class)
        ->and($context->actingAsPlatform())->toBeFalse();
});

it('nests platform frames so an inner exit does not reopen the tenant scope', function (): void {
    $context = app(TenantContext::class);

    $context->enterPlatformMode('outer');
    $context->enterPlatformMode('inner');
    $context->exitPlatformMode();

    expect($context->actingAsPlatform())->toBeTrue();

    $context->exitPlatformMode();

    expect($context->actingAsPlatform())->toBeFalse();
});

it('announces entering and leaving platform mode for the audit trail', function (): void {
    Event::fake([PlatformModeEntered::class, PlatformModeExited::class]);

    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant);

    $result = $context->asPlatform('admin: platform-wide session count', fn (): string => 'counted');

    expect($result)->toBe('counted')
        ->and($context->current()?->is($tenant))->toBeTrue()
        ->and($context->actingAsPlatform())->toBeFalse();

    Event::assertDispatched(PlatformModeEntered::class, function (PlatformModeEntered $event) use ($tenant): bool {
        return $event->reason === 'admin: platform-wide session count'
            && $event->tenantId === $tenant->id
            && $event->depth === 1;
    });

    Event::assertDispatched(PlatformModeExited::class, fn (PlatformModeExited $event): bool => $event->reason === 'admin: platform-wide session count' && $event->forced === false
    );
});

it('closes a platform frame left open at a request or job boundary', function (): void {
    Event::fake([PlatformModeExited::class]);

    $context = app(TenantContext::class);
    $context->enterPlatformMode('admin: impersonation');

    $context->forget();

    expect($context->actingAsPlatform())->toBeFalse();

    Event::assertDispatched(PlatformModeExited::class, fn (PlatformModeExited $event): bool => $event->forced === true);
});

it('leaves platform mode even when the callback throws', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant);

    expect(fn () => $context->asPlatform('admin: failing report', function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect($context->actingAsPlatform())->toBeFalse()
        ->and($context->current()?->is($tenant))->toBeTrue();
});

it('runs a callback for another tenant and restores the previous context', function (): void {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($acme, TenantResolutionSource::Session);

    $seen = $context->runFor($globex, fn (Tenant $tenant): string => $context->currentId().'|'.$tenant->id);

    expect($seen)->toBe($globex->id.'|'.$globex->id)
        ->and($context->currentId())->toBe($acme->id)
        ->and($context->resolvedVia())->toBe(TenantResolutionSource::Session);
});

it('restores the previous context when runFor throws', function (): void {
    $acme = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($acme);

    expect(fn () => $context->runFor(Tenant::factory()->create(), function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect($context->currentId())->toBe($acme->id);
});

it('suspends platform mode inside runFor so the read is scoped to one tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enterPlatformMode('admin: inspect one tenant');

    $insideId = $context->runFor($tenant, fn (): array => [
        $context->currentId(),
        $context->actingAsPlatform(),
    ]);

    expect($insideId)->toBe([$tenant->id, false])
        ->and($context->actingAsPlatform())->toBeTrue()
        ->and($context->current())->toBeNull();

    $context->exitPlatformMode();
});

it('restores a captured snapshot verbatim', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant, TenantResolutionSource::ApiToken);

    $snapshot = $context->snapshot();
    $context->forget();

    expect($context->current())->toBeNull();

    $context->restore($snapshot);

    expect($context->currentId())->toBe($tenant->id)
        ->and($context->resolvedVia())->toBe(TenantResolutionSource::ApiToken);
});

it('stashes and releases the context around an isolated scope without auditing an exit', function (): void {
    Event::fake([PlatformModeEntered::class, PlatformModeExited::class]);

    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant);
    $context->enterPlatformMode('admin: batch');

    $context->isolate('frame-1');

    expect($context->actingAsPlatform())->toBeFalse()
        ->and($context->current())->toBeNull();

    $context->release('frame-1');

    expect($context->actingAsPlatform())->toBeTrue();

    Event::assertDispatched(PlatformModeEntered::class, 1);
    Event::assertNotDispatched(PlatformModeExited::class);
});

it('ignores releasing an unknown isolation frame', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant);

    $context->release('never-stashed');

    expect($context->currentId())->toBe($tenant->id);
});
