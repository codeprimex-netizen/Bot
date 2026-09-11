<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ModeCapabilityException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\Session;
use App\Models\Tenant;

/**
 * The single place a `ChannelDriver` comes from — `session.channel_mode → exactly one
 * driver`, plus the two gates that must run before anybody has one (Req 8.3, 8.4, 8.10,
 * 8.11 / A8; design § Channel Mode 2.4, 2.6; Correctness Properties 21, 22, 23, 24, 26).
 *
 * ```php
 * // the send path (Algorithm 9, task 8.1)
 * $router->assertSupported($session, $content->capability());   // typed refusal, before any driver exists
 * $driver = $router->driverFor($session);                       // exactly one, by session.channel_mode
 *
 * // the inbound path (task 8.3), after channel_webhook_routes.route_key resolves the session
 * $event = $router->driverFor($session)->parseWebhook($request, $credentials);
 *
 * // the dispatch path (task 8.5) — resolution here, execution there
 * foreach ($router->failoverChain($session) as $driver) {
 *     // breaker check, attempt, audit — task 8.5
 * }
 * ```
 *
 * ## Why routing is a service and not a `match`
 *
 * design § 2.6: *"`ChannelRouter::driverFor(session)` is the single point that maps
 * `channel_mode → driver`; the send pipeline, inbound webhook, and management services all
 * go through it, so routing is **exactly-once and centralized** (Property 22)."*
 *
 * A four-arm `match` at each call site would satisfy the letter of that and lose the three
 * things this contract exists to guarantee, none of which a call site can be relied on to
 * remember:
 *
 * | Guarantee | Where it comes from | What it prevents |
 * |---|---|---|
 * | every driver handed out is wrapped in `ModeGuardedChannelDriver` | `driverForMode()` wraps, always | a driver answering `requiresAntiBan() = false` on `BAILEYS`, or widening a `❌` cell (Req 8.8, Property 21/24) |
 * | a driver is only ever resolved for the **acting** tenant's session | ownership is checked before anything else, outermost | resolving another tenant's credentials for a session you do not own (Property 23) |
 * | the session's mode is the *only* input | no fallback mode, no default, no "credentials missing ⇒ use Baileys" | a tenant's official-mode traffic silently leaving on an unregistered Baileys number (Property 22) |
 *
 * ## The four members, and the boundaries between them
 *
 * | Member | Answers | Touches the database |
 * |---|---|---|
 * | `assertSupported()` / `supportLevel()` | can this mode be *asked* to do this at all? | **no** — a pure read of the capability matrix, so a refusal costs nothing |
 * | `driverFor()` | which single driver serves this session? | yes: the credential row for `(tenant, mode)` |
 * | `driverForMode()` | which driver serves `(mode, tenant)`, with no session in hand | yes, same |
 * | `failoverChain()` | which drivers, in what order, may this dispatch attempt? | yes: the plan, and a credential row per hop |
 *
 * ## What this contract deliberately does not do
 *
 * | Concern | Owner |
 * |---|---|
 * | walking the chain, breaker checks, `ChannelSendLog` rows, failover/exhaustion audit | task 8.5 |
 * | the anti-ban vs template/24-hour-window branch (Algorithm 9) | tasks 8.1, 8.4 |
 * | persisting the authoritative capability set from the `supports()` handshake | task 8.2 |
 * | validating credentials against a live driver, activate/rollback | task 7.6 |
 * | draining a mode switch's in-flight sends | task 8.6 |
 *
 * The implementation is `DefaultChannelRouter`, bound `scoped()` by `ChannelServiceProvider`.
 */
interface ChannelRouter
{
    /**
     * The one driver that serves `$session` — the driver whose `mode()` is
     * `$session->channel_mode`, and no other (Req 8.4 / A8, Correctness Property 22).
     *
     * A **total function of `$session->channel_mode`**. There is no second input: no
     * platform default, no "the tenant's other session was on Cloud API", and above all no
     * reroute when the session's own mode is not usable. Missing credentials are
     * `ChannelCredentialException` — see that class for why rerouting would be the worst
     * available outcome — and an unsupported *capability* was already refused by
     * `assertSupported()` before this was called.
     *
     * The returned instance is always a `ModeGuardedChannelDriver`. Callers may treat it as
     * a plain `ChannelDriver`; what matters is that no caller can obtain an unwrapped one,
     * because this contract is the only source of drivers.
     *
     * @throws CrossTenantAccessException when `$session` belongs to a tenant other than the acting one
     * @throws ChannelCredentialException when the session's mode has no usable credentials for its tenant
     */
    public function driverFor(Session $session): ChannelDriver;

    /**
     * The driver for `(mode, tenant)` — the same resolution as `driverFor()`, for callers
     * that have no session in hand.
     *
     * The mode-screen's health check (task 7.6), the registration step of session creation
     * (task 9.1, which needs a driver *before* the session is live), and `failoverChain()`'s
     * hops. Identical rules: wrapped, tenant-checked, memoised, and refused when the mode
     * has no usable credentials.
     *
     * @throws CrossTenantAccessException when a *different* tenant is bound
     * @throws ChannelCredentialException when `$mode` has no usable credentials for `$tenant`
     */
    public function driverForMode(ChannelMode $mode, Tenant $tenant): ChannelDriver;

    /**
     * The drivers this dispatch may attempt, in order, primary first — **never empty**
     * (Req 8.10, 8.11 / A8; design § Channel Mode 2.6).
     *
     * Resolution only. Task 8.5 owns execution — when to advance (breaker OPEN, retryable
     * failure after exhausted attempts), the audit row per hop, and the exhaustion verdict.
     * The split is here because the two halves fail differently: getting the *order* wrong
     * is a configuration bug visible in a list, while getting the *advance* wrong is a
     * duplicate send.
     *
     * An unconfigured session returns exactly `[driverFor($session)]`, so 8.5's loop needs no
     * "no failover" special case, and a tenant whose plan does not include
     * `PlanFeature::ChannelFailover` gets that same one-element chain however long its stored
     * list is.
     *
     * @return non-empty-list<ChannelDriver>
     *
     * @throws CrossTenantAccessException when `$session` belongs to another tenant
     * @throws ChannelCredentialException when the **primary** mode has no usable credentials
     */
    public function failoverChain(Session $session): array;

    /**
     * Refuse, before anything is constructed or read, an operation this session's mode does
     * not support (Req 8.3 / A8; Correctness Properties 21 and 26).
     *
     * The pre-dispatch gate every capability-sensitive service calls first — groups, welcome,
     * extraction, tagging, channels, templates, and Algorithm 9's capability arm. `❌` in the
     * matrix is `ModeCapabilityException`; `⚠️` is **not** refused here (see `supportLevel()`).
     *
     * Ordering is the point rather than a detail: no driver is instantiated, no credential row
     * is read, no tenant is resolved, and no I/O of any kind happens before the refusal, so an
     * unsupported operation cannot have a side effect — not a provider call, not a breaker
     * sample, not a decrypted secret in memory.
     *
     * @throws ModeCapabilityException when the session's mode refuses `$capability`
     */
    public function assertSupported(Session $session, ChannelCapability $capability): void;

    /**
     * How well this session's mode supports `$capability` — the matrix cell, three-valued and
     * unthrown.
     *
     * The non-throwing companion to `assertSupported()`, for the callers that must tell
     * `Native` from `Conditional`: task 8.4's 24-hour-window / approved-template rule, task
     * 8.1's provider-tier branch, and the panel, which renders a `⚠️` capability enabled with
     * a caveat rather than disabled.
     *
     * @see ChannelCapabilitySupport for what each value obliges a caller to do
     */
    public function supportLevel(Session $session, ChannelCapability $capability): ChannelCapabilitySupport;

    /**
     * Drop whatever this router remembers about `(tenant, mode)`, so the next resolution
     * re-reads.
     *
     * The seam for a caller that has just changed what a resolution would **answer** rather
     * than what it is allowed to do: task 7.6 stamping `verified_at` on a candidate or marking
     * a set `INVALID`, and task 8.6 switching a session's mode mid-request. Both change which
     * credential row `ChannelCredentialStore::rowFor()`'s newest-verified-first ordering
     * selects, and neither is visible to a router that already answered once — so this is
     * called in the same breath as `ChannelCredentialStore::forget()`, and one without the
     * other leaves half a stale resolution standing.
     *
     * ## Why it is on the contract and not only on the implementation
     *
     * Memoisation reads like an implementation detail, and for one call it is. It stops being
     * one the moment resolution is **observable**: `driverFor()` is documented to answer at
     * most once per `(tenant, mode)` per unit of work, which is what makes it safe to call in a
     * campaign's hot loop, and any implementation honouring that has something to drop. A
     * contract that promised the memo but not the way out of it forced every collaborator to
     * type-check for `DefaultChannelRouter` before dropping it — a defensive `instanceof`
     * that reads as caution while actually meaning *"this call may silently do nothing"*.
     *
     * A memo-less implementation satisfies this trivially with an empty body, which is a
     * cheaper obligation than the one the `instanceof` imposed on every caller.
     *
     * Idempotent, cheap, and the **only** mutation this contract exposes. It cannot change
     * routing: a forgotten `(tenant, mode)` re-resolves to the same mode, through the same
     * registry, under the same ownership and credential checks — so it is a way to make a
     * resolution *fresh*, never a way to make it different.
     *
     * ## `flush()` is deliberately **not** here
     *
     * `DefaultChannelRouter::flush()` exists and stays on the implementation. The case for
     * promoting it is symmetry: `ChannelCredentialStore` puts both `forget()` and `flush()` on
     * its interface, the two memos are dropped in pairs, and a future bulk rotation would hit
     * the same asymmetry this member was added to remove. The case against is what actually
     * decides it — `forget()` was promoted because it *had* callers being forced into an
     * `instanceof`, and `flush()` has none anywhere in the codebase; and unlike `forget()`,
     * whose arguments confine it to one tenant and one mode, `flush()` discards **every**
     * tenant's resolutions, which is not an operation a mode screen or a validator running
     * inside one tenant's request should be offered. Leaving it on the concrete class means its
     * one plausible user — a platform-wide sweep, or a test proving the memo is per-instance —
     * has to name the implementation, which is the right amount of friction. It should be added
     * the day a caller exists, with that caller as the evidence.
     */
    public function forget(Tenant $tenant, ChannelMode $mode): void;
}
