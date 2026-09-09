<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;

/**
 * Maps a presented API token to the single tenant that owns it.
 *
 * Extracted as a contract so the token store is swappable: the default
 * implementation reads the platform's own `tenant_api_tokens` table, and when
 * the public API adopts Sanctum (`/api/v1`, design §Architecture) a
 * Sanctum-backed implementation is bound here instead — no resolver, middleware,
 * or context change.
 *
 * Implementations MUST return `null` for anything they cannot positively verify
 * (malformed, unknown, revoked, or expired), never a best guess.
 */
interface TenantTokenRepository
{
    public function tenantForToken(string $plainTextToken): ?Tenant;
}
