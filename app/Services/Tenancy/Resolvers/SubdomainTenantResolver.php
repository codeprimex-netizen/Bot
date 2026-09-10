<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Resolvers;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;
use App\Services\Domains\PlatformHosts;
use App\Services\Tenancy\TenantResolution;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Door 3 — the tenant subdomain `{slug}.{apex}` (Req 9.3 / A9).
 *
 * The request host is **never trusted as data**. It is only ever used as a
 * lookup key, and only after three checks:
 *
 * 1. the host must sit exactly one label below a *configured* apex
 *    (`wa.tenancy.apexes`, defaulting to the host of `config('app.url')`) — an
 *    arbitrary `Host:` header therefore matches nothing;
 * 2. that label must be a syntactically valid DNS label and must not be a
 *    reserved platform name (`www`, `admin`, `api`, ...);
 * 3. the label must match a row in `tenants`. No row, no tenant — never a
 *    fallback to "some" tenant.
 *
 * All three now live in `App\Services\Domains\PlatformHosts`, which is the single
 * reader of the apex and reserved-label config. This class parsed that config itself
 * until task 5.5, and `DomainRegistrar` needed exactly the same answers to decide
 * whether a custom-domain claim trespasses on the platform's host space. A divergence
 * between the two would be a host whose owner two parts of the platform disagree
 * about, so there is one implementation rather than two that are expected to match.
 *
 * Nothing here is used to *generate* URLs: Req 9.1 forbids deriving a URL host
 * from the request, and absolute/webhook/signed URLs come from `BaseUrl`
 * instead (tasks 5.1–5.3).
 *
 * ## Its neighbour in the chain
 *
 * `CustomDomainTenantResolver` (task 5.5) sits **ahead** of this one and matches an
 * exact, *verified* row in `tenant_domains`. The two host spaces are disjoint by
 * construction — `DomainRegistrar` refuses to claim anything under an apex, which is
 * precisely the space this resolver owns — so the order between them is a statement of
 * specificity rather than a tie-break that fires.
 *
 * The remaining A9 work is task 5.4's: the accepted-host allowlist (platform apex +
 * verified tenant subdomains + verified custom domains) rejecting unlisted hosts at
 * `TrustHosts`, before resolution runs at all.
 */
final class SubdomainTenantResolver implements TenantResolver
{
    public function __construct(private readonly PlatformHosts $hosts) {}

    public function resolve(Request $request): ?TenantResolution
    {
        $label = $this->hosts->tenantLabel(Str::lower($request->getHost()));

        if ($label === null) {
            return null;
        }

        $tenant = $this->tenantForLabel($label);

        return $tenant instanceof Tenant
            ? new TenantResolution($tenant, TenantResolutionSource::Subdomain)
            : null;
    }

    /**
     * A tenant claims a host either through its explicit `subdomain`, or — for
     * tenants that never customised it — through its `slug` (Req 9.3 spells the
     * host as `{slug}.{apex}`). The `subdomain IS NULL` guard keeps the two
     * lookups disjoint, so one tenant's slug can never shadow another tenant's
     * subdomain.
     */
    private function tenantForLabel(string $label): ?Tenant
    {
        return Tenant::query()->where('subdomain', $label)->first()
            ?? Tenant::query()->where('slug', $label)->whereNull('subdomain')->first();
    }
}
