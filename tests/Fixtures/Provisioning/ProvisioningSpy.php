<?php

declare(strict_types=1);

namespace Tests\Fixtures\Provisioning;

use App\Services\Tenancy\Provisioning\TenantProvisioningStep;

/**
 * Shared state for the provisioning-pipeline test doubles, plus the helpers that append
 * them to the configured pipeline.
 *
 * Test doubles only, and only reachable from `tests/` — the production pipeline is
 * `wa.tenancy.provisioning.steps`, and a step is only ever *appended* here by a test
 * that then asserts the failure it provoked.
 */
final class ProvisioningSpy
{
    /**
     * Step names whose `rollback()` ran, in call order — so a test can assert that
     * compensation happens in reverse application order.
     *
     * @var list<string>
     */
    private static array $rollbacks = [];

    public static function reset(): void
    {
        self::$rollbacks = [];
    }

    public static function recordRollback(string $step): void
    {
        self::$rollbacks[] = $step;
    }

    /**
     * @return list<string>
     */
    public static function rollbacks(): array
    {
        return self::$rollbacks;
    }

    /**
     * Append doubles to the real pipeline, so the production steps all run for real and
     * the failure happens after them.
     *
     * @param  list<class-string<TenantProvisioningStep>>  $steps
     */
    public static function append(array $steps): void
    {
        $configured = config('wa.tenancy.provisioning.steps');

        config()->set('wa.tenancy.provisioning.steps', [
            ...(is_array($configured) ? $configured : []),
            ...$steps,
        ]);
    }
}
