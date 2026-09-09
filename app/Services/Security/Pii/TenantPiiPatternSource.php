<?php

declare(strict_types=1);

namespace App\Services\Security\Pii;

use App\Models\Tenant;
use App\Support\Pii\CompiledPiiPattern;

/**
 * Where a tenant's configured redaction patterns come from (Req 32.2 / NFR3 —
 * "configurable tenant patterns").
 *
 * A seam rather than a class because the patterns have two homes over the life of
 * the build: operator configuration today, and a tenant settings screen later. Both
 * must hand the redactor the same thing — patterns that have already been through
 * `TenantPatternCompiler` — so that "an operator regex is validated and bounded
 * before it runs" is a property of the type, not of whoever remembered to call the
 * validator.
 */
interface TenantPiiPatternSource
{
    /**
     * The validated patterns for a tenant, or the platform-wide ones when no tenant
     * is bound.
     *
     * Never throws: a tenant whose patterns are all invalid gets an empty list and
     * the built-in detectors, because refusing to redact is the one outcome a bad
     * line of configuration must not be able to cause.
     *
     * @return list<CompiledPiiPattern>
     */
    public function patternsFor(?Tenant $tenant): array;
}
