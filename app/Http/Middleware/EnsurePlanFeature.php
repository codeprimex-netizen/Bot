<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PlanFeature;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level plan gating: `plan.feature:{key}` (Req 11.3 / B2, Req 22.2 / C5,
 * Correctness Property 7).
 *
 * ```php
 * Route::middleware(['auth', 'resolve.tenant', 'tenant.member', 'plan.feature:ai'])
 *     ->get('/app/chatbot/ai', AiSettings::class);
 *
 * // Several keys = all of them are required:
 * ->middleware('plan.feature:flows,integrations')
 * ```
 *
 * Registered as the `plan.feature` alias in `bootstrap/app.php`, so a panel or API
 * route *declares* its gate instead of hand-rolling an `if` in every component —
 * exactly how task 1.3 turned the suspension guards into the `tenant.mutate` ability.
 *
 * It must sit **after** `resolve.tenant`: the tenant comes from `TenantContext`, and an
 * unbound context is refused rather than guessed. Refusals are thrown as
 * `FeatureNotInPlanException`, so the status (402 when the feature is on a higher
 * active plan, 403 otherwise), the public sentence, and the error code are identical to
 * every other surface, and `bootstrap/app.php` renders the JSON envelope for API and
 * Livewire callers.
 *
 * A key outside `PlanFeature` is a **route misconfiguration** and raises
 * `InvalidArgumentException` (a 500) rather than denying: a typo'd gate key would
 * otherwise lock a working screen for every tenant with no way to notice.
 */
final readonly class EnsurePlanFeature
{
    public function __construct(
        private TenantContext $context,
        private PlanGate $gate,
    ) {}

    /**
     * @throws FeatureNotInPlanException when the resolved tenant's plan omits a feature
     * @throws InvalidArgumentException when the route names no feature, or an unknown one
     */
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        // Validate the whole list before consulting anything: a typo must fail the same
        // way on every request, including ones that carry no tenant.
        $required = $this->required($features);

        $tenant = $this->context->current();

        foreach ($required as $feature) {
            if ($tenant === null) {
                throw FeatureNotInPlanException::withoutTenant($feature);
            }

            $denial = $this->gate->denial($tenant, $feature);

            if ($denial !== null) {
                throw $denial;
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * @param  list<string>  $features
     * @return list<PlanFeature>
     */
    private function required(array $features): array
    {
        $required = array_map(PlanFeature::coerce(...), $features);

        if ($required === []) {
            throw new InvalidArgumentException(
                'The plan.feature middleware needs at least one feature key, e.g. plan.feature:ai. '
                .'Known keys: '.implode(', ', PlanFeature::keys()).'.',
            );
        }

        return $required;
    }
}
