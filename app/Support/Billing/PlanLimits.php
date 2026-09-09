<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\QuotaKind;
use App\Exceptions\Billing\MalformedPlanException;

/**
 * The validated `plans.limits` map: the allowance a plan grants for each
 * `QuotaKind` (Req 25.1 / D2).
 *
 * `QuotaGuard` (task 2.3) reads its ceiling from here — it is the only source of
 * the `limit` it stamps on a `tenant_usage` counter row.
 *
 * ## Shape
 *
 * A JSON **object** keyed by `QuotaKind` values, whose values are non-negative
 * integers or `null`:
 *
 * ```json
 * { "MESSAGES_MONTHLY": null, "MESSAGES_DAILY": 20000, "SESSIONS": 10,
 *   "CONTACTS": null, "AI_CREDITS": 50000, "CAMPAIGNS_CONCURRENT": 10 }
 * ```
 *
 * | Input                        | Result                                          |
 * |------------------------------|-------------------------------------------------|
 * | `{"SESSIONS": 3}`            | allowance of 3                                  |
 * | `{"SESSIONS": null}`         | **unlimited**                                   |
 * | `{"SESSIONS": 0}`            | allowance of 0 — the feature is metered to zero  |
 * | key omitted                  | allowance of 0 (see below)                       |
 * | `{"SESSION": 3}`             | **malformed** — an unknown kind is a typo        |
 * | `{"SESSIONS": "3"}`          | **malformed** — a numeric string is ambiguous    |
 * | `{"SESSIONS": -1}`           | **malformed** — a negative ceiling is meaningless|
 *
 * ## Absence grants nothing; only `null` is unlimited
 *
 * `null` means unlimited, so absence must *not*: if an omitted key read as
 * "unlimited", a typo'd or half-filled plan would hand out an infinite allowance,
 * and adding a `QuotaKind` in a later phase would retroactively make every
 * existing plan unlimited for it. An omitted kind therefore grants **0**, and
 * `undeclared()` lets the plan-management UI (task 31.1) surface exactly which
 * kinds an admin still has to price.
 */
final class PlanLimits
{
    /**
     * The allowance of a `QuotaKind` the plan does not mention.
     */
    public const int UNDECLARED_ALLOWANCE = 0;

    /**
     * @param  array<string, int|null>  $limits  keyed by `QuotaKind::value`
     */
    private function __construct(private readonly array $limits) {}

    /**
     * A plan that grants no allowance for anything.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Validate a decoded `plans.limits` value into a usable map.
     *
     * @param  mixed  $raw  the decoded JSON column, straight from the database
     * @param  string  $context  which plan is being read, for the error message
     *
     * @throws MalformedPlanException
     */
    public static function fromRaw(mixed $raw, string $context = 'plan'): self
    {
        if ($raw === null) {
            throw MalformedPlanException::limits($context, 'the column is null — write `{}` for a plan that grants no allowance');
        }

        if (! is_array($raw)) {
            throw MalformedPlanException::limits($context, sprintf('expected a JSON object, got %s', get_debug_type($raw)));
        }

        $limits = [];

        foreach ($raw as $kind => $limit) {
            if (! is_string($kind) || QuotaKind::tryFrom($kind) === null) {
                throw MalformedPlanException::limits($context, sprintf(
                    'key %s is not a quota kind; expected one of %s',
                    json_encode($kind),
                    implode(', ', QuotaKind::values()),
                ));
            }

            if ($limit !== null && ! is_int($limit)) {
                throw MalformedPlanException::limits($context, sprintf(
                    'the limit for %s is %s, but only an integer or null (unlimited) are accepted',
                    $kind,
                    get_debug_type($limit),
                ));
            }

            if (is_int($limit) && $limit < 0) {
                throw MalformedPlanException::limits($context, sprintf(
                    'the limit for %s is %d; a ceiling cannot be negative (use 0 to grant nothing, null for unlimited)',
                    $kind,
                    $limit,
                ));
            }

            $limits[$kind] = $limit;
        }

        return new self($limits);
    }

    /**
     * The allowance for $kind: a non-negative ceiling, or null for unlimited.
     *
     * A kind the plan does not declare returns `UNDECLARED_ALLOWANCE` (0) — never
     * null, so an incomplete plan can never read as unlimited.
     */
    public function for(QuotaKind $kind): ?int
    {
        if (! array_key_exists($kind->value, $this->limits)) {
            return self::UNDECLARED_ALLOWANCE;
        }

        return $this->limits[$kind->value];
    }

    /**
     * Whether $kind is explicitly unlimited (declared as `null`).
     */
    public function isUnlimited(QuotaKind $kind): bool
    {
        return array_key_exists($kind->value, $this->limits)
            && $this->limits[$kind->value] === null;
    }

    /**
     * Whether the plan mentions $kind at all — the difference between a
     * deliberate `0` and an omission.
     */
    public function declares(QuotaKind $kind): bool
    {
        return array_key_exists($kind->value, $this->limits);
    }

    /**
     * The whole map, keyed by `QuotaKind::value`.
     *
     * @return array<string, int|null>
     */
    public function all(): array
    {
        return $this->limits;
    }

    /**
     * Quota kinds this plan has not priced yet — an admin-facing to-do list, and
     * the reason absence is not silently unlimited.
     *
     * @return list<QuotaKind>
     */
    public function undeclared(): array
    {
        return array_values(array_filter(
            QuotaKind::cases(),
            fn (QuotaKind $kind): bool => ! $this->declares($kind),
        ));
    }
}
