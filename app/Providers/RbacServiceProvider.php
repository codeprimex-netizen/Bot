<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Rbac\DatabaseRbacService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the authorization primitive (Req 32.1 / NFR3, task 4.6; used by task 30.4's role
 * management screen).
 *
 * One binding, and its lifetime is the point: **`scoped()`**, not `singleton()`.
 *
 * `DatabaseRbacService` memoises `(user, tenant) → role` so a panel screen that asks
 * about a dozen permissions costs one query instead of a dozen. A singleton would give
 * that cache the lifetime of the *worker*, which on Octane or a queue worker means a
 * revoked role could keep authorizing until the process restarted, and a role cached for
 * one tenant could be consulted while another tenant is bound. Scoped instances are
 * discarded at the end of every request and every queued job — the same boundary
 * `TenancyServiceProvider` maintains for `TenantContext` and `SecurityServiceProvider`
 * for the key caches.
 */
final class RbacServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RbacService::class, DatabaseRbacService::class);
    }
}
