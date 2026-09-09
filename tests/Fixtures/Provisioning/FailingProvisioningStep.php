<?php

declare(strict_types=1);

namespace Tests\Fixtures\Provisioning;

use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use RuntimeException;

/**
 * A step that always throws — how the tests provoke the rollback path Req 1.8's
 * "atomically" depends on.
 *
 * Appended *after* the real pipeline, so every production step has genuinely run (row
 * written, plan associated, DEK sealed, directory created, audit entry appended) before
 * the failure. That is what makes the assertions afterwards meaningful: they check that
 * real effects were really undone, not that nothing ever happened.
 */
final class FailingProvisioningStep implements TenantProvisioningStep
{
    public const string NAME = 'test.failing';

    public const string MESSAGE = 'provisioning step failed on purpose';

    public function name(): string
    {
        return self::NAME;
    }

    public function apply(TenantProvisioningContext $context): void
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function rollback(TenantProvisioningContext $context): void
    {
        ProvisioningSpy::recordRollback(self::NAME);
    }
}
