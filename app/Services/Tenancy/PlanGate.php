<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\PlanFeature;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Exceptions\Billing\MalformedPlanException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\PlanRepository;

/**
 * The one place that answers "does this tenant's plan include this feature?"
 * (design.md §Components and Interfaces → Tenancy layer; Req 11.3 / B2,
 * Req 22.2 / C5, Correctness Property 7).
 *
 * Three call shapes, for the three things callers actually need:
 *
 * ```php
 * $gate->allows($tenant, PlanFeature::Ai);        // bool — hide/disable a control (Req 22.2)
 * $gate->authorize($tenant, PlanFeature::Ai);     // throws FeatureNotInPlanException — API/action guard
 * $gate->denial($tenant, PlanFeature::Ai);        // the refusal, unthrown — build a 402/403 response or a banner
 * ```
 *
 * The boolean form exists because Req 22.2 says *hide or disable*, not *explode*: a
 * panel renders a disabled control with an explanation, and only the action behind it
 * throws. `denies()` + `isPurchasable()` is enough to choose between "Upgrade" and
 * "Unavailable" without catching anything.
 *
 * ## Where this is called
 *
 * - **Resolution pipeline** (task 11.3): `ConversationEngine` skips any stage whose
 *   `ResolverStage::requiresFeature()` is not allowed — Algorithm 1's `CONTINUE`
 *   branch. A skipped stage is not an error; the pipeline simply falls through to the
 *   next one (`LlmReplyStage` → `FallbackStage`).
 * - **Panels** (Phase C): feature-gated routes add the `plan.feature:{key}` middleware
 *   and components ask `allows()` when rendering.
 * - **API** (task 26.x): the same middleware on the tenant API routes.
 *
 * ## Fail-closed rules
 *
 * | Input                                     | Result                                              |
 * |-------------------------------------------|-----------------------------------------------------|
 * | plan grants the flag                      | allowed                                             |
 * | plan omits or disables the flag           | denied (`PlanFeatures` treats absence as `false`)   |
 * | tenant has **no plan at all**             | denied — never "skip the gate"                      |
 * | feature key not in the catalogue          | `InvalidArgumentException` — loud, not a silent no  |
 * | plan JSON malformed                       | `MalformedPlanException` propagates — a plan the platform cannot read grants nothing |
 *
 * ## Platform mode does **not** bypass plan gating
 *
 * Req 1.5's audited bypass is about **tenant data isolation** — `TenantScope`, so an
 * admin can see across tenants. It is not a licence to hand a tenant features it has
 * not bought. This gate therefore ignores `TenantContext::actingAsPlatform()`
 * entirely: it answers about the `Tenant` it is handed, so a platform admin
 * impersonating a tenant (`TenantContext::runFor()`) sees exactly that tenant's plan
 * limits. If it were otherwise, support would build flows and AI settings a tenant
 * cannot run, screenshots from an impersonated session would advertise features the
 * tenant never purchased, and Property 7's "∀ tenant" claim would hold only for
 * non-impersonated sessions — an invariant with a hole in it. Platform admins change
 * what a tenant may use by editing the *plan* (task 31.1), which is audited, visible
 * to the tenant, and billable.
 *
 * ## Cost
 *
 * Every inbound message walks the pipeline, so this runs several times per message. It
 * resolves the plan through `PlanRepository` (versioned cache, invalidated by
 * `PlanObserver` on every plan write) and adds **no cache of its own**: a second layer
 * could outlive a version bump and grant a feature an admin has just revoked.
 */
final readonly class PlanGate
{
    public function __construct(private PlanRepository $plans) {}

    /**
     * Whether $tenant's plan includes $feature.
     *
     * @throws \InvalidArgumentException on a feature key outside the catalogue
     * @throws MalformedPlanException when the tenant's plan JSON cannot be read
     */
    public function allows(Tenant $tenant, PlanFeature|string $feature): bool
    {
        $plan = $this->plans->forTenant($tenant);

        // No plan is *no features*, not "unknown, allow it": a tenant whose plan was
        // retired under it, or that has not been assigned one yet, has bought nothing.
        return $plan?->allows(PlanFeature::coerce($feature)->value) ?? false;
    }

    /**
     * The inverse of `allows()`, for readability at call sites that render a disabled
     * control.
     *
     * @throws \InvalidArgumentException
     * @throws MalformedPlanException
     */
    public function denies(Tenant $tenant, PlanFeature|string $feature): bool
    {
        return ! $this->allows($tenant, $feature);
    }

    /**
     * Refuse unless $tenant's plan includes $feature.
     *
     * @throws FeatureNotInPlanException 402 when a higher active plan sells the feature, 403 otherwise
     * @throws \InvalidArgumentException on a feature key outside the catalogue
     * @throws MalformedPlanException when the tenant's plan JSON cannot be read
     */
    public function authorize(Tenant $tenant, PlanFeature|string $feature): void
    {
        $denial = $this->denial($tenant, $feature);

        if ($denial !== null) {
            throw $denial;
        }
    }

    /**
     * The refusal for $feature as a value, or null when the tenant may use it.
     *
     * This is what `authorize()` throws; the `plan.feature` Gate ability and
     * middleware use it to build a response (status, public sentence, error code)
     * without a try/catch, so all three surfaces refuse identically.
     *
     * @throws \InvalidArgumentException
     * @throws MalformedPlanException
     */
    public function denial(Tenant $tenant, PlanFeature|string $feature): ?FeatureNotInPlanException
    {
        $feature = PlanFeature::coerce($feature);
        $plan = $this->plans->forTenant($tenant);

        if ($plan !== null && $plan->allows($feature->value)) {
            return null;
        }

        $tenantId = (string) $tenant->getKey();
        $planSlug = $this->slugOf($plan);
        $upgrades = $this->upgradePlans($feature);

        return $upgrades === []
            ? FeatureNotInPlanException::notAvailable($feature, $tenantId, $planSlug)
            : FeatureNotInPlanException::upgradeRequired($feature, $tenantId, $upgrades, $planSlug);
    }

    /**
     * Every catalogue feature $tenant's plan grants — what a panel needs in one call
     * to decide which navigation entries and controls to render (Req 22.2 / C5).
     *
     * @return list<PlanFeature>
     *
     * @throws MalformedPlanException
     */
    public function granted(Tenant $tenant): array
    {
        $plan = $this->plans->forTenant($tenant);

        if ($plan === null) {
            return [];
        }

        $granted = [];

        foreach (PlanFeature::cases() as $feature) {
            if ($plan->allows($feature->value)) {
                $granted[] = $feature;
            }
        }

        return $granted;
    }

    /**
     * Whether $feature can be obtained by upgrading — i.e. some **active** plan grants
     * it. Drives both the 402-vs-403 split and the panel's "Upgrade" vs "Unavailable"
     * copy.
     *
     * @throws \InvalidArgumentException
     */
    public function isPurchasable(PlanFeature|string $feature): bool
    {
        return $this->upgradePlans($feature) !== [];
    }

    /**
     * Slugs of the active plans that grant $feature, in catalogue order — the upgrade
     * CTA's targets. Public pricing information, so it is safe to surface.
     *
     * A plan whose own JSON is malformed is **skipped** rather than fatal here: it
     * grants nothing by definition, and one corrupt row in the catalogue must not turn
     * another tenant's legitimate 403 into a 500. The tenant's *own* plan is not
     * treated this leniently — `allows()` lets `MalformedPlanException` through, so the
     * corruption is still reported at the read that depends on it.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function upgradePlans(PlanFeature|string $feature): array
    {
        $feature = PlanFeature::coerce($feature);
        $slugs = [];

        foreach ($this->plans->active() as $plan) {
            try {
                $allowed = $plan->allows($feature->value);
            } catch (MalformedPlanException) {
                continue;
            }

            $slug = $this->slugOf($plan);

            if ($allowed && $slug !== null) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    private function slugOf(?Plan $plan): ?string
    {
        $slug = $plan?->getAttribute('slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
