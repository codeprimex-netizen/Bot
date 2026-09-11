<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\ErrorClass;
use App\Enums\RetryDisposition;

/**
 * The answer `RetryPolicy::decide()` gives: try again or not, how long to wait, and why —
 * an object a queued job or the outbox relay can act on without re-deriving anything
 * (Req 31.1 / NFR2; Req 7.1 / A7).
 *
 * ```php
 * $decision = $policy->decide($e, $this->attempts());
 *
 * if ($decision->shouldRetry) {
 *     $this->release($decision->delaySeconds());   // never a drop: the work comes back
 *     return;
 * }
 *
 * $this->fail($e);   // explicit, recorded, surfaced — also never a drop
 * ```
 *
 * ## Two endings, both explicit — Req 31.1 in one object
 *
 * Req 31.1: *"never drop an outbound job on quota/rate limits; defer (release) or block
 * explicitly."* A decision therefore has exactly two shapes and no third:
 *
 * - `shouldRetry === true` — the work is kept. `delayMs` is how long to wait, and it is
 *   never 0 for a defer (a zero-second release is a spin).
 * - `shouldRetry === false` — the work stops **here and loudly**. `mustFailExplicitly()`
 *   is `true`, `reason` says which of the three ways it ended (budget exhausted, class
 *   never retryable, or a quota block with no reset coming), and the caller is required to
 *   fail the job so the failure reaches `failed_jobs` and the tenant's error dashboard.
 *
 * There is deliberately no "ignore" and no way to build a decision that neither retries
 * nor gives up: `catch (Throwable) { return; }` is the bug this class exists to make
 * unavailable, because a swallowed send is indistinguishable afterwards from a send that
 * was never asked for.
 *
 * ## `reason` is for humans and for tests
 *
 * The reason constants are stable strings, so a log line, an error-dashboard row, and an
 * assertion can all name the same outcome. `toArray()` emits them under the keys of the
 * design's structured logging schema (`err_class`, `outcome`), so a caller logs a decision
 * without inventing a field name.
 */
final readonly class RetryDecision
{
    /** Retrying after a computed, jittered wait. */
    public const string REASON_BACKOFF = 'backoff';

    /** Deferring for a wait a provider named (`Retry-After`). */
    public const string REASON_RETRY_AFTER = 'retry_after';

    /** Deferring until a quota period rolls over. */
    public const string REASON_PERIOD_RESET = 'period_reset';

    /** Give-up: the class's attempt budget is spent. */
    public const string REASON_ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    /** Give-up: nothing about this class of failure improves with waiting. */
    public const string REASON_NOT_RETRYABLE = 'not_retryable';

    /**
     * Give-up: the failure carries its own "no reset is coming" — a non-deferrable
     * `QuotaExceededException` (not priced, metered to zero, larger than a whole period,
     * a gauge at capacity). Req 31.1's *block* half.
     */
    public const string REASON_BLOCKED = 'blocked';

    /**
     * @param  int  $attempt  the attempt that just failed (1-based, as `attempts()` reports)
     * @param  int|null  $maxAttempts  the class's budget; null when it waits as long as it must
     * @param  int  $delayMs  how long to wait; always 0 when not retrying
     */
    private function __construct(
        public bool $shouldRetry,
        public ErrorClass $class,
        public RetryDisposition $disposition,
        public int $attempt,
        public ?int $maxAttempts,
        public int $delayMs,
        public string $reason,
    ) {}

    /**
     * Retry after a computed backoff.
     */
    public static function retry(ErrorClass $class, int $attempt, ?int $maxAttempts, int $delayMs): self
    {
        return new self(true, $class, RetryDisposition::Retry, max(1, $attempt), $maxAttempts, max(0, $delayMs), self::REASON_BACKOFF);
    }

    /**
     * Defer for a wait somebody else named — a `Retry-After` or a period reset.
     *
     * The wait is floored at **one millisecond**, which — because `delaySeconds()` rounds up
     * — makes a zero-second release impossible for a defer. That is the same guarantee
     * `QuotaVerdict::secondsUntilPeriodReset()` gives with its `max(1, …)`: a job released
     * with no delay at the instant a limit is hit comes straight back and burns a worker.
     *
     * The floor is one millisecond and not one second on purpose: rounding the *stored*
     * value up to a second would re-correlate the bottom of the jitter window for the
     * rate-limit class, which is exactly what full jitter is there to avoid. The second is
     * imposed by the queue's granularity at the moment of release, not by the policy.
     */
    public static function defer(ErrorClass $class, int $attempt, ?int $maxAttempts, int $delayMs, string $reason): self
    {
        return new self(true, $class, RetryDisposition::Defer, max(1, $attempt), $maxAttempts, max(1, $delayMs), $reason);
    }

    /**
     * Stop, explicitly. The caller must fail the job — never return quietly.
     */
    public static function giveUp(ErrorClass $class, int $attempt, ?int $maxAttempts, string $reason): self
    {
        return new self(false, $class, RetryDisposition::FailFast, max(1, $attempt), $maxAttempts, 0, $reason);
    }

    /**
     * The wait in whole seconds, rounded **up** — the argument for `release()`, which the
     * queue measures in seconds.
     *
     * Rounding up matters: a 250 ms backoff rounded down would release with 0 and defeat
     * the backoff entirely, so any non-zero wait is at least one second on a
     * second-granularity queue.
     */
    public function delaySeconds(): int
    {
        return $this->delayMs <= 0 ? 0 : (int) ceil($this->delayMs / 1000);
    }

    /**
     * Whether the unit of work survives this decision (Req 31.1: it must either survive
     * or fail visibly).
     */
    public function keepsWork(): bool
    {
        return $this->shouldRetry;
    }

    /**
     * Whether this is a defer — a wait that was honoured rather than computed.
     */
    public function isDeferred(): bool
    {
        return $this->disposition->isDeferred();
    }

    /**
     * Whether the caller must fail the job explicitly. The complement of `keepsWork()`,
     * named for the obligation rather than the state, because the obligation is the part
     * that is easy to forget.
     */
    public function mustFailExplicitly(): bool
    {
        return ! $this->shouldRetry;
    }

    /**
     * Whether the give-up was caused by the budget running out (as opposed to a class that
     * was never retryable, or an explicit block).
     */
    public function isExhausted(): bool
    {
        return ! $this->shouldRetry && $this->reason === self::REASON_ATTEMPTS_EXHAUSTED;
    }

    /**
     * One line for an operator, an audit payload, or a failed-job note.
     */
    public function describe(): string
    {
        $budget = $this->maxAttempts === null ? 'unlimited' : (string) $this->maxAttempts;

        if ($this->shouldRetry) {
            return sprintf(
                '%s (%s): attempt %d of %s failed — %s in %dms (%s).',
                $this->class->value,
                $this->disposition->value,
                $this->attempt,
                $budget,
                $this->isDeferred() ? 'deferring' : 'retrying',
                $this->delayMs,
                $this->reason,
            );
        }

        return sprintf(
            '%s: giving up after attempt %d of %s — %s. The failure is recorded, not discarded.',
            $this->class->value,
            $this->attempt,
            $budget,
            $this->reason,
        );
    }

    /**
     * The decision as data, under the field names of the design's structured logging
     * schema (`err_class`, `outcome`).
     *
     * @return array{err_class: string, outcome: string, retry: bool, attempt: int, max_attempts: int|null, delay_ms: int, reason: string}
     */
    public function toArray(): array
    {
        return [
            'err_class' => $this->class->value,
            'outcome' => $this->disposition->value,
            'retry' => $this->shouldRetry,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'delay_ms' => $this->delayMs,
            'reason' => $this->reason,
        ];
    }
}
