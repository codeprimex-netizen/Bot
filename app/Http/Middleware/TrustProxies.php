<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Which proxies may speak for a client, and which of their headers are believed
 * (Req 9.4 / A9; Req 32.x / NFR3 — request attribution).
 *
 * ## The default is to trust nothing, and that is a security decision, not laziness
 *
 * `X-Forwarded-For` and `X-Forwarded-Proto` are client-supplied strings. Trusting them from
 * an untrusted source lets any caller **choose its own IP address and scheme**, and the
 * platform believes that choice in three places where being wrong is expensive:
 *
 *  - **rate limiting / throttles** — a per-IP limit is bypassed by rotating a header value,
 *    which is free;
 *  - **the anti-fraud device/IP heuristics** (task 4.5) — an abuse signal keyed on an IP
 *    an attacker picks is worse than no signal, because it can be aimed at *another*
 *    tenant's address to get them limited;
 *  - **audit correlation** — a trail that records the caller's own claim about where it
 *    came from is evidence of nothing.
 *
 * So `wa.url.proxies.trusted` is **empty by default**: no proxy is trusted, forwarded
 * headers are ignored, and `$request->ip()` is the socket peer. A deployment that really
 * does sit behind a load balancer lists it — `WA_TRUSTED_PROXIES=10.0.0.0/8` or an explicit
 * address list — and only then are its headers believed. `'*'` (trust the immediate peer,
 * whatever it is) is accepted because a platform-as-a-service load balancer has no stable
 * address, but it is never a default and it is never inferred.
 *
 * The failure mode of getting this *too tight* is visible and safe (every client looks like
 * it came from the load balancer, and HTTPS detection needs `wa.url.force_https`); the
 * failure mode of getting it too loose is invisible and unsafe. That asymmetry decides the
 * default.
 *
 * ## This is about attribution, not about URLs
 *
 * `ConfiguredBaseUrl` never reads a forwarded header — or any request state — so no proxy
 * configuration can move an emitted URL onto another origin (Property 27 holds either way).
 * What forwarded headers *do* reach is the **accepted-host** check: with a proxy trusted,
 * `X-Forwarded-Host` becomes the effective host, which is why `EnforceAllowedHost` runs
 * after this middleware and checks the effective value.
 *
 * `X-Forwarded-Prefix` and the AWS ELB variant are dropped from the trusted set that
 * Laravel would otherwise enable: the first rewrites the framework's notion of the
 * application root from a header, and neither is needed by any deployment shape this
 * platform documents.
 */
final class TrustProxies extends Middleware
{
    /**
     * The forwarded headers believed **from a trusted proxy** — the four standard ones and
     * no more.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * The configured proxies, read per request.
     *
     * Read here rather than passed to `trustProxies(at: ...)` in `bootstrap/app.php`
     * because that runs while the HTTP kernel is being constructed — before the config
     * files are loaded — so a value read there would be null on every boot.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = config('wa.url.proxies.trusted');

        if (is_string($configured)) {
            $configured = trim($configured);

            return $configured === '' ? null : $configured;
        }

        $proxies = [];

        foreach (is_array($configured) ? $configured : [] as $proxy) {
            if (! is_string($proxy) || trim($proxy) === '') {
                continue;
            }

            $proxy = trim($proxy);

            if ($proxy === '*' || $proxy === '**') {
                // The parent only recognises the wildcard as a *string*, and as an array
                // entry it would be compared against the peer address as a literal — i.e.
                // it would silently trust nothing while reading as though it trusted
                // everything. `WA_TRUSTED_PROXIES=*` is comma-split into a list before it
                // gets here, so the wildcard is unwrapped rather than mangled.
                return $proxy;
            }

            $proxies[] = $proxy;
        }

        // null rather than [] when nothing is configured: the parent treats an empty result
        // as "not configured" anyway, and returning null keeps its documented fallbacks
        // (`config('trustedproxy.proxies')`, and the managed-platform detection) reachable
        // for a deployment that uses them.
        return $proxies === [] ? null : $proxies;
    }
}
