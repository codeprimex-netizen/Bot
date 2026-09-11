<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Http\Request;

/**
 * Identifies the tenant an incoming request belongs to.
 *
 * One implementation per "door" (panel session, subdomain, API key), composed in
 * precedence order by `ChainTenantResolver` and driven by the `ResolveTenant`
 * middleware. Adding a door — the verified per-tenant custom domain of Req 9.3
 * / A9, landing in tasks 5.1–5.7 — means writing another implementation and
 * listing it in `config('wa.tenancy.resolvers')`; no existing resolver changes.
 *
 * Contract for every implementation:
 *
 * - return `null` rather than guessing: an unknown host or an invalid key must
 *   never fall through to "some" tenant;
 * - identify only. Membership, role, tenant status, plan, and quota are checked
 *   by later middleware and gates, so a suspended tenant still resolves (and is
 *   then blocked with a meaningful error instead of a 404).
 */
interface TenantResolver
{
    public function resolve(Request $request): ?TenantResolution;
}
