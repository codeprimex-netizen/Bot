<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * *Why* `QuotaGuard` answered the way it did (Req 3.4 / A3).
 *
 * Each reason implies exactly one `QuotaOutcome`, and `outcome()` is that mapping —
 * so a verdict is built from a reason and can never carry a reason and an outcome that
 * disagree. It also means the defer/block rule Req 3.4 asks for is written down **once,
 * as data**, instead of being re-derived at each call site:
 *
 * | Reason | Outcome | Why |
 * |---|---|---|
 * | `WITHIN_ALLOWANCE` | allow | the request fits the remaining allowance |
 * | `UNLIMITED` | allow | the plan declares the kind `null` |
 * | `OVERAGE` | allow | the allowance is spent but the plan explicitly sells overage |
 * | `PERIOD_EXHAUSTED` | **defer** | spent for *this* period; a fresh bucket will fit it |
 * | `EXCEEDS_PERIOD_LIMIT` | block | more units than a whole period grants — no reset helps |
 * | `GAUGE_AT_CAPACITY` | block | a gauge has no period to roll over; deferring would spin for ever |
 * | `METERED_TO_ZERO` | block | the plan prices the kind at a deliberate `0` |
 * | `NOT_PRICED` | block | the plan does not mention the kind at all (`PlanLimits`: absence grants 0) |
 * | `NO_PLAN` | block | the tenant is on no plan — no allowance, never "skip the check" |
 * | `PLAN_UNREADABLE` | block | `plans.limits` could not be interpreted; fail closed |
 *
 * ## The defer/block line
 *
 * A **defer** is a promise that time alone fixes the problem, so it is reserved for
 * exactly one situation: an accruing counter that is full for the current period. Every
 * other refusal is structural — no amount of waiting makes a 5 000-message batch fit a
 * 1 000-message plan, and a gauge (`SESSIONS`, `CONTACTS`, `CAMPAIGNS_CONCURRENT`)
 * shares one standing bucket that never resets, so a deferred gauge request would be
 * re-released for ever. Those block, which surfaces an explanation the tenant can act
 * on (upgrade, delete something, wait for a campaign to finish) instead of a job that
 * quietly bounces.
 *
 * `isTransient()` is that distinction, and it is what the dispatch eligibility gate and
 * task 2.4's period-reset command both key off.
 */
enum QuotaReason: string
{
    case WithinAllowance = 'WITHIN_ALLOWANCE';
    case Unlimited = 'UNLIMITED';
    case Overage = 'OVERAGE';
    case PeriodExhausted = 'PERIOD_EXHAUSTED';
    case ExceedsPeriodLimit = 'EXCEEDS_PERIOD_LIMIT';
    case GaugeAtCapacity = 'GAUGE_AT_CAPACITY';
    case MeteredToZero = 'METERED_TO_ZERO';
    case NotPriced = 'NOT_PRICED';
    case NoPlan = 'NO_PLAN';
    case PlanUnreadable = 'PLAN_UNREADABLE';

    /**
     * The one outcome this reason implies.
     */
    public function outcome(): QuotaOutcome
    {
        return match ($this) {
            self::WithinAllowance, self::Unlimited, self::Overage => QuotaOutcome::Allow,
            self::PeriodExhausted => QuotaOutcome::Defer,
            self::ExceedsPeriodLimit, self::GaugeAtCapacity, self::MeteredToZero,
            self::NotPriced, self::NoPlan, self::PlanUnreadable => QuotaOutcome::Block,
        };
    }

    /**
     * Whether this refusal clears on its own, with no operator or tenant action.
     *
     * Only a full period does. Everything else needs a plan change, a deletion, or a
     * smaller request — which is why only `PERIOD_EXHAUSTED` defers.
     */
    public function isTransient(): bool
    {
        return $this === self::PeriodExhausted;
    }

    /**
     * Whether the refusal is about how the plan is *priced* rather than about how much
     * has been used.
     *
     * The set an upgrade CTA is the right response to: the tenant has not overspent
     * anything, the plan simply grants nothing for this kind.
     */
    public function isPricing(): bool
    {
        return match ($this) {
            self::MeteredToZero, self::NotPriced, self::NoPlan => true,
            default => false,
        };
    }

    /**
     * Human-readable label for panels, logs, and operator views.
     */
    public function label(): string
    {
        return match ($this) {
            self::WithinAllowance => 'Within allowance',
            self::Unlimited => 'Unlimited on this plan',
            self::Overage => 'Allowance spent — billed as overage',
            self::PeriodExhausted => 'Allowance spent for this period',
            self::ExceedsPeriodLimit => 'Larger than the whole period allowance',
            self::GaugeAtCapacity => 'At the limit this plan allows',
            self::MeteredToZero => 'Not included in this plan',
            self::NotPriced => 'Not priced on this plan',
            self::NoPlan => 'No active plan',
            self::PlanUnreadable => 'Plan limits could not be read',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
