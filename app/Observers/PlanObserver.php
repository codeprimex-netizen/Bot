<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Plan;
use App\Services\Billing\PlanRepository;

/**
 * The two things that must happen around **every** plan write (Req 25.1 / D2,
 * Req 30.4 / NFR1):
 *
 *  1. `saving` — refuse a plan whose `features`/`limits` JSON the platform cannot
 *     interpret, so corrupt gating data is caught at the admin edit that caused it
 *     rather than on a tenant's next message;
 *  2. `saved`/`deleted` — bump the plan cache version, so no reader can be served a
 *     plan as it was before the edit.
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
