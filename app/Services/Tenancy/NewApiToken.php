<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\TenantApiToken;

/**
 * A freshly issued API key together with its plaintext.
 *
 * The plaintext (`{id}|{secret}`) is the only copy that will ever exist — the
 * database keeps a hash — so the caller must hand it to the tenant now.
 */
final readonly class NewApiToken
{
    public function __construct(
        public TenantApiToken $token,
        public string $plainText,
    ) {}
}
