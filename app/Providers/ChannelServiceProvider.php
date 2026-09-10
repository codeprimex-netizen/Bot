<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Audit\AuditService;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\DatabaseChannelCredentialStore;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Channel Mode layer — per-tenant, per-mode credentials for now (Req 8.5 / A8;
 * Req 32.3 / NFR3).
 *
 * ## `ChannelCredentialStore` — scoped, and that is the security boundary
 *
 * `scoped()` rather than `singleton()`, for the same reason `FieldCipher` and
 * `SigningSecretStore` are: the store memoises which credential row answers a
 * `(tenant, mode, provider)` lookup, and that memo must die with the request or the job.
 * Laravel discards scoped instances at the end of every request and the queue worker resets
 * them between jobs, which gives two guarantees at once — one tenant's resolved credentials
 * cannot be carried into another tenant's job inside a long-lived worker, and a credential set
 * that has just been disabled or rotated away from cannot keep being selected past the unit of
 * work that resolved it.
 *
 * A `singleton()` here would quietly turn a per-request memo into a process-lifetime cache of
 * credential rows. Nothing in the store's own code would change; it would simply stop being
 * true that a revoked credential stops being used.
 *
 * Bound **by interface only**, so task 7.6, the mode panel, and the drivers of tasks 7.1–7.5
 * depend on the contract rather than on this implementation.
 */
class ChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(
            ChannelCredentialStore::class,
            fn (Application $app): ChannelCredentialStore => new DatabaseChannelCredentialStore(
                $app->make(TenantContext::class),
                $app->make(AuditService::class),
            ),
        );
    }
}
