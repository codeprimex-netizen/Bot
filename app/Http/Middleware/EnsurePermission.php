<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantPermission;
use App\Enums\TenantResolutionSource;
use App\Exceptions\Security\PermissionDeniedException;
use App\Models\User;
use App\Services\Rbac\RbacService;
use App\Services\Tenancy\ApiTokenIdentity;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level RBAC and API-key scope: `tenant.permission:{key}` (Req 32.1 / NFR3;
 * design.md § STRIDE rows "Panels (User/Admin)" and "Public API").
 *
 * ```php
 * Route::middleware(['auth', 'resolve.tenant', 'tenant.permission:campaigns.manage'])
 *     ->get('/app/campaigns', Campaigns::class);
 *
 * // Several keys = all of them are required:
 * ->middleware('tenant.permission:contacts.manage,messages.send')
 *
 * // The same declaration protects the public API; the caller there is a key, and the
 * // key's scopes are what is checked.
 * Route::middleware(['resolve.tenant', 'tenant.permission:messages.send'])
 *     ->post('/api/v1/messages', SendMessage::class);
 * ```
 *
 * This is the **enforcement point** for the RBAC row of the STRIDE table. `RbacService`
 * can be called directly from a component or a job, but a guard that every screen has to
 * remember to call is a guard that some screen will not; declaring it on the route is
 * what makes the check part of the route's definition, exactly as task 2.2 did for plan
 * features.
 *
 * ## Which identity is checked
 *
 * The door the tenant came through decides — `TenantContext::resolvedVia()`:
 *
 * | Source | Checked against |
 * |---|---|
 * | `api_token` | the verified key's scopes (`ApiTokenIdentity`, published by `ResolveTenant`) |
 * | anything else | the authenticated user's role in the bound tenant |
 *
 * An API-token request whose credential is missing from the request is **refused**, not
 * fallen back to the session user: falling back would let a request that presented a key
 * be authorized as whoever happened to be logged in.
 *
 * ## Failure modes, and which way each one fails
 *
 * - no tenant bound, no identity, wrong tenant, missing role, missing scope →
 *   `PermissionDeniedException` (403), one sentence, no oracle;
 * - a permission key that is not in `TenantPermission` → `InvalidArgumentException`
 *   (500). A typo'd gate is a developer mistake, and 500 is loud; denying instead would
 *   lock a working screen for every tenant and look exactly like a permissions bug. The
 *   whole list is validated *before* anything is consulted, so a typo fails identically
 *   on every request, including one that carries no tenant.
 *
 * It must sit **after** `resolve.tenant`, and — for panel routes — after `auth`.
 */
final readonly class EnsurePermission
{
    public function __construct(
        private TenantContext $context,
        private RbacService $rbac,
    ) {}

    /**
     * @throws PermissionDeniedException when the caller may not exercise a permission
     * @throws InvalidArgumentException when the route names no permission, or an unknown one
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $required = $this->required($permissions);

        foreach ($required as $permission) {
            $this->authorize($request, $permission);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * Refuse unless the request's identity holds `$permission` in the bound tenant.
     */
    private function authorize(Request $request, TenantPermission $permission): void
    {
        if ($this->context->resolvedVia() === TenantResolutionSource::ApiToken) {
            $this->rbac->authorizeToken($this->identity($request), $permission);

            return;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            throw PermissionDeniedException::withoutIdentity($permission);
        }

        $this->rbac->authorize($user, $permission);
    }

    /**
     * The verified API credential `ResolveTenant` published, or `null`.
     *
     * A value of any other type is treated as absent: an attribute is a shared bag, and
     * "something else is under this key" must deny rather than be interpreted.
     */
    private function identity(Request $request): ?ApiTokenIdentity
    {
        $identity = $request->attributes->get(ApiTokenIdentity::REQUEST_ATTRIBUTE);

        return $identity instanceof ApiTokenIdentity ? $identity : null;
    }

    /**
     * @param  list<string>  $permissions
     * @return list<TenantPermission>
     */
    private function required(array $permissions): array
    {
        $required = array_map(TenantPermission::coerce(...), $permissions);

        if ($required === []) {
            throw new InvalidArgumentException(
                'The tenant.permission middleware needs at least one permission key, e.g. '
                .'tenant.permission:campaigns.manage. Known permissions: '
                .implode(', ', TenantPermission::keys()).'.',
            );
        }

        return $required;
    }
}
