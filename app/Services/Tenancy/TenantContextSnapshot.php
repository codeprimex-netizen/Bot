<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;

/**
 * An immutable capture of the whole tenant context at one instant.
 *
 * Used wherever a scope has to be *suspended and put back exactly as it was*:
 * `TenantContext::runFor()`, `asPlatform()`, and the queue worker boundary
 * (a job must neither inherit nor leak a tenant).
 */
final readonly class TenantContextSnapshot
{
    /**
     * @param  list<array{reason: string, startedAt: float}>  $platformFrames  open `actingAsPlatform()` frames, outermost first
     */
    public function __construct(
        public ?Tenant $tenant,
        public TenantResolutionSource $source,
        public array $platformFrames,
    ) {}

    /**
     * A snapshot of the empty (global / unbound) context.
     */
    public static function empty(): self
    {
        return new self(null, TenantResolutionSource::None, []);
    }
}
