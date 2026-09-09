<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;

/**
 * The outcome of a successful tenant resolution: which tenant, and which door
 * it came through.
 *
 * Resolution is *identification only* — it never authorizes. Whether the
 * resolved tenant may actually be used (membership, role, `isOperational()`,
 * plan/quota) is decided by later middleware and gates.
 */
final readonly class TenantResolution
{
    public function __construct(
        public Tenant $tenant,
        public TenantResolutionSource $source,
    ) {}
}
