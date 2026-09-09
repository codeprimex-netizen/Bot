<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning\Steps;

use App\Services\Audit\AuditService;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;

/**
 * Step 7 — the `tenant.provisioned` audit entry (Req 24.2 / D1).
 *
 * Last, so the payload can report what every other step actually did rather than what
 * the pipeline intended to do; and **inside the transaction**, exactly like
 * `AuditedTenantLifecycle`'s status transitions, so there is no reachable state where a
 * tenant exists and nothing says who created it or what it was created with.
 *
 * ## The chain is named, not inferred
 *
 * `tenant: $tenant` is passed explicitly. Provisioning runs with no tenant bound (and
 * sometimes from platform mode or another tenant's session, when an admin creates an
 * account), so an inferred chain would put a new tenant's first event on the platform
 * chain — away from the history it belongs to. This mirrors the reasoning in
 * `AuditedTenantLifecycle`: every lifecycle entry lands on the subject tenant's chain.
 *
 * ## What the payload is for
 *
 * `steps` is the part worth explaining. Provisioning is an ordered, configurable
 * pipeline (`wa.tenancy.provisioning.steps`), which means the answer to "what does a
 * provisioned tenant have?" is a deployment-time decision that changes as phases land.
 * Recording the step names makes that answer *per tenant* and after the fact: a tenant
 * created before task 10.1 registers its wallet step has a first audit row that proves
 * it never got one, which is the difference between a backfill that can be targeted and
 * one that has to guess.
 */
final class RecordProvisioningAuditStep implements TenantProvisioningStep
{
    public function __construct(private readonly AuditService $audit) {}

    public function name(): string
    {
        return 'audit.entry';
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $tenant = $context->tenant();

        $this->audit->write(
            'tenant.provisioned',
            [
                ...$context->outcome(),
                'steps' => $context->appliedSteps(),
            ],
            $tenant,
            tenant: $tenant,
        );
    }

    /**
     * Nothing to compensate: the entry is written inside `provision()`'s transaction, so a
     * rolled-back provisioning leaves no entry — which is correct. An audit row is
     * evidence of something that happened, and a provisioning that was undone did not.
     */
    public function rollback(TenantProvisioningContext $context): void {}
}
