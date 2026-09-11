<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;

/**
 * The outcome of a successful tenant resolution: which tenant, which door
 * it came through, and — for the API-key door — the credential that was verified.
 *
 * Resolution is *identification only* — it never authorizes. Whether the
 * resolved tenant may actually be used (membership, role, `isOperational()`,
 * plan/quota) is decided by later middleware and gates. `$token` is part of the
 * identification, not a decision: it says *which key* spoke, so the gate that comes
 * later can ask what that key was issued for without verifying it a second time.
 */
final readonly class TenantResolution
{
    /**
     * @param  ApiTokenIdentity|null  $token  the verified API credential, when the tenant
     *                                        came through the API-key door; null for the
     *                                        session and subdomain doors
     */
    public function __construct(
        public Tenant $tenant,
        public TenantResolutionSource $source,
        public ?ApiTokenIdentity $token = null,
    ) {}
}
