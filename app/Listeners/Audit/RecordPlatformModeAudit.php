<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\Tenancy\PlatformModeEntered;
use App\Events\Tenancy\PlatformModeExited;
use App\Services\Audit\AuditActor;
use App\Services\Audit\AuditService;

/**
 * Writes the audited tenant-scope bypass to the audit trail (Req 1.5 / A1;
 * Req 24.2 / D1).
 *
 * Req 1.5 allows exactly one way to read across tenants — `actingAsPlatform()` — and
 * calls it *audited*. Task 0.2 made that possible without coupling the tenancy layer
 * to the auth guards: `TenantContext` announces every open and close on the event bus
 * and resolves nobody. This listener is the other half — it turns those announcements
 * into rows, and it is where the acting admin is resolved.
 *
 * ## Why entries land on the platform chain
 *
 * The bypass is a platform-level act, so it is recorded on the platform chain even
 * when a tenant was bound beforehand; that tenant is preserved as
 * `prior_tenant_id` in the payload. Keeping it off the tenant's chain matters for two
 * reasons: the invariant `chain_key = tenant_id ?? 'platform'` stays true (so a
 * tenant-scoped read can never see platform entries), and an admin reviewing "every
 * bypass that happened last week" reads one chain instead of all of them.
 *
 * ## Method naming
 *
 * The two methods are deliberately *not* called `handle…`: Laravel's listener
 * auto-discovery registers any `handle*` method it finds under `app/Listeners`, and
 * `AuditServiceProvider` already registers them explicitly (so the wiring is greppable
 * from the event class). Both would mean every bypass recorded twice.
 *
 * ## Why this cannot recurse
 *
 * Appending never opens platform mode of its own — `AuditService` reads chains through
 * the sanctioned `withoutTenantScope()` bypass precisely so that writing *about* a
 * bypass does not need one. One `asPlatform()` block therefore produces exactly two
 * entries: entered, then exited.
 */
final readonly class RecordPlatformModeAudit
{
    public function __construct(private AuditService $audit) {}

    public function recordEntered(PlatformModeEntered $event): void
    {
        $this->audit->writeForPlatform(
            'platform_mode.entered',
            [
                'reason' => $event->reason,
                'prior_tenant_id' => $event->tenantId,
                'depth' => $event->depth,
            ],
            actor: $this->actor(),
        );
    }

    public function recordExited(PlatformModeExited $event): void
    {
        $this->audit->writeForPlatform(
            'platform_mode.exited',
            [
                'reason' => $event->reason,
                'duration_ms' => $event->durationMs,
                // True when a request/job boundary closed a frame the caller left open:
                // not an error, but worth being able to search for.
                'forced' => $event->forced,
            ],
            actor: $this->actor(),
        );
    }

    /**
     * The admin behind the bypass, where the request can tell us — `System` when it
     * cannot (a scheduler or console command running platform-wide maintenance).
     *
     * `platformMode: true` biases an ordinary authenticated session towards `Admin`,
     * because opening the bypass is something only a platform super-admin can do; once
     * the `platform-admin` guard exists (task 30.1) that guard answers directly.
     */
    private function actor(): AuditActor
    {
        return AuditActor::fromAuth(platformMode: true);
    }
}
