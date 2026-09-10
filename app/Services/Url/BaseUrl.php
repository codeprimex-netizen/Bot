<?php

declare(strict_types=1);

namespace App\Services\Url;

use App\Models\Tenant;

/**
 * The canonical origin every absolute URL the platform emits is built from
 * (Req 9.1, 9.3 / A9; design § Base URL §U.3; Correctness Property 27).
 *
 * ```php
 * app(BaseUrl::class)->platform();          // 'https://bot.example.com'
 * app(BaseUrl::class)->forTenant($tenant);  // 'https://chat.acme.example' (verified domain)
 * ```
 *
 * **Never derived from the request.** Neither method takes a request, and no
 * implementation may read `Host`, `X-Forwarded-Host`, `$_SERVER`, or anything else the
 * caller controls. Building URLs from the request host is host-header injection:
 * poisoned password-reset links, cache poisoning, and webhook callbacks registered
 * against an attacker's origin — the attacker then receives another party's traffic
 * over a link the platform signed. The origin comes from configuration only, and
 * `tests/Feature/Url/BaseUrlTest.php` holds this namespace to it by scanning its source
 * as well as by varying the `Host` header.
 *
 * The returned string is **canonical**: one spelling per origin, no trailing slash (see
 * `App\Support\Url\CanonicalBase`). Task 5.3 binds that host into signed-URL
 * signatures, so a second spelling would make a legitimate link fail verification.
 *
 * `UrlBuilder` (task 5.2) is the consumer: it appends paths, builds webhook callbacks,
 * and signs expiring links. Callers that need a URL should ask it, not this interface.
 */
interface BaseUrl
{
    /**
     * The canonical platform base (never derived from the request `Host` header).
     *
     * Precedence: `platform_settings['base_url']` → `config('app.url')`, first non-empty
     * value winning (Req 9.3).
     *
     * @throws \App\Exceptions\Url\InvalidBaseUrlException when the deployment is not configured with a usable origin
     */
    public function platform(): string;

    /**
     * The base for a specific tenant: its **verified** custom domain when it has one,
     * otherwise the platform base (Req 9.3).
     *
     * An unverified domain claim is never used — resolution falls *down* to the platform
     * base rather than *up* to a host the tenant may not own (Req 9.7).
     *
     * @throws \App\Exceptions\Url\InvalidBaseUrlException when the deployment is not configured with a usable origin
     */
    public function forTenant(Tenant $tenant): string;
}
