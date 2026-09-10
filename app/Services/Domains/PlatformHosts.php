<?php

declare(strict_types=1);

namespace App\Services\Domains;

use Illuminate\Support\Str;

/**
 * The deployment's own host space: which apexes it serves, which labels under them
 * belong to the platform, and how a tenant subdomain is spelled (Req 9.3, 9.7 / A9).
 *
 * One reader of `wa.tenancy.apexes` and `wa.tenancy.reserved_subdomains`, shared by the
 * two places that must agree about them:
 *
 *  - `App\Services\Tenancy\Resolvers\SubdomainTenantResolver` — *is this request host a
 *    tenant subdomain, and whose?*
 *  - `App\Services\Domains\DomainRegistrar` — *may a tenant claim this host as a custom
 *    domain?*
 *
 * They previously each parsed the config themselves, and a divergence between them is a
 * security bug in a specific direction: a label the registrar thinks is claimable but
 * the resolver treats as platform-owned (or vice versa) is a host whose owner two parts
 * of the platform disagree about. One implementation makes that impossible rather than
 * unlikely.
 *
 * ## Nothing here reads the request
 *
 * Every method takes a host as an argument. This class has no `Request`, no
 * `$_SERVER`, and no way to obtain one — the caller decides where a host came from, and
 * a host that came from the request is only ever used as a **lookup key** (Property 27's
 * other half: generation never reads the host, resolution reads it but never trusts it).
 */
final class PlatformHosts
{
    /**
     * A single DNS label: alphanumeric, inner hyphens allowed, max 63 characters.
     *
     * The same pattern `SubdomainTenantResolver` and `TenantProvisioningSpec` use — a
     * label one of them accepts must be one the others can match.
     */
    public const string LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Hosts tenant subdomains hang off.
     *
     * Empty config falls back to the host of `APP_URL`, which is the deployment's own
     * apex — so a single-domain install needs no configuration to route subdomains, and
     * a multi-apex install lists them explicitly.
     *
     * Read on every call rather than memoised: `config()` is already an in-memory array,
     * and a memo would pin the list for the lifetime of a queue worker, so a test (or an
     * operator using `config:clear`) changing an apex would not be seen.
     *
     * @return list<string>
     */
    public function apexes(): array
    {
        $apexes = $this->normalisedList(config('wa.tenancy.apexes'));

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
    public function reservedLabels(): array
    {
        return $this->normalisedList(config('wa.tenancy.reserved_subdomains'));
    }

    /**
     * Whether $host *is* one of the apexes (not something under one).
     */
    public function isApex(string $host): bool
    {
        return in_array($this->normalise($host), $this->apexes(), true);
    }

    /**
     * The apex $host sits under, or null when it sits under none.
     *
     * Any depth, unlike `tenantLabel()`: used to refuse a claim anywhere inside the
     * platform's own host space, including `deep.nested.app.example.com`.
     */
    public function apexFor(string $host): ?string
    {
        $host = $this->normalise($host);

        foreach ($this->apexes() as $apex) {
            if ($host !== $apex && str_ends_with($host, '.'.$apex)) {
                return $apex;
            }
        }

        return null;
    }

    /**
     * The one label $host sits above a known apex, or null.
     *
     * Null covers every host that is not a well-formed tenant subdomain: one below no
     * apex, an apex itself, a deeper nesting (`a.b.apex` — two labels, so no tenant), a
     * label that is not a valid DNS label, and a **reserved** label. The last is why
     * this returns null rather than the label: a reserved label is not a tenant's, so
     * treating it as one is exactly the confusion this method exists to prevent.
     */
    public function tenantLabel(string $host): ?string
    {
        $host = $this->normalise($host);

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
     * The reserved label $host uses under an apex, or null.
     *
     * Distinguished from `tenantLabel()` returning null so a refusal can *say* which
     * reserved name was hit — `admin.app.example.com` is refused as reserved, while
     * `not-a-label!.app.example.com` is refused as malformed, and the tenant's next
     * action differs.
     */
    public function reservedLabelIn(string $host): ?string
    {
        $host = $this->normalise($host);
        $reserved = $this->reservedLabels();

        foreach ($this->apexes() as $apex) {
            if ($host === $apex || ! str_ends_with($host, '.'.$apex)) {
                continue;
            }

            $label = substr($host, 0, -(strlen($apex) + 1));

            if (in_array($label, $reserved, true)) {
                return $label;
            }
        }

        return null;
    }

    private function normalise(string $host): string
    {
        return Str::lower(rtrim(trim($host), '.'));
    }

    /**
     * Lowercase, trimmed, non-empty strings from a config list.
     *
     * @return list<string>
     */
    private function normalisedList(mixed $configured): array
    {
        $values = [];

        foreach (is_array($configured) ? $configured : [] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = Str::lower(trim($value));
            }
        }

        return array_values(array_unique($values));
    }
}
