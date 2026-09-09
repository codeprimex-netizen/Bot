<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PlanFeature;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires plan feature gating: the `PlanGate` service and the `plan.feature` ability
 * (Req 11.3 / B2, Req 22.2 / C5).
 *
 * A provider of its own rather than another block in `TenancyServiceProvider`, because
 * plan gating is a self-contained concern with its own two collaborators
 * (`PlanRepository`, `TenantContext`) and nothing in the tenancy wiring depends on it.
 */
class PlanGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless — it holds no per-tenant state and caches nothing of its own (the
        // plan cache lives in `PlanRepository`), so one instance serves every caller.
        $this->app->singleton(PlanGate::class);
    }

    public function boot(): void
    {
        $this->registerFeatureAbility();
    }

    /**
     * Plan gating as an ability, so a panel component or Blade view asks the same
     * question the `plan.feature` middleware asks — and gets the same answer, status,
     * and sentence (Req 22.2 / C5: *hide or disable*, never crash).
     *
     * ```php
     * Gate::allows('plan.feature', PlanFeature::Ai);            // current tenant
     * Gate::allows('plan.feature', ['ai', $tenant]);            // an explicit one
     * Gate::authorize('plan.feature', PlanFeature::Campaigns);  // 402/403 on refusal
     * ```
     *
     * Defined with a **nullable** user for the same reason `tenant.mutate` is: a plan is
     * a property of the tenant, not of the person, so a console command, a queued job,
     * and a webhook get an answer too. Whether *this user* may act for the tenant is a
     * separate, additive check (`tenant_users` RBAC, task 30.4).
     *
     * The tenant argument is optional and defaults to the bound context. **No tenant
     * bound = denied**, including in `actingAsPlatform()` mode: platform mode bypasses
     * tenant *data* isolation (Req 1.5), never plan entitlements — see `PlanGate`. An
     * admin impersonating a tenant goes through `TenantContext::runFor()`, which binds
     * that tenant, so this ability then answers with the tenant's own plan.
     */
    private function registerFeatureAbility(): void
    {
        Gate::define('plan.feature', function (?User $user, PlanFeature|string $feature, ?Tenant $tenant = null): Response {
            $feature = PlanFeature::coerce($feature);
            $tenant ??= $this->app->make(TenantContext::class)->current();

            if ($tenant === null) {
                return $this->denial(FeatureNotInPlanException::withoutTenant($feature));
            }

            $denial = $this->app->make(PlanGate::class)->denial($tenant, $feature);

            return $denial === null ? Response::allow() : $this->denial($denial);
        });
    }

    /**
     * One denial shape: the public sentence, the 402/403 an unhandled
     * `Gate::authorize()` aborts with, and the machine-readable code an API client
     * matches on — all three taken from the exception the service layer raises, so a
     * refusal reads the same whether it came through the ability, the middleware, or
     * `PlanGate::authorize()`.
     */
    private function denial(FeatureNotInPlanException $exception): Response
    {
        return Response::denyWithStatus(
            $exception->getStatusCode(),
            $exception->publicMessage(),
            $exception->errorCode(),
        );
    }
}
