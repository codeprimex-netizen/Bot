<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of one step inside a saga (Req 31.5 / NFR2, Algorithm 8).
 *
 *   PENDING --forward ok----> DONE --compensated--> COMPENSATED
 *   PENDING --forward threw-> FAILED
 *
 * Four states, mapping exactly onto Algorithm 8's loop invariant — *"`done[]` holds
 * exactly the steps whose forward action succeeded and whose compensation has not
 * yet run"*. That set is recoverable from the table alone as
 * `status = DONE`, which is what lets a crashed saga resume its unwind: the
 * orchestrator does not need the in-memory `done[]` list to survive, because the
 * rows are the list.
 *
 * `FAILED` marks the one step whose forward action threw; it is never compensated,
 * because a forward action that failed has (by the idempotency contract) left
 * nothing to undo.
 */
enum SagaStepStatus: string
{
    case Pending = 'PENDING';
    case Done = 'DONE';
    case Compensated = 'COMPENSATED';
    case Failed = 'FAILED';

    /**
     * Whether this step's forward action succeeded and has not been undone — i.e.
     * whether the step is in Algorithm 8's `done[]` set and therefore still owes a
     * compensation if the saga unwinds.
     */
    public function needsCompensation(): bool
    {
        return $this === self::Done;
    }

    /**
     * Whether the forward action may still be attempted.
     */
    public function awaitsExecution(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the step has reached a final state for its saga.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Compensated, self::Failed => true,
            self::Pending, self::Done => false,
        };
    }

    /**
     * States this state may legally transition into.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Done, self::Failed],
            self::Done => [self::Compensated],
            self::Compensated, self::Failed => [],
        };
    }

    /**
     * Whether a transition from this state to $to is permitted.
     *
     * A no-op transition is always permitted: forward actions and compensations are
     * both idempotent, so re-marking a step must not error.
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
            self::Pending => 'Pending',
            self::Done => 'Done',
            self::Compensated => 'Compensated',
            self::Failed => 'Failed',
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
