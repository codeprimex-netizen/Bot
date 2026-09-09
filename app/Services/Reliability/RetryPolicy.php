<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\BackoffShape;
use App\Enums\ErrorClass;
use App\Exceptions\Tenancy\QuotaExceededException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * How long to wait before trying again, and whether to try again at all — exponential
 * backoff with **full jitter** (base 250 ms, cap 30 s), driven by the per-error-class
 * matrix (design.md § Error Handling; Req 31.1 / NFR2; Req 7.1 / A7).
 *
 * ```php
 * // in a queued job
 * public function handle(RetryPolicy $policy): void
 * {
 *     try {
 *         $this->send();
 *     } catch (Throwable $e) {
 *         $decision = $policy->decide($e, $this->attempts());
 *
 *         Log::warning('send.failed', $decision->toArray() + ['trace_id' => $this->traceId]);
 *
 *         if ($decision->shouldRetry) {
 *             $this->release($decision->delaySeconds());   // deferred or retried — kept either way
 *
 *             return;
 *         }
 *
 *         $this->fail($e);                                 // explicit, recorded, never silent
 *     }
 * }
 * ```
 *
 * ## The two design signatures, and the one callers actually want
 *
 * design.md fixes `delay(int $attempt, ErrorClass $class): int` (ms) and
 * `shouldRetry(int $attempt, ErrorClass $class): bool`; both are here, unchanged, for
 * callers that already know the class. Everything real goes through
 * `decide(Throwable, int $attempt)`, which classifies the failure, reads the matrix,
 * honours any `Retry-After` the exception carries, and returns a `RetryDecision` — one
 * object that answers retry-or-not, how long, and why. A `catch` block that has to
 * assemble those four answers itself is a `catch` block that will get one of them wrong.
 *
 * `$attempt` is 1-based throughout: it is *the attempt that just failed*, which is exactly
 * what `Illuminate\Queue\InteractsWithQueue::attempts()` returns inside a job.
 *
 * ## Full jitter — what it is and why the multiplier is random
 *
 * `delay = random(0, min(cap, base · 2^n))`. The delay is drawn uniformly from the entire
 * window rather than set to its top edge, because an outage fails every in-flight job at
 * the same instant: a deterministic backoff then wakes all N workers at the same instant
 * too, on every attempt, and the recovering dependency is knocked over again by a
 * synchronised herd. Independent draws spread the same retries evenly across the window and
 * keep the workers decorrelated for good. See `App\Enums\BackoffShape` for the full
 * argument, including why the interval starts at 0 and why a class that must not hammer
 * gets a wider `base_ms` instead of a floor on the jitter.
 *
 * Two waits are **not** jittered, on purpose: a provider's `Retry-After` and a quota
 * period's reset. Those are authoritative — jittering them down guarantees the next attempt
 * is refused as well.
 *
 * ## Req 31.1: never a drop
 *
 * *"THE system SHALL never drop an outbound job on quota/rate limits; it SHALL defer
 * (release) or block explicitly."* Every path through `decide()` ends in a decision that
 * either keeps the work (`shouldRetry === true`) or requires the caller to fail it
 * explicitly (`mustFailExplicitly() === true`). There is no third return value, no `null`,
 * and no "no policy for this" — an unmapped throwable gets
 * `wa.reliability.retry.default_class` and a real budget.
 *
 * The quota case is the one the requirement names, and it has both halves:
 *
 * | `QuotaExceededException` | Decision | Wait |
 * |---|---|---|
 * | `isDeferrable()` (period exhausted) | **defer**, unlimited attempts | its own `retryAfterSeconds()` — the exact reset, honoured verbatim |
 * | not deferrable (not priced, metered to zero, larger than a whole period, gauge at capacity) | **give up**, reason `blocked` | none: no reset is coming, so releasing would bounce for ever |
 *
 * ## How the rest of the platform composes with this
 *
 * This class owns **backoff arithmetic and the matrix**, and nothing else. It does not
 * park work, count quota, or track failures — those exist already and stay where they are:
 *
 * - **a deferred quota send** — `QuotaVerdict::secondsUntilPeriodReset()` is the wait; a
 *   caller that has the verdict in hand should release on that directly (it is the same
 *   number this class would honour out of the exception) and, for a campaign or import,
 *   also `QuotaParkingLot::park()` so the *run* is recorded as `QUOTA_PAUSED` and the
 *   tenant is told. The policy is for the failure path that has an exception and no verdict.
 * - **the outbox relay (task 3.3)** — its `next_attempt_at <- now() + backoffWithJitter(row.attempts)`
 *   is `now()->addMilliseconds($policy->decide($e, $row->attempts)->delayMs)`; a give-up
 *   decision is what moves the row to `FAILED` instead of rescheduling it.
 *   Sub-second precision is why `RetryDecision` carries milliseconds and not just seconds.
 * - **the circuit breaker (task 3.2)** — orthogonal, and the reason `CIRCUIT_OPEN` is a
 *   fail-fast class: while a breaker is OPEN the right move is the fallback chain, not a
 *   sleep, and retrying through an open breaker is precisely what it exists to prevent.
 * - **`$tries` / `backoff()`** — a job that prefers the framework's own retry accounting can
 *   declare `public $tries` from `triesFor()` and `backoff()` from `backoffSeconds()`
 *   instead of calling `decide()`. Same numbers, less control: the framework cannot know
 *   the error class in advance, so a job that mixes error classes should use `decide()`.
 *
 * ## Cost
 *
 * One `config()` read per matrix lookup and one `random_int()` per delay. No I/O, nothing
 * cached, nothing stateful — safe to resolve as a singleton and safe to call from inside a
 * `failed()` handler.
 */
final readonly class RetryPolicy
{
    /**
     * Ceiling on a wait taken from a `Retry-After` or a period reset (45 days), matching
     * `wa.tenancy.quota.retention_days`.
     *
     * Comfortably longer than the longest quota period, so no legitimate defer is
     * shortened; short enough that a provider sending a nonsense `Retry-After` cannot park
     * a job past the point where anybody is still watching for it.
     */
    public const int MAX_HONOURED_SECONDS = 45 * 24 * 60 * 60;

    /**
     * How many entries `backoffSeconds()` produces for a class that retries indefinitely.
     */
    private const int UNLIMITED_BACKOFF_ENTRIES = 10;

    public function __construct(
        private ErrorClassifier $classifier,
        private RetryMatrix $matrix,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The decision
    |--------------------------------------------------------------------------
    */

    /**
     * Everything a caller needs after catching $e on attempt $attempt.
     */
    public function decide(Throwable $e, int $attempt): RetryDecision
    {
        return $this->decideFor(
            $this->classify($e),
            $attempt,
            $this->retryAfterSecondsFrom($e),
            $this->isBlocked($e),
        );
    }

    /**
     * The same decision for a caller that already knows the class — a driver that read a
     * vendor error code, or the relay replaying a row whose class was recorded.
     *
     * @param  int|null  $retryAfterSeconds  an authoritative wait, when something named one
     * @param  bool  $blocked  the failure says no wait will help (Req 31.1's *block*)
     */
    public function decideFor(ErrorClass $class, int $attempt, ?int $retryAfterSeconds = null, bool $blocked = false): RetryDecision
    {
        $attempt = max(1, $attempt);
        $rule = $this->matrix->for($class);
        $budget = $rule->isUnlimited() ? null : $rule->maxAttempts;

        if ($blocked) {
            return RetryDecision::giveUp($class, $attempt, $budget, RetryDecision::REASON_BLOCKED);
        }

        if (! $rule->isRetryable()) {
            return RetryDecision::giveUp($class, $attempt, $budget, RetryDecision::REASON_NOT_RETRYABLE);
        }

        if (! $rule->allowsAnotherAttemptAfter($attempt)) {
            return RetryDecision::giveUp($class, $attempt, $budget, RetryDecision::REASON_ATTEMPTS_EXHAUSTED);
        }

        $delayMs = $this->delayWithin($rule, $attempt, $retryAfterSeconds);

        if (! $rule->disposition->isDeferred()) {
            return RetryDecision::retry($class, $attempt, $budget, $delayMs);
        }

        return RetryDecision::defer($class, $attempt, $budget, $delayMs, $this->deferReason($rule, $retryAfterSeconds));
    }

    /*
    |--------------------------------------------------------------------------
    | design.md's two signatures
    |--------------------------------------------------------------------------
    */

    /**
     * Milliseconds to wait after attempt $attempt of a failure of $class —
     * `random(0, min(cap, base · 2^n))`.
     *
     * `0` for a class that is not retried at all. A quota defer with nothing to honour
     * returns the cap rather than 0: a re-check every 30 s is a wait, a re-check every 0 ms
     * is a spin.
     */
    public function delay(int $attempt, ErrorClass $class): int
    {
        return $this->delayWithin($this->matrix->for($class), max(1, $attempt), null);
    }

    /**
     * Whether attempt $attempt of a failure of $class may be followed by another one.
     */
    public function shouldRetry(int $attempt, ErrorClass $class): bool
    {
        return $this->matrix->for($class)->allowsAnotherAttemptAfter(max(1, $attempt));
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the matrix
    |--------------------------------------------------------------------------
    */

    /**
     * The class of $e — the configured default when nothing recognises it.
     */
    public function classify(Throwable $e): ErrorClass
    {
        return $this->classifier->classify($e) ?? $this->matrix->defaultClass();
    }

    /**
     * The matrix row for $class.
     */
    public function rule(ErrorClass $class): RetryRule
    {
        return $this->matrix->for($class);
    }

    /**
     * The whole matrix, for operator output and diagnostics.
     *
     * @return array<string, RetryRule>
     */
    public function matrix(): array
    {
        return $this->matrix->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Laravel queue interop
    |--------------------------------------------------------------------------
    */

    /**
     * A value for a job's `$tries` — total attempts including the first.
     *
     * `0` for a class that waits as long as it must, which is how the queue spells
     * "unlimited"; `1` for a fail-fast class, so the job runs once and then fails rather
     * than being retried by the worker's default.
     */
    public function triesFor(ErrorClass $class): int
    {
        $rule = $this->matrix->for($class);

        if ($rule->isUnlimited()) {
            return 0;
        }

        return max(1, $rule->maxAttempts);
    }

    /**
     * A value for a job's `backoff()` — one freshly jittered delay per retry, in seconds.
     *
     * Each call re-draws, so a job that returns this from `backoff()` still gets
     * independent jitter on every release. Seconds are rounded **up**, so a sub-second
     * window becomes a one-second wait instead of none; `decide()->delayMs` is the
     * millisecond-precise value for callers (like the outbox relay) that schedule their own
     * next attempt.
     *
     * @return list<int>
     */
    public function backoffSeconds(ErrorClass $class): array
    {
        $rule = $this->matrix->for($class);

        if (! $rule->isRetryable()) {
            return [];
        }

        $retries = $rule->isUnlimited()
            ? self::UNLIMITED_BACKOFF_ENTRIES
            : min(self::UNLIMITED_BACKOFF_ENTRIES, $rule->maxAttempts - 1);

        $delays = [];

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $ms = $this->delayWithin($rule, $attempt, null);
            $delays[] = $ms <= 0 ? 0 : (int) ceil($ms / 1000);
        }

        return $delays;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The wait for one attempt: an authoritative one when there is one, else a draw from
     * the full-jitter window.
     */
    private function delayWithin(RetryRule $rule, int $attempt, ?int $retryAfterSeconds): int
    {
        if ($rule->backoff === BackoffShape::None) {
            return 0;
        }

        if ($rule->backoff->honoursRetryAfter() && $retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return min($retryAfterSeconds, self::MAX_HONOURED_SECONDS) * 1000;
        }

        // A period reset nobody told us about: re-check at the cap. Never 0 — Req 31.1's
        // defer is a wait, and a zero-delay release is a worker spinning on a full quota.
        if ($rule->backoff === BackoffShape::PeriodReset) {
            return $rule->capMs;
        }

        return random_int(0, $rule->window($attempt));
    }

    /**
     * Which flavour of defer this is, for the decision's `reason`.
     */
    private function deferReason(RetryRule $rule, ?int $retryAfterSeconds): string
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return $rule->backoff === BackoffShape::PeriodReset
                ? RetryDecision::REASON_PERIOD_RESET
                : RetryDecision::REASON_RETRY_AFTER;
        }

        return $rule->backoff === BackoffShape::PeriodReset
            ? RetryDecision::REASON_PERIOD_RESET
            : RetryDecision::REASON_BACKOFF;
    }

    /**
     * An authoritative wait carried by the exception itself.
     *
     * `QuotaExceededException` is asked directly (it knows the exact period reset and
     * returns `null` when no reset is coming); everything else is read from the
     * `Retry-After` header a `HttpExceptionInterface` may carry, in its numeric form. The
     * HTTP-date form is deliberately ignored rather than parsed: a wrong clock would turn
     * a two-second wait into a two-hour one, and every provider the platform talks to sends
     * seconds.
     *
     * A hint is only *used* by a class whose backoff honours one (`RATE_LIMIT`, `QUOTA`);
     * a 503 with `Retry-After: 30` is still retried on jittered backoff, because a fixed 30
     * seconds is exactly the synchronised herd full jitter exists to avoid.
     */
    private function retryAfterSecondsFrom(Throwable $e): ?int
    {
        if ($e instanceof QuotaExceededException) {
            return $e->retryAfterSeconds();
        }

        if (! $e instanceof HttpExceptionInterface) {
            return null;
        }

        $header = $e->getHeaders()['Retry-After'] ?? null;

        if (! is_string($header) && ! is_int($header)) {
            return null;
        }

        $value = trim((string) $header);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * Whether the failure itself says that no wait will help — Req 31.1's *block*.
     *
     * Only a non-deferrable `QuotaExceededException` does today: its class (`QUOTA`)
     * defers by nature, so without this the one refusal that has no reset coming would be
     * released for ever. Everything else that must not be retried says so through its
     * *class* instead, which is the extension point a provider driver uses (classify it
     * into a fail-fast class) rather than this method.
     */
    private function isBlocked(Throwable $e): bool
    {
        return $e instanceof QuotaExceededException && ! $e->isDeferrable();
    }
}
