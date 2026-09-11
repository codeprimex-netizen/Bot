<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\QuotaKind;
use App\Enums\QuotaOutcome;
use App\Enums\QuotaReason;

/**
 * The answer `QuotaGuard::verdict()` gives: may this tenant spend `units` of this
 * quota right now, why, and — when the answer is "not yet" — for how long
 * (Req 3.4 / A3; design.md Algorithm 3).
 *
 * ```php
 * $verdict = $quota->verdict($tenant, QuotaKind::MessagesMonthly);
 *
 * if ($verdict->isDeferred()) {
 *     $job->release($verdict->secondsUntilPeriodReset());   // Algorithm 3's defer branch
 *     return;
 * }
 *
 * if ($verdict->isBlocked()) {
 *     throw QuotaExceededException::from($tenant, $verdict); // never a silent drop
 * }
 * ```
 *
 * ## Side-effect free, and cheap enough to ask often
 *
 * A verdict is a *reading*, not a reservation: nothing is written, nothing is held, and
 * asking twice gives the same answer. That is what lets the dispatch eligibility gate
 * (`QuotaDispatchEligibility`) ask speculatively about every candidate tenant in every
 * window without spending anybody's allowance — and it is why a verdict can go stale
 * between the ask and the send. The authoritative decrement is
 * `QuotaGuard::consume()`, which re-checks under a lock.
 *
 * ## Reading the numbers
 *
 * `$used` / `$limit` are the bucket as it stood when the question was asked, so a panel
 * can render "1 240 of 5 000 this month" from the same object the gate used.
 * `$limit === null` means the plan declares the kind unlimited; `remaining()` then
 * returns `self::UNLIMITED` rather than a real count, so callers can compare and format
 * instead of dividing by an infinity.
 *
 * A refusal that never looked at the counter (no plan, unreadable plan) reports
 * `used = 0`, `limit = 0` — there is no allowance to be part-way through.
 */
final readonly class QuotaVerdict
{
    /**
     * The stand-in for "no ceiling", used in two places:
     *
     * - what `remaining()` and `QuotaGuard::remaining()` return for an unlimited kind,
     *   because the design fixes their return type at `int`;
     * - what `QuotaGuard` stamps in `tenant_usage.limit` for an unlimited kind, because
     *   that column is a non-null unsigned bigint and cannot hold `null`.
     *
     * Anything rendering a limit must therefore compare against this constant (or ask
     * `isUnlimited()`) rather than printing the raw number.
     */
    public const int UNLIMITED = PHP_INT_MAX;

    /**
     * @param  string  $periodKey  the `tenant_usage` bucket the reading came from
     * @param  int  $units  what was asked for
     * @param  int  $used  the bucket's counter when asked
     * @param  int|null  $limit  the plan's ceiling; null = unlimited
     * @param  int  $resetSeconds  seconds until this bucket rolls over; 0 when it never does
     */
    private function __construct(
        public QuotaKind $kind,
        public QuotaReason $reason,
        public string $periodKey,
        public int $units,
        public int $used,
        public ?int $limit,
        private int $resetSeconds,
    ) {}

    /**
     * Build the verdict a reason implies — the only constructor.
     *
     * There is deliberately no way to pair an outcome with a reason by hand:
     * `QuotaReason::outcome()` decides, so "exhausted period" can never accidentally be
     * built as a block, nor "not priced" as a defer.
     */
    public static function for(
        QuotaKind $kind,
        QuotaReason $reason,
        string $periodKey,
        int $units,
        int $used,
        ?int $limit,
        int $resetSeconds = 0,
    ): self {
        return new self(
            $kind,
            $reason,
            $periodKey,
            max(0, $units),
            max(0, $used),
            $limit === null ? null : max(0, $limit),
            max(0, $resetSeconds),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | What the caller does next
    |--------------------------------------------------------------------------
    */

    public function outcome(): QuotaOutcome
    {
        return $this->reason->outcome();
    }

    public function isAllowed(): bool
    {
        return $this->outcome()->isAllowed();
    }

    public function isDeferred(): bool
    {
        return $this->outcome()->isDeferred();
    }

    public function isBlocked(): bool
    {
        return $this->outcome()->isBlocked();
    }

    /**
     * How long to wait before the request would fit — the argument Algorithm 3 passes
     * to `release()`.
     *
     * Always **at least 1** for a deferred verdict, so a job released at the very
     * instant a period rolls cannot busy-loop on a zero-second delay. `0` for anything
     * that is not a defer: there is nothing to wait for.
     */
    public function secondsUntilPeriodReset(): int
    {
        if (! $this->isDeferred()) {
            return 0;
        }

        return max(1, $this->resetSeconds);
    }

    /*
    |--------------------------------------------------------------------------
    | The numbers
    |--------------------------------------------------------------------------
    */

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    /**
     * Allowance left in this bucket, never negative — `self::UNLIMITED` when the plan
     * sets no ceiling.
     */
    public function remaining(): int
    {
        if ($this->limit === null) {
            return self::UNLIMITED;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * How many of the requested units do not fit. `0` whenever the verdict allows.
     */
    public function shortfall(): int
    {
        if ($this->isAllowed()) {
            return 0;
        }

        return max(0, $this->units - $this->remaining());
    }

    /*
    |--------------------------------------------------------------------------
    | Explaining it
    |--------------------------------------------------------------------------
    */

    /**
     * One sentence a tenant may be shown.
     *
     * Req 3.4 requires the tenant to be *notified* when a quota stops a send, so the
     * refusal has to carry something sayable — the quota's own label, the numbers, and
     * what happens next. Nothing here is sensitive: it is the tenant's own plan and its
     * own usage.
     */
    public function explanation(): string
    {
        $label = $this->kind->label();

        return match ($this->reason) {
            QuotaReason::WithinAllowance => sprintf('%s: %s of %d used.', $label, $this->formatUsed(), $this->limit ?? 0),
            QuotaReason::Unlimited => sprintf('%s: unlimited on this plan.', $label),
            QuotaReason::Overage => sprintf('%s: allowance of %d spent — further use is billed as overage.', $label, $this->limit ?? 0),
            QuotaReason::PeriodExhausted => sprintf(
                '%s: the allowance of %d for this period is used up. Queued work resumes in about %s.',
                $label,
                $this->limit ?? 0,
                $this->formatReset(),
            ),
            QuotaReason::ExceedsPeriodLimit => sprintf(
                '%s: %d at once is more than the %d this plan allows for a whole period.',
                $label,
                $this->units,
                $this->limit ?? 0,
            ),
            QuotaReason::GaugeAtCapacity => sprintf('%s: %d of %d in use — remove one or upgrade to add more.', $label, $this->used, $this->limit ?? 0),
            QuotaReason::MeteredToZero => sprintf('%s: this plan includes none.', $label),
            QuotaReason::NotPriced => sprintf('%s: this plan does not include it.', $label),
            QuotaReason::NoPlan => sprintf('%s: no active plan, so nothing is allowed yet.', $label),
            QuotaReason::PlanUnreadable => sprintf('%s: the plan could not be read, so the request was refused.', $label),
        };
    }

    /**
     * Log / audit shape — everything the decision was made from, and nothing else.
     *
     * @return array{kind: string, outcome: string, reason: string, period_key: string, units: int, used: int, limit: int|null, remaining: int|null, reset_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'outcome' => $this->outcome()->value,
            'reason' => $this->reason->value,
            'period_key' => $this->periodKey,
            'units' => $this->units,
            'used' => $this->used,
            'limit' => $this->limit,
            'remaining' => $this->limit === null ? null : $this->remaining(),
            'reset_seconds' => $this->secondsUntilPeriodReset(),
        ];
    }

    private function formatUsed(): string
    {
        return (string) $this->used;
    }

    /**
     * The reset delay in the coarsest unit that is still honest — a tenant-facing
     * "in about 3 hours", not "in 10 843 seconds".
     */
    private function formatReset(): string
    {
        $seconds = $this->secondsUntilPeriodReset();

        if ($seconds < 60) {
            return sprintf('%d second%s', $seconds, $seconds === 1 ? '' : 's');
        }

        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);

            return sprintf('%d minute%s', $minutes, $minutes === 1 ? '' : 's');
        }

        if ($seconds < 86_400) {
            $hours = intdiv($seconds, 3600);

            return sprintf('%d hour%s', $hours, $hours === 1 ? '' : 's');
        }

        $days = intdiv($seconds, 86_400);

        return sprintf('%d day%s', $days, $days === 1 ? '' : 's');
    }
}
