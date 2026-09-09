<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

/**
 * Verifies a presented API token and reports **who it is and what it may do**.
 *
 * Extracted as a contract so the token store is swappable: the default
 * implementation reads the platform's own `tenant_api_tokens` table, and when
 * the public API adopts Sanctum (`/api/v1`, design §Architecture) a
 * Sanctum-backed implementation is bound here instead — no resolver, middleware,
 * or context change.
 *
 * Implementations MUST return `null` for anything they cannot positively verify
 * (malformed, unknown, revoked, or expired), never a best guess.
 *
 * Task 4.6 widened the return value from a bare `Tenant` to `ApiTokenIdentity`, so the
 * credential's scopes travel with the credential that was verified. The alternative —
 * resolving the tenant here and re-reading the scopes in the authorization middleware —
 * verifies the same credential in two places, and two verifiers eventually disagree.
 * An implementation therefore also has to report the scopes it stored; returning an
 * empty scope list is legal and means the key may do nothing.
 */
interface TenantTokenRepository
{
    public function resolve(string $plainTextToken): ?ApiTokenIdentity;
}
