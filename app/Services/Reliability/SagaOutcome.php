<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\SagaStatus;
use App\Exceptions\Reliability\SagaCompensatedException;
use App\Models\Saga;
use Throwable;

/**
 * How a saga run ended (Req 15.5 / B6, Req 31.5 / NFR2, Algorithm 8, Correctness
 * Property 18).
 *
 * Property 18 says a saga has two legal endings — *all forward steps completed*, or
 * *every completed step compensated* — and this object is where that binary is stated in
 * code. It has **three** constructors, though, and the third one is the honest part:
 *
 * | Constructor | `sagas.status` | What happened |
 * |---|---|---|
 * | `completed()` | `COMPLETED` | every forward step landed |
 * | `compensated()` | `FAILED` | a step failed; every completed step was undone; nothing is outstanding |
 * | `compensationIncomplete()` | `COMPENSATING` | a step failed **and a compensation also failed**; the saga still owes work |
 *
 * Algorithm 8's pseudocode has no third case: it calls `compensateIdempotent()` in a
 * loop with no failure branch and then assigns `FAILED` unconditionally. Taken
 * literally, a compensation that throws would either abort the unwind (leaving the
 * *remaining* steps' effects orphaned and no record of it) or be swallowed and reported
 * as a clean rollback — a saga marked "all compensated" that is still holding a
 * reservation. Both readings break the property the algorithm exists to guarantee, so
 * the orchestrator runs every remaining compensation regardless, and reports this third
 * outcome. It is deliberately **not terminal**: `COMPENSATING` is resumable, so the
 * recovery sweep retries the unwind and `Saga::scopeStalled()` surfaces it to an
 * operator. See `PersistedSagaOrchestrator`.
 *
 * ## `replayed` — nothing ran this time
 *
 * True when `run()` was called on a saga that had already reached a terminal state.
 * Re-running a saga is a no-op by design (its steps are idempotent, so a duplicate job
 * delivery or a recovery sweep racing the original must be harmless), and a caller that
 * cares whether *this* call is the one that placed the order needs to be able to tell —
 * the same distinction `IdempotencyOutcome::isReplay()` draws, for the same reason.
 */
final readonly class SagaOutcome
{
    /**
     * @param  string  $sagaId  the saga this describes
     * @param  string  $type  its `sagas.type`
     * @param  SagaStatus  $status  the status the saga was left in
     * @param  string|null  $failedStep  name of the step whose forward action threw, when one did
     * @param  list<string>  $compensated  steps undone by this run, in the order they were undone (reverse execution order)
     * @param  list<string>  $unfinished  steps whose compensation failed and which therefore still owe work
     * @param  Throwable|null  $cause  the forward failure that started the unwind
     * @param  bool  $replayed  true when the saga was already terminal and nothing ran
     */
    private function __construct(
        public string $sagaId,
        public string $type,
        public SagaStatus $status,
        public ?string $failedStep = null,
        public array $compensated = [],
        public array $unfinished = [],
        public ?Throwable $cause = null,
        public bool $replayed = false,
    ) {}

    /**
     * Every forward step completed.
     */
    public static function completed(Saga $saga, bool $replayed = false): self
    {
        return new self($saga->id, $saga->type, SagaStatus::Completed, replayed: $replayed);
    }

    /**
     * A step failed and the unwind finished: nothing is left to undo.
     *
     * @param  list<string>  $compensated
     */
    public static function compensated(
        Saga $saga,
        ?string $failedStep = null,
        array $compensated = [],
        ?Throwable $cause = null,
        bool $replayed = false,
    ): self {
        return new self(
            $saga->id,
            $saga->type,
            SagaStatus::Failed,
            failedStep: $failedStep,
            compensated: $compensated,
            cause: $cause,
            replayed: $replayed,
        );
    }

    /**
     * A compensation failed. The saga is left `COMPENSATING` — resumable, and visible to
     * the stalled-saga query — because it still owes work.
     *
     * @param  list<string>  $compensated
     * @param  list<string>  $unfinished
     */
    public static function compensationIncomplete(
        Saga $saga,
        ?string $failedStep,
        array $compensated,
        array $unfinished,
        ?Throwable $cause = null,
    ): self {
        return new self(
            $saga->id,
            $saga->type,
            SagaStatus::Compensating,
            failedStep: $failedStep,
            compensated: $compensated,
            unfinished: $unfinished,
            cause: $cause,
        );
    }

    /**
     * Every forward step landed.
     */
    public function isCompleted(): bool
    {
        return $this->status === SagaStatus::Completed;
    }

    /**
     * The unwind ran to the end: no completed step's effect is outstanding.
     */
    public function isCompensated(): bool
    {
        return $this->status === SagaStatus::Failed;
    }

    /**
     * A compensation failed, so an effect is still orphaned and a human should look.
     *
     * The one condition in a saga run worth paging on: `isCompensated()` is a clean,
     * expected business failure, while this is the platform failing to undo its own work.
     */
    public function needsAttention(): bool
    {
        return $this->status === SagaStatus::Compensating;
    }

    /**
     * Whether the saga was already terminal, so this call ran nothing.
     */
    public function isReplay(): bool
    {
        return $this->replayed;
    }

    /**
     * The exception form, for callers whose only sensible response is to fail loudly.
     */
    public function toException(): SagaCompensatedException
    {
        return SagaCompensatedException::from($this);
    }

    /**
     * Throw unless every forward step completed — the one-liner for a job that should
     * surface a rolled-back saga to its own retry/failed-job machinery.
     *
     * @throws SagaCompensatedException
     */
    public function throwUnlessCompleted(): void
    {
        if (! $this->isCompleted()) {
            throw $this->toException();
        }
    }

    /**
     * One sentence for a log line or an exception message. Internal identifiers only —
     * the saga's `state` holds the order and the customer and is never quoted here.
     */
    public function summary(): string
    {
        if ($this->isCompleted()) {
            return sprintf(
                'Saga [%s] of type [%s] completed%s.',
                $this->sagaId,
                $this->type,
                $this->replayed ? ' on an earlier run (nothing ran now)' : '',
            );
        }

        $failure = $this->failedStep === null
            ? 'its unwind was resumed'
            : sprintf('step [%s] failed', $this->failedStep);

        if ($this->needsAttention()) {
            return sprintf(
                'Saga [%s] of type [%s]: %s and the unwind is INCOMPLETE — [%s] could not be '
                .'compensated and still owe work. The saga is left COMPENSATING for retry.',
                $this->sagaId,
                $this->type,
                $failure,
                implode(', ', $this->unfinished),
            );
        }

        return sprintf(
            'Saga [%s] of type [%s]: %s; %s compensated in reverse order, nothing outstanding.',
            $this->sagaId,
            $this->type,
            $failure,
            $this->compensated === [] ? 'no completed steps needed to be' : '['.implode(', ', $this->compensated).']',
        );
    }

    /**
     * Log / audit shape. Step names and ids only, never the saga's state.
     *
     * @return array{saga_id: string, type: string, status: string, failed_step: string|null, compensated: list<string>, unfinished: list<string>, replayed: bool}
     */
    public function toArray(): array
    {
        return [
            'saga_id' => $this->sagaId,
            'type' => $this->type,
            'status' => $this->status->value,
            'failed_step' => $this->failedStep,
            'compensated' => $this->compensated,
            'unfinished' => $this->unfinished,
            'replayed' => $this->replayed,
        ];
    }
}
