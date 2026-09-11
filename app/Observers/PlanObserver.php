<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Plan;
use App\Services\Billing\PlanRepository;
use App\Services\Tenancy\QuotaParkingLot;

/**
 * The three things that must happen around **every** plan write (Req 25.1 / D2,
 * Req 30.4 / NFR1, Req 20.3 / C3):
 *
 *  1. `saving` — refuse a plan whose `features`/`limits` JSON the platform cannot
 *     interpret, so corrupt gating data is caught at the admin edit that caused it
 *     rather than on a tenant's next message;
 *  2. `saved`/`deleted` — bump the plan cache version, so no reader can be served a
 *     plan as it was before the edit;
 *  3. `saved` with changed `limits` — let quota-paused work know its ceiling moved, so a
 *     raised limit resumes parked campaigns mid-period instead of leaving them waiting for
 *     a period reset they no longer need (Req 20.3's "after top-up/upgrade").
 *
 * Attached with `#[ObservedBy]` on the model rather than registered in a provider:
 * the guarantee then travels with the model, including in code paths (seeders,
 * console commands, tests) that never boot a billing provider.
 *
 * **Not covered:** writes that bypass Eloquent events — `Plan::query()->update()`,
 * raw SQL, a bulk import. Those must call `PlanRepository::flush()` themselves.
 */
final class PlanObserver
{
    public function saving(Plan $plan): void
    {
        $plan->assertWellFormed();
    }

    public function saved(Plan $plan): void
    {
        $this->invalidate();

        if ($plan->wasChanged('limits')) {
            // Pull-forward only, never an inline resume: a popular plan has thousands of
            // tenants, and an admin's edit must not pay for all of them. The scheduled
            // sweep picks the work up within the minute — and `QuotaGuard` still decides,
            // so a *lowered* limit simply leaves the work parked.
            app(QuotaParkingLot::class)->allowanceChangedForPlan($plan->id);
        }
    }

    public function deleted(Plan $plan): void
    {
        $this->invalidate();
    }

    /**
     * One atomic version bump invalidates every cached plan entry — by id, by slug,
     * and the active-catalogue listing — rather than only the keys of the plan that
     * changed. A plan edit is rare and a stale gate decision is expensive, so the
     * blunt instrument is the right one here.
     */
    private function invalidate(): void
    {
        app(PlanRepository::class)->flush();
    }
}
