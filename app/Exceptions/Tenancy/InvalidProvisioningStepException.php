<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use LogicException;

/**
 * `wa.tenancy.provisioning.steps` does not describe a runnable provisioning pipeline
 * (Req 1.8 / A1).
 *
 * A deployment error, raised when the registry is first asked for its steps — before
 * any tenant row is written.
 *
 * ## Why this is fatal and not "skip the bad entry"
 *
 * `wa.tenancy.resolvers` deliberately *skips* an entry it cannot resolve: a missing
 * resolver means one fewer door, and the request simply resolves no tenant, which
 * fails closed. Provisioning is the opposite shape. Every step in the list is part of
 * the atomic unit Req 1.8 names, so skipping one produces a tenant that is created
 * and *incomplete* — no seed plan, or no DEK, or no storage prefix — and nothing
 * downstream will ever notice, because each of those absences looks exactly like a
 * legitimate "not yet". A typo in this list must therefore stop provisioning, not
 * quietly change what provisioning means.
 */
final class InvalidProvisioningStepException extends LogicException
{
    public static function notAClass(mixed $entry): self
    {
        return new self(sprintf(
            'wa.tenancy.provisioning.steps contains [%s], which is not a class name. '
            .'Every entry must be a class implementing %s.',
            get_debug_type($entry),
            TenantProvisioningStep::class,
        ));
    }

    public static function missingClass(string $class): self
    {
        return new self(sprintf(
            'wa.tenancy.provisioning.steps names class [%s], which does not exist.',
            $class,
        ));
    }

    public static function notAStep(string $class): self
    {
        return new self(sprintf(
            'wa.tenancy.provisioning.steps names [%s], which does not implement %s.',
            $class,
            TenantProvisioningStep::class,
        ));
    }

    public static function duplicated(string $name): self
    {
        return new self(sprintf(
            'wa.tenancy.provisioning.steps registers two steps named [%s]. Step names are '
            .'the audit trail\'s record of what provisioning did, so they must be unique.',
            $name,
        ));
    }

    public static function emptyPipeline(): self
    {
        return new self(
            'wa.tenancy.provisioning.steps is empty. Provisioning would create nothing at '
            .'all — not even the tenant record — so an empty pipeline is refused rather '
            .'than run.'
        );
    }
}
