<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a persisted saga instance (Req 31.5 / NFR2, Algorithm 8).
 *
 *   RUNNING --all steps done--------> COMPLETED
 *   RUNNING --a step failed---------> COMPENSATING --all undone--> FAILED
 *
 * The two terminal states are the two halves of Correctness Property 18 (saga
 * atomicity): `COMPLETED` means every forward step landed, `FAILED` means every
 * step that landed has been compensated. `COMPENSATING` is the only intermediate
 * state and it is persisted precisely so a crash *during* the unwind resumes the
 * unwind instead of leaving a partial side effect behind.
 *
 * `COMPENSATING -> COMPLETED` is not a legal edge: once the platform has started
 * undoing work, the saga cannot claim success.
 */
enum SagaStatus: string
{
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Compensating = 'COMPENSATING';
    case Failed = 'FAILED';

    /**
     * Whether the orchestrator still has work to do on this saga — the predicate
     * the crash-recovery sweep uses to find sagas to resume.
     */
    public function isResumable(): bool
    {
        return match ($this) {
            self::Running, self::Compensating => true,
            self::Completed, self::Failed => false,
        };
    }

    /**
     * Whether the saga has reached a final state.
     */
    public function isTerminal(): bool
    {
        return ! $this->isResumable();
    }

    /**
     * Whether the saga is unwinding completed steps in reverse order.
     */
    public function isCompensating(): bool
    {
        return $this === self::Compensating;
    }

    /**
     * States this state may legally transition into.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Running => [self::Completed, self::Compensating],
            self::Compensating => [self::Failed],
            self::Completed, self::Failed => [],
        };
    }

    /**
     * Whether a transition from this state to $to is permitted.
     *
     * A no-op transition is always permitted so that re-running a saga (which
     * Algorithm 8 makes safe through idempotent steps) does not error.
     */
    public function canTransitionTo(self $to): bool
    {
        return $this === $to || in_array($to, $this->allowedNext(), true);
    }

    /**
     * Human-readable label for the order/flow inspector.
     */
    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Compensating => 'Compensating',
            self::Failed => 'Failed (compensated)',
        };
    }

    /**
     * The states a recovery sweep should look for, as raw column values.
     *
     * @return array<int, string>
     */
    public static function resumableValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isResumable())),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
