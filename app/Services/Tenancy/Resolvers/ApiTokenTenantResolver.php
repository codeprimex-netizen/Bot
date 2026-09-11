<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Resolvers;

use App\Enums\TenantResolutionSource;
use App\Services\Tenancy\TenantResolution;
use App\Services\Tenancy\TenantResolver;
use App\Services\Tenancy\TenantTokenRepository;
use Illuminate\Http\Request;

/**
 * Door 3 — a machine caller's API key (`/api/v1`).
 *
 * Accepts a Sanctum-style bearer token (`Authorization: Bearer {id}|{secret}`)
 * or the same value in the configured API-key header. The token→tenant lookup
 * itself lives behind `TenantTokenRepository`, so replacing the default store
 * with Sanctum's `personal_access_tokens` later is a container binding swap and
 * nothing more.
 *
 * A malformed, unknown, revoked, or expired token resolves to *no tenant* — the
 * request then continues unauthenticated and is refused by the API's auth
 * middleware, rather than silently acting inside somebody else's account.
 *
 * A successful resolution carries the verified credential (`ApiTokenIdentity`) on the
 * resolution, so `ResolveTenant` can publish it and `EnsurePermission` can check the
 * key's scopes without re-verifying it. Resolution itself still only *identifies*: a
 * key with no scopes resolves its tenant and is then refused by every scope gate.
 */
final readonly class ApiTokenTenantResolver implements TenantResolver
{
    public function __construct(private TenantTokenRepository $tokens) {}

    public function resolve(Request $request): ?TenantResolution
    {
        $plainTextToken = $this->presentedToken($request);

        if ($plainTextToken === null) {
            return null;
        }

        $identity = $this->tokens->resolve($plainTextToken);

        return $identity === null
            ? null
            : new TenantResolution($identity->tenant, TenantResolutionSource::ApiToken, $identity);
    }

    /**
     * The raw token presented on the request, if any.
     */
    private function presentedToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && trim($bearer) !== '') {
            return trim($bearer);
        }

        $header = config('wa.tenancy.api_key_header', 'X-Api-Key');
        $value = $request->header(is_string($header) && $header !== '' ? $header : 'X-Api-Key');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
