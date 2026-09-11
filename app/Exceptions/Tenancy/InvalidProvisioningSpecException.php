<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use InvalidArgumentException;

/**
 * The `array $spec` handed to `TenantLifecycle::provision()` cannot be turned into a
 * tenant (Req 1.8 / A1).
 *
 * Provisioning is the one write in the platform with **no tenant to fall back on**:
 * there is no existing row to merge into, no context to inherit a timezone from, and
 * no later screen that will notice a nonsense value before it becomes a customer
 * account. So the spec is validated up front, in full, and rejected as a whole —
 * `App\Services\Tenancy\Provisioning\TenantProvisioningSpec` never repairs a value
 * and never silently drops one.
 *
 * ## Why an unknown key is an error
 *
 * `unknownKeys()` exists because the spec is an array literal at every call site
 * (registration, the admin panel, a seeder, tinker). A misspelt `timzone` that was
 * *ignored* would provision a tenant on the wrong clock and look like it worked;
 * a misspelt `plan_slag` would silently seed the default plan instead of the one the
 * operator picked. Both are indistinguishable from success afterwards, which is
 * exactly the class of bug an allow-listed key set removes.
 *
 * Extends `InvalidArgumentException`: it is a programming/input error at the call
 * site, not a runtime condition to retry, so it stays in the same catch bucket as the
 * empty-reason guard on `transitionTo()`.
 */
final class InvalidProvisioningSpecException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $keys
     * @param  list<string>  $allowed
     */
    public static function unknownKeys(array $keys, array $allowed): self
    {
        return new self(sprintf(
            'Unknown tenant provisioning spec key(s): %s. Allowed keys are: %s. '
            .'Unknown keys are refused rather than ignored — a typo that is ignored '
            .'provisions a tenant that looks correct and is not.',
            implode(', ', $keys),
            implode(', ', $allowed),
        ));
    }

    public static function missingName(): self
    {
        return new self(
            'A tenant provisioning spec requires a non-empty "name" — it is the account '
            .'label every panel and invoice shows, and the slug is derived from it when '
            .'none is given.'
        );
    }

    public static function invalidLabel(string $key, string $value, string $reason): self
    {
        return new self(sprintf(
            'Tenant provisioning spec "%s" [%s] is not usable as a host label: %s. '
            .'It becomes `{label}.{apex}`, so it must be a valid DNS label (a-z, 0-9, '
            .'inner hyphens, 1-63 characters) and must not be a reserved platform name.',
            $key,
            $value,
            $reason,
        ));
    }

    public static function invalidTimezone(string $timezone): self
    {
        return new self(sprintf(
            'Tenant provisioning spec "timezone" [%s] is not a known IANA timezone '
            .'identifier. Every scheduled send, quiet-hours window and report period is '
            .'computed in the tenant\'s zone, so an unknown one is refused rather than '
            .'defaulted to UTC behind the operator\'s back.',
            $timezone,
        ));
    }

    public static function invalidLocale(string $locale): self
    {
        return new self(sprintf(
            'Tenant provisioning spec "locale" [%s] is not a well-formed locale tag '
            .'(e.g. "en", "en_GB", "pt-BR", max 10 characters — the column\'s width).',
            $locale,
        ));
    }

    public static function invalidTrialWindow(string $detail): self
    {
        return new self(sprintf(
            'Tenant provisioning spec has an unusable trial window: %s. Pass either '
            .'"trial_days" (a non-negative integer) or "trial_ends_at" (a date), never '
            .'both — with neither, `wa.tenancy.trial_days` is used.',
            $detail,
        ));
    }

    public static function invalidTier(string $value): self
    {
        return new self(sprintf(
            'Tenant provisioning spec "tier" [%s] is not one of the isolation tiers. '
            .'Use an App\\Enums\\TenantTier case or its value.',
            $value,
        ));
    }

    /**
     * @param  list<string>  $keys
     */
    public static function tierDetailsWithoutTier(array $keys): self
    {
        return new self(sprintf(
            'Tenant provisioning spec names %s without naming a "tier". Those values '
            .'live on the tenant\'s `tenant_tiers` row, and that row is only written '
            .'when a tier is asked for — silently creating one would hide the tier '
            .'decision from the operator who did not make it.',
            implode(', ', array_map(static fn (string $key): string => '"'.$key.'"', $keys)),
        ));
    }

    public static function invalidLaneWeight(string $value): self
    {
        return new self(sprintf(
            'Tenant provisioning spec "lane_weight" [%s] must be an integer of at least '
            .'1. A weight can be lowered but never zeroed: zero would starve the tenant '
            .'in the weighted-fair dispatch loop (Req 1.7 / A1).',
            $value,
        ));
    }

    public static function invalidRole(string $value): self
    {
        return new self(sprintf(
            'Tenant provisioning spec "owner_role" [%s] is not an App\\Enums\\TenantRole.',
            $value,
        ));
    }

    public static function unknownOwner(string $userId): self
    {
        return new self(sprintf(
            'Tenant provisioning spec "owner" names user [%s], which does not exist. '
            .'The owner membership is the first row that lets a human reach the new '
            .'tenant, so provisioning refuses to create a tenant nobody can open.',
            $userId,
        ));
    }
}
