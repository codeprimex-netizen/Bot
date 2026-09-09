<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;

/**
 * A `DEDICATED_DB` tenant is routed to a database connection that
 * `config/database.php` does not define (Req 1.6 / A1).
 *
 * Fail-closed, like the rest of the tenancy layer. The tempting alternative —
 * falling back to the shared connection — is the worst possible outcome for the
 * tier that exists precisely to keep a tenant's rows *off* the shared database: the
 * query would succeed against the wrong database, silently reading and writing
 * another population's data and breaking the data-residency promise the tenant is
 * paying for. A typo in a shard map is an operator error, so it is reported as one.
 *
 * Note the shape of the guard: this is raised only when something *names* a
 * connection that does not exist. A deployment with no shard configuration at all is
 * not an error — `TierResolver::connection()` returns `null` there, and the tenant
 * stays on the shared connection until its cutover is set up.
 */
final class UnknownShardConnectionException extends RuntimeException
{
    public static function forTenant(string $tenantId, string $connection): self
    {
        return new self(sprintf(
            'Tenant [%s] is on tier DEDICATED_DB and routed to database connection [%s], '
            .'which is not defined in config/database.php. Define the connection, or correct '
            .'the tenant_tiers row / wa.tenancy.tiers.shard map that names it — the shared '
            .'connection is deliberately not used as a fallback.',
            $tenantId,
            $connection,
        ));
    }
}
