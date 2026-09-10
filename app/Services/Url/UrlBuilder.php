<?php

declare(strict_types=1);

namespace App\Services\Url;

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Exceptions\Url\UnbuildableUrlException;
use App\Models\Tenant;
use DateTimeInterface;

/**
 * Every absolute URL the platform emits (Req 9.1, 9.2, 9.6 / A9; design § Base URL §U.3;
 * Correctness Property 27).
 *
 * ```php
 * $urls = app(UrlBuilder::class);
 *
 * $urls->absolute('/exports');                                  // https://bot.example.com/exports
 * $urls->absolute('/exports', $tenant);                         // https://chat.acme.example/exports  (verified domain)
 * $urls->webhook('cloud-api', $routeKey, $tenant);              // https://…/webhooks/cloud-api/{routeKey}
 * $urls->signed('/exports/01J…/download', now()->addMinutes(15));
 * $urls->tenantSubdomain($tenant);                              // https://acme.app.bot.example.com
 * ```
 *
 * ## One rule, and it is structural
 *
 * Every method builds from `BaseUrl` — the configured canonical origin — and from
 * nothing else. No implementation may read `Host`, `X-Forwarded-Host`, `$_SERVER`, or
 * call the framework's request-aware URL generator, because a URL built from the request
 * host is host-header injection: a poisoned password-reset link, a poisoned cache entry,
 * or a webhook callback registered against an attacker's origin — at which point the
 * attacker receives another party's traffic over a link this platform signed.
 *
 * The guarantee is not a review rule. No method takes a host, a request, or anything
 * derived from one; the only hosts reachable are the operator's configured origin and a
 * tenant's *verified* domain. `tests/Feature/Url/BaseUrlTest.php` additionally scans this
 * namespace's source for request-derived reads, and task 5.7's property test varies the
 * incoming `Host` header arbitrarily.
 *
 * ## Tenant awareness
 *
 * `absolute()`, `webhook()` and `signed()` take an optional tenant. An explicit tenant
 * always wins; when none is given the **ambient** tenant (`TenantContext::current()`) is
 * used, so a tenant-scoped request or job emits that tenant's origin without every call
 * site remembering to pass it, and platform-level code with no tenant bound gets the
 * platform origin. A tenant with a verified custom domain therefore gets its own origin
 * everywhere, including in its webhook callbacks (design § Base URL §U.2).
 *
 * `tenantSubdomain()` is the exception: it always hangs off the platform apex, because
 * that is what the subdomain *is*.
 */
interface UrlBuilder
{
    /**
     * An absolute link to `$path` on the tenant's origin, or the platform's.
     *
     * `$path` is a server-side path (`/exports`, `exports/42` — both accepted, one
     * spelling emitted) and may not carry a query string or a fragment; see
     * `App\Support\Url\UrlPath` for the full rule and the reasons. An empty path yields
     * the origin itself.
     *
     * @throws UnbuildableUrlException when `$path` cannot be appended to an origin
     * @throws InvalidBaseUrlException when the deployment has no usable canonical base
     */
    public function absolute(string $path, ?Tenant $tenant = null): string;

    /**
     * The webhook callback URL a provider is registered with: `{base}/webhooks/{provider}/{routeKey}`
     * (Req 9.2; design § Base URL §U.4).
     *
     * `$routeKey` is the opaque key stored in `channel_webhook_routes.route_key`, which
     * maps an inbound webhook back to exactly one `(tenant, session, driver)` tuple. It is
     * the caller's — task 5.6's — job to generate it with enough entropy that it cannot be
     * guessed, and to re-register the callback if the tenant's origin later changes.
     *
     * Always HTTPS outside `local`/`testing`: this URL is fetched from the public internet
     * and carries a verify token or an HMAC.
     *
     * @param  string  $provider  a short lowercase slug — `bridge`, `cloud-api`, `on-premise`, `razorpay`
     *
     * @throws UnbuildableUrlException when the provider or route key is not a safe path
     *                                 segment, or the origin is not HTTPS
     * @throws InvalidBaseUrlException when the deployment has no usable canonical base
     */
    public function webhook(string $provider, string $routeKey, ?Tenant $tenant = null): string;

    /**
     * A signed, expiring URL for exports and payment links, whose signature binds the
     * canonical host (Req 9.6).
     *
     * A window longer than 3600 seconds is refused here rather than at verification, and
     * so is an expiry that has already passed. `$expiresAt` of null uses
     * `wa.url.signed.ttl_seconds`. Verify a presented URL with `SignedUrlSigner::verify()`
     * / `assertValid()`.
     *
     * @throws UnbuildableUrlException when the path is unusable, or the window has passed
     *                                 or exceeds 3600 seconds
     * @throws InvalidBaseUrlException when the deployment has no usable canonical base
     * @throws \App\Exceptions\Security\KeyUnavailableException when no signing secret can be issued
     */
    public function signed(string $path, ?DateTimeInterface $expiresAt = null, ?Tenant $tenant = null): string;

    /**
     * The tenant's subdomain origin, `{slug}.{apex}` (Req 9.3; design § Base URL §U.1).
     *
     * The label is the tenant's explicit `subdomain` when it has one and its `slug`
     * otherwise — the same rule `SubdomainTenantResolver` matches on, so the URL emitted
     * here resolves back to the tenant it was built for. The apex comes from
     * `wa.tenancy.apexes`, falling back to the platform base host.
     *
     * A label that is reserved, is not a DNS label, or is claimed by another tenant is
     * refused rather than emitted: a subdomain URL that resolves to the wrong tenant, or
     * to no tenant, fails silently at whoever received it (Req 9.7).
     *
     * @throws UnbuildableUrlException when the tenant has no usable subdomain label
     * @throws InvalidBaseUrlException when the deployment has no usable canonical base
     */
    public function tenantSubdomain(Tenant $tenant): string;
}
