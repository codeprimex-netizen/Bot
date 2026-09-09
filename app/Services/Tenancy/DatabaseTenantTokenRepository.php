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
 */
final class DatabaseTenantTokenRepository implements TenantTokenRepository
{
    public function tenantForToken(string $plainTextToken): ?Tenant
    {
        [$id, $secret] = TenantApiToken::splitPlainText($plainTextToken);

        if ($secret === '') {
            return null;
        }

        $query = TenantApiToken::query()->where('token', TenantApiToken::hashSecret($secret));

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

        return $tenant;
    }
}
