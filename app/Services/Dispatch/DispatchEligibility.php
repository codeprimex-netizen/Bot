<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Tenant;

/**
 * "May this tenant be dispatched *right now*?" — the one question the fair scheduler
 * asks before putting a tenant in the rotation (Req 30.6 / NFR1; design.md
 * §"Noisy-neighbor prevention" → quota-aware dispatch).
 *
 * Deliberately the narrowest possible contract: one tenant in, a boolean out, no
 * reason, no side effects, no consumption. It is a **cheap, repeatable pre-check**,
 * not an authorization and not a reservation — the scheduler may ask about a tenant
 * many times a second, and it asks about tenants it then decides not to dispatch.
 *
 * ## What "skip" means, and what it does not
 *
 * A gate that answers `false` takes the tenant out of *this* window only:
 *
 * - the tenant's deficit credit is **left where it is** — neither topped up (so it
 *   cannot hoard credit while capped and then burst) nor cleared (so it resumes on its
 *   normal share the moment the cap lifts);
 * - the round is **not consumed** on its behalf: the freed units go to the tenants
 *   that can use them, which is the entire point of quota-aware dispatch —
 *   design.md's *"a tenant at its rate cap is skipped in the dispatch loop (not spun),
 *   freeing workers for others"*;
 * - nothing is dropped or failed. A skip is not a rejection of the tenant's work; the
 *   work stays queued and is picked up in a later window (Req 31.1 / NFR2 — never drop
 *   an outbound job on a rate limit).
 *
 * A gate must therefore be **transient-safe**: the only reasons that belong here are
 * ones that clear by themselves or by an operator action. A permanent refusal (a
 * feature not in the plan, an invalid recipient) belongs at the send gate, where it
 * can produce an error the tenant sees, not here, where it would silently mean
 * "later".
 *
 * ## Who implements it
 *
 * `Eligibility\CompositeDispatchEligibility` is what the scheduler actually gets: it
 * ANDs together the suspension gate, which is mandatory in code, plus whatever
 * `wa.dispatch.eligibility.gates` adds. Later phases plug in here **by appending a
 * class to that config array** — no scheduler change, no call-site change:
 *
 * | Task | Gate it adds | Question it answers |
 * |---|---|---|
 * | 1.3 (done) | `Eligibility\LifecycleDispatchEligibility` | is the tenant suspended/cancelled? (`TenantLifecycle::canSendOutbound()`) |
 * | 2.3 (done) | `Eligibility\QuotaDispatchEligibility` | has the tenant any allowance left this period? Uses `QuotaGuard::verdict()` only — **never** `consume()`: this method is asked speculatively and must not spend quota. |
 * | **9.6** | an anti-ban gate | is the tenant inside its per-minute/hour/day pacing budget, its warm-up cap, or a quiet-hours window? |
 * | **36.5** | a per-tenant AI-concurrency gate | is this tenant already at its in-flight `ai-reply` cap? (the second half of Req 30.6) |
 *
 * Each of those gates is also the natural place for its own metric or log line: the
 * scheduler reports *that* a tenant was skipped (`DispatchRoundResult::$skipped`), and
 * the gate that refused knows *why*.
 */
interface DispatchEligibility
{
    /**
     * Whether this tenant can be given work in the current dispatch window.
     *
     * Implementations must be side-effect free and must fail **closed** — an
     * unavailable dependency answers `false` (skip the tenant this window, try again
     * next window) rather than letting work through a gate that could not be checked.
     */
    public function canDispatch(Tenant $tenant): bool;
}
