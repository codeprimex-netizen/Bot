<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;

/**
 * A tenant-owned model was queried or created while **no tenant was bound and
 * platform mode was not open** — so there is no honest answer to "whose rows is
 * this?" (Req 1.1, 1.2 / A1).
 *
 * This is the **fail-closed** half of `BelongsToTenant`. The alternative — quietly
 * dropping the constraint — would turn any unresolved context into a cross-tenant
 * read; the other alternative — quietly returning nothing — would turn the same bug
 * into silent data loss (`firstOrCreate()` would duplicate rows, `updateOrCreate()`
 * would write a second copy, a scheduler counting work would conclude there is
 * none). Neither is acceptable for the invariant Property 1 protects, so an
 * unresolved context is a **programming error** and is reported as one.
 *
 * The fix at the call site is always to say what you mean, explicitly:
 *
 * - `TenantContext::runFor($tenant, fn () => ...)` — do this work as one tenant;
 * - `TenantContext::asPlatform($reason, fn () => ...)` — audited platform-wide read
 *   (Req 1.5 / A1);
 * - `Model::withoutTenantScope()` — a system query at a boundary that has no tenant
 *   yet (tenant resolution itself, webhook intake), greppable by design.
 */
final class MissingTenantContextException extends RuntimeException
{
    /**
     * @param  class-string  $model
     */
    public static function forQuery(string $model): self
    {
        return new self(sprintf(
            'Refusing to query [%s] with no tenant bound and platform mode closed. '
            .'Bind one with TenantContext::runFor(), open an audited platform read with '
            .'TenantContext::asPlatform(), or declare a deliberate system query with '
            .'%s::withoutTenantScope().',
            $model,
            class_basename($model),
        ));
    }

    /**
     * @param  class-string  $model
     */
    public static function forCreate(string $model): self
    {
        return new self(sprintf(
            'Refusing to create [%s] without a tenant_id: no tenant is bound, so the row '
            .'cannot be attributed to one. Bind a tenant with TenantContext::runFor() or pass '
            .'tenant_id explicitly.',
            $model,
        ));
    }
}
