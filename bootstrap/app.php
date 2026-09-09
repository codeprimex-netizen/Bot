<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        //
    })->create();
