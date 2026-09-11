<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\TenantContext;

/**
 * Shared entry points for the audit-trail tests.
 *
 * A class rather than Pest helper functions on purpose: Pest loads every test file
 * into one process, so a global `audit()` helper would be a name the whole suite has
 * to keep free forever.
 */
final class Audit
{
    public static function service(): AuditService
    {
        return app(AuditService::class);
    }

    public static function tenancy(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Append `$count` honest entries to one chain, and hand back the rows.
     *
     * Payloads deliberately mix types (string, int, float, bool, null, nested) so the
     * canonical serialization is exercised by every chain the tests build, not only by
     * its own unit test.
     *
     * @return list<AuditLog>
     */
    public static function chain(int $count, Tenant|string|null $tenant = null, string $action = 'thing.happened'): array
    {
        $entries = [];

        for ($index = 1; $index <= $count; $index++) {
            $payload = [
                'index' => $index,
                'ratio' => $index / 7,
                'label' => 'entry-'.$index,
                'enabled' => $index % 2 === 0,
                'nothing' => null,
                'nested' => ['depth' => ['value' => $index * 2]],
            ];

            $entries[] = $tenant === null
                ? self::service()->writeForPlatform($action, $payload)
                : self::service()->write($action, $payload, tenant: $tenant);
        }

        return $entries;
    }
}
