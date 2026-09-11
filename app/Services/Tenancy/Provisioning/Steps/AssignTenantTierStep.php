<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning\Steps;

use App\Models\TenantTierAssignment;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use App\Services\Tenancy\TierResolver;

/**
 * Step 4 — the tenant's place on the isolation ladder, when the spec asks for one
 * (Req 1.6 / A1).
 *
 * ## Most tenants get no row, on purpose
 *
 * `TierResolver` answers `tierOf()` and `laneWeight()` for a tenant with no
 * `tenant_tiers` row from `wa.tenancy.tiers`, which is the whole point of the tier
 * being a config flip. Writing a row that merely restates the configured default would
 * undo that: flipping `wa.tenancy.tiers.default` would then move only the tenants
 * provisioned *after* the flip, and every earlier one would stay pinned to the old
 * value by a row nobody knew was there. So `TenantProvisioningSpec::needsTierRow()`
 * writes a row only for a non-default tier, or when a weight, region or shard is
 * pinned — and this step is a no-op otherwise.
 *
 * ## The compensation is a cache, not a row
 *
 * The row is inside `provision()`'s transaction, so the database undoes itself. What it
 * cannot undo is `TenantTierAssignment::booted()`'s side effect: saving the row calls
 * `TierResolver::forget()`, and anything that resolved a tier between that save and the
 * failure would have re-cached the answer derived from a row that is about to vanish.
 * `rollback()` therefore forgets again — cheap, idempotent, and the difference between
 * a rolled-back provisioning and a tenant that a cached tier claims is on a dedicated
 * shard.
 */
final class AssignTenantTierStep implements TenantProvisioningStep
{
    public function __construct(private readonly TierResolver $tiers) {}

    public function name(): string
    {
        return 'tier.assignment';
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $spec = $context->spec;

        if (! $spec->needsTierRow()) {
            // Recorded even when absent: "this tenant is on the default tier" is the fact
            // an operator reading the audit entry needs, and it is not the same fact as
            // "the tier step did not run".
            $context->record('tier', $spec->tier?->value);

            return;
        }

        $assignment = new TenantTierAssignment;
        // Explicit `tenant_id`: `BelongsToTenant` would otherwise try to inherit one from
        // `TenantContext`, and provisioning binds no tenant.
        $assignment->forceFill([
            'tenant_id' => $context->tenant()->id,
            'tier' => $spec->tier,
            'lane_weight' => $spec->laneWeight,
            'data_region' => $spec->dataRegion,
            'shard_key' => $spec->shardKey,
        ]);
        $assignment->save();

        $context->record('tier', $spec->tier?->value);
        $context->record('tier_row', [
            'lane_weight' => $spec->laneWeight,
            'data_region' => $spec->dataRegion,
            'shard_key' => $spec->shardKey,
        ]);
    }

    public function rollback(TenantProvisioningContext $context): void
    {
        if ($context->hasTenant()) {
            $this->tiers->forget($context->tenant());
        }
    }
}
