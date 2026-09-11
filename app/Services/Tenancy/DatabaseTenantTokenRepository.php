<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantApiToken;

/**
 * Default token store: the platform's own `tenant_api_tokens` table.
 *
 * The secret is never compared in PHP — it is hashed and matched by the unique
 * index, so lookup time does not depend on how much of a guess was correct.
 *
 * This is the one place that reads `tenant_api_tokens` across tenants, and it has
 * to: it runs *before* a tenant is bound and its entire job is to work out which
 * tenant the caller is. It therefore states the bypass explicitly with
 * `withoutTenantScope()` (Req 1.2 / A1) — the lookup is still narrowed to a single
 * row by the token hash, and the tenant it returns is the one that owns that row.
 *
 * The row's `scopes` are read on the same pass and returned inside the identity, so the
 * key is verified exactly once (task 4.6). A key with no scopes still resolves its
 * tenant — resolution is identification, not authorization — and is then refused by
 * every scope gate.
 */
final class DatabaseTenantTokenRepository implements TenantTokenRepository
{
    public function resolve(string $plainTextToken): ?ApiTokenIdentity
    {
        [$id, $secret] = TenantApiToken::splitPlainText($plainTextToken);

        if ($secret === '') {
            return null;
        }

        $query = TenantApiToken::withoutTenantScope()->where('token', TenantApiToken::hashSecret($secret));

        if ($id !== null) {
            $query->whereKey($id);
        }

        $token = $query->first();

        if (! $token instanceof TenantApiToken || ! $token->isUsable()) {
            return null;
        }

        $tenant = $token->tenant;

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $token->markUsed();

        return ApiTokenIdentity::fromToken($token, $tenant);
    }
}
