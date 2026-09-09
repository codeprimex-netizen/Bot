<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuditServiceProvider::class,
    App\Providers\TenancyServiceProvider::class,
    App\Providers\PlanGateServiceProvider::class,
    App\Providers\DispatchServiceProvider::class,
    App\Providers\SecurityServiceProvider::class,
    App\Providers\CircuitBreakerServiceProvider::class,
    App\Providers\IdempotencyServiceProvider::class,
    App\Providers\RetryServiceProvider::class,
];
