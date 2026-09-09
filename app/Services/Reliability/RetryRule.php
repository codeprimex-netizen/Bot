<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\BackoffShape;
use App\Enums\ErrorClass;
use App\Enums\RetryDisposition;

/**
 * One row of the retry/backoff matrix: everything `RetryPolicy` needs to answer "again?
 * and when?" for a single `ErrorClass` (design.md § Error Handling, "Retry / backoff
 * matrix").
 *
 * A rule is the *resolved* row — the class's structural half (`disposition`, `backoff`,
 * from `ErrorClass` itself) already merged with the operational half (`maxAttempts`,
 * `baseMs`, `capMs`, from `wa.reliability.retry`). `RetryMatrix` builds it; nothing else
 * constructs one, so a rule that contradicts its class cannot exist.
 *
 * It carries no randomness: `window()` gives the *bounds* the delay is drawn from, and
 * drawing is `RetryPolicy`'s job. That split is what makes the matrix testable without
 * seeding a random number generator — the interesting invariant ("the delay never exceeds
 * the cap") is a property of the window, and the window is a pure function.
 */
final readonly class RetryRule
{
    /**
     * `maxAttempts` for a class that may be attempted for as long as it takes.
     *
     * Only `QUOTA` uses it by default: design.md's matrix records its max attempts as
     * *"until reset"* and Req 31.1 forbids ever dropping the work, so a numeric budget
     * would be a budget for throwing a tenant's campaign away. Mirrors
     * `QuotaVerdict::UNLIMITED`, which uses the same sentinel for the same reason (the
     * design fixes these return types at `int`).
     */
    public const int UNLIMITED_ATTEMPTS = PHP_INT_MAX;

    /**
     * Upper bound on a configured attempt count, so a fat-fingered `attempts` cannot
     * become an effectively infinite retry loop on a *retrying* (as opposed to deferring)
     * class. Deliberately generous: it is a guard rail, not a policy.
     */
    public const int MAX_CONFIGURABLE_ATTEMPTS = 1000;

    /**
     * @param  int  $maxAttempts  total attempts allowed *including the first*; 0 for a
     *                            fail-fast class, `self::UNLIMITED_ATTEMPTS` for a defer
     *                            that waits as long as it must
     * @param  int  $baseMs  first window width, doubled per attempt
     * @param  int  $capMs  widest the window may grow to
     */
    public function __construct(
        public ErrorClass $class,
        public RetryDisposition $disposition,
        public BackoffShape $backoff,
        public int $maxAttempts,
        public int $baseMs,
        public int $capMs,
    ) {}

    /**
     * Whether a failure of this class may be attempted again at all.
     *
     * Both halves have to agree: the class must keep work by nature (a suspended tenant
     * never does), and the configured budget must be more than the one attempt that
     * already failed.
     */
    public function isRetryable(): bool
    {
        return $this->disposition->keepsWork() && $this->maxAttempts > 0;
    }

    /**
     * Whether this rule waits for as long as it takes (design's "until reset").
     */
    public function isUnlimited(): bool
    {
        return $this->maxAttempts >= self::UNLIMITED_ATTEMPTS;
    }

    /**
     * Whether attempt number $attempt (1-based, the attempt that just failed) may be
     * followed by another one.
     */
    public function allowsAnotherAttemptAfter(int $attempt): bool
    {
        if (! $this->isRetryable()) {
            return false;
        }

        return $this->isUnlimited() || max(1, $attempt) < $this->maxAttempts;
    }

    /**
     * The width of the full-jitter window for attempt $attempt: `min(cap, base·2^(n-1))`.
     *
     * $attempt is 1-based — it is the attempt that just failed, which is exactly what
     * `Illuminate\Queue\InteractsWithQueue::attempts()` reports — so the first retry is
     * drawn from `[0, base]`, the second from `[0, 2·base]`, and so on until the cap
     * flattens the series. (design.md writes the same series as `base·2^attempt` with a
     * 0-based retry index.)
     *
     * Doubling is done by shifting a bounded exponent rather than by `pow()`, so a caller
     * that passes attempt 900 gets the cap instead of an integer overflow.
     */
    public function window(int $attempt): int
    {
        if ($this->backoff === BackoffShape::None) {
            return 0;
        }

        $steps = max(1, $attempt) - 1;

        if ($steps >= 31) {
            return $this->capMs;
        }

        $width = $this->baseMs * (1 << $steps);

        return min($this->capMs, $width);
    }

    /**
     * The rule as data, for structured logs, the error dashboard, and operator output.
     *
     * @return array{err_class: string, disposition: string, backoff: string, retryable: bool, max_attempts: int|null, base_ms: int, cap_ms: int}
     */
    public function toArray(): array
    {
        return [
            'err_class' => $this->class->value,
            'disposition' => $this->disposition->value,
            'backoff' => $this->backoff->value,
            'retryable' => $this->isRetryable(),
            'max_attempts' => $this->isUnlimited() ? null : $this->maxAttempts,
            'base_ms' => $this->baseMs,
            'cap_ms' => $this->capMs,
        ];
    }
}
