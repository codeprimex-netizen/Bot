<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ModeCapabilityException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\ChannelCredential;
use App\Models\Plan;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelDriver;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\DefaultChannelRouter;
use App\Services\Channel\ModeGuardedChannelDriver;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Channel\RouterProbeDriver;

/*
|--------------------------------------------------------------------------
| ChannelRouter (Req 8.3, 8.4, 8.10, 8.11, 8.13 / A8)
|--------------------------------------------------------------------------
| The router is the only place a driver comes from, so four platform-wide guarantees are
| properties of *this* class and of nothing else. Each is asserted here in the form that
| would catch it being lost silently:
|
|   1. **exactly one driver, by `session.channel_mode`** (Property 22) — and, the half that
|      matters most, no reroute when that mode is unusable: an official-mode send with no
|      credentials is refused, never quietly put on the Baileys default;
|   2. **every driver leaves wrapped** in `ModeGuardedChannelDriver` (Properties 21, 24) —
|      which is what makes the anti-ban guarantee unbypassable, since nothing else hands out
|      drivers;
|   3. **cross-tenant resolution is refused before anything is read** (Property 23), with the
|      driver-construction counter proving the ordering rather than just the outcome;
|   4. **`failoverChain()` resolves, and only resolves** — head, dedup, unknown-value
|      tolerance, credential omission, and the plan gate. Execution is task 8.5.
|
| Drivers are tasks 7.1–7.5, so the registry here holds `RouterProbeDriver`s: honest about
| mode and policy (it uses `DerivesChannelPolicy`, as the real ones will), and counting its
| own constructions, which is the only way to tell memoisation from an equal-looking object.
*/

beforeEach(function (): void {
    Cache::flush();
    RouterProbeDriver::reset();
});

/**
 * A router wired exactly as `ChannelServiceProvider` wires the real one, with a probe driver
 * registered for `$modes` (all four by default).
 */
function probeRouter(ChannelMode ...$modes): DefaultChannelRouter
{
    return new DefaultChannelRouter(
        app(TenantContext::class),
        app(ChannelCredentialStore::class),
        app(PlanGate::class),
        RouterProbeDriver::registry(...($modes === [] ? ChannelMode::cases() : $modes)),
    );
}

/**
 * A tenant whose plan grants exactly $features.
 *
 * @param  array<string, bool>  $features
 */
function routerTenant(array $features = []): Tenant
{
    return Tenant::factory()->create([
        'plan_id' => Plan::factory()->create(['features' => $features])->id,
    ]);
}

/**
 * Credentials for every mode that needs them, so a session on any mode is routable.
 */
function credentialEveryMode(Tenant $tenant): void
{
    $store = app(ChannelCredentialStore::class);

    foreach (ChannelMode::cases() as $mode) {
        if (! $mode->requiresTenantCredentials()) {
            continue;
        }

        $store->put(
            $tenant,
            $mode,
            ['access_token' => 'secret-'.$mode->value],
            ['phone_number_id' => '1000'],
            provider: $mode->usesProvider() ? BspProvider::Twilio : null,
        );
    }
}

/**
 * A session of $tenant on $mode, with an optional stored failover chain.
 *
 * @param  list<ChannelMode|string>|null  $failover
 */
function routerSession(Tenant $tenant, ChannelMode $mode, ?array $failover = null): Session
{
    return app(TenantContext::class)->runFor($tenant, fn (): Session => Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => $mode,
        'failover_modes' => $failover === null ? null : array_map(
            static fn (ChannelMode|string $value): string => $value instanceof ChannelMode ? $value->value : $value,
            $failover,
        ),
    ]));
}

/*
|--------------------------------------------------------------------------
| Exactly one driver, by session.channel_mode — Property 22
|--------------------------------------------------------------------------
*/

it('routes a session to exactly the driver whose mode is the session mode', function (): void {
    $tenant = routerTenant();
    credentialEveryMode($tenant);
    $router = probeRouter();

    foreach (ChannelMode::cases() as $mode) {
        $session = routerSession($tenant, $mode);

        $driver = $router->driverFor($session);

        expect($driver->mode())->toBe($mode)
            // Never zero, never two, and never another mode's: the credentials, the rate
            // rules and the ban-risk profile all follow from this being right.
            ->and($driver->mode())->toBe($session->channel_mode);
    }
});

it('resolves the same driver by mode without a session in hand', function (): void {
    $tenant = routerTenant();
    credentialEveryMode($tenant);
    $router = probeRouter();

    // The shape task 7.6's health check and task 9.1's pre-live registration need.
    expect($router->driverForMode(ChannelMode::CloudApi, $tenant)->mode())->toBe(ChannelMode::CloudApi)
        ->and($router->driverForMode(ChannelMode::Baileys, $tenant)->mode())->toBe(ChannelMode::Baileys);
});

it('routes the default mode for a tenant that has configured nothing at all', function (): void {
    // The zero-official-API-onboarding promise: BAILEYS needs no credential row, so a
    // brand-new tenant routes (Req 8.13, ChannelMode::requiresTenantCredentials()).
    $tenant = routerTenant();
    $session = routerSession($tenant, ChannelMode::Baileys);

    expect(app(ChannelCredentialStore::class)->rowFor($tenant, ChannelMode::Baileys))->toBeNull()
        ->and(probeRouter()->driverFor($session)->mode())->toBe(ChannelMode::Baileys);
});

it('refuses a mode with no usable credentials instead of rerouting it to the default', function (): void {
    $tenant = routerTenant();
    $session = routerSession($tenant, ChannelMode::CloudApi);
    $router = probeRouter();

    // The reroute this refusal prevents would put a brand's official, template-approved
    // traffic on an unregistered WhatsApp Web number — a Property 22 violation whose only
    // symptom is a customer seeing a number nobody advertised.
    expect(fn (): ChannelDriver => $router->driverFor($session))
        ->toThrow(ChannelCredentialException::class, 'cannot send')
        // ...and nothing was resolved in its place.
        ->and(RouterProbeDriver::built(ChannelMode::Baileys))->toBe(0)
        ->and(RouterProbeDriver::built(ChannelMode::CloudApi))->toBe(0);
});

it('refuses a mode whose credential set exists but is not usable', function (): void {
    $tenant = routerTenant();
    $store = app(ChannelCredentialStore::class);
    $credential = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG'], ['waba_id' => '1']);

    // Configured, then switched off: from a send's point of view identical to absent, which
    // is exactly what `rowFor()` reports and what the router must act on.
    $credential->forceFill(['status' => ChannelCredentialStatus::Disabled])->save();
    $store->forget($tenant, ChannelMode::CloudApi);

    $session = routerSession($tenant, ChannelMode::CloudApi);

    expect(fn (): ChannelDriver => probeRouter()->driverFor($session))
        ->toThrow(ChannelCredentialException::class);
});

it('refuses a mode no driver is registered for, rather than substituting one', function (): void {
    $tenant = routerTenant();
    credentialEveryMode($tenant);
    $session = routerSession($tenant, ChannelMode::CloudApi);

    // A deployment defect (tasks 7.1–7.5 register the entries), reported as one.
    expect(fn (): ChannelDriver => probeRouter(ChannelMode::Baileys)->driverFor($session))
        ->toThrow(LogicException::class, 'No channel driver is registered for mode [CLOUD_API]');
});

/*
|--------------------------------------------------------------------------
| Every driver leaves wrapped — Properties 21, 24
|--------------------------------------------------------------------------
*/

it('hands out every driver wrapped in the mode guard, on every path', function (): void {
    $tenant = routerTenant(['channel_failover' => true]);
    credentialEveryMode($tenant);
    $router = probeRouter();
    $session = routerSession($tenant, ChannelMode::CloudApi, [ChannelMode::Baileys]);

    $wrapped = [
        $router->driverFor($session),
        $router->driverForMode(ChannelMode::Baileys, $tenant),
        ...$router->failoverChain($session),
    ];

    foreach ($wrapped as $driver) {
        // Unwrapped is a defect: the decorator is the only thing that makes
        // `requiresAntiBan()` non-disableable and a `❌` cell un-widenable, and the router is
        // the only place drivers are obtained — so if one leaves here bare, nothing else
        // catches it.
        expect($driver)->toBeInstanceOf(ModeGuardedChannelDriver::class)
            ->and($driver->requiresAntiBan())->toBe($driver->mode()->isWebProtocol())
            ->and($driver->supports(ChannelCapability::Groups))->toBe($driver->mode() === ChannelMode::Baileys);
    }
});

/*
|--------------------------------------------------------------------------
| Cross-tenant resolution is refused, outermost — Property 23
|--------------------------------------------------------------------------
*/

it('refuses to resolve a driver for another tenant\'s session, before reading anything', function (): void {
    $acme = routerTenant(['channel_failover' => true]);
    $globex = routerTenant(['channel_failover' => true]);
    credentialEveryMode($globex);

    $session = routerSession($globex, ChannelMode::CloudApi, [ChannelMode::Baileys]);
    $router = probeRouter();

    app(TenantContext::class)->set($acme);

    expect(fn (): ChannelDriver => $router->driverFor($session))
        ->toThrow(CrossTenantAccessException::class)
        ->and(fn (): array => $router->failoverChain($session))
        ->toThrow(CrossTenantAccessException::class)
        ->and(fn (): ChannelDriver => $router->driverForMode(ChannelMode::CloudApi, $globex))
        ->toThrow(CrossTenantAccessException::class)
        // The ownership check is *outermost*: no driver was constructed and no credential row
        // was resolved on behalf of a caller that was about to be refused.
        ->and(RouterProbeDriver::built(ChannelMode::CloudApi))->toBe(0);

    // ...and the refusal is the router's own, not one it happened to inherit. The relation
    // guard would also refuse `$session->tenant`, and the credential store would refuse the
    // row read — both *after* work has been done on a foreign tenant's behalf. Asserting which
    // refusal fires is what keeps the ordering claim honest rather than accidental.
    try {
        $router->driverFor($session);
        $message = '';
    } catch (CrossTenantAccessException $refused) {
        $message = $refused->getMessage();
    }

    expect(str_contains($message, Session::class))->toBeTrue($message)
        // Not the credential store's refusal (which names `ChannelCredential`, and fires only
        // after a row read has been attempted for the wrong tenant)...
        ->and(str_contains($message, ChannelCredential::class))->toBeFalse($message)
        // ...and not the relation guard's (which fires while loading `$session->tenant`).
        ->and(str_contains($message, 'Refusing to load'))->toBeFalse($message);
});

it('refuses a foreign tenant even for a mode that needs no credential lookup', function (): void {
    // The case no other guard covers: BAILEYS needs no credential row, so the store is never
    // consulted and no relation is loaded — without the router's own check, a caller acting as
    // Acme would be handed a driver resolved for Globex.
    $acme = routerTenant();
    $globex = routerTenant();

    app(TenantContext::class)->set($acme);

    expect(fn (): ChannelDriver => probeRouter()->driverForMode(ChannelMode::Baileys, $globex))
        ->toThrow(CrossTenantAccessException::class)
        ->and(RouterProbeDriver::built(ChannelMode::Baileys))->toBe(0);
});

it('resolves for a named tenant when no tenant is bound at all', function (): void {
    // The console, the scheduler, and a job that has not bound one yet — the same allowance
    // `DatabaseChannelCredentialStore` makes, and for the same reason.
    $tenant = routerTenant();
    credentialEveryMode($tenant);
    $session = routerSession($tenant, ChannelMode::CloudApi);

    app(TenantContext::class)->forget();

    expect(probeRouter()->driverFor($session)->mode())->toBe(ChannelMode::CloudApi);
});

/*
|--------------------------------------------------------------------------
| Memoised per (tenant, mode), for one unit of work
|--------------------------------------------------------------------------
*/

it('resolves a driver once per tenant and mode, and not once per call', function (): void {
    $acme = routerTenant();
    $globex = routerTenant();
    credentialEveryMode($acme);
    credentialEveryMode($globex);
    $router = probeRouter();

    $acmeSession = routerSession($acme, ChannelMode::CloudApi);
    $globexSession = routerSession($globex, ChannelMode::CloudApi);

    $router->driverFor($acmeSession);
    $router->driverFor($acmeSession);
    $router->driverForMode(ChannelMode::CloudApi, $acme);

    // One instance for Acme's Cloud API...
    expect(RouterProbeDriver::built(ChannelMode::CloudApi))->toBe(1);

    // ...and a second tenant gets its own, because a driver is bound to one tenant's
    // credentials.
    $router->driverFor($globexSession);
    expect(RouterProbeDriver::built(ChannelMode::CloudApi))->toBe(2);

    // The seam task 7.6 and task 8.6 need after changing what a resolution would answer.
    $router->forget($acme, ChannelMode::CloudApi);
    $router->driverFor($acmeSession);
    expect(RouterProbeDriver::built(ChannelMode::CloudApi))->toBe(3);

    // A fresh unit of work re-resolves: the memo is per instance, and the binding is
    // `scoped()`, so nothing survives the request or the job.
    probeRouter()->driverFor($acmeSession);
    expect(RouterProbeDriver::built(ChannelMode::CloudApi))->toBe(4);
});

it('binds the router scoped, so no driver survives a unit of work', function (): void {
    $first = app(ChannelRouter::class);

    expect($first)->toBeInstanceOf(DefaultChannelRouter::class)
        ->and(app(ChannelRouter::class))->toBe($first);

    // What `scoped()` means: the container forgets it at the end of the request, and the
    // queue worker between jobs — so one tenant's resolved drivers cannot be carried into
    // another tenant's job.
    app()->forgetScopedInstances();

    expect(app(ChannelRouter::class))->not->toBe($first);
});

/*
|--------------------------------------------------------------------------
| failoverChain — resolution only (Req 8.10, 8.11)
|--------------------------------------------------------------------------
*/

it('returns a one-element chain for an unconfigured session, never an empty one', function (): void {
    $tenant = routerTenant(['channel_failover' => true]);
    credentialEveryMode($tenant);
    $session = routerSession($tenant, ChannelMode::CloudApi);

    $chain = probeRouter()->failoverChain($session);

    // design § 2.6: the default is no failover. A one-element chain means task 8.5's advance
    // loop needs no "not configured" special case.
    expect($chain)->toHaveCount(1)
        ->and($chain[0]->mode())->toBe(ChannelMode::CloudApi);
});

it('puts the session\'s own mode at the head, whatever the stored list says', function (): void {
    $tenant = routerTenant(['channel_failover' => true]);
    credentialEveryMode($tenant);

    // A stored list that names the primary last, and a mode that is not the primary first.
    $session = routerSession($tenant, ChannelMode::CloudApi, [
        ChannelMode::Baileys,
        ChannelMode::OnPremise,
        ChannelMode::CloudApi,
    ]);

    $modes = array_map(
        static fn (ChannelDriver $driver): string => $driver->mode()->value,
        probeRouter()->failoverChain($session),
    );

    // Head is the primary; the stored order is preserved for the rest; the primary is not
    // attempted twice (Req 8.11).
    expect($modes)->toBe(['CLOUD_API', 'BAILEYS', 'ON_PREMISE']);
});

it('attempts each distinct mode at most once', function (): void {
    $tenant = routerTenant(['channel_failover' => true]);
    credentialEveryMode($tenant);
    $session = routerSession($tenant, ChannelMode::CloudApi, [
        ChannelMode::Baileys,
        ChannelMode::Baileys,
        ChannelMode::OnPremise,
        ChannelMode::Baileys,
    ]);

    $modes = array_map(
        static fn (ChannelDriver $driver): string => $driver->mode()->value,
        probeRouter()->failoverChain($session),
    );

    expect($modes)->toBe(['CLOUD_API', 'BAILEYS', 'ON_PREMISE'])
        ->and($modes)->toBe(array_values(array_unique($modes)));
});

it('skips a stored mode this release does not know, rather than failing the dispatch', function (): void {
    $tenant = routerTenant(['channel_failover' => true]);
    credentialEveryMode($tenant);

    // A chain written by a newer release, or naming a mode an older one has retired.
    $session = routerSession($tenant, ChannelMode::CloudApi, ['TELEGRAM', 'BAILEYS', '']);

    expect($session->failoverModes())->toBe([ChannelMode::Baileys]);

    $modes = array_map(
        static fn (ChannelDriver $driver): string => $driver->mode()->value,
        probeRouter()->failoverChain($session),
    );

    expect($modes)->toBe(['CLOUD_API', 'BAILEYS']);
});

it('omits a fallback mode the tenant cannot authenticate as', function (): void {
    $tenant = routerTenant(['channel_failover' => true]);
    app(ChannelCredentialStore::class)->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG']);

    // ON_PREMISE and BSP_GATEWAY have no credentials; BAILEYS needs none.
    $session = routerSession($tenant, ChannelMode::CloudApi, [
        ChannelMode::OnPremise,
        ChannelMode::BspGateway,
        ChannelMode::Baileys,
    ]);

    $modes = array_map(
        static fn (ChannelDriver $driver): string => $driver->mode()->value,
        probeRouter()->failoverChain($session),
    );

    // A fallback that cannot authenticate is not a fallback — and refusing the whole
    // dispatch over an *optional* hop would turn a working primary into an outage.
    expect($modes)->toBe(['CLOUD_API', 'BAILEYS']);
});

it('still refuses when the primary mode of a configured chain is unusable', function (): void {
    $tenant = routerTenant(['channel_failover' => true]);
    $session = routerSession($tenant, ChannelMode::CloudApi, [ChannelMode::Baileys]);

    // The asymmetry, stated as a test: lenient for a hop, strict for the primary. A chain is
    // not a way to get a send out on a half-configured primary — that would be the reroute
    // Property 22 forbids, with extra steps.
    expect(fn (): array => probeRouter()->failoverChain($session))
        ->toThrow(ChannelCredentialException::class);
});

it('collapses the chain to the primary when the plan does not include failover', function (): void {
    $tenant = routerTenant(['channel_failover' => false]);
    credentialEveryMode($tenant);
    $session = routerSession($tenant, ChannelMode::CloudApi, [ChannelMode::Baileys, ChannelMode::OnPremise]);

    expect(probeRouter()->failoverChain($session))->toHaveCount(1);

    // Read on every call, so granting the entitlement takes effect without touching the
    // stored chain...
    $plan = $tenant->plan;
    assert($plan instanceof Plan);
    $plan->forceFill(['features' => ['channel_failover' => true]])->save();
    Cache::flush();

    expect(probeRouter()->failoverChain($session))->toHaveCount(3);

    // ...and revoking it collapses every session's chain immediately, rather than leaving a
    // configured one live for the rest of the billing period.
    $plan->forceFill(['features' => ['channel_failover' => false]])->save();
    Cache::flush();

    expect(probeRouter()->failoverChain($session))->toHaveCount(1);
});

it('denies failover to a tenant with no plan at all', function (): void {
    $tenant = Tenant::factory()->create(['plan_id' => null]);
    $session = routerSession($tenant, ChannelMode::Baileys, [ChannelMode::CloudApi]);

    // `PlanGate` fails closed on a planless tenant, and the router inherits that rather than
    // treating "no plan" as "no restriction".
    expect(probeRouter()->failoverChain($session))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| assertSupported — refused before any I/O (Properties 21, 26)
|--------------------------------------------------------------------------
*/

it('refuses an unsupported capability before any driver is built or any query is run', function (): void {
    $tenant = routerTenant();
    credentialEveryMode($tenant);
    $session = routerSession($tenant, ChannelMode::CloudApi);
    $router = probeRouter();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $refuse = function () use ($router, $session): void {
        $router->assertSupported($session, ChannelCapability::Groups);
    };

    expect($refuse)
        ->toThrow(ModeCapabilityException::class, 'Group management is not available on this session')
        // The ordering *is* the guarantee: an operation the mode refuses must have no side
        // effect at all — no provider call, no breaker sample, no decrypted secret in memory.
        ->and($queries)->toBe(0)
        ->and(RouterProbeDriver::built(ChannelMode::CloudApi))->toBe(0);
});

it('allows a native capability and a conditional one, and reports which is which', function (): void {
    $tenant = routerTenant();
    credentialEveryMode($tenant);
    $session = routerSession($tenant, ChannelMode::CloudApi);
    $router = probeRouter();

    $router->assertSupported($session, ChannelCapability::SendSingle);
    // ⚠️ does not throw: the 24-hour window and the approved-template rule are task 8.4's,
    // and refusing here would refuse the commonest official-mode send there is.
    $router->assertSupported($session, ChannelCapability::FreeFormAnytime);

    expect($router->supportLevel($session, ChannelCapability::SendSingle)->isNative())->toBeTrue()
        ->and($router->supportLevel($session, ChannelCapability::FreeFormAnytime)->isConditional())->toBeTrue()
        ->and($router->supportLevel($session, ChannelCapability::Groups)->isSupported())->toBeFalse();
});

it('gates every cell of the matrix the way the session\'s mode does', function (): void {
    $router = probeRouter();

    foreach (ChannelMode::cases() as $mode) {
        $session = new Session(['channel_mode' => $mode]);

        foreach (ChannelCapability::cases() as $capability) {
            $support = $router->supportLevel($session, $capability);

            expect($support)->toBe($mode->supportFor($capability));

            if ($support->isSupported()) {
                $router->assertSupported($session, $capability);

                continue;
            }

            try {
                $router->assertSupported($session, $capability);
                /** @var ModeCapabilityException|null $thrown */
                $thrown = null;
            } catch (ModeCapabilityException $exception) {
                $thrown = $exception;
            }

            expect($thrown?->mode)->toBe($mode)
                ->and($thrown?->capability)->toBe($capability);
        }
    }
});
