<?php

declare(strict_types=1);

namespace App\Services\Dispatch\Eligibility;

use App\Models\Tenant;
use App\Services\Dispatch\DispatchEligibility;
use App\Services\Tenancy\TenantLifecycle;

/**
 * The suspension gate: a tenant that may not send is not in the rotation
 * (Req 1.1, 1.7 / A1; Req 30.6 / NFR1).
 *
 * The one eligibility gate that exists today, and the reason the scheduler ships with
 * a real implementation rather than a placeholder. It re-derives nothing: the answer is
 * `TenantLifecycle::canSendOutbound()`, the authoritative predicate, so `SUSPENDED` and
 * `CANCELLED` mean the same thing in the dispatch loop as they do at the send gate and
 * in the panels.
 *
 * ## Why the scheduler checks it at all, given the send gate also will
 *
 * Task 9.3's send pipeline calls `assertCanSendOutbound()` as its step 0, so a
 * suspended tenant's message can never leave regardless of what the scheduler does.
 * Checking here is not redundant with that — it is the difference between *refusing*
 * work and *not picking it up*: without this gate a suspended tenant with a million
 * queued recipients would be granted its full weighted share of every window, and each
 * grant would travel through the dispatcher only to be thrown away at the gate. The
 * suspended tenant would be consuming exactly the share of the platform that Req 30.6
 * exists to protect the paying tenants from. Skipping it here hands those units to
 * tenants that can use them.
 *
 * This gate is applied **unconditionally**, not through `wa.dispatch.eligibility.gates`
 * — see `CompositeDispatchEligibility`. Suspension is not a tunable.
 */
final readonly class LifecycleDispatchEligibility implements DispatchEligibility
{
    public function __construct(private TenantLifecycle $lifecycle) {}

    public function canDispatch(Tenant $tenant): bool
    {
        // Passing the model, not the id: `canSendOutbound()` then reads the status off
        // the instance instead of querying, which matters when a dispatch window asks
        // about a few hundred tenants.
        return $this->lifecycle->canSendOutbound($tenant);
    }
}
