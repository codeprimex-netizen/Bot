<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * The tenant state machine, its side effects, and the guards that make a tenant's
 * status *mean* something (Req 1.1 / A1; Req 10.3 / B1; design.md §"Tenant
 * lifecycle").
 *
 * ```
 * [*] -> TRIAL         provision            (task 1.2)
 * TRIAL -> ACTIVE      subscribe            activate()
 * ACTIVE -> SUSPENDED  non-payment / abuse  suspend()
 * SUSPENDED -> ACTIVE  reactivate           reactivate()
 * ACTIVE -> CANCELLED  cancel / offboard    cancel()
 * SUSPENDED -> CANCELLED                    cancel()
 * CANCELLED -> [*]     verified hard-delete after the retention window (task 34.3)
 * ```
 *
 * ## Division of labour
 *
 * `TenantStatus` owns the *rules* — which edges exist, which states are terminal,
 * which are operational. This service owns *enforcement and consequence*: it refuses
 * an edge the enum does not have (`InvalidTenantTransitionException`), performs the
 * write, records the offboarding debt, and audits the decision. Nothing here
 * re-implements `TenantStatus::allowedNext()`; a new edge is added in the enum and
 * this service follows.
 *
 * ## Guards: statuses that only matter if something asks
 *
 * A status column that nobody consults blocks nothing, so the second half of this
 * interface is the set of authoritative predicates every other subsystem consults.
 * They are the *only* place the meaning of `SUSPENDED` is written down, and they take
 * no `$force` / `$bypass` argument by design (Req 4.4 / A4 applies the same rule to
 * anti-ban): a caller cannot opt out of them, it can only fail to call them, which is
 * why each one below names the task that must call it.
 *
 * | Guard | Answer for `SUSPENDED` | Called by |
 * |---|---|---|
 * | `canSendOutbound()` / `assertCanSendOutbound()` | **no** | task 9.3 `tenantSendGate`, as step 0 |
 * | `canRecordInbound()` | **yes** (Req 10.3) | task 11.2 `HandleInboundMessageJob` |
 * | `canAutoReply()` | **no** (Req 10.3) | task 11.3 `ConversationEngine::handle` |
 * | `canMutate()` / `assertCanMutate()` | **no** | Phase C panel components, via the `tenant.mutate` gate |
 * | `isReadOnly()` | **yes** | Phase C panels, to render the read-only banner |
 *
 * ## Not here yet
 *
 * - `provision(array $spec): Tenant` — **task 1.2**. It must create the tenant,
 *   wallet, default chatbot, per-tenant DEK, storage prefix, and seed plan atomically
 *   (Req 1.8 / A1); `wallets` (task 10.1) and `chatbots` (task 11.1) do not exist yet,
 *   so it is absent rather than stubbed.
 * - `export(Tenant): ExportArchive` — data portability, Req 28.2 / D5.
 * - `offboard(Tenant): void` — **task 34.3**. `cancel()` already records *when* the
 *   purge falls due (`purgeDueAt()`, `isPurgeDue()`); `offboard()` is the executor
 *   that deletes across OLTP, vector store, object storage, and analytics, verifies
 *   zero residue, and writes the signed deletion certificate.
 */
interface TenantLifecycle
{
    /*
    |--------------------------------------------------------------------------
    | Transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Move a tenant to `$to`, or fail.
     *
     * The general form every named transition below delegates to. Three outcomes, and
     * no fourth:
     *
     * - `$to` is the tenant's current status → **idempotent success**: no write, no
     *   audit entry (a no-op is not a decision worth recording), the tenant back.
     * - the edge exists → the write, its side effects, and one audit entry.
     * - the edge does not exist → `InvalidTenantTransitionException`, never a silent
     *   no-op.
     *
     * Safe to call with any tenant from any context: platform mode, a bound tenant, or
     * nothing bound at all (console, scheduler, queue). The audit entry always lands on
     * the subject tenant's chain, where its history belongs.
     *
     * @param  string  $reason  non-empty, human-readable, recorded in the audit trail
     * @return Tenant the same instance, with the new status and lifecycle timestamps
     *
     * @throws \InvalidArgumentException on an empty reason
     * @throws \App\Exceptions\Tenancy\InvalidTenantTransitionException when the edge does not exist
     */
    public function transitionTo(Tenant $tenant, TenantStatus $to, string $reason): Tenant;

    /**
     * `TRIAL -> ACTIVE` — the tenant subscribed.
     */
    public function activate(Tenant $tenant, string $reason = 'Subscription activated'): Tenant;

    /**
     * `TRIAL|ACTIVE -> SUSPENDED` — non-payment or abuse.
     *
     * Blocks all outbound, keeps inbound logging, and puts panels in read-only mode.
     * No data is deleted and no queue is drained: suspension is reversible, and
     * `reactivate()` restores sending with nothing to rebuild.
     *
     * The design sketch types this `void`; returning the tenant is a superset, so
     * callers that only want the side effect can keep ignoring it.
     */
    public function suspend(Tenant $tenant, string $reason): Tenant;

    /**
     * `SUSPENDED -> ACTIVE` — the suspension is lifted and sending resumes.
     *
     * Calling this on a `TRIAL` tenant is the `activate()` edge and is recorded as
     * such; calling it on an `ACTIVE` tenant is an idempotent no-op.
     */
    public function reactivate(Tenant $tenant, string $reason = 'Suspension lifted'): Tenant;

    /**
     * `TRIAL|ACTIVE|SUSPENDED -> CANCELLED` — terminal.
     *
     * Records `cancelled_at`, from which the retention window and therefore the purge
     * due date are computed, and audits what offboarding still owes. It deliberately
     * **does not delete anything**: the design's diagram ends `CANCELLED -> [*]` at
     * *hard-delete after the retention window (verified)*, so the data must survive
     * cancellation until task 34.3's purge runs.
     */
    public function cancel(Tenant $tenant, string $reason): Tenant;

    /*
    |--------------------------------------------------------------------------
    | Outbound guard (Req 1.1 / A1) — the suspension choke point
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this tenant may send anything at all right now.
     *
     * True for `ACTIVE` and `TRIAL`; false for `SUSPENDED` and `CANCELLED`, and false
     * for a tenant id that resolves to nothing (fail closed — a draining job whose
     * tenant is gone must not send).
     *
     * This is the *authoritative* predicate, not a copy of one: `Tenant::isOperational()`
     * and `TenantStatus::isOperational()` are the same answer read from the model and
     * the enum, and all three must stay in agreement.
     */
    public function canSendOutbound(Tenant|string $tenant): bool;

    /**
     * The same question as an assertion, for the send path.
     *
     * **Task 9.3 must call this first**, before the plan gate, inside
     * `tenantSendGate` (Algorithm 3) — i.e. ahead of
     * `planGate.allows(...) -> quotaGuard.verdict(...) -> antiBanEngine.gate(...)`, so
     * a suspended tenant is refused before any quota is examined or consumed. A
     * suspended tenant's send is a **block**, never a defer: deferring assumes the
     * condition clears by itself, and this one clears only when an operator
     * reactivates the tenant.
     *
     * There is no bypass parameter, and there must never be one.
     *
     * @throws \App\Exceptions\Tenancy\TenantNotOperationalException
     */
    public function assertCanSendOutbound(Tenant|string $tenant): void;

    /*
    |--------------------------------------------------------------------------
    | Inbound guard (Req 10.3 / B1) — log, but do not answer
    |--------------------------------------------------------------------------
    */

    /**
     * Whether an inbound message for this tenant may still be persisted.
     *
     * **True while `SUSPENDED`** — that is the entire point of Req 10.3: a suspended
     * tenant keeps its inbound log, so nothing a customer sent is lost while billing
     * is sorted out. False once `CANCELLED`, because an offboarding tenant must not
     * accumulate new personal data it has just promised to delete.
     *
     * The seam for Phase D: `messages_inbound` arrives with task 11.1, so today this
     * predicate is the rule without its table. Task 11.2's `HandleInboundMessageJob`
     * must consult it *before* persisting, and task 11.3's `ConversationEngine::handle`
     * must pair it with `canAutoReply()` — record, then halt.
     */
    public function canRecordInbound(Tenant|string $tenant): bool;

    /**
     * Whether the conversation engine may answer an inbound message automatically.
     *
     * The prohibition half of Req 10.3, and the same answer as `canSendOutbound()` by
     * construction — a reply is outbound. It exists as its own predicate because
     * task 11.3 reads as *"log the inbound, then decide whether to reply"*, and the
     * design's Algorithm 1 precondition (`ASSERT ctx.tenant.status IN {ACTIVE, TRIAL}`)
     * is exactly this check.
     */
    public function canAutoReply(Tenant|string $tenant): bool;

    /*
    |--------------------------------------------------------------------------
    | Panel guard (Req 1.1 / A1) — readable, not writable
    |--------------------------------------------------------------------------
    */

    /**
     * Whether panel writes are allowed for this tenant.
     *
     * Consulted by every Phase C User Panel component that mutates something, through
     * the `tenant.mutate` gate that `TenancyServiceProvider` registers on top of this
     * method — so a component authorizes rather than re-deriving the rule:
     *
     * ```php
     * Gate::authorize('tenant.mutate', $tenant);   // or $this->authorize(...) in Livewire
     * ```
     */
    public function canMutate(Tenant|string $tenant): bool;

    /**
     * `canMutate()` as an assertion, for service-layer writes that have no gate around
     * them.
     *
     * @param  string  $intent  what was being attempted, for the log ("update chatbot")
     *
     * @throws \App\Exceptions\Tenancy\TenantNotOperationalException
     */
    public function assertCanMutate(Tenant|string $tenant, string $intent): void;

    /**
     * Whether the panel should render read-only: reads work, writes do not.
     *
     * The inverse of `canMutate()`, named for how a panel uses it (disable the form,
     * show the banner) rather than for what it denies.
     */
    public function isReadOnly(Tenant|string $tenant): bool;

    /*
    |--------------------------------------------------------------------------
    | Offboarding seam (Req 28.2 / D5 — executed by task 34.3)
    |--------------------------------------------------------------------------
    */

    /**
     * When this tenant's data becomes eligible for the verified hard delete, or `null`
     * while it has not been cancelled.
     *
     * `cancelled_at + wa.tenancy.lifecycle.retention_days`, computed rather than
     * stored, so shortening or extending the retention policy takes effect for tenants
     * already in the window instead of needing a backfill.
     */
    public function purgeDueAt(Tenant $tenant): ?Carbon;

    /**
     * Whether the retention window has elapsed and the purge is owed now.
     *
     * Task 34.3's scheduled job selects its work with the matching query —
     * `Tenant::query()->where('status', TenantStatus::Cancelled)->where('cancelled_at', '<=', now()->subDays($days))`
     * — and should re-check this per tenant before deleting anything.
     */
    public function isPurgeDue(Tenant $tenant): bool;

    /**
     * How long a cancelled tenant's data is kept before the purge falls due.
     */
    public function retentionDays(): int;
}
