<?php

declare(strict_types=1);

use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Exceptions\Security\PermissionDeniedException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\QuotaExceededException;
use App\Exceptions\Url\HostNotAllowedException;
use App\Http\Middleware\EnforceAllowedHost;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\TrustProxies;
use App\Services\Domains\HostAllowlist;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | Req 9.4 / A9 — the accepted-host allowlist, wired from the live one.
        |
        | `App\Services\Domains\HostAllowlist` is the single source: the platform apex(es),
        | one label under each (tenant subdomains), and every *verified* custom domain,
        | read through the same cache version tenant resolution uses — so a domain verified
        | a second ago is accepted now and a revoked one is refused now. A callable rather
        | than an array because that list changes at runtime, and a static config array
        | would pin it to deploy time.
        |
        | `subdomains: false` on purpose: the framework's own helper would add
        | `^(.+\.)?{APP_URL host}$`, which admits *any depth* of subdomain — `a.b.apex` is
        | not a tenant subdomain, and one wildcard certificate should not become an open
        | host space. The patterns from the allowlist admit exactly one label.
        |
        | `patternsFor()` returns `[]` — Symfony's spelling of "no host restriction" — for
        | the paths that must answer on a host the platform has not accepted yet: the
        | ACME-style domain-ownership challenge (an http-01 check is fetched at the host
        | *before* it is verified, so restricting it would make the method permanently
        | unsatisfiable) and the health check (an orchestrator dials a container by IP).
        | `EnforceAllowedHost` skips the same paths from the same list.
        */
        $middleware->trustHosts(at: static function (): array {
            $allowlist = app(HostAllowlist::class);
            $request = app('request');

            return $request instanceof Request
                ? $allowlist->patternsFor($request->decodedPath())
                : $allowlist->patterns();
        }, subdomains: false);

        // Req 9.5 / A9: an unlisted host is refused with a typed 400 before routing
        // dispatches anything. Appended to the global stack so it runs *after*
        // TrustProxies and therefore checks the effective host (a trusted proxy's
        // X-Forwarded-Host), and so it covers API and webhook traffic, not just `web`.
        $middleware->append(EnforceAllowedHost::class);

        // Trust no proxy unless one is configured (`wa.url.proxies.trusted`). Replacing
        // the framework's middleware rather than calling `trustProxies(at: ...)` here:
        // this callback runs while the HTTP kernel is constructed, before the config
        // files are loaded, so a value read at this point would always be null.
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);

        // Available explicitly for API/panel route groups, which stack the
        // membership, plan, and quota gates on top of it.
        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            // Plan feature gating (Req 11.3 / B2, Req 22.2 / C5). Stacks *after*
            // resolve.tenant: `plan.feature:ai`, or `plan.feature:flows,integrations`
            // to require several.
            'plan.feature' => EnsurePlanFeature::class,
            // RBAC + API-key scope (Req 32.1 / NFR3; STRIDE rows "Panels" and
            // "Public API"). Stacks *after* resolve.tenant (and after `auth` on panel
            // routes): `tenant.permission:campaigns.manage`, or a comma-separated list
            // to require several. A panel caller is checked against their role, an API
            // caller against the scopes its key was issued with.
            'tenant.permission' => EnsurePermission::class,
        ]);

        // Appended (not prepended) so it runs after StartSession and can read
        // the panel's active tenant. A request that matches no tenant simply
        // carries none.
        $middleware->appendToGroup('web', ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | Req 9.5 / A9 — the framework's own host refusal, in the platform's vocabulary.
        |
        | `EnforceAllowedHost` converts Symfony's `SuspiciousOperationException` into the
        | typed refusal below, but it only gets the chance when it is the first thing to
        | read the host — and it is not. The framework's `TrustProxies` runs *before* it
        | (deliberately: the accepted-host check has to see the effective, post-forwarding
        | host) and calls `$request->host()` itself, for its Forge/Vapor detection. So a
        | *structurally* unreadable Host — embedded credentials, a CR/LF payload, a NUL, a
        | U-label, two root dots — raised before `EnforceAllowedHost` ran, and Laravel
        | mapped it (`RequestExceptionInterface`) to a generic `Bad request.` 400: right
        | status, but no `host_not_allowed` code, no JSON envelope for an API caller, and no
        | log line naming the host. Two refusal shapes for one cause, which is exactly what
        | `EnforceAllowedHost` documents that it exists to prevent.
        |
        | Mapped rather than rendered, because a render callback runs *after*
        | `prepareException()` has already turned it into a `BadRequestHttpException` —
        | catching it there would mean catching every bad request. Mapping also covers every
        | other place the framework can raise it (`TrustHosts`, a later `getHost()`), so the
        | conversion is not a list of known callers.
        */
        $exceptions->map(function (SuspiciousOperationException $e): HostNotAllowedException {
            $request = app('request');
            $host = $request instanceof Request ? $request->headers->get('HOST') : null;

            return HostNotAllowedException::forHost(is_string($host) ? $host : '');
        });

        // Req 9.5 / A9: a request on a host the platform does not serve gets a 400 saying
        // exactly that, and nothing else.
        //
        // Rendered here — plain text for a browser, the usual envelope for an API client —
        // rather than through the error views, because a Blade error page builds asset and
        // route URLs, and building URLs while serving a host we have just refused is the
        // thing this refusal exists to prevent. The body never echoes the host back: the
        // sender knows what it sent, and reflecting an attacker-controlled header adds a
        // surface for nothing. The host is in the log line
        // (`HostNotAllowedException::operatorMessage()`).
        $exceptions->render(function (HostNotAllowedException $e, Request $request): JsonResponse|Response {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->publicMessage(),
                    'error' => HostNotAllowedException::ERROR_CODE,
                ], HostNotAllowedException::STATUS);
            }

            return response(
                $e->publicMessage()."\n",
                HostNotAllowedException::STATUS,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        });

        // Req 1.3 / A1: a cross-tenant access attempt is a clean 403 on every
        // surface. The exception carries its own status (it is an
        // HttpExceptionInterface), so the panel gets the framework's 403 page; API
        // and Livewire callers get a stable JSON envelope here.
        //
        // The body is the exception's fixed public sentence, never its internal
        // message: the internal one names the model and fingerprints the ids for the
        // log, and none of that belongs in a response.
        $exceptions->render(function (CrossTenantAccessException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->publicMessage(),
                'error' => CrossTenantAccessException::ERROR_CODE,
            ], CrossTenantAccessException::STATUS);
        });

        // Req 32.1 / NFR3: a caller without the permission — a role that does not carry
        // it, or an API key not issued for it — is a 403 on every surface. The envelope
        // carries the permission key (it came from the route the caller already reached,
        // so it reveals nothing new) but never *why* it was refused: "not a member" and
        // "member without the role" must be indistinguishable, or the 403 becomes a
        // membership oracle.
        $exceptions->render(function (PermissionDeniedException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->publicMessage(),
                'error' => $e->errorCode(),
                'permission' => $e->permission->value,
            ], PermissionDeniedException::STATUS);
        });

        // Req 11.3 / B2, Req 22.2 / C5: a feature the tenant's plan does not include is
        // a 402 when a higher active plan sells it ("upgrade") and a 403 when nothing
        // does ("not permitted") — see FeatureNotInPlanException for the rule. The
        // feature key and the upgrade targets are public catalogue data, so the envelope
        // carries them: without them the panel cannot render an actionable CTA.
        $exceptions->render(function (FeatureNotInPlanException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            $payload = [
                'message' => $e->publicMessage(),
                'error' => $e->errorCode(),
                'feature' => $e->feature->value,
            ];

            if ($e->upgradePlans !== []) {
                $payload['upgrade_plans'] = $e->upgradePlans;
            }

            return response()->json($payload, $e->getStatusCode());
        });

        // Req 3.4 / A3: an exhausted allowance defers or blocks, and either way the tenant
        // is *told* — never silently dropped. The envelope carries the quota, the outcome
        // and (for a deferrable refusal) the wait, so a client can back off with the same
        // number the queue releases a job with. `Retry-After` comes from the exception's
        // own headers, so header and body cannot disagree.
        $exceptions->render(function (QuotaExceededException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            $payload = [
                'message' => $e->publicMessage(),
                'error' => $e->errorCode(),
                'quota' => $e->verdict->kind->value,
                'outcome' => $e->verdict->outcome()->value,
                'reason' => $e->verdict->reason->value,
            ];

            if ($e->retryAfterSeconds() !== null) {
                $payload['retry_after'] = $e->retryAfterSeconds();
            }

            return response()->json($payload, $e->getStatusCode(), $e->getHeaders());
        });
    })->create();
