<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * *How* the wait before the next attempt is arrived at — the "Backoff" column of the
 * design's retry matrix (design.md § Error Handling).
 *
 * The matrix names four shapes, and they differ in **who decides the delay**:
 *
 * | Shape | Delay comes from | Matrix rows |
 * |---|---|---|
 * | `EXPONENTIAL_FULL_JITTER` | `RetryPolicy` — `rand(0, min(cap, base·2^n))` | transient network / 5xx, bridge, timeout, media, unknown |
 * | `RETRY_AFTER_ELSE_EXPONENTIAL` | the provider's `Retry-After`, else the formula | rate-limited (429) |
 * | `PERIOD_RESET` | the quota bucket's own reset clock | quota exceeded |
 * | `NONE` | nothing — there is no next attempt | validation, auth, permission, not-on-WhatsApp, circuit open |
 *
 * ## Why full jitter, and not a fixed multiplier
 *
 * `delay = random(0, min(cap, base · 2^attempt))` — the delay is drawn uniformly from
 * the whole window, not set to the top of it.
 *
 * The reason is correlation, not politeness. A dependency going down takes out every
 * in-flight job at once, so N workers fail at the same instant. With a deterministic
 * backoff they all wake at the same instant too, and keep doing so on every subsequent
 * attempt: the recovering dependency is hit by N simultaneous requests, falls over again,
 * and the platform has built itself a synchronised retry storm — a thundering herd that
 * gets *worse* as the fleet grows, and that can hold a service down long after the
 * original fault cleared. Drawing each delay independently from `[0, window]` spreads the
 * same number of retries evenly over the window, so the recovering dependency sees a
 * trickle it can absorb, and the workers permanently decorrelate after the first attempt.
 *
 * "Full" is the interval starting at **0**, which is what makes the decorrelation
 * immediate; the alternatives (equal jitter, decorrelated jitter) keep a floor and so keep
 * some of the correlation. The cost of drawing from 0 is that an unlucky retry comes back
 * almost at once — which is why the *number* of attempts is budgeted per error class, and
 * why a class that must not hammer (a 429) is given a larger `base_ms` rather than a
 * floor on the jitter: raising the base widens the window instead of re-correlating its
 * lower edge.
 *
 * The window grows exponentially so that a fault lasting minutes costs a handful of
 * attempts rather than hundreds, and is clamped at `cap_ms` so that a long outage settles
 * into a steady re-check interval instead of drifting into hours.
 *
 * ## Why an authoritative wait is honoured verbatim
 *
 * `RETRY_AFTER_ELSE_EXPONENTIAL` and `PERIOD_RESET` are the two shapes where somebody
 * *knows* the answer: a provider that sent `Retry-After: 47`, or a quota bucket that rolls
 * in 47 seconds. Jittering that value down would guarantee the next attempt is refused
 * too, and capping it at 30s would guarantee a month-long quota defer re-checks 86 000
 * times. So the cap and the jitter govern *computed* backoff only; a wait somebody named
 * is passed through (floored at one second — a zero-second release is a spin, not a wait).
 */
enum BackoffShape: string
{
    /** `rand(0, min(cap, base·2^attempt))`. */
    case ExponentialFullJitter = 'EXPONENTIAL_FULL_JITTER';

    /** The provider's `Retry-After` when it sent one, otherwise the jittered formula. */
    case RetryAfterElseExponential = 'RETRY_AFTER_ELSE_EXPONENTIAL';

    /** The quota period's own reset clock; a bounded re-check when it is unknown. */
    case PeriodReset = 'PERIOD_RESET';

    /** No wait, because there is no next attempt. */
    case None = 'NONE';

    /**
     * Whether the delay is drawn randomly from an exponentially growing window.
     */
    public function usesJitter(): bool
    {
        return match ($this) {
            self::ExponentialFullJitter, self::RetryAfterElseExponential => true,
            self::PeriodReset, self::None => false,
        };
    }

    /**
     * Whether an externally supplied wait (`Retry-After`, period reset) outranks the
     * computed one.
     */
    public function honoursRetryAfter(): bool
    {
        return match ($this) {
            self::RetryAfterElseExponential, self::PeriodReset => true,
            self::ExponentialFullJitter, self::None => false,
        };
    }

    /**
     * Human-readable label for panels, logs, and operator views.
     */
    public function label(): string
    {
        return match ($this) {
            self::ExponentialFullJitter => 'Exponential backoff with full jitter',
            self::RetryAfterElseExponential => 'Honour Retry-After, else exponential + jitter',
            self::PeriodReset => 'Wait for the quota period to reset',
            self::None => 'No backoff',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
