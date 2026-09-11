<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ApiTokenIdentity;
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
 * routes stack the later gates on top: `tenant.permission:{key}` for RBAC and API-key
 * scope (task 4.6), `plan.feature:{key}` for plan gating (task 2.2), and the
 * platform-admin guard for the admin side (task 30.1).
 *
 * When the tenant came through the API-key door, the verified credential is published on
 * the request under `ApiTokenIdentity::REQUEST_ATTRIBUTE` so `EnsurePermission` can read
 * the key's scopes without verifying the key a second time.
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

        // Likewise for the credential: a previous request's API key must not still be
        // on a re-used request object, and a request that resolves no token must be
        // indistinguishable from one that never carried one.
        $request->attributes->remove(ApiTokenIdentity::REQUEST_ATTRIBUTE);

        $resolution = $this->resolver->resolve($request);

        if ($resolution instanceof TenantResolution) {
            $this->context->set($resolution->tenant, $resolution->source);

            // The verified credential, for `EnsurePermission` (task 4.6). Published here
            // rather than fetched there so the key is verified exactly once per request.
            if ($resolution->token !== null) {
                $request->attributes->set(ApiTokenIdentity::REQUEST_ATTRIBUTE, $resolution->token);
            }
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
