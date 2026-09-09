<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Persisted state of one circuit breaker row (Req 31.3 / NFR2, Algorithm 7).
 *
 * The state machine has exactly four legal edges:
 *
 *   CLOSED    -> OPEN        (failure threshold or error rate crossed)
 *   OPEN      -> HALF_OPEN   (the open duration elapsed; probes are admitted)
 *   HALF_OPEN -> CLOSED      (a probe succeeded)
 *   HALF_OPEN -> OPEN        (a probe failed)
 *
 * There is deliberately **no** `CLOSED -> HALF_OPEN` edge: half-open only ever
 * follows a cool-down, so a closed breaker can never silently start rationing
 * calls. And there is no direct `OPEN -> CLOSED` edge: recovery must be *proven*
 * by a probe, never assumed from the clock alone.
 *
 * This enum is the declarative half of the breaker — the transition table and the
 * "may a call run at all?" predicate. Deciding *when* to move (thresholds, window
 * rotation, probe budget) belongs to `App\Services\Reliability\CircuitBreaker`
 * (task 3.2); nothing here reads config or touches the database.
 */
enum CircuitState: string
{
    case Closed = 'CLOSED';
    case Open = 'OPEN';
    case HalfOpen = 'HALF_OPEN';

    /**
     * Whether the guarded operation may be invoked at all in this state.
     *
     * `false` for `OPEN` is the load-bearing half of Correctness Property 13: while
     * open, the operation is never invoked and the caller gets a fast typed failure.
     *
     * `HALF_OPEN` returns `true` because the state itself permits calls — but only
     * as many as the probe budget allows, which is row-level accounting the state
     * cannot see. Callers in `HALF_OPEN` MUST also consult
     * `App\Models\CircuitBreaker::hasProbeCapacity()`.
     */
    public function admitsCalls(): bool
    {
        return match ($this) {
            self::Closed, self::HalfOpen => true,
            self::Open => false,
        };
    }

    /**
     * Whether calls in this state are rationed by the probe budget.
     */
    public function rationsCalls(): bool
    {
        return $this === self::HalfOpen;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isHalfOpen(): bool
    {
        return $this === self::HalfOpen;
    }

    /**
     * States this state may legally transition into.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Closed => [self::Open],
            self::Open => [self::HalfOpen],
            self::HalfOpen => [self::Closed, self::Open],
        };
    }

    /**
     * Whether a transition from this state to $to is permitted.
     *
     * A no-op transition (same state) is always permitted: a closed breaker that
     * keeps succeeding, or an open one that keeps fast-failing, must not error.
     */
    public function canTransitionTo(self $to): bool
    {
        return $this === $to || in_array($to, $this->allowedNext(), true);
    }

    /**
     * Human-readable label for the platform health dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Closed => 'Closed (healthy)',
            self::Open => 'Open (failing fast)',
            self::HalfOpen => 'Half-open (probing)',
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
