<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ChannelMode;
use App\Services\Audit\AuditService;
use App\Services\Channel\BaileysChannelDriver;
use App\Services\Channel\Bsp\BspAdapterRegistry;
use App\Services\Channel\BspGatewayChannelDriver;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelCredentialValidator;
use App\Services\Channel\ChannelDriver;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\CloudApiChannelDriver;
use App\Services\Channel\DatabaseChannelCredentialStore;
use App\Services\Channel\DefaultChannelRouter;
use App\Services\Channel\OnPremiseChannelDriver;
use App\Services\Channel\OnPremiseTokenCache;
use App\Services\Channel\ProviderCallGuard;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
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
 * ## Four `scoped()` bindings, and that is the security boundary
 *
 * `scoped()` rather than `singleton()`, for the same reason `FieldCipher` and
 * `SigningSecretStore` are. Each of these objects holds something derived from **one
 * tenant**, for one unit of work:
 *
 * | Binding | What it holds | What a `singleton()` would mean |
 * |---|---|---|
 * | `ChannelCredentialStore` | which credential row answers a `(tenant, mode, provider)` lookup | a revoked or rotated-away credential set keeps being selected |
 * | `ChannelRouter` | the driver instance for a `(tenant, mode)` pair | one tenant's driver reached from another tenant's job |
 * | `OnPremiseTokenCache` | a **live bearer token** minted from a decrypted password | a token derived from decrypted credentials outliving the job that decrypted them (Req 8.5) |
 * | `ChannelCredentialValidator` | nothing itself — but it drops the two memos above | its `forget()` calls landing on a different store's memo than the one routing reads |
 *
 * Laravel discards scoped instances at the end of every request and the queue worker resets
 * them between jobs, which gives two guarantees at once — one tenant's resolved credentials,
 * drivers or tokens cannot be carried into another tenant's job inside a long-lived worker,
 * and a credential set that has just been disabled or rotated away from cannot keep being
 * selected past the unit of work that resolved it.
 *
 * A `singleton()` on any of the first three would quietly turn a per-request memo into a
 * process-lifetime cache. Nothing in those classes' own code would change; it would simply
 * stop being true that a revoked credential stops being used.
 *
 * The first two are bound **by interface only**, so task 7.6, the mode panel, and the drivers
 * of tasks 7.1–7.4 depend on the contracts rather than on these implementations.
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
 * All four `ChannelMode` cases are registered — `BAILEYS` (task 7.1), `CLOUD_API` (task 7.2),
 * `ON_PREMISE` (task 7.3) and `BSP_GATEWAY` (task 7.4) — so the registry is complete and every
 * mode a session may hold resolves. It stays worth stating what happens if that ever stops
 * being true, because the mechanism is the guarantee rather than the current contents:
 * resolving a mode with no entry raises `LogicException` naming the mode and listing what is
 * registered, rather than substituting a default backend — so an unfinished or mis-keyed mode
 * is a deployment defect reported as one, never a tenant's official traffic quietly leaving
 * through the Baileys bridge. The router additionally refuses an entry whose driver reports a
 * different `mode()` than its key.
 *
 * `FakeChannelDriver` (task 7.5) is deliberately **not** here: it is bound in the testing
 * container only, and a fifth entry in this map would be a way for it to reach production
 * routing.
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
        $this->registerProviderGuard();
        $this->registerBspAdapters();
        $this->registerOnPremiseTokenCache();
        $this->registerCredentialValidator();

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
     * Filled one line at a time by tasks 7.1–7.5. Keys are `ChannelMode::*->value` rather than
     * free strings so a typo cannot register a driver under a mode that does not exist; the
     * router additionally refuses an entry whose driver reports a different `mode()` than the
     * key it was found under.
     *
     * `BAILEYS` is registered through the container rather than constructed here, and that is
     * the point of the closure: `BaileysChannelDriver` asks for the `BridgeClient`
     * **interface**, so what it receives is the chain `BridgeServiceProvider` composed —
     * `TenantScopedBridgeClient → GuardedBridgeClient → HttpBridgeClient`. Naming
     * `HttpBridgeClient` here, or letting the driver build its own, would drop ownership
     * scoping and the breaker for every send routed through Channel Mode while leaving the
     * pre-Channel-Mode paths intact — a regression with no symptom until a tenant addressed
     * another tenant's session id.
     *
     * @return array<string, Closure(): ChannelDriver>
     */
    private static function drivers(Application $app): array
    {
        return [
            ChannelMode::Baileys->value => fn (): ChannelDriver => $app->make(BaileysChannelDriver::class),
            ChannelMode::CloudApi->value => fn (): ChannelDriver => $app->make(CloudApiChannelDriver::class),
            ChannelMode::OnPremise->value => fn (): ChannelDriver => $app->make(OnPremiseChannelDriver::class),
            ChannelMode::BspGateway->value => fn (): ChannelDriver => $app->make(BspGatewayChannelDriver::class),
        ];
    }

    /**
     * The circuit breaker + bounded inline retry every **official** driver shares.
     *
     * A singleton because it holds nothing per tenant: the breaker identity is an argument
     * (`ProviderCallGuard::breakerName($mode, $tenantId)`), not state, so one instance cannot
     * carry one tenant's breaker into another tenant's job — the property that makes the
     * `scoped()` bindings above necessary and this one safe.
     *
     * Registered here rather than left to autowiring for one reason: the attempt budget and the
     * inline delay cap are operator knobs (`wa.channel.guard`), and an autowired instance would
     * silently use the compiled-in defaults while the config keys sat there looking effective.
     * The fallbacks are the class's own constants, so a deleted key degrades to a working guard.
     *
     * Tasks 7.3 and 7.4 receive the same instance by constructor injection and inherit the whole
     * policy — the breaker family, the budget, the fail-closed conversion — without restating it.
     */
    private function registerProviderGuard(): void
    {
        $this->app->singleton(
            ProviderCallGuard::class,
            fn (Application $app): ProviderCallGuard => new ProviderCallGuard(
                $app->make(CircuitBreaker::class),
                $app->make(RetryPolicy::class),
                self::intValue(config('wa.channel.guard.attempts'), ProviderCallGuard::DEFAULT_ATTEMPTS),
                self::intValue(config('wa.channel.guard.max_delay_ms'), ProviderCallGuard::DEFAULT_MAX_DELAY_MS),
            ),
        );
    }

    /**
     * The eight BSP adapters, constructed once per process.
     *
     * A `singleton()`, and this one is a **performance** choice rather than a correctness one —
     * worth saying plainly, because everything else in this provider is `scoped()` for reasons
     * that are not negotiable. What makes it safe is that the registry can hold no tenant
     * state to carry anywhere: every adapter is `final readonly`, holds nothing, performs no
     * I/O, and is a pure function from `ChannelCredentials` to a `BspRequest`
     * (`Bsp\BspAdapter`). The credentials arrive as an argument on every call, exactly as
     * `ProviderCallGuard`'s breaker identity does, which is what distinguishes both from the
     * memo-holding bindings above.
     *
     * What the binding buys: `BspAdapterRegistry` constructs all eight in its constructor and
     * validates that they cover `BspProvider` exactly once each, so an autowired instance would
     * repeat that work every time the driver *or* the error classifier were resolved — and the
     * classifier is resolved from inside a failed job, which is the least useful place to spend
     * eight object constructions. `scoped()` would already collapse that to once per unit of
     * work; `singleton()` collapses it to once per process for a stateless object, so it is the
     * honest spelling of "there is nothing per-tenant here".
     *
     * Registered rather than left to autowiring only so that decision is written down: the
     * class self-constructs (its `$adapters` parameter defaults to `null`, meaning
     * `defaults()`), so `app(BspAdapterRegistry::class)` resolved perfectly well without this.
     */
    private function registerBspAdapters(): void
    {
        $this->app->singleton(BspAdapterRegistry::class);
    }

    /**
     * Where an On-Premise bearer token lives — and `scoped()` is the whole of what makes it
     * allowed to exist (Req 8.5 / A8).
     *
     * **Not optional, and not a performance binding.** `OnPremiseTokenCache` holds a token
     * minted by `POST /v1/users/login` from a **decrypted username and password**, so Req 8.5's
     * *"held only within the lifetime of the request or job that uses them, discarding them
     * before that request or job returns"* applies to the holder as much as to the plaintext.
     * `scoped()` is what delivers that: Laravel forgets scoped instances when the request ends,
     * and `Illuminate\Queue\Worker` calls `forgetScopedInstances()` between jobs — so the cache
     * cannot span two units of work, and a token cannot reach the next job, which may be
     * another tenant's.
     *
     * The class's own three defences cover what a binding cannot: the key is a digest of the
     * **password** (so a rotation cannot be answered with a token minted from the value it
     * replaced), `__serialize()` throws (so it cannot ride a queue payload sideways out of the
     * unit of work), and a token inside its last `EXPIRY_SKEW_SECONDS` is treated as already
     * expired (so a send does not start with two seconds of token left and fail `AUTH`, which
     * is structurally zero retries and therefore a lost message).
     *
     * A closure rather than a bare class name because `scoped(Class::class)` and
     * `scoped(Class::class, fn () => new Class)` differ in nothing but legibility here, and the
     * explicit form is where the paragraph above can be pinned to the line it describes. The
     * constructor takes no arguments by design: a cache that had to be given anything would be
     * a cache whose contents could be handed in from outside the unit of work.
     *
     * `OnPremiseChannelDriver` receives this instance by constructor injection, so *one* login
     * serves a whole campaign inside one job instead of one login per recipient — which is the
     * other half of Req 8.5's sentence, *"re-obtained rather than causing a run of auth
     * failures"*.
     */
    private function registerOnPremiseTokenCache(): void
    {
        $this->app->scoped(
            OnPremiseTokenCache::class,
            fn (): OnPremiseTokenCache => new OnPremiseTokenCache,
        );
    }

    /**
     * Validate-before-activate (task 7.6), bound `scoped()` to match the two memos it drops.
     *
     * It resolves with no wiring at all — every constructor parameter is an interface this
     * provider or `AuditServiceProvider` already binds — so this binding is about **lifetime**
     * rather than about construction. `ChannelCredentialValidator::activate()` and
     * `invalidate()` change which row `rowFor()`'s newest-verified-first ordering answers with,
     * and then call `forget()` on the store *and* the router so the change is visible
     * immediately. Those calls only mean anything if the validator is holding the same store
     * and router instances the send path holds, and `scoped()` is what guarantees that for the
     * length of a request or job: with a default (transient) binding a validator resolved twice
     * in one request would still reach the right memos — the store and router are scoped
     * themselves — but a `singleton()` validator in a long-lived worker would outlive them and
     * end up dropping memos on collaborators nothing else is using any more.
     *
     * Bound by its concrete class because it has no interface: it is a single implementation
     * with one caller shape (the mode screen, the console sweep), and inventing a contract for
     * it would be inventing a second place for its behaviour to be described.
     */
    private function registerCredentialValidator(): void
    {
        $this->app->scoped(ChannelCredentialValidator::class);
    }

    private static function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
