<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuditServiceProvider::class,
    App\Providers\TenancyServiceProvider::class,
    App\Providers\UrlServiceProvider::class,
    App\Providers\DomainServiceProvider::class,
    App\Providers\PlanGateServiceProvider::class,
    App\Providers\RbacServiceProvider::class,
    App\Providers\DispatchServiceProvider::class,
    App\Providers\SecurityServiceProvider::class,
    App\Providers\PiiServiceProvider::class,
    App\Providers\AbuseServiceProvider::class,
    App\Providers\CircuitBreakerServiceProvider::class,
    App\Providers\IdempotencyServiceProvider::class,
    App\Providers\RetryServiceProvider::class,
    App\Providers\OutboxServiceProvider::class,
    App\Providers\SagaServiceProvider::class,
    App\Providers\BridgeServiceProvider::class,
];
