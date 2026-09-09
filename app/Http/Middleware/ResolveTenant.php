<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantResolution;
use App\Services\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the request's tenant into `TenantContext` before anything reads data.
 *
 * Runs the configured resolver chain (panel session → subdomain → API key) and
 * binds the first match. No match leaves the context empty rather than guessing
 * — the request then simply has no tenant, and the routes that require one
 * refuse it.
 *
 * Registered as the `resolve.tenant` alias and appended to the `web` group (so
 * it sits after `StartSession` and can see the panel session). Panel and API
 * routes stack the later gates on top: `tenant.member` for membership/role
 * (task 30.1 for the platform-admin side), `plan.feature:{key}` for plan gating
 * (task 2.2).
 */
final readonly class ResolveTenant
{
    public function __construct(
        private TenantContext $context,
        private TenantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Start from empty: the context is a container singleton, and on a
        // long-lived worker (Octane) a previous request's tenant must not
        // survive into a request that resolves to none.
        $this->context->forget();

        $resolution = $this->resolver->resolve($request);

        if ($resolution instanceof TenantResolution) {
            $this->context->set($resolution->tenant, $resolution->source);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * Release the tenant once the response has been sent, closing any platform
     * frame the request left open (which emits its audit exit event).
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->forget();
    }
}
