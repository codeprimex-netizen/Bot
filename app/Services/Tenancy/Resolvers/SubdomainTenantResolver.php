<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Resolvers;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;
use App\Services\Tenancy\TenantResolution;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Door 2 — the tenant subdomain `{slug}.{apex}` (Req 9.3 / A9).
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
 * Nothing here is used to *generate* URLs: Req 9.1 forbids deriving a URL host
 * from the request, and absolute/webhook/signed URLs come from `BaseUrl`
 * instead (tasks 5.1–5.7).
 *
 * **Seam for tasks 5.1–5.7.** The full A9 subsystem replaces the config-driven
 * apex list with the verified-host allowlist (platform apex + verified tenant
 * subdomains + verified custom domains) and rejects unlisted hosts outright at
 * `TrustHosts`. Verified per-tenant custom domains arrive as a sibling
 * resolver added to `config('wa.tenancy.resolvers')` ahead of this one; this
 * class does not need to change.
 */
final class SubdomainTenantResolver implements TenantResolver
{
    /**
     * A single DNS label: alphanumeric, inner hyphens allowed, max 63 chars.
     */
    private const string LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function resolve(Request $request): ?TenantResolution
    {
        $label = $this->tenantLabel(Str::lower($request->getHost()));

        if ($label === null) {
            return null;
        }

        $tenant = $this->tenantForLabel($label);

        return $tenant instanceof Tenant
            ? new TenantResolution($tenant, TenantResolutionSource::Subdomain)
            : null;
    }

    /**
     * The one label a host sits below a known apex, or null if it sits below
     * none of them (including the apex itself, and any deeper nesting).
     */
    private function tenantLabel(string $host): ?string
    {
        foreach ($this->apexes() as $apex) {
            if ($host === $apex || ! str_ends_with($host, '.'.$apex)) {
                continue;
            }

            $label = substr($host, 0, -(strlen($apex) + 1));

            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                continue;
            }

            return in_array($label, $this->reservedLabels(), true) ? null : $label;
        }

        return null;
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

    /**
     * Hosts tenant subdomains may hang off. Empty config falls back to the
     * canonical `APP_URL` host, which is the deployment's own apex.
     *
     * @return list<string>
     */
    private function apexes(): array
    {
        $configured = config('wa.tenancy.apexes');
        $apexes = [];

        foreach (is_array($configured) ? $configured : [] as $apex) {
            if (is_string($apex) && trim($apex) !== '') {
                $apexes[] = Str::lower(trim($apex));
            }
        }

        if ($apexes === []) {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $apexes[] = Str::lower($host);
            }
        }

        return array_values(array_unique($apexes));
    }

    /**
     * Platform-owned labels that can never belong to a tenant.
     *
     * @return list<string>
     */
    private function reservedLabels(): array
    {
        $configured = config('wa.tenancy.reserved_subdomains');
        $reserved = [];

        foreach (is_array($configured) ? $configured : [] as $label) {
            if (is_string($label) && trim($label) !== '') {
                $reserved[] = Str::lower(trim($label));
            }
        }

        return array_values(array_unique($reserved));
    }
}
