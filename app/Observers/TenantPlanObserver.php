<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Tenancy\TenantQuotaRestored;
use App\Models\Tenant;
use App\Services\Tenancy\QuotaParkingLot;

/**
 * Req 20.3's *"or after top-up/upgrade"*, for the path that exists today: a tenant moved to
 * another plan.
 *
 * A plan change can hand back allowance **mid-period** — `PlanRepository`'s versioned cache
 * is already invalidated by `PlanObserver`, so `QuotaGuard` sees the new ceiling on its very
 * next question. What was missing is that parked work has no reason to ask again until its
 * `resume_at`, which may be weeks away. This observer is that reason: it pulls the tenant's
 * holds forward and attempts the resume immediately, so a tenant who upgrades sees their
 * campaigns move rather than waiting for a period that has not rolled.
 *
 * Attached with `#[ObservedBy]` on `Tenant` rather than registered in a provider, for the
 * same reason as `PlanObserver`: the guarantee then travels with the model, including
 * through seeders, console commands and admin tooling that never boot a billing provider.
 *
 * ## Narrow on purpose
 *
 * - It fires **only** when `plan_id` actually changed, so the many other tenant writes
 *   (status transitions, timezone edits, `TenantLifecycle`) cost nothing at all — not even
 *   a query.
 * - A downgrade goes through the same path, and that is correct: the sweep re-asks
 *   `QuotaGuard`, and work the smaller plan cannot cover simply stays parked (with its
 *   reason updated to the blocking one, which is how an operator sees "needs an upgrade").
 * - It does not touch the plan *catalogue* case — an admin editing a plan's limits affects
 *   every tenant on it, and doing that inline would make one edit's cost proportional to
 *   the plan's popularity. `PlanObserver` handles it with a single bulk pull-forward.
 *
 * The wallet path (task 10.4) needs no observer at all: `WalletService::topUp()` calls
 * `QuotaParkingLot::allowanceChanged($tenant, TenantQuotaRestored::TRIGGER_TOP_UP)`
 * directly, which is the same one line this class runs.
 */
final class TenantPlanObserver
{
    public function updated(Tenant $tenant): void
    {
        if (! $tenant->wasChanged('plan_id')) {
            return;
        }

        app(QuotaParkingLot::class)->allowanceChanged($tenant, TenantQuotaRestored::TRIGGER_PLAN_CHANGE);
    }
}
