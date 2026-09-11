<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Resolvers;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;
use App\Services\Domains\VerifiedDomainDirectory;
use App\Services\Tenancy\TenantResolution;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;

/**
 * Door 2a — a tenant's **verified** custom domain (Req 9.3, 9.7 / A9).
 *
 * Sits ahead of `SubdomainTenantResolver` in `wa.tenancy.resolvers`, which is where
 * `config/wa.php` said it would go: *"Verified per-tenant custom domains (Req 9.3, tasks
 * 5.1–5.7) join this list as another resolver ahead of the subdomain one."* Ahead,
 * because a custom domain is the more specific claim — a host is either an exact,
 * verified row or it is not, whereas the subdomain door matches a *pattern* under an
 * apex. Ordering it after would make no practical difference today (the two host spaces
 * are disjoint: `DomainRegistrar` refuses to claim anything under an apex) and would be
 * the wrong default the moment they overlap.
 *
 * ## Unverified resolves nothing
 *
 * The lookup is against verified rows only (`VerifiedDomainDirectory` filters in SQL,
 * and `TenantDomain::scopeVerified()` is the filter). This is the requirement's teeth: if
 * an unverified claim resolved, a tenant could type in a host it does not own and start
 * receiving requests routed as that tenant — sessions, webhooks and panel traffic for a
 * name somebody else controls. So a host that is unknown, claimed-but-unverified, or
 * revoked all behave identically: no tenant, and the chain moves on.
 *
 * ## The host is a key, never an assertion
 *
 * `$request->getHost()` is attacker-controlled. It is used here as nothing but a lookup
 * key into rows a verification wrote, and a miss yields null rather than a guess. Two
 * further properties keep that honest:
 *
 *  - the tenant row must exist and is fetched by primary key, so a stale directory entry
 *    (a tenant deleted between the cache write and the read) resolves to nothing rather
 *    than to a dangling id;
 *  - nothing here is used to *generate* a URL. Req 9.1 forbids deriving a URL host from
 *    the request, and `BaseUrl` cannot even take one (Property 27). Generation never
 *    reads the host; resolution reads it but only as a key.
 *
 * ## Cost
 *
 * One cached lookup per request (`VerifiedDomainDirectory`, version-bump invalidated),
 * plus one primary-key fetch on a hit. A miss — every request to the platform apex —
 * costs the cached negative and no query at all.
 */
final readonly class CustomDomainTenantResolver implements TenantResolver
{
    public function __construct(private VerifiedDomainDirectory $directory) {}

    public function resolve(Request $request): ?TenantResolution
    {
        $tenantId = $this->directory->tenantIdFor($request->getHost());

        if ($tenantId === null) {
            return null;
        }

        $tenant = Tenant::query()->whereKey($tenantId)->first();

        return $tenant instanceof Tenant
            ? new TenantResolution($tenant, TenantResolutionSource::CustomDomain)
            : null;
    }
}
