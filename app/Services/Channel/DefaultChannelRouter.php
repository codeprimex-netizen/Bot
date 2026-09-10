<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\PlanFeature;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ModeCapabilityException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Closure;
use LogicException;

/**
 * `ChannelRouter` over a per-mode driver registry — the four decisions routing actually
 * makes, and why each is made here rather than at a call site (Req 8.3, 8.4, 8.10, 8.11,
 * 8.13 / A8; Correctness Properties 21, 22, 23, 24, 26).
 *
 * ## 1. Ownership is checked first, outermost, always
 *
 * `TenantScopedBridgeClient` is the precedent and its reasoning transfers exactly: it is the
 * outermost decorator so *"a foreign session id must be refused without consuming a retry
 * attempt, without touching the breaker, and above all without a request"*. Here the same
 * order matters for a different resource — credentials. Resolving a driver for a session the
 * acting tenant does not own would read `channel_credentials` for the *other* tenant and hand
 * back a driver authenticated as them, which is Property 23 inverted and the kind of failure
 * that produces no error anywhere.
 *
 * So `driverFor()`, `driverForMode()` and `failoverChain()` all compare the session's (or the
 * argument's) tenant against `TenantContext::currentId()` **before** the memo, before the
 * plan gate, and before any query — and refuse with `CrossTenantAccessException` (403) rather
 * than with an empty answer, for the reason `DatabaseChannelCredentialStore` gives: an empty
 * answer is indistinguishable from "this tenant has no credentials for this mode", which is a
 * state Req 8.13 makes the platform act on.
 *
 * A caller with **no** tenant bound is allowed through — the console, the scheduler, a
 * platform sweep, a job that has not bound one yet — exactly as the credential store allows
 * it, and the store then runs its own read inside `TenantContext::runFor()`. The router adds
 * no bypass of its own and holds no per-tenant state that could outlive one, because it is
 * bound `scoped()`.
 *
 * ## 2. Every driver leaves here wrapped, and that is what makes the anti-ban promise hold
 *
 * `resolve()` returns `new ModeGuardedChannelDriver($driver)`, with no path that returns the
 * raw instance. Since this class is the only place a driver is obtained, "no send can bypass
 * the anti-ban gate on a web-protocol mode" (Req 8.8, Property 24) and "no per-provider config
 * can widen a `❌` cell" (Property 21) stop being conventions the drivers of tasks 7.1–7.5
 * have to honour and become properties of the object graph. The decorator's own docblock makes
 * the argument for why a trait could not do this; this class is the other half of it — the
 * trait writes the right answers, the decorator enforces them, and the router is what
 * guarantees the decorator is present.
 *
 * A registry entry whose driver reports a `mode()` other than the key it was registered under
 * is a `LogicException`, not a silent acceptance: a message routed to such a driver would use
 * another mode's credentials and rate rules, and the whole of Property 22 rests on that key
 * being true.
 *
 * ## 3. `session.channel_mode` is the only input to `driverFor()`
 *
 * There is no fallback arm in this class — no platform default mode, no "the tenant's other
 * session was on Cloud API", and specifically no "credentials missing, use Baileys". A missing
 * credential set for the session's own mode raises `ChannelCredentialException`; see that
 * class for why the alternative — putting a brand's official, template-approved traffic on an
 * unregistered WhatsApp Web number — is worse than a refused send.
 *
 * `BAILEYS` needs no credential row at all (`ChannelMode::requiresTenantCredentials()` is
 * false), so the check is conditional on the mode rather than universal. That asymmetry is
 * the zero-official-API-onboarding promise: the default mode routes for a tenant that has
 * configured nothing.
 *
 * ## 4. Memoised per `(tenant, mode)`, for the length of one unit of work
 *
 * The memo is a private array keyed `{tenant}|{mode}`, and the binding is `scoped()` — the
 * same shape and the same reasoning as `DatabaseChannelCredentialStore`, which the class
 * docblock there sets out at length. Two properties matter:
 *
 * - **not shared.** A driver instance is bound to one tenant's credentials and one mode's
 *   rules; a `singleton()` or a `Cache` entry would let it outlive the request that resolved
 *   it and, in a long-lived queue worker, reach the next job — a different tenant's.
 * - **not a correctness dependency.** Resolution is a single indexed credential lookup plus a
 *   container `make()`, so the memo deduplicates work inside a campaign's hot loop and nothing
 *   more. A credential set that has just been disabled stops being routable at the next unit of
 *   work, and `forget()` drops the memo for callers that change one mid-request (task 7.6
 *   stamping `verified_at`, task 8.6 switching a session's mode).
 *
 * The memo holds **drivers**, never credentials: the plaintext lifetime rule of Req 8.5 lives
 * in the store, which decrypts on every `for()` call and is not asked for anything here beyond
 * *whether a usable row exists* (`rowFor()`, which decrypts nothing).
 *
 * ## The registry, and why it is code rather than config
 *
 * `$factories` maps a mode's backed value to a closure producing that mode's driver;
 * `ChannelServiceProvider` supplies it and tasks 7.1–7.5 add an entry each. It is not a
 * config key, for the reason `ChannelCapability`'s docblock gives about the matrix: which
 * backend serves a mode is a property of the platform, not an operator preference, and a
 * mis-keyed entry in a `.env`-driven map would be a live cross-mode routing bug. A mode with
 * no entry raises `LogicException` naming the mode — a deployment defect, reported as one.
 */
final class DefaultChannelRouter implements ChannelRouter
{
    /**
     * Resolved drivers, keyed `{tenantId}|{mode}` — see the class docblock for the lifetime
     * and for what is deliberately not in here.
     *
     * @var array<string, ChannelDriver>
     */
    private array $drivers = [];

    /**
     * @param  array<string, Closure(): ChannelDriver>  $factories  mode backed value → its driver, registered by `ChannelServiceProvider`
     */
    public function __construct(
        private readonly TenantContext $context,
        private readonly ChannelCredentialStore $credentials,
        private readonly PlanGate $plans,
        private readonly array $factories = [],
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    */

    public function driverFor(Session $session): ChannelDriver
    {
        // Ownership first — before the memo, the plan, and any query. See decision 1.
        $tenant = $this->ownerOf($session);

        // The session's mode, and nothing else (Property 22). A missing credential set is an
        // error here and *not* a reroute, which is the whole of decision 3.
        return $this->resolve($session->channel_mode, $tenant);
    }

    public function driverForMode(ChannelMode $mode, Tenant $tenant): ChannelDriver
    {
        $this->assertActingFor($tenant);

        return $this->resolve($mode, $tenant);
    }

    /**
     * @return non-empty-list<ChannelDriver>
     */
    public function failoverChain(Session $session): array
    {
        $tenant = $this->ownerOf($session);

        // The head is the session's own mode, whatever the stored list says — a chain that
        // began somewhere else would be a reroute wearing a chain's clothes, and Req 8.10
        // describes advancing *from* the primary. Resolved through `driverFor()`, so a session
        // whose own mode is unusable fails here exactly as it would there: the chain is not a
        // way to get a send out on a half-configured primary.
        $chain = [$this->driverFor($session)];

        // Read on every call, not at the point a chain was configured: revoking the
        // entitlement (a downgrade, a plan edit) must collapse the chain immediately rather
        // than leave a configured one live for the rest of the period. design § 2.6 calls
        // failover "opt-in, plan-gated"; `PlanFeature::ChannelFailover` is that gate.
        if ($this->plans->denies($tenant, PlanFeature::ChannelFailover)) {
            return $chain;
        }

        // Req 8.11 — each *distinct* driver at most once per dispatch. The primary counts as
        // attempted, so a stored list that names it again adds nothing.
        $seen = [$session->channel_mode->value => true];

        foreach ($session->failoverModes() as $mode) {
            if (isset($seen[$mode->value])) {
                continue;
            }

            $seen[$mode->value] = true;

            // A fallback whose credentials are absent is **omitted**, not an error. The
            // asymmetry with the primary above is deliberate: refusing the whole dispatch
            // because an *optional* extra hop is half-configured would turn a working primary
            // into an outage, while silently rerouting the primary would send a tenant's
            // official traffic from a number it never registered. So the strict answer belongs
            // to the primary and the lenient one to the fallbacks.
            $driver = $this->resolveFallback($mode, $tenant);

            if ($driver !== null) {
                $chain[] = $driver;
            }
        }

        return $chain;
    }

    /*
    |--------------------------------------------------------------------------
    | Capability gating
    |--------------------------------------------------------------------------
    */

    /**
     * `❌` ⇒ refuse; `⚠️` ⇒ allow, and let the layer that owns the condition apply it.
     *
     * ## Why `Conditional` does not throw
     *
     * The matrix has three states and this gate has two outcomes, so one of the three has to
     * be sorted into "attempt it". It has to be `Conditional`, because the conditions are all
     * *runtime* facts this method cannot see and must not guess at:
     *
     * | `⚠️` cell | The condition | Owner |
     * |---|---|---|
     * | `FREE_FORM_ANYTIME` on an official mode | is the 24-hour customer-service window open, or is an approved template attached? | task 8.4 (`TemplateRequiredException`) |
     * | `SEND_BULK` on an official mode | the number's quality tier / messaging limit | task 8.1 |
     * | `MEDIA`, `INTERACTIVE`, `DELIVERY_RECEIPTS` on `BSP_GATEWAY` | the partner's own sub-matrix, resolved from live credentials | task 7.4 |
     * | `TEMPLATE` on `BAILEYS` | there is no approval registry — a template can only be rendered as text | task 7.1 |
     *
     * Throwing here would refuse a Cloud API free-form reply *inside* the 24-hour window — the
     * single most common official-mode send there is — and would refuse every BSP media send
     * before the partner had been consulted. Answering the other way and dropping the
     * distinction would be worse in a quieter way: the caller could no longer tell that a
     * further rule exists, and 8.4's window check would have nothing to key on.
     *
     * So the split is: **this method decides what is impossible, later gates decide what is
     * permitted right now.** `supportLevel()` is the seam those gates read, and it is a
     * separate, non-throwing member precisely so a caller is never forced to use exceptions
     * for control flow to discover a `⚠️`.
     *
     * No I/O, no driver, no tenant resolution — see the interface for why that ordering is
     * itself part of the guarantee (Properties 21, 26).
     */
    public function assertSupported(Session $session, ChannelCapability $capability): void
    {
        if ($this->supportLevel($session, $capability) === ChannelCapabilitySupport::Unsupported) {
            throw ModeCapabilityException::for($session->channel_mode, $capability);
        }
    }

    public function supportLevel(Session $session, ChannelCapability $capability): ChannelCapabilitySupport
    {
        // Straight from the mode's matrix cell. Not from a driver instance: the answer must
        // be available before one is constructed, and `ModeGuardedChannelDriver` derives its
        // own `supports()` from this same matrix, so the two cannot disagree.
        return $session->channel_mode->supportFor($capability);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Drop the memoised driver for `(tenant, mode)`, so the next resolution re-reads.
     *
     * The seam for a caller that changes what a resolution would answer: task 7.6 stamping
     * `verified_at` or marking a set `INVALID`, and task 8.6 switching a session's mode
     * mid-request. Cheap, and the only mutation this class exposes.
     */
    public function forget(Tenant $tenant, ChannelMode $mode): void
    {
        unset($this->drivers[$this->memoKey($tenant, $mode)]);
    }

    /**
     * Drop every memoised driver.
     */
    public function flush(): void
    {
        $this->drivers = [];
    }

    /**
     * The wrapped driver for `(mode, tenant)` — memoised, guarded, and refused outright when
     * the mode has nothing to authenticate with.
     *
     * The wrapping is unconditional and there is no sibling method that skips it: that is what
     * makes decision 2 a property of the graph rather than a convention.
     *
     * @throws ChannelCredentialException when the mode has no usable credentials for the tenant
     * @throws LogicException when no driver is registered for `$mode`, or one is registered under the wrong mode
     */
    private function resolve(ChannelMode $mode, Tenant $tenant): ChannelDriver
    {
        $key = $this->memoKey($tenant, $mode);

        if (isset($this->drivers[$key])) {
            return $this->drivers[$key];
        }

        if (! $this->hasCredentials($mode, $tenant)) {
            throw ChannelCredentialException::missing($mode, $tenant->id);
        }

        return $this->drivers[$key] = new ModeGuardedChannelDriver($this->driverOf($mode));
    }

    /**
     * The same resolution for a **failover hop**: `null` instead of an exception when the mode
     * has no usable credentials.
     *
     * A separate method rather than a boolean flag on `resolve()`, because the difference is
     * not a mode of one operation — it is which of two documented behaviours the caller is
     * entitled to, and the strict one must stay the default for anything reached from
     * `driverFor()`.
     */
    private function resolveFallback(ChannelMode $mode, Tenant $tenant): ?ChannelDriver
    {
        $key = $this->memoKey($tenant, $mode);

        if (isset($this->drivers[$key])) {
            return $this->drivers[$key];
        }

        return $this->hasCredentials($mode, $tenant) ? $this->resolve($mode, $tenant) : null;
    }

    /**
     * Whether a send on `$mode` could authenticate as `$tenant` right now.
     *
     * `rowFor()` rather than `for()`: this is an existence question, and `for()` would decrypt
     * the secret bag to answer it. The store's row lookup already applies
     * `ChannelCredential::isUsable()`, so a non-null row means active, verified-or-unverified,
     * and actually carrying its secret fields — there is nothing left for this method to
     * re-check.
     *
     * `BAILEYS` short-circuits to `true` without a query at all: its bridge URL and shared
     * token are platform configuration rather than tenant secrets
     * (`ChannelMode::requiresTenantCredentials()`), so a tenant that has configured nothing
     * still routes. Substituting that platform config is the Baileys driver's business (task
     * 7.1, via `ChannelCredentials::platform()`), not the router's.
     */
    private function hasCredentials(ChannelMode $mode, Tenant $tenant): bool
    {
        if (! $mode->requiresTenantCredentials()) {
            return true;
        }

        return $this->credentials->rowFor($tenant, $mode) !== null;
    }

    /**
     * The registered driver for `$mode`, checked against its own `mode()`.
     *
     * @throws LogicException when the registry has no entry, or the entry is keyed wrongly
     */
    private function driverOf(ChannelMode $mode): ChannelDriver
    {
        $factory = $this->factories[$mode->value] ?? null;

        if ($factory === null) {
            throw new LogicException(sprintf(
                'No channel driver is registered for mode [%s]. Every mode a session may hold must '
                .'have exactly one driver, registered in App\Providers\ChannelServiceProvider — '
                .'routing has no default and will not substitute another mode\'s driver. Registered: %s.',
                $mode->value,
                $this->factories === [] ? 'none' : implode(', ', array_keys($this->factories)),
            ));
        }

        $driver = $factory();

        if ($driver->mode() !== $mode) {
            throw new LogicException(sprintf(
                'The driver registered for mode [%s] reports mode [%s]. Property 22 rests on that key '
                .'being true: a message routed through it would use another mode\'s credentials, rate '
                .'rules, and ban-risk profile.',
                $mode->value,
                $driver->mode()->value,
            ));
        }

        return $driver;
    }

    /*
    |--------------------------------------------------------------------------
    | Scoping
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant that owns `$session`, proven to be the one this code is acting as.
     *
     * The id comparison happens **before** the relation is loaded: reading `$session->tenant`
     * first would be a query made on behalf of a caller that is about to be refused, and the
     * refusal is the interesting event.
     *
     * @throws CrossTenantAccessException when a different tenant is bound
     */
    private function ownerOf(Session $session): Tenant
    {
        $acting = $this->context->currentId();

        if ($acting !== null && $acting !== $session->tenant_id) {
            throw CrossTenantAccessException::forRetrieval(Session::class, $session->tenant_id, $acting);
        }

        return $session->tenant;
    }

    /**
     * Refuse to answer about `$tenant` while a *different* tenant is bound.
     *
     * Deliberately identical in shape and reasoning to
     * `DatabaseChannelCredentialStore::assertActingFor()` — including allowing a caller with
     * no tenant bound through, so the console, the scheduler and platform sweeps can resolve a
     * driver for a named tenant.
     *
     * @throws CrossTenantAccessException when a different tenant is bound
     */
    private function assertActingFor(Tenant $tenant): void
    {
        $acting = $this->context->currentId();

        if ($acting !== null && $acting !== $tenant->id) {
            throw CrossTenantAccessException::forRetrieval(Session::class, $tenant->id, $acting);
        }
    }

    private function memoKey(Tenant $tenant, ChannelMode $mode): string
    {
        return $tenant->id.'|'.$mode->value;
    }
}
