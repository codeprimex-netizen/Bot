<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Url\HostNotAllowedException;
use App\Services\Domains\HostAllowlist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request whose host is not on the accepted-host allowlist, before the requested
 * action runs (Req 9.4, 9.5 / A9; Correctness Property 27's third clause).
 *
 * ## Why this exists next to Laravel's `TrustHosts`
 *
 * `TrustHosts` is wired from the same allowlist (`bootstrap/app.php`), and it is the deeper
 * of the two guards: it installs Symfony's trusted-host regexp, so *any* later
 * `Request::getHost()` on an unlisted host raises rather than returning an attacker's
 * string. What it does not do is refuse the request in a way the platform controls — the
 * refusal surfaces as a generic framework 400 — and it deliberately does nothing at all in
 * `local` and while running tests, which would leave Req 9.5 with no test that a rejection
 * happens.
 *
 * So this middleware is the *decision*, in the platform's own vocabulary: one typed
 * `HostNotAllowedException`, the same 400 the framework's path yields, raised before
 * routing dispatches anything. Both read `HostAllowlist`, so there is one list and one
 * answer; neither is a second opinion about what is accepted.
 *
 * ## Where it sits in the stack, and why that exact place
 *
 * Appended to the **global** stack, which puts it after `TrustProxies`. That order is
 * load-bearing: behind a trusted proxy the effective host comes from `X-Forwarded-Host`,
 * and a check that ran first would test the internal hop's name and then serve whatever
 * the forwarded header said. It is global rather than on `web`, because a host is refused
 * for API and webhook traffic too — a provider callback registered against the canonical
 * base never arrives on another name.
 *
 * One consequence of that position, recorded so it is a decision rather than a surprise:
 * `HandleCors` sits earlier in the global stack, so a **preflight** `OPTIONS` on an
 * unrecognised host is answered by the CORS middleware before this runs. That is harmless
 * — a preflight dispatches no action, binds no tenant, and reveals only the static CORS
 * policy — and moving ahead of `TrustProxies` to change it would cost the correctness of
 * the host being checked, which matters more.
 *
 * ## What it does not do
 *
 * It does not redirect to the canonical host (that is an open redirector driven by a
 * header), does not resolve a tenant (`ResolveTenant` does, later, and only from rows), and
 * does not read the host for any purpose other than comparing it against the list. Nothing
 * here can influence URL generation: no URL is built on this path at all.
 */
final readonly class EnforceAllowedHost
{
    public function __construct(private HostAllowlist $allowlist) {}

    public function handle(Request $request, Closure $next): Response
    {
        // `decodedPath()`, because that is the string the router matches a route against
        // (`UriValidator` rawurldecodes it): an exemption that read the raw path would
        // refuse a percent-encoded spelling of the challenge path that the route itself
        // would still have served.
        if ($this->allowlist->enforces() && ! $this->allowlist->isUnrestrictedPath($request->decodedPath())) {
            $this->assertAllowed($request);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * @throws HostNotAllowedException when the request's effective host is not accepted
     */
    private function assertAllowed(Request $request): void
    {
        try {
            $host = $request->getHost();
        } catch (SuspiciousOperationException) {
            // Symfony already refused it — an invalid host, or one outside the trusted-host
            // patterns `TrustHosts` installed from this same allowlist. Converted rather
            // than propagated so both layers produce one response shape, and so the log
            // line comes from one place.
            throw HostNotAllowedException::forHost($this->rawHost($request));
        }

        if (! $this->allowlist->accepts($host)) {
            throw HostNotAllowedException::forHost($host);
        }
    }

    /**
     * The host as the request spelled it, for the log line only.
     *
     * `getHost()` returns `''` once Symfony has refused a host, so the header is read
     * directly. It is never compared against anything and never reaches a response — the
     * decision has already been made at this point.
     */
    private function rawHost(Request $request): string
    {
        $host = $request->headers->get('HOST');

        return is_string($host) ? $host : '';
    }
}
