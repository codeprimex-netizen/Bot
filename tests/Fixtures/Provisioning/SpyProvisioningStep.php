<?php

declare(strict_types=1);

namespace Tests\Fixtures\Provisioning;

use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;

/**
 * A step that succeeds and records that it was compensated — the "everything before the
 * failure" half of the rollback test.
 */
final class SpyProvisioningStep implements TenantProvisioningStep
{
    public const string NAME = 'test.spy';

    public function name(): string
    {
        return self::NAME;
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $context->record('spy', true);
    }

    public function rollback(TenantProvisioningContext $context): void
    {
        ProvisioningSpy::recordRollback(self::NAME);
    }
}
