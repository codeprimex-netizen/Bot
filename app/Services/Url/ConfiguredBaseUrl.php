<?php

declare(strict_types=1);

namespace App\Services\Url;

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Platform\PlatformSettings;
use App\Support\Url\CanonicalBase;

/**
 * The Req 9.3 precedence chain, resolved and cached (design § Base URL §U.2, §U.3;
 * Correctness Property 27).
 *
 * ```
 * per-tenant verified custom domain  →  platform_settings['base_url']  →  config('app.url')
 * ```
 *
 * First non-empty value wins, and each link is *more* operator-controlled than the one
 * below it: a tenant's own domain beats the deployment override, which beats the
 * compiled-in default. Nothing in the chain comes from the request.
 *
 * ## Why "never read the request host" is structural here
 *
 * This class has exactly two collaborators — a settings reader and a cache — and neither
 * can reach a request. There is no `Request` parameter to pass a host through, no
 * container lookup of one, and no method that accepts a host from a caller. The only
 * hosts that can appear in the output are the one an operator configured and the ones a
 * tenant *verified*, and `CanonicalBase` refuses anything with a CR/LF or whitespace in
 * it on the way through. `tests/Feature/Url/BaseUrlTest.php` additionally scans this
 * namespace's source for request-derived reads, so the property is enforced rather than
 * documented.
 *
 * ## HTTPS is forced outside local/testing
 *
 * A webhook callback or a signed export link emitted over `http://` puts a bearer-grade
 * credential — the signature, the verify token, the download URL — on the wire in
 * cleartext, where any network hop can replay it. So a base resolved in any environment
 * other than `local`/`testing` is upgraded to HTTPS (`wa.url.force_https` can pin the
 * decision either way for an environment that terminates TLS elsewhere). The upgrade is
 * part of canonicalisation, not a separate step, so the string that gets signed and the
 * string that gets linked are the same one.
 *
 * ## Caching: config in the key, data in the version
 *
 * Every absolute URL, every webhook registration, and every signed link starts here, so
 * the resolved string is cached (`BaseUrlCache`). The two classes of input are invalidated
 * differently, and between them nothing needs a manual cache clear:
 *
 *  - **config inputs** (`APP_URL`, the HTTPS decision) are *fingerprinted into the cache
 *    key*. Changing `APP_URL` changes the key, so the new origin is served on the very
 *    next call — there is no window in which a redeployed domain is still cached;
 *  - **database inputs** (`platform_settings['base_url']`, `tenant_domains`) bump the
 *    namespace version from their model writes — `PlatformSettingObserver` and
 *    `TenantDomain::booted()` — so an admin's save and task 5.5's domain verification both
 *    take effect immediately.
 */
final class ConfiguredBaseUrl implements BaseUrl
{
    /**
     * Named in `InvalidBaseUrlException` messages when the compiled-in default is at fault.
     */
    public const string APP_URL_SOURCE = "config('app.url')";

    /**
     * Environments where a plain-HTTP, loopback origin is a working development setup
     * rather than a broken deployment.
     *
     * @var list<string>
     */
    private const array LOCAL_ENVIRONMENTS = ['local', 'testing'];

    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly BaseUrlCache $cache,
    ) {}

    public function platform(): string
    {
        return $this->cache->remember(
            'platform:'.$this->configFingerprint(),
            fn (): string => $this->resolvePlatform()->value(),
        );
    }

    public function forTenant(Tenant $tenant): string
    {
        $tenantId = $tenant->getKey();

        if (! is_string($tenantId) || $tenantId === '') {
            // An unsaved tenant has no domains by definition; it still has a base.
            return $this->platform();
        }

        return $this->cache->remember(
            'tenant:'.$tenantId.':'.$this->configFingerprint(),
            fn (): string => $this->resolveForTenant($tenantId)->value(),
        );
    }

    /**
     * The platform origin: the admin override when set, otherwise `APP_URL`.
     *
     * @throws InvalidBaseUrlException
     */
    private function resolvePlatform(): CanonicalBase
    {
        $override = $this->settings->baseUrl();

        if ($override !== null) {
            return $this->canonicalize($override, PlatformSettings::settingSource(PlatformSettings::BASE_URL));
        }

        return $this->canonicalize((string) config('app.url'), self::APP_URL_SOURCE);
    }

    /**
     * The tenant's origin: its verified custom domain over the platform origin.
     *
     * Only the host is the tenant's — scheme, port and path prefix are inherited from the
     * platform base, so a deployment on a non-standard port or under a path prefix serves
     * custom domains the same way it serves its own apex.
     *
     * The tenant *subdomain* form (`tenant.{slug}.{apex}`) is deliberately not a link in
     * this chain; see the note in `wa.url` config and `UrlBuilder::tenantSubdomain()`
     * (task 5.2).
     *
     * @throws InvalidBaseUrlException
     */
    private function resolveForTenant(string $tenantId): CanonicalBase
    {
        $platform = $this->resolvePlatform();
        $host = TenantDomain::canonicalHostFor($tenantId);

        if ($host === null) {
            return $platform;
        }

        return $this->guard(
            $platform->withHost($host, TenantDomain::HOST_SOURCE),
            TenantDomain::HOST_SOURCE,
            $host,
        );
    }

    /**
     * Parse, canonicalise, and hold the result to the deployment's rules.
     *
     * @throws InvalidBaseUrlException
     */
    private function canonicalize(string $raw, string $source): CanonicalBase
    {
        return $this->guard(CanonicalBase::parse($raw, $source), $source, $raw);
    }

    /**
     * The two environment-dependent rules, applied to whatever origin was resolved so
     * they cannot be true of one link in the chain and false of another.
     *
     * @throws InvalidBaseUrlException when the origin only reaches the loopback interface
     */
    private function guard(CanonicalBase $base, string $source, string $raw): CanonicalBase
    {
        $base = $this->forcesHttps() ? $base->withHttps() : $base;

        if ($base->isLoopback() && ! $this->isLocalEnvironment()) {
            // `config('app.url')` defaults to `http://localhost`, so an APP_URL nobody set
            // is indistinguishable from one set to the loopback interface — and a
            // deployment that keeps it registers webhook callbacks no provider can reach.
            // Refusing is the only way that failure becomes visible where it is caused.
            $environment = app()->environment();

            throw InvalidBaseUrlException::loopback(
                $source,
                $raw,
                is_string($environment) ? $environment : 'unknown',
            );
        }

        return $base;
    }

    /**
     * Whether emitted URLs must be HTTPS. `wa.url.force_https` pins the answer; unset, it
     * follows the environment.
     */
    private function forcesHttps(): bool
    {
        $configured = config('wa.url.force_https');

        // An absent value must mean "follow the environment", and it arrives here as null
        // (config default) or '' (`WA_URL_FORCE_HTTPS=` in an env file). Both are checked
        // before `filter_var`, which reads either as an explicit `false` — that reading
        // would silently emit http:// links from every deployment that never set the key.
        if ($configured === null || $configured === '') {
            return ! $this->isLocalEnvironment();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? ! $this->isLocalEnvironment();
    }

    private function isLocalEnvironment(): bool
    {
        return app()->environment(self::LOCAL_ENVIRONMENTS) === true;
    }

    /**
     * A short digest of every *config-level* input to the resolved base.
     *
     * Carried in the cache key so that changing `APP_URL` or the HTTPS decision — a
     * deploy-time change no model write announces — cannot be served from a cache entry
     * built under the old value. The digest is not a secret and does not need to be
     * collision-resistant against an adversary: both inputs are operator-controlled.
     */
    private function configFingerprint(): string
    {
        return substr(sha1(sprintf(
            '%s|%s',
            (string) config('app.url'),
            $this->forcesHttps() ? 'https' : 'any',
        )), 0, 12);
    }
}
