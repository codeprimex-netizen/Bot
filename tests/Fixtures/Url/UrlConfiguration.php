<?php

declare(strict_types=1);

namespace Tests\Fixtures\Url;

/**
 * One cell of the configuration matrix Correctness Property 27 has to hold across
 * (Req 9.1, 9.3, 9.4 / A9).
 *
 * ## Why the configuration is a generated dimension and not a fixed `beforeEach`
 *
 * Property 27's first clause — "every emitted URL carries the *configured* canonical
 * host" — is only interesting if "the configured host" can be several different things.
 * There are five independent inputs that each move it, and the platform resolves them
 * through three collaborators that must agree (`ConfiguredBaseUrl`, `PlatformHosts`,
 * `CanonicalUrlBuilder::apex()`):
 *
 *  1. **`APP_URL` vs the `platform_settings['base_url']` override** — Req 9.3's
 *     precedence. This is the axis the task-5.4 defect lived on: `PlatformHosts` derived
 *     the host space from `APP_URL` while the builder hung tenant labels off the
 *     *resolved* base, so setting the override made every emitted subdomain link point at
 *     a host the platform refused. One cell of a matrix would have caught it; a scripted
 *     `beforeEach` with one `APP_URL` never could.
 *  2. **apexes configured (`wa.tenancy.apexes`) vs derived** — a multi-domain install
 *     lists them, a single-domain install lets them fall back to the platform host, and
 *     the two produce *different* subdomain hosts from the same tenant.
 *  3. **a tenant with a verified custom domain vs one without** — the tenant base is
 *     either its own origin or the platform's.
 *  4. **a path-prefixed base (`/app`) vs root** — the mount point is inside the signed
 *     path, so it changes what a signature covers as well as what a link reads as.
 *  5. **a non-default port vs the scheme default** — the port is part of the *authority*,
 *     which is the field a signature binds.
 *
 * All 2^5 combinations are enumerated (`matrix()`) rather than sampled: 32 is small
 * enough to cover exhaustively, and a sampled cell would leave the interaction that
 * actually broke — override × derived apexes — unexercised on most runs.
 *
 * ## The expectations are derived here, from the inputs
 *
 * Every accessor below computes what the platform *must* emit, from the drawn inputs
 * alone and without calling any production class. That is what stops the property from
 * being vacuous: `platformBase()` is not "whatever `BaseUrl` said", so a builder that
 * returned a constant — or one that read the request host — fails the equality rather
 * than agreeing with itself.
 *
 * The scheme is `https` in every cell. It is not a matrix dimension because it is not an
 * input to the host: `wa.url.force_https` is covered by `BaseUrlTest`, and adding it here
 * would double the cell count to re-assert someone else's claim.
 *
 * @immutable
 */
final readonly class UrlConfiguration
{
    /**
     * The host `APP_URL` names in every cell.
     */
    public const string APP_HOST = 'bot.example.test';

    /**
     * The host the `platform_settings['base_url']` override names — deliberately a
     * *different* host from `APP_URL`, so a cell that uses the override and one that does
     * not cannot accidentally expect the same string.
     */
    public const string OVERRIDE_HOST = 'panel.example.test';

    /**
     * The apex tenant subdomains hang off when `wa.tenancy.apexes` is configured. Not a
     * parent, child or suffix of either host above: an apex that overlapped one of them
     * would make "the label hung off the apex" and "the platform host" the same string in
     * some cells, and the assertions would stop distinguishing them.
     */
    public const string APEX = 'app.example.test';

    /**
     * An operator-listed internal host (`wa.url.hosts.additional`) — the fourth thing
     * Req 9.4 admits, and the one an allowlist bug is most likely to widen.
     */
    public const string ADDITIONAL_HOST = 'internal-lb.svc.cluster.local';

    /**
     * A non-default HTTPS port, so the authority is `host:port` rather than `host`.
     */
    public const int PORT = 8443;

    /**
     * The mount point of a deployment served under a path prefix.
     */
    public const string MOUNT = '/app';

    public function __construct(
        public bool $override,
        public bool $configuredApexes,
        public bool $customDomain,
        public bool $mounted,
        public bool $explicitPort,
    ) {}

    /**
     * Every cell of the 2^5 matrix, in a fixed order (the caller shuffles).
     *
     * @return non-empty-list<self>
     */
    public static function matrix(): array
    {
        $cells = [];

        foreach ([false, true] as $override) {
            foreach ([false, true] as $apexes) {
                foreach ([false, true] as $domain) {
                    foreach ([false, true] as $mounted) {
                        foreach ([false, true] as $port) {
                            $cells[] = new self($override, $apexes, $domain, $mounted, $port);
                        }
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * The value to write into `config('app.url')`.
     */
    public function appUrl(): string
    {
        return $this->base(self::APP_HOST);
    }

    /**
     * The value to write into `platform_settings['base_url']`, or null for a cell that
     * leaves the platform on `APP_URL`.
     */
    public function overrideUrl(): ?string
    {
        return $this->override ? $this->base(self::OVERRIDE_HOST) : null;
    }

    /**
     * The value to write into `wa.tenancy.apexes`.
     *
     * @return list<string>
     */
    public function apexes(): array
    {
        return $this->configuredApexes ? [self::APEX] : [];
    }

    /**
     * The host of the resolved canonical platform base — Req 9.3's precedence, applied by
     * hand.
     */
    public function platformHost(): string
    {
        return $this->override ? self::OVERRIDE_HOST : self::APP_HOST;
    }

    /**
     * The apex a tenant subdomain hangs off.
     *
     * `CanonicalUrlBuilder::apex()` uses the platform host when no apex is configured, and
     * otherwise the first configured apex that is not the platform host itself. `APEX` is
     * never equal to either configured host, so the rule reduces to this.
     */
    public function apex(): string
    {
        return $this->configuredApexes ? self::APEX : $this->platformHost();
    }

    /**
     * Every host the deployment accepts by *exact* match: its own canonical host, the
     * operator's additions, and whichever verified custom domains exist.
     *
     * @param  list<string>  $verifiedHosts
     * @return list<string>
     */
    public function exactHosts(array $verifiedHosts): array
    {
        return array_values(array_unique([
            $this->platformHost(),
            self::ADDITIONAL_HOST,
            ...$verifiedHosts,
        ]));
    }

    /**
     * `scheme://host[:port]` — the authority a signature binds, with its scheme.
     */
    public function origin(string $host): string
    {
        return 'https://'.$host.($this->explicitPort ? ':'.self::PORT : '');
    }

    /**
     * `scheme://host[:port][/mount]` — a canonical base string.
     */
    public function base(string $host): string
    {
        return $this->origin($host).$this->mount();
    }

    public function platformBase(): string
    {
        return $this->base($this->platformHost());
    }

    /**
     * The base for a tenant: its verified custom domain when it has one, else the
     * platform's (Req 9.3, 9.7 — resolution falls *down*, never up to an unverified
     * claim).
     */
    public function tenantBase(?string $customHost): string
    {
        return $customHost === null ? $this->platformBase() : $this->base($customHost);
    }

    /**
     * `{label}.{apex}` with the platform base's scheme, port and mount — never the
     * tenant's own domain, which is not what a subdomain hangs off.
     */
    public function subdomainBase(string $label): string
    {
        return $this->base($label.'.'.$this->apex());
    }

    public function mount(): string
    {
        return $this->mounted ? self::MOUNT : '';
    }

    /**
     * The cell, for a failure message.
     */
    public function describe(): string
    {
        return sprintf(
            'base from %s, apexes %s, tenant %s a verified custom domain, %s, %s',
            $this->override ? "platform_settings['base_url']" : 'APP_URL',
            $this->configuredApexes ? 'configured ['.self::APEX.']' : 'derived from the base',
            $this->customDomain ? 'with' : 'without',
            $this->mounted ? 'mounted under '.self::MOUNT : 'root-mounted',
            $this->explicitPort ? 'port '.self::PORT : 'default port',
        );
    }
}
