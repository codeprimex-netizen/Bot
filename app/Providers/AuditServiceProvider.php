<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Tenancy\PlatformModeEntered;
use App\Events\Tenancy\PlatformModeExited;
use App\Listeners\Audit\RecordPlatformModeAudit;
use App\Services\Audit\AuditService;
use App\Services\Audit\HashChainAuditService;
use App\Services\Tenancy\TenantContext;
use App\Support\Audit\AuditPayloadNormalizer;
use App\Support\Audit\AuditPayloadRedactor;
use App\Support\Audit\CanonicalSerializer;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the audit trail: the hash-chain service and the listener that records the
 * audited tenant-scope bypass (Req 1.5 / A1; Req 24.2, 24.5 / D1).
 */
class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless and cheap; shared so every caller hashes with the same rules.
        $this->app->singleton(CanonicalSerializer::class);
        $this->app->singleton(AuditPayloadRedactor::class);

        $this->app->singleton(AuditPayloadNormalizer::class, function (): AuditPayloadNormalizer {
            return new AuditPayloadNormalizer(
                maxDepth: (int) config('wa.audit.max_payload_depth', 6),
                maxStringLength: (int) config('wa.audit.max_string_length', 2000),
                maxArrayItems: (int) config('wa.audit.max_array_items', 100),
            );
        });

        $this->app->singleton(AuditService::class, function (Application $app): AuditService {
            return new HashChainAuditService(
                $app->make(TenantContext::class),
                // Resolved explicitly: `ConnectionInterface` has no default binding, and
                // audit rows always go to the default connection even for a tenant on a
                // dedicated shard (Req 1.6 / A1) — one trail, not one per shard.
                $app->make('db')->connection(),
                $app->make(CacheFactory::class),
                $app->make(CanonicalSerializer::class),
                $app->make(AuditPayloadNormalizer::class),
                $app->make(AuditPayloadRedactor::class),
                lockSeconds: (int) config('wa.audit.lock_seconds', 5),
            );
        });
    }

    public function boot(): void
    {
        // Registered explicitly rather than left to listener auto-discovery: this is the
        // wiring that makes Req 1.5's "audited" true, so it should be greppable from the
        // event class rather than implied by a directory scan. (The listener's methods
        // avoid the `handle*` prefix for the same reason — discovery would otherwise
        // register them a second time and every bypass would be recorded twice.)
        Event::listen(PlatformModeEntered::class, [RecordPlatformModeAudit::class, 'recordEntered']);
        Event::listen(PlatformModeExited::class, [RecordPlatformModeAudit::class, 'recordExited']);
    }
}
