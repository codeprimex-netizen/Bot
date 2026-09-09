<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning\Steps;

use App\Exceptions\Tenancy\SeedPlanUnavailableException;
use App\Services\Billing\PlanRepository;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;

/**
 * Step 2 — the seed plan (Req 1.8 / A1; Req 25.1 / D2).
 *
 * The plan is what makes the tenant usable: `PlanGate` reads its feature flags and
 * `QuotaGuard` reads its limits on every action, and both treat "no plan" as **no
 * features and no allowance**. So this step either associates a plan or stops
 * provisioning — see `SeedPlanUnavailableException` for why a planless tenant is worse
 * than a failed signup.
 *
 * ## Which plan
 *
 * The spec's `plan` when it names one (an admin creating a tenant on `growth`, a
 * migration putting an account back on the plan it paid for), otherwise
 * `wa.tenancy.default_plan_slug`. Either way it is resolved **by slug through
 * `PlanRepository`**, never taken as an object from the caller: a `Plan` instance in a
 * spec array could be unsaved, stale, or from another environment's fixture, and this
 * association is the one a tenant's whole entitlement hangs on.
 *
 * ## Inactive plans are allowed
 *
 * `active` is a *catalogue* flag — whether the plan is sellable on the pricing page —
 * not a validity flag. Grandfathered and internal plans are deliberately inactive and
 * are exactly what a migration or a support-created tenant needs to be put on, so this
 * step does not second-guess a slug that resolves. It records `active` in the audit
 * payload instead, so the unusual case is visible.
 */
final class AssignSeedPlanStep implements TenantProvisioningStep
{
    public function __construct(private readonly PlanRepository $plans) {}

    public function name(): string
    {
        return 'plan.seed';
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $requested = $context->spec->planSlug;
        $plan = $requested === null ? $this->plans->defaultPlan() : $this->plans->findBySlug($requested);

        if ($plan === null) {
            throw $requested === null
                ? SeedPlanUnavailableException::noDefaultPlan($this->configuredDefaultSlug())
                : SeedPlanUnavailableException::unknownSlug($requested);
        }

        $tenant = $context->tenant();
        $tenant->plan_id = $plan->getKey();
        $tenant->save();

        // Slug rather than id: the id is a ULID whose digit runs the audit redactor would
        // mask, and the tenant row already carries `plan_id` anyway.
        $context->record('plan', [
            'slug' => $plan->slug,
            'requested' => $requested !== null,
            'active' => (bool) $plan->active,
        ]);
    }

    /**
     * Nothing to compensate: the association is an `UPDATE` inside `provision()`'s
     * transaction.
     */
    public function rollback(TenantProvisioningContext $context): void {}

    private function configuredDefaultSlug(): string
    {
        $slug = config('wa.tenancy.default_plan_slug', 'starter');

        return is_string($slug) && trim($slug) !== '' ? trim($slug) : 'starter';
    }
}
