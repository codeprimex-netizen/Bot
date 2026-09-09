<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * A plan's `features` or `limits` JSON does not have the shape the platform
 * requires, so the plan cannot be interpreted (Req 25.1 / D2).
 *
 * This is deliberately a hard failure rather than a lenient fallback. The two
 * JSON columns are the *only* thing standing between a tenant and a feature it
 * has not paid for, so a map the platform cannot read must never be interpreted
 * as "everything allowed" or quietly coerced into something plausible — it is
 * refused, loudly, at the point of use:
 *
 *  - on **write**, `PlanObserver::saving()` validates before the row is
 *    persisted, so a bad plan never reaches the database (an admin editing a
 *    plan sees the error, not the tenants who bought it);
 *  - on **read**, `Plan::featureFlags()` / `Plan::quotaLimits()` validate again,
 *    so a row hand-edited by SQL, restored from an older schema, or written by a
 *    future migration still fails closed rather than granting access.
 *
 * Unhandled, it surfaces as a 500: it signals corrupt platform data, not a
 * tenant mistake, and there is no user-facing remedy to offer.
 */
final class MalformedPlanException extends RuntimeException
{
    /**
     * @param  string  $context  which plan is at fault, e.g. `plan "growth"`
     * @param  string  $reason  what is wrong, in terms an admin can act on
     */
    public static function features(string $context, string $reason): self
    {
        return new self(sprintf(
            'The `features` map of %s is malformed: %s. Feature flags must be a JSON object of '
            .'"feature": true|false pairs; a plan that cannot be read grants nothing.',
            $context,
            $reason,
        ));
    }

    /**
     * @param  string  $context  which plan is at fault, e.g. `plan "growth"`
     * @param  string  $reason  what is wrong, in terms an admin can act on
     */
    public static function limits(string $context, string $reason): self
    {
        return new self(sprintf(
            'The `limits` map of %s is malformed: %s. Quota limits must be a JSON object keyed by '
            .'QuotaKind whose values are non-negative integers, or null for unlimited.',
            $context,
            $reason,
        ));
    }
}
