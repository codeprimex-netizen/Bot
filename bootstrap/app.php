<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\CrossTenantAccessException;
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
    })->create();
