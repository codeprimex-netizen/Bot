<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Services\Url\BaseUrl;
use App\Support\Url\CanonicalBase;
use Illuminate\Support\Str;

/**
 * The deployment's own host space: which apexes it serves, which labels under them
 * belong to the platform, and how a tenant subdomain is spelled (Req 9.3, 9.7 / A9).
 *
 * One reader of `wa.tenancy.apexes` and `wa.tenancy.reserved_subdomains`, shared by the
 * three places that must agree about them:
 *
 *  - `App\Services\Tenancy\Resolvers\SubdomainTenantResolver` — *is this request host a
 *    tenant subdomain, and whose?*
 *  - `App\Services\Domains\DomainRegistrar` — *may a tenant claim this host as a custom
 *    domain?*
 *  - `App\Services\Domains\HostAllowlist` — *may a request on this host be served at
 *    all?* (task 5.4, Req 9.4)
 *
 * The first two previously each parsed the config themselves, and a divergence between
 * them is a security bug in a specific direction: a label the registrar thinks is
 * claimable but the resolver treats as platform-owned (or vice versa) is a host whose
 * owner two parts of the platform disagree about. One implementation makes that
 * impossible rather than unlikely.
 *
 * ## The apex is derived from the *canonical* base, not from `APP_URL` alone
 *
 * `apexes()` falls back to the host of the **resolved canonical platform base** — which
 * is `platform_settings['base_url']` when an operator set one, and `config('app.url')`
 * otherwise (Req 9.3's precedence) — because that is the value
 * `UrlBuilder::tenantSubdomain()` hangs the label off when no apex is configured.
 *
 * Reading `config('app.url')` directly here (which this class did until task 5.4) made
 * the two halves disagree in exactly one configuration, and it was a configuration a
 * deployment reaches by using the feature as designed: an admin sets the base-URL
 * override, `WA_TENANT_APEXES` is empty, and the platform then **emits**
 * `acme.panel.example` while resolution (and, worse, the accepted-host allowlist built on
 * it) only ever recognised `acme.bot.example`. Every tenant subdomain link the platform
 * handed out pointed at a host it would refuse. Emission and acceptance now read one
 * source, so a host the platform emits is a host it accepts and resolves.
 *
 * ## Nothing here reads the request
 *
 * Every method takes a host as an argument. This class has no `Request`, no
 * `$_SERVER`, and no way to obtain one — the caller decides where a host came from, and
 * a host that came from the request is only ever used as a **lookup key** (Property 27's
 * other half: generation never reads the host, resolution reads it but never trusts it).
 * `BaseUrl` is the same shape: it resolves a *configured* origin and has no request input
 * either, so depending on it introduces no path from a header to a host decision.
 */
final class PlatformHosts
{
    /**
     * Named in `InvalidBaseUrlException` messages raised while normalising a configured
     * apex.
     */
    public const string APEX_SOURCE = 'wa.tenancy.apexes';

    /**
     * Named in messages when the canonical platform base cannot be parsed back.
     */
    public const string PLATFORM_SOURCE = 'the resolved canonical base';

    public function __construct(private readonly BaseUrl $baseUrl) {}

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
     * Empty config falls back to `platformHost()` — the host of the canonical base, which
     * is the deployment's own apex — so a single-domain install needs no configuration to
     * route subdomains, and a multi-apex install lists them explicitly.
     *
     * Each configured entry is normalised through `CanonicalBase::host()`, the same
     * normaliser `UrlBuilder::tenantSubdomain()` runs them through, so `Example.COM.` and
     * `example.com` cannot be one apex on the emit side and another here. An entry that is
     * not a usable hostname is **skipped**, which fails closed — hosts under it match
     * nothing and are therefore not accepted — while `CanonicalUrlBuilder::apex()` refuses
     * the same entry loudly at the moment a URL would be built from it. That is the pair
     * on purpose: the side that emits shouts, the side that admits stays shut.
     *
     * Read on every call rather than memoised: `config()` is already an in-memory array,
     * the canonical base is cached in `BaseUrlCache`, and a memo would pin the list for
     * the lifetime of a queue worker — so an operator changing the override (or an apex)
     * would not be seen.
     *
     * @return list<string>
     */
    public function apexes(): array
    {
        $apexes = $this->configuredApexes();

        if ($apexes === []) {
            $host = $this->platformHost();

            if ($host !== null) {
                $apexes[] = $host;
            }
        }

        return array_values(array_unique($apexes));
    }

    /**
     * The host of the canonical platform base — the origin this deployment actually emits
     * links on (Req 9.3's precedence: `platform_settings['base_url']` → `config('app.url')`).
     *
     * Not an apex by itself: with `wa.tenancy.apexes` configured, tenant subdomains hang
     * off those and this is simply the platform's own host. `HostAllowlist` therefore
     * accepts it exactly, never a label under it.
     *
     * Returns null only when no usable host can be determined at all. A base that cannot
     * be parsed is *not* fatal here: `ConfiguredBaseUrl` already refuses it wherever a URL
     * is emitted, and turning a misconfigured `APP_URL` into a 500 on every inbound
     * request — including the health check and the domain-ownership challenge — would take
     * a deployment down twice for one mistake. The compiled-in default is used as the last
     * word instead.
     */
    public function platformHost(): ?string
    {
        try {
            return CanonicalBase::parse($this->baseUrl->platform(), self::PLATFORM_SOURCE)->host;
        } catch (InvalidBaseUrlException) {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return is_string($host) && $host !== '' ? Str::lower(rtrim($host, '.')) : null;
        }
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
     * `wa.tenancy.apexes`, canonicalised, malformed entries dropped.
     *
     * @return list<string>
     */
    private function configuredApexes(): array
    {
        $apexes = [];

        foreach ($this->normalisedList(config('wa.tenancy.apexes')) as $value) {
            try {
                $apexes[] = CanonicalBase::host($value, self::APEX_SOURCE);
            } catch (InvalidBaseUrlException) {
                continue;
            }
        }

        return array_values(array_unique($apexes));
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
