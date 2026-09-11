<?php

declare(strict_types=1);

namespace App\Events\Tenancy;

/**
 * A platform-admin opened the audited tenant-scope bypass (Req 1.5 / A1).
 *
 * This is the hook the audit trail plugs into: `AuditService` (task 4.3) and the
 * platform-admin guard (task 30.1) listen for this event and write the acting
 * admin, reason, and timestamp to the hash-chained `audit_logs` table. Nothing
 * here resolves the actor — the listener owns that, so the tenancy layer stays
 * independent of the auth guards.
 */
final readonly class PlatformModeEntered
{
    /**
     * @param  string  $reason  why the bypass was opened
     * @param  string|null  $tenantId  the tenant that was bound before the bypass, if any
     * @param  int  $depth  nesting depth after opening (1 = outermost)
     */
    public function __construct(
        public string $reason,
        public ?string $tenantId,
        public int $depth = 1,
    ) {}
}
