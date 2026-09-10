<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ChannelMode;
use App\Services\Audit\AuditService;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelDriver;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\DatabaseChannelCredentialStore;
use App\Services\Channel\DefaultChannelRouter;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Channel Mode layer: per-tenant, per-mode credentials, and the router that turns
 * `session.channel_mode` into exactly one driver (Req 8.4, 8.5 / A8; Req 32.3 / NFR3;
 * Correctness Properties 22, 23).
 *
 * ## Both bindings are `scoped()`, and that is the security boundary
 *
 * `scoped()` rather than `singleton()`, for the same reason `FieldCipher` and
 * `SigningSecretStore` are. Each of these objects memoises something derived from **one
 * tenant**: the store remembers which credential row answers a `(tenant, mode, provider)`
 * lookup, and the router remembers the driver instance for a `(tenant, mode)` pair. Laravel
 * discards scoped instances at the end of every request and the queue worker resets them
 * between jobs, which gives two guarantees at once — one tenant's resolved credentials or
 * drivers cannot be carried into another tenant's job inside a long-lived worker, and a
 * credential set that has just been disabled or rotated away from cannot keep being selected
 * past the unit of work that resolved it.
 *
 * A `singleton()` here would quietly turn a per-request memo into a process-lifetime cache.
 * Nothing in either class's own code would change; it would simply stop being true that a
 * revoked credential stops being used.
 *
 * Bound **by interface only**, so task 7.6, the mode panel, and the drivers of tasks 7.1–7.5
 * depend on the contracts rather than on these implementations.
 *
 * ## The driver registry lives here, in code
 *
 * `DefaultChannelRouter` takes a map of `ChannelMode` value → a closure producing that mode's
 * driver, and `drivers()` below is that map. It is a code registry rather than a config key
 * for the reason `ChannelCapability`'s docblock gives about the capability matrix: which
 * backend serves a mode is a property of the platform, not an operator preference, and a
 * mis-keyed entry in an environment-driven map would be a live cross-mode routing bug — a
 * tenant's official traffic leaving through another backend's credentials.
 *
 * The map is **empty until tasks 7.1–7.5 land**, and the router says so loudly: resolving a
 * mode with no entry raises `LogicException` naming the mode and listing what is registered,
 * rather than substituting a default backend. Each of those tasks adds exactly one line:
 *
 * ```php
 * ChannelMode::Baileys->value => fn (): ChannelDriver => $app->make(BaileysChannelDriver::class),
 * ```
 *
 * Closures rather than class strings so a driver may be constructed however it needs to be —
 * `BaileysChannelDriver` wraps the existing `BridgeClient` chain, `BspGatewayChannelDriver`
 * fronts eight providers — without this provider having to know or the router having to care.
 * They are resolved lazily, at most once per `(tenant, mode)` per unit of work, so registering
 * a mode a deployment never uses costs nothing.
 */
class ChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(
            ChannelCredentialStore::class,
            fn (Application $app): ChannelCredentialStore => new DatabaseChannelCredentialStore(
                $app->make(TenantContext::class),
                $app->make(AuditService::class),
            ),
        );

        $this->app->scoped(
            ChannelRouter::class,
            fn (Application $app): ChannelRouter => new DefaultChannelRouter(
                $app->make(TenantContext::class),
                $app->make(ChannelCredentialStore::class),
                $app->make(PlanGate::class),
                self::drivers($app),
            ),
        );
    }

    /**
     * One entry per `ChannelMode`, keyed by its backed value — the platform's driver registry.
     *
     * Empty here, and filled one line at a time by tasks 7.1–7.5. Keys are
     * `ChannelMode::*->value` rather than free strings so a typo cannot register a driver under
     * a mode that does not exist; the router additionally refuses an entry whose driver reports
     * a different `mode()` than the key it was found under.
     *
     * @return array<string, Closure(): ChannelDriver>
     */
    private static function drivers(Application $app): array
    {
        return [
            // Task 7.1: ChannelMode::Baileys->value    => fn (): ChannelDriver => $app->make(BaileysChannelDriver::class),
            // Task 7.2: ChannelMode::CloudApi->value   => fn (): ChannelDriver => $app->make(CloudApiChannelDriver::class),
            // Task 7.3: ChannelMode::OnPremise->value  => fn (): ChannelDriver => $app->make(OnPremiseChannelDriver::class),
            // Task 7.4: ChannelMode::BspGateway->value => fn (): ChannelDriver => $app->make(BspGatewayChannelDriver::class),
        ];
    }
}
