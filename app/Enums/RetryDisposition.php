<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a caller *does* with a failure — the three, and only three, endings Req 31.1
 * allows (design.md § Error Handling, "Retry / backoff matrix").
 *
 * Req 31.1: *"THE system SHALL never drop an outbound job on quota/rate limits; it SHALL
 * defer (release) or block explicitly."* A disposition is that sentence made
 * enumerable — there is no fourth case, and in particular no "swallow it".
 *
 * | Disposition | Queue action | Counts against `$tries` | Ends the work |
 * |---|---|---|---|
 * | `RETRY` | `$job->release($decision->delaySeconds())` | yes | no |
 * | `DEFER` | `release()` with an **authoritative** wait, and/or `QuotaParkingLot::park()` | no (it is not a fault) | no |
 * | `FAIL_FAST` | `$job->fail($e)` — recorded, surfaced, never silently discarded | n/a | yes, explicitly |
 *
 * ## Why `RETRY` and `DEFER` are not the same thing
 *
 * Both put the job back on the queue, so the *mechanism* is shared — but they are
 * different events and must be reported differently:
 *
 * - a **retry** is a failure being re-attempted. The wait is invented by
 *   `RetryPolicy` (exponential + full jitter), it is budgeted (`maxAttempts`), and
 *   exhausting the budget is an error.
 * - a **defer** is not a failure at all: something *told us when to come back* — the
 *   quota period rolls at a known time (`QuotaVerdict::secondsUntilPeriodReset()`), or a
 *   provider sent `Retry-After`. The wait is honoured rather than computed, and a defer
 *   that keeps deferring is normal operation (a campaign parked for three weeks until the
 *   monthly allowance resets), which is why its budget can be unlimited.
 *
 * Collapsing the two would force one of two bugs: either a legitimate month-long quota
 * defer burns a 5-attempt retry budget and the work is dropped (violating Req 31.1), or a
 * genuinely broken dependency is retried for ever and never surfaces.
 *
 * ## Why `FAIL_FAST` is not a drop
 *
 * `keepsWork()` is `false` for `FAIL_FAST`, exactly as `QuotaOutcome::Block` is — but
 * "the work ends here" is the *opposite* of "nobody hears about it". A fail-fast
 * disposition means the caller must fail the job explicitly so the failure lands in
 * `failed_jobs` and the tenant's error dashboard (Req 7.1, task 29.2). Waiting is what is
 * pointless — an unroutable number does not become routable, a plan does not grow a
 * feature, a suspended tenant does not un-suspend on a timer (task 1.3: a suspension is a
 * **block**, never a defer).
 */
enum RetryDisposition: string
{
    /** Re-attempt after a computed, jittered backoff, within a budget. */
    case Retry = 'RETRY';

    /** Come back at a time somebody else named; not an error, not budgeted. */
    case Defer = 'DEFER';

    /** Stop now, loudly: no wait changes the answer. */
    case FailFast = 'FAIL_FAST';

    /**
     * Whether the unit of work survives this disposition.
     *
     * Mirrors `QuotaOutcome::keepsWork()` deliberately: the two enums answer the same
     * question about the same job, one from the quota's point of view and one from the
     * failure's.
     */
    public function keepsWork(): bool
    {
        return $this !== self::FailFast;
    }

    /**
     * Whether the wait comes from outside (a `Retry-After`, a period reset) rather than
     * from the backoff formula.
     */
    public function isDeferred(): bool
    {
        return $this === self::Defer;
    }

    /**
     * Whether the caller must fail the job explicitly instead of releasing it.
     */
    public function isFailFast(): bool
    {
        return $this === self::FailFast;
    }

    /**
     * Human-readable label for panels, logs, and operator views.
     */
    public function label(): string
    {
        return match ($this) {
            self::Retry => 'Retry with backoff',
            self::Defer => 'Defer until it can succeed',
            self::FailFast => 'Fail immediately',
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
