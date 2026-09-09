<?php

declare(strict_types=1);

use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Exceptions\Security\PermissionDeniedException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\QuotaExceededException;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
