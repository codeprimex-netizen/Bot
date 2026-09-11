<?php

declare(strict_types=1);

namespace App\Services\Url;

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Exceptions\Url\UnbuildableUrlException;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Url\CanonicalBase;
use App\Support\Url\UrlPath;
use DateTimeInterface;

/**
 * The `UrlBuilder` implementation: four ways to spell an origin plus a path, and no fifth
 * way to obtain the origin (Req 9.1, 9.2, 9.6 / A9; design § Base URL §U.3, §U.4).
 *
 * Read `UrlBuilder` for the contract. This class is where the decisions behind it live.
 *
 * ## Three collaborators, none of which can reach a request
 *
 * `BaseUrl` resolves the configured origin, `TenantContext` answers "which tenant is
 * acting" from state the platform set itself, and `SignedUrlSigner` binds a host into an
 * HMAC. There is no `Request` here, no `$_SERVER` read, and no call into the framework's
 * URL generator — which is request-aware, and would quietly reintroduce exactly the
 * dependency Req 9.1 forbids. That is Property 27's structural half: an attacker-supplied
 * host has no parameter to arrive through.
 *
 * ## Why the base string is parsed back into a value object
 *
 * `BaseUrl` returns a string, but signing needs `CanonicalBase::authority()` and path
 * joining needs the mount point, so the string is parsed back. That is cheap and, more to
 * the point, *idempotent*: the string is already canonical, so parsing it cannot produce a
 * second spelling of the origin — and a signature is only verifiable if the host it bound
 * has exactly one spelling. Parses are memoised per base string; the key is the whole
 * value, so a memo can never outlive the value it describes.
 *
 * ## The webhook shape is fixed, not configurable
 *
 * `{base}/webhooks/{provider}/{routeKey}` (design § Base URL §U.1). A config key for the
 * prefix would have to be read in two places that must agree — here, and by the routes
 * task 5.6 registers — and a deployment where they disagree registers callbacks with Meta
 * and Razorpay that this platform answers 404 to, which is discovered as "the provider
 * stopped sending us messages". `webhooks` is already reserved as a subdomain
 * (`wa.tenancy.reserved_subdomains`), so the path is ours to keep.
 */
final class CanonicalUrlBuilder implements UrlBuilder
{
    /**
     * Named in `InvalidBaseUrlException` messages when a base handed back by `BaseUrl`
     * cannot be parsed — which would be a defect in `BaseUrl`, not in configuration.
     */
    public const string BASE_SOURCE = 'the resolved canonical base';

    /**
     * Named in messages when a derived subdomain host is not usable.
     */
    public const string SUBDOMAIN_SOURCE = 'the tenant subdomain';

    /**
     * First path segment of every provider callback (design § Base URL §U.1).
     */
    private const string WEBHOOK_SEGMENT = 'webhooks';

    /**
     * A provider slug: one short lowercase DNS-label-shaped token, so it cannot carry a
     * separator into a URL a third party will fetch.
     */
    private const string PROVIDER_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/';

    /**
     * A route key: the URL-safe base64 alphabet, which is what a ULID or a random token
     * uses.
     */
    private const string ROUTE_KEY_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * A single DNS label — the same pattern `SubdomainTenantResolver` matches a request
     * host against, so a label this class emits is a label that resolver accepts.
     */
    private const string LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Environments where a plain-HTTP origin is a working development setup rather than a
     * broken deployment (the same list `ConfiguredBaseUrl` uses).
     *
     * @var list<string>
     */
    private const array LOCAL_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Parsed bases, keyed by the canonical string they came from.
     *
     * @var array<string, CanonicalBase>
     */
    private array $parsed = [];

    public function __construct(
        private readonly BaseUrl $baseUrl,
        private readonly TenantContext $tenants,
        private readonly SignedUrlSigner $signer,
    ) {}

    public function absolute(string $path, ?Tenant $tenant = null): string
    {
        $base = $this->base($tenant);

        return $base->value().UrlPath::normalize($path, 'absolute');
    }

    public function webhook(string $provider, string $routeKey, ?Tenant $tenant = null): string
    {
        $slug = strtolower(trim($provider));

        if (preg_match(self::PROVIDER_PATTERN, $slug) !== 1) {
            throw UnbuildableUrlException::provider($provider);
        }

        if (preg_match(self::ROUTE_KEY_PATTERN, $routeKey) !== 1) {
            throw UnbuildableUrlException::routeKey($routeKey);
        }

        $base = $this->base($tenant);

        if (! $base->isSecure() && ! $this->isLocalEnvironment()) {
            $environment = app()->environment();

            throw UnbuildableUrlException::insecureWebhookBase(
                $base->value(),
                is_string($environment) ? $environment : 'unknown',
            );
        }

        // Composed and then normalised, rather than concatenated: both parts are already
        // validated, so this is defence in depth against a future caller reaching the
        // segments some other way.
        return $base->value().UrlPath::normalize(
            self::WEBHOOK_SEGMENT.'/'.$slug.'/'.$routeKey,
            'webhook',
        );
    }

    public function signed(string $path, ?DateTimeInterface $expiresAt = null, ?Tenant $tenant = null): string
    {
        return $this->signer->sign($this->base($tenant), $path, $expiresAt);
    }

    public function tenantSubdomain(Tenant $tenant): string
    {
        // The platform base, never the tenant's custom domain: a subdomain hangs off the
        // apex by definition, and a tenant with its own domain still has one.
        $platform = $this->canonical($this->baseUrl->platform());
        $label = $this->subdomainLabel($tenant);

        return $platform->withHost($label.'.'.$this->apex($platform), self::SUBDOMAIN_SOURCE)->value();
    }

    /**
     * The origin to build on: the explicit tenant's, else the ambient tenant's, else the
     * platform's.
     *
     * @throws InvalidBaseUrlException
     */
    private function base(?Tenant $tenant): CanonicalBase
    {
        $subject = $tenant ?? $this->tenants->current();

        return $this->canonical(
            $subject instanceof Tenant
                ? $this->baseUrl->forTenant($subject)
                : $this->baseUrl->platform(),
        );
    }

    /**
     * @throws InvalidBaseUrlException
     */
    private function canonical(string $base): CanonicalBase
    {
        return $this->parsed[$base] ??= CanonicalBase::parse($base, self::BASE_SOURCE);
    }

    /**
     * The label a tenant answers on: its explicit `subdomain`, else its `slug`.
     *
     * `SubdomainTenantResolver` matches `subdomain` first and only falls back to
     * `slug WHERE subdomain IS NULL`, so emitting the slug for a tenant that *has* a
     * subdomain would produce a URL that resolves to no tenant at all. Design § Base URL
     * spells the host as `{slug}.{apex}`; the slug is the label only for tenants that
     * never customised it, and resolution is the authority on which of the two answers.
     *
     * @throws UnbuildableUrlException when the label is unusable, reserved, or another
     *                                 tenant's
     */
    private function subdomainLabel(Tenant $tenant): string
    {
        $explicit = $tenant->subdomain;
        $label = strtolower(trim(is_string($explicit) && $explicit !== '' ? $explicit : $tenant->slug));

        if ($label === '') {
            throw UnbuildableUrlException::unusableSubdomainLabel($label, 'it is empty');
        }

        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw UnbuildableUrlException::unusableSubdomainLabel(
                $label,
                'it is not a single DNS label (letters, digits and inner hyphens, at most 63 '
                .'characters), so no request could carry it as one',
            );
        }

        if (in_array($label, $this->reservedLabels(), true)) {
            throw UnbuildableUrlException::reservedSubdomain($label);
        }

        if (! is_string($explicit) || $explicit === '') {
            $this->assertSlugLabelUnclaimed($label, $tenant);
        }

        return $label;
    }

    /**
     * Refuse a slug-derived label that another tenant has claimed as its explicit
     * subdomain.
     *
     * `tenants.slug` and `tenants.subdomain` are unique columns but not unique *together*,
     * so tenant B may hold `subdomain = 'acme'` while tenant A holds `slug = 'acme'` with
     * no subdomain of its own. Resolution matches the subdomain column first, so a URL
     * built from A's slug would resolve to B: a cross-tenant link, and a silent one — the
     * recipient lands on a working panel belonging to someone else.
     *
     * One indexed existence check, on a method that emits a panel or email link rather
     * than one per message. Req 9.7 refuses the collision at assignment time (task 5.5);
     * this refuses to *emit* one, which is the half that must hold for rows that predate
     * that check.
     *
     * @throws UnbuildableUrlException
     */
    private function assertSlugLabelUnclaimed(string $label, Tenant $tenant): void
    {
        $query = Tenant::query()->where('subdomain', $label);
        $id = $tenant->getKey();

        if (is_string($id) && $id !== '') {
            $query->whereKeyNot($id);
        }

        if ($query->exists()) {
            throw UnbuildableUrlException::subdomainCollision($label);
        }
    }

    /**
     * The apex tenant subdomains hang off.
     *
     * `wa.tenancy.apexes` is the shared source — the same list `SubdomainTenantResolver`
     * accepts inbound hosts against — so what this emits is what that resolves. When the
     * platform's own host is one of the configured apexes it wins, and otherwise the first
     * entry does; an empty list falls back to the platform host, which is what a
     * single-domain deployment means by "the apex".
     *
     * A malformed entry is refused rather than skipped: skipping would silently move every
     * tenant's URL onto whichever apex came next in the list.
     *
     * @throws InvalidBaseUrlException when a configured apex is not a usable hostname
     */
    private function apex(CanonicalBase $platform): string
    {
        $configured = config('wa.tenancy.apexes');
        $apexes = [];

        foreach (is_array($configured) ? $configured : [] as $apex) {
            if (is_string($apex) && trim($apex) !== '') {
                $apexes[] = CanonicalBase::host($apex, 'wa.tenancy.apexes');
            }
        }

        if ($apexes === [] || in_array($platform->host, $apexes, true)) {
            return $platform->host;
        }

        return $apexes[0];
    }

    /**
     * Platform-owned labels no tenant may answer on.
     *
     * @return list<string>
     */
    private function reservedLabels(): array
    {
        $configured = config('wa.tenancy.reserved_subdomains');
        $reserved = [];

        foreach (is_array($configured) ? $configured : [] as $label) {
            if (is_string($label) && trim($label) !== '') {
                $reserved[] = strtolower(trim($label));
            }
        }

        return array_values(array_unique($reserved));
    }

    private function isLocalEnvironment(): bool
    {
        return app()->environment(self::LOCAL_ENVIRONMENTS) === true;
    }
}
