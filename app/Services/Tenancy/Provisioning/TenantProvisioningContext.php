<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning;

use App\Models\Tenant;
use LogicException;

/**
 * The state one run of the provisioning pipeline shares between its steps
 * (Req 1.8 / A1).
 *
 * Deliberately the *only* channel between steps. A step reads the validated spec,
 * reads the tenant once it exists, and leaves evidence of what it did — and it cannot
 * reach anything else, which is what keeps the pipeline's order the only coupling
 * between two steps.
 *
 * Mutable, and scoped to a single `provision()` call — never resolved from the
 * container, never reused.
 */
final class TenantProvisioningContext
{
    private ?Tenant $tenant = null;

    /**
     * What each step did, in the order it was recorded — the payload of the
     * `tenant.provisioned` audit entry.
     *
     * @var array<string, mixed>
     */
    private array $outcome = [];

    /**
     * Names of the steps that have been started, in order.
     *
     * "Started", not "finished": a step is marked before it runs, because a step that
     * threw halfway is exactly the one whose compensation must not be skipped.
     *
     * @var list<string>
     */
    private array $applied = [];

    public function __construct(public readonly TenantProvisioningSpec $spec) {}

    /*
    |--------------------------------------------------------------------------
    | The tenant
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant being provisioned.
     *
     * @throws LogicException when called before the record step has created it — a
     *                        pipeline ordering bug, not a runtime condition
     */
    public function tenant(): Tenant
    {
        if ($this->tenant === null) {
            throw new LogicException(
                'No tenant has been created yet in this provisioning run. A step that needs '
                .'the tenant must be registered after CreateTenantRecordStep in '
                .'wa.tenancy.provisioning.steps.'
            );
        }

        return $this->tenant;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Publish the freshly created tenant to the rest of the pipeline.
     *
     * Write-once: a second call would mean two steps each believe they created the
     * tenant, and the compensations of the first would then be pointed at the wrong row.
     */
    public function setTenant(Tenant $tenant): void
    {
        if ($this->tenant !== null) {
            throw new LogicException(
                'The tenant for this provisioning run has already been created; a second '
                .'step must not replace it.'
            );
        }

        $this->tenant = $tenant;
    }

    /*
    |--------------------------------------------------------------------------
    | Evidence
    |--------------------------------------------------------------------------
    */

    /**
     * Record what a step did, for the audit entry.
     *
     * Keep it small and diff-shaped, like any audit payload: the plan's slug rather
     * than the plan, the key's version rather than the key. Values are redacted by the
     * audit layer before storage, so a step must still not record a secret here.
     */
    public function record(string $key, mixed $value): void
    {
        $this->outcome[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function outcome(): array
    {
        return $this->outcome;
    }

    /*
    |--------------------------------------------------------------------------
    | Applied steps
    |--------------------------------------------------------------------------
    */

    public function markApplied(string $step): void
    {
        $this->applied[] = $step;
    }

    /**
     * @return list<string>
     */
    public function appliedSteps(): array
    {
        return $this->applied;
    }
}
