<?php

declare(strict_types=1);

use App\Enums\SessionLoginMethod;
use App\Enums\StorageArea;
use App\Exceptions\Bridge\UnknownSessionException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Bridge\BridgeClient;
use App\Services\Bridge\GuardedBridgeClient;
use App\Services\Bridge\HttpBridgeClient;
use App\Services\Bridge\TenantScopedBridgeClient;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Bridge\FakeBridgeClient;

/*
|--------------------------------------------------------------------------
| "The Bridge never learns about tenants" (design.md § Bridge multi-tenancy)
|--------------------------------------------------------------------------
| The sidecar has no tenant column, no tenant context, and nothing to check a session id
| against — so the guarantee cannot live there. It lives in the *container binding*: the
| outermost `BridgeClient` resolves every session id against the acting tenant's own rows
| before a byte leaves the process.
|
| These tests are the ones that make that structural rather than conventional. They assert
| the three answers a session id can get, that a foreign id never reaches the wire at all,
| and that the auth-state directory is derived from the resolved owner rather than taken
| from the caller — because a caller-supplied path would reintroduce, one layer up, exactly
| the cross-tenant reach the id resolution just closed.
*/

beforeEach(function (): void {
    // The auth-state assertions below create real directories; faking the disk keeps them
    // out of the repository's storage tree, exactly as the TenantStorage tests do.
    Storage::fake(StorageArea::AuthState->disk());
});

function scopedBridge(FakeBridgeClient $inner): TenantScopedBridgeClient
{
    return new TenantScopedBridgeClient($inner, app(TenantStorage::class));
}

/**
 * @return array{0: Tenant, 1: Session}
 */
function tenantWithSession(?string $name = null): array
{
    $tenant = Tenant::factory()->create();

    $session = app(TenantContext::class)->runFor($tenant, fn (): Session => Session::factory()
        ->connected()
        ->create(['tenant_id' => $tenant->id, 'name' => $name ?? 'Session '.Str::random(6)]));

    return [$tenant, $session];
}

/*
|--------------------------------------------------------------------------
| The container binding
|--------------------------------------------------------------------------
*/

it('binds the ownership check as the outermost layer of the one production chain', function (): void {
    // Ownership outermost is the design, not a preference: a foreign id must be refused
    // without a request, without a retry attempt, and without telling the breaker that this
    // tenant's traffic is failing.
    $bridge = app(BridgeClient::class);

    expect($bridge)->toBeInstanceOf(TenantScopedBridgeClient::class);

    $inner = (new ReflectionProperty(TenantScopedBridgeClient::class, 'inner'))->getValue($bridge);

    expect($inner)->toBeInstanceOf(GuardedBridgeClient::class);

    $wire = (new ReflectionProperty(GuardedBridgeClient::class, 'inner'))->getValue($inner);

    expect($wire)->toBeInstanceOf(HttpBridgeClient::class);
});

it('binds no test double in the container', function (): void {
    // Property 28: `Fake*` lives under tests/, which only autoload-dev maps, so it is not
    // even autoloadable in a production install.
    expect(app(BridgeClient::class))->not->toBeInstanceOf(FakeBridgeClient::class);
});

it('drops the guard but never the ownership check when the guard is switched off', function (): void {
    config()->set('wa.bridge.guard.enabled', false);
    app()->forgetInstance(BridgeClient::class);

    $bridge = app(BridgeClient::class);

    expect($bridge)->toBeInstanceOf(TenantScopedBridgeClient::class);

    // Ownership scoping is not a resilience feature, so there is no key that removes it.
    $inner = (new ReflectionProperty(TenantScopedBridgeClient::class, 'inner'))->getValue($bridge);

    expect($inner)->toBeInstanceOf(HttpBridgeClient::class);
});

/*
|--------------------------------------------------------------------------
| The three answers a session id can get
|--------------------------------------------------------------------------
*/

it('lets a tenant reach its own session', function (): void {
    [$tenant, $session] = tenantWithSession();
    $inner = (new FakeBridgeClient)->connect($session->id);

    app(TenantContext::class)->runFor($tenant, function () use ($inner, $session): void {
        $receipt = scopedBridge($inner)->sendText($session->id, 'x@s.whatsapp.net', 'Hi');

        expect($receipt->waMessageId)->not->toBe('');
    });

    expect($inner->sentTo($session->id))->toHaveCount(1);
});

it('refuses a session id belonging to another tenant, before anything reaches the wire', function (): void {
    [, $foreign] = tenantWithSession();
    [$acme] = tenantWithSession();

    $inner = (new FakeBridgeClient)->connect($foreign->id);

    app(TenantContext::class)->runFor($acme, function () use ($inner, $foreign): void {
        expect(fn () => scopedBridge($inner)->sendText($foreign->id, 'x@s.whatsapp.net', 'Hi'))
            ->toThrow(CrossTenantAccessException::class);
    });

    // The decisive assertion: not merely denied, but never attempted. A check further in
    // would still deny the call while polluting the breaker on the way.
    expect($inner->callLog())->toBe([])
        ->and($inner->sentTo($foreign->id))->toBe([]);
});

it('refuses every session-addressed operation for a foreign id', function (): void {
    [, $foreign] = tenantWithSession();
    [$acme] = tenantWithSession();

    $inner = (new FakeBridgeClient)->connect($foreign->id)->showQr($foreign->id);
    $bridge = scopedBridge($inner);
    $id = $foreign->id;

    app(TenantContext::class)->runFor($acme, function () use ($bridge, $id): void {
        $operations = [
            'provisionSession' => fn () => $bridge->provisionSession($id, SessionLoginMethod::Qr),
            'startSession' => fn () => $bridge->startSession($id),
            'stopSession' => fn () => $bridge->stopSession($id),
            'sessionState' => fn () => $bridge->sessionState($id),
            'qr' => fn () => $bridge->qr($id),
            'pairingCode' => fn () => $bridge->pairingCode($id, '919812345678'),
            'sendText' => fn () => $bridge->sendText($id, 'x@s.whatsapp.net', 'Hi'),
            'sendPresence' => fn () => $bridge->sendPresence($id, 'x@s.whatsapp.net', App\Enums\PresenceState::Composing),
            'checkNumbers' => fn () => $bridge->checkNumbers($id, ['919812345678']),
        ];

        foreach ($operations as $label => $operation) {
            expect($operation)->toThrow(
                CrossTenantAccessException::class,
                null,
                $label.' reached another tenant\'s session',
            );
        }
    });

    expect($inner->callLog())->toBe([]);
});

it('tells a nonexistent session apart from another tenants, so a typo is not an isolation alert', function (): void {
    [$acme] = tenantWithSession();
    $inner = new FakeBridgeClient;

    app(TenantContext::class)->runFor($acme, function () use ($inner): void {
        expect(fn () => scopedBridge($inner)->startSession(Str::ulid()->toBase32()))
            ->toThrow(UnknownSessionException::class);
    });

    expect($inner->callLog())->toBe([]);
});

it('refuses an id that is not even a ULID before the database is asked', function (): void {
    [$acme] = tenantWithSession();
    $inner = new FakeBridgeClient;

    app(TenantContext::class)->runFor($acme, function () use ($inner): void {
        foreach (['', '   ', '../health', 'not-a-ulid'] as $malformed) {
            expect(fn () => scopedBridge($inner)->startSession($malformed))
                ->toThrow(UnknownSessionException::class);
        }
    });

    expect($inner->callLog())->toBe([]);
});

it('fails closed when no tenant is bound at all', function (): void {
    [, $session] = tenantWithSession();
    $inner = (new FakeBridgeClient)->connect($session->id);

    app(TenantContext::class)->forget();

    // An unresolved context is a bug, and a bug must not be allowed to become a
    // cross-tenant reach: `TenantScope` refuses the lookup before this class decides
    // anything.
    expect(fn () => scopedBridge($inner)->startSession($session->id))
        ->toThrow(MissingTenantContextException::class)
        ->and($inner->callLog())->toBe([]);
});

it('reaches any tenants session inside the audited platform frame', function (): void {
    [, $session] = tenantWithSession();
    $inner = (new FakeBridgeClient)->connect($session->id);

    // Req 1.5's audited bypass — the only way the post-deploy restore sweep can walk every
    // tenant's sessions, and it is announced on the event bus.
    app(TenantContext::class)->asPlatform('restore sweep', function () use ($inner, $session): void {
        $inner->connect($session->id);

        scopedBridge($inner)->startSession($session->id);
    });

    expect($inner->calls('session.start'))->toBe(1);
});

it('refuses a soft-deleted session', function (): void {
    [$tenant, $session] = tenantWithSession();
    $inner = (new FakeBridgeClient)->connect($session->id);

    app(TenantContext::class)->runFor($tenant, function () use ($inner, $session): void {
        $session->delete();

        // A deleted session's credentials are being torn down; reaching the bridge for one
        // would recreate state the platform has already promised to remove.
        expect(fn () => scopedBridge($inner)->startSession($session->id))
            ->toThrow(UnknownSessionException::class);
    });

    expect($inner->callLog())->toBe([]);
});

it('passes the health probe through, because it names no session and belongs to no tenant', function (): void {
    $inner = new FakeBridgeClient;

    app(TenantContext::class)->forget();

    expect(scopedBridge($inner)->isReachable())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Auth state
|--------------------------------------------------------------------------
*/

it('derives the auth-state directory from the resolved owner', function (): void {
    [$tenant, $session] = tenantWithSession();
    $inner = new FakeBridgeClient;

    app(TenantContext::class)->runFor($tenant, function () use ($inner, $session): void {
        scopedBridge($inner)->provisionSession($session->id, SessionLoginMethod::Qr);
    });

    $expected = app(TenantStorage::class)->authStateFullPath($tenant->id, $session->id);

    expect($inner->authStateDir($session->id))->toBe($expected)
        ->and($expected)->toContain($tenant->id)
        ->and($expected)->toContain($session->id);
});

it('discards a caller-supplied auth-state directory rather than trusting it', function (): void {
    [$tenant, $session] = tenantWithSession();
    [$other] = tenantWithSession();
    $inner = new FakeBridgeClient;

    app(TenantContext::class)->runFor($tenant, function () use ($inner, $session, $other): void {
        scopedBridge($inner)->provisionSession(
            $session->id,
            SessionLoginMethod::Qr,
            null,
            // A caller naming its own session and somebody else's credential directory is
            // exactly the reach the id resolution just closed; accepting this would reopen it
            // one layer up.
            '/srv/auth/tenants/'.$other->id,
        );
    });

    expect($inner->authStateDir($session->id))->not->toContain($other->id)
        ->and($inner->authStateDir($session->id))->toContain($tenant->id);
});

it('creates the auth-state directory before the bridge is told to use it', function (): void {
    [$tenant, $session] = tenantWithSession();
    $inner = new FakeBridgeClient;

    $storage = app(TenantStorage::class);

    expect($storage->disk(StorageArea::AuthState)->exists($storage->authStatePath($tenant->id, $session->id)))
        ->toBeFalse();

    app(TenantContext::class)->runFor($tenant, function () use ($inner, $session): void {
        scopedBridge($inner)->provisionSession($session->id, SessionLoginMethod::Qr);
    });

    // The sidecar opens the directory directly, so a missing one is an error there rather
    // than here.
    expect($storage->disk(StorageArea::AuthState)->exists($storage->authStatePath($tenant->id, $session->id)))
        ->toBeTrue();
});

it('falls back to the session row phone when the caller names none', function (): void {
    $tenant = Tenant::factory()->create();

    $session = app(TenantContext::class)->runFor($tenant, fn (): Session => Session::factory()
        ->connected('919812345678')
        ->create(['tenant_id' => $tenant->id]));

    $inner = new FakeBridgeClient;

    app(TenantContext::class)->runFor($tenant, function () use ($inner, $session): void {
        // Pairing-code login needs a number, and the row already knows it — so the lifecycle
        // service does not have to thread it through every call.
        $init = scopedBridge($inner)->provisionSession($session->id, SessionLoginMethod::PairingCode);

        expect($init->pairingCode)->not->toBeNull();
    });

    expect($inner->calls('session.provision'))->toBe(1);
});
