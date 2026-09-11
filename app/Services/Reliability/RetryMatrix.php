<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\ErrorClass;

/**
 * The retry/backoff matrix as **data**: one `RetryRule` per `ErrorClass`, read from
 * `wa.reliability.retry` (design.md § Error Handling; Req 31.1 / NFR2).
 *
 * ```php
 * $rule = $matrix->for(ErrorClass::Network);
 *
 * $rule->maxAttempts;        // 5
 * $rule->window(3);          // 1000 — the ms window attempt 3's delay is drawn from
 * $rule->isRetryable();      // true
 * ```
 *
 * ## Why the numbers are config and the meaning is not
 *
 * Every value the design's matrix states as a *number* lives in config
 * (`base_ms` 250, `cap_ms` 30 000, and the per-class attempt counts), because those are
 * the things an operator legitimately tunes during an incident — a flapping bridge may
 * deserve 8 attempts this week, and a provider that has started 429-ing may deserve a
 * wider first window. Nothing here is a literal in a method body.
 *
 * Every value the design states as a *behaviour* comes from `ErrorClass` and cannot be
 * configured: whether the class retries, defers, or fails fast, and which backoff shape it
 * uses. `attempts` therefore has exactly one direction of travel — it can take a retryable
 * class's budget away (down to 0, which makes it fail fast), and it cannot give a budget to
 * a class that has nothing to wait for. Configuring `attempts: 5` for `PERMISSION` does
 * not make a plan-feature refusal retryable; it is ignored, and `RetryMatrixTest` pins that.
 *
 * ## Reading config defensively
 *
 * A malformed matrix must not take the platform's error handling with it: this is the code
 * that runs *while something is already failing*. So every read is total —
 *
 * - an unknown class key is skipped (there is no class to apply it to);
 * - a non-integer `attempts` falls back to `default_attempts`;
 * - `attempts: null` means unlimited, which is how the design's "until reset" is expressed;
 * - a negative `attempts`, or one over `RetryRule::MAX_CONFIGURABLE_ATTEMPTS`, is clamped;
 * - a `base_ms`/`cap_ms` below 1 falls back to the group default, and a `cap_ms` below
 *   `base_ms` is raised to it (a cap under the base would make the first window narrower
 *   than the base, i.e. quietly reconfigure the series).
 *
 * The result is that a bad value degrades one class to the documented default instead of
 * throwing inside a failure handler — the one place an exception is least useful.
 */
final readonly class RetryMatrix
{
    /**
     * Fallback base window (ms) when `wa.reliability.retry.base_ms` is missing or
     * unusable — the design's 250 ms, so the shipped config and this floor agree.
     */
    public const int DEFAULT_BASE_MS = 250;

    /**
     * Fallback cap (ms) — the design's 30 s.
     */
    public const int DEFAULT_CAP_MS = 30_000;

    /**
     * Attempts a retryable class gets when config names no budget for it. Modest on
     * purpose: an unclassified failure retried three times is a nuisance, retried fifty
     * times is an outage amplifier.
     */
    public const int DEFAULT_ATTEMPTS = 3;

    /**
     * Config key the whole matrix hangs off.
     */
    private const string CONFIG_KEY = 'wa.reliability.retry';

    /**
     * The rule for $class, with config applied over the class's structural policy.
     */
    public function for(ErrorClass $class): RetryRule
    {
        $row = $this->row($class);
        $disposition = $class->disposition();

        $baseMs = $this->positiveInt($row['base_ms'] ?? null) ?? $this->baseMs();
        $capMs = $this->positiveInt($row['cap_ms'] ?? null) ?? $this->capMs();

        return new RetryRule(
            class: $class,
            disposition: $disposition,
            backoff: $class->backoff(),
            maxAttempts: $disposition->keepsWork() ? $this->attemptsFor($row) : 0,
            baseMs: $baseMs,
            capMs: max($baseMs, $capMs),
        );
    }

    /**
     * Every class's rule, keyed by class value — the operator-facing view of the matrix
     * and what a "show me the current retry policy" command prints.
     *
     * @return array<string, RetryRule>
     */
    public function all(): array
    {
        $rules = [];

        foreach (ErrorClass::cases() as $class) {
            $rules[$class->value] = $this->for($class);
        }

        return $rules;
    }

    /**
     * The class an unclassified `Throwable` is treated as
     * (`wa.reliability.retry.default_class`, default `UNKNOWN`).
     *
     * Configurable because the safe default is deployment-specific: `UNKNOWN` (retry
     * three times) suits a platform whose unmapped failures are usually transient, while a
     * deployment that would rather surface everything it has not classified can set
     * `VALIDATION` and get a fail-fast. Never silently "no policy": an unmapped throwable
     * still gets a budget, a decision, and an `err_class` in the log.
     */
    public function defaultClass(): ErrorClass
    {
        $configured = config(self::CONFIG_KEY.'.default_class');

        return ErrorClass::tryFromName(is_string($configured) ? $configured : null) ?? ErrorClass::Unknown;
    }

    /**
     * Group-wide first window width in ms.
     */
    public function baseMs(): int
    {
        return $this->positiveInt(config(self::CONFIG_KEY.'.base_ms')) ?? self::DEFAULT_BASE_MS;
    }

    /**
     * Group-wide cap in ms — the widest any computed window may grow to.
     */
    public function capMs(): int
    {
        $cap = $this->positiveInt(config(self::CONFIG_KEY.'.cap_ms')) ?? self::DEFAULT_CAP_MS;

        return max($this->baseMs(), $cap);
    }

    /**
     * Attempts for a retryable class: the row's own budget, else the group default.
     *
     * @param  array<string, mixed>  $row
     */
    private function attemptsFor(array $row): int
    {
        if (array_key_exists('attempts', $row) && $row['attempts'] === null) {
            return RetryRule::UNLIMITED_ATTEMPTS;
        }

        $attempts = $this->intOrNull($row['attempts'] ?? null) ?? $this->defaultAttempts();

        return max(0, min(RetryRule::MAX_CONFIGURABLE_ATTEMPTS, $attempts));
    }

    private function defaultAttempts(): int
    {
        $configured = $this->intOrNull(config(self::CONFIG_KEY.'.default_attempts'));

        if ($configured === null || $configured < 0) {
            return self::DEFAULT_ATTEMPTS;
        }

        return min(RetryRule::MAX_CONFIGURABLE_ATTEMPTS, $configured);
    }

    /**
     * The configured row for $class, or an empty row when there is none.
     *
     * @return array<string, mixed>
     */
    private function row(ErrorClass $class): array
    {
        $classes = config(self::CONFIG_KEY.'.classes');

        if (! is_array($classes)) {
            return [];
        }

        $row = $classes[$class->value] ?? null;

        if (! is_array($row)) {
            return [];
        }

        $normalised = [];

        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1
            ? (int) trim($value)
            : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = $this->intOrNull($value);

        return $int !== null && $int > 0 ? $int : null;
    }
}
