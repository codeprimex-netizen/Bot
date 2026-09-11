<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\SagaStatus;
use App\Enums\SagaStepStatus;
use App\Exceptions\Reliability\InvalidSagaDefinitionException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Exceptions\Reliability\UnrecordableResultException;
use App\Models\Saga;
use App\Models\SagaStep;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Algorithm 8 on `sagas` / `saga_steps`: run every forward step, or undo every step that
 * ran (Req 15.5 / B6, Req 31.5 / NFR2, Correctness Property 18).
 *
 * ## The rows are `done[]`
 *
 * The pseudocode keeps an in-memory `done` list and its loop invariant — *"`done[]` holds
 * exactly the steps whose forward action succeeded and whose compensation has not yet
 * run"*. An in-memory list dies with the worker, and Req 31.5 has to survive that, so the
 * list is a column: `done[]` **is** `saga_steps WHERE status = DONE`, read back through
 * `Saga::compensatableSteps()`. Nothing here needs to survive in memory, which is why a
 * saga interrupted mid-flight — forward or unwinding — resumes correctly from the
 * database alone. Steps are laid out and persisted *before* the first one executes, for
 * the same reason.
 *
 * ## Two deviations from the pseudocode, both required for the property to hold
 *
 * **1. Forward and compensation use distinct idempotency keys.** Algorithm 8 passes the
 * same literal `key := saga.id + ':' + step.name` to `executeIdempotent(forward)` and to
 * `compensateIdempotent(compensation)`. Against a real `IdempotencyStore` that is a bug,
 * not a shorthand: the forward action has already recorded that key `COMPLETED`, so
 * `once()` would **replay its recorded result and never call the compensation**. Every
 * unwind would be a silent no-op that reported success — the exact failure Property 18
 * exists to exclude. Forwards therefore use `Saga::stepKey()` and compensations
 * `Saga::compensationKey()` (`…:compensate`), which is why those are two methods on the
 * model. Each direction stays idempotent on its own key, which is all the algorithm's
 * preconditions actually require.
 *
 * **2. A failed compensation is a third outcome.** The pseudocode's unwind loop has no
 * failure branch and assigns `FAILED` unconditionally afterwards. Compensations call the
 * same fallible dependencies the forward actions did, so this run:
 *
 * - **continues** with the remaining compensations after one fails — abandoning them
 *   would orphan the effects of steps *earlier* than the one that could not be undone,
 *   which is a strictly worse partial state than the one the unwind was called to fix;
 * - leaves the saga **`COMPENSATING`**, never `FAILED`. `FAILED` is this platform's word
 *   for "compensated, nothing outstanding" (see `SagaStatus`); writing it over an
 *   incomplete unwind would tell every operator and dashboard that a still-held
 *   reservation had been released. `COMPENSATING` is resumable, so the recovery sweep
 *   retries the unwind, and `Saga::scopeStalled()` surfaces it — a state an operator can
 *   see and act on;
 * - reports `SagaOutcome::needsAttention()`, so a caller can page rather than shrug.
 *
 * ## What `run()` refuses to guess
 *
 * | Situation | Behaviour |
 * |---|---|
 * | saga `COMPLETED` / `FAILED` | no-op, `SagaOutcome::isReplay()` |
 * | saga `COMPENSATING` | resumes the unwind; never restarts forward |
 * | saga `RUNNING` but some step is `FAILED`/`COMPENSATED` | unwinds — the crash window between marking a step and marking the saga |
 * | some steps already `DONE` | not re-executed; the run resumes at the first `PENDING` step |
 * | another worker holds a step's key | `OperationInFlightException` propagates; **not** treated as a step failure |
 * | forward action's result cannot be recorded | the step is settled `DONE` (its effect landed) and the saga unwinds — so that step is **compensated**, never marked `FAILED` |
 * | saga type has no definition | `InvalidSagaDefinitionException`; the saga is left untouched |
 *
 * The concurrency row is the subtle one. Two workers on one saga is normal — a duplicate
 * job delivery, a recovery sweep racing the original — and the ledger, not a lock, is
 * what keeps a side effect single. But a fast-failing `once()` refusal means *"someone
 * else is running this step right now"*, and a run that mistook that for a forward
 * failure would unwind a saga whose next step is mid-flight. So it propagates untouched
 * and the caller's backoff does the waiting, exactly as `IdempotencyOptions::failingFast()`
 * intends.
 */
final readonly class PersistedSagaOrchestrator implements SagaOrchestrator
{
    /**
     * How long a saga's forward/compensation keys are kept when
     * `wa.reliability.saga.key_retention_days` is absent.
     *
     * Deliberately longer than the store's own default: these keys must outlive the
     * longest time a saga can sit `COMPENSATING` waiting for a dependency to come back,
     * because a pruned compensation key makes the next unwind attempt indistinguishable
     * from a first one — and re-running a compensation that already succeeded is the
     * double effect the ledger exists to prevent.
     */
    public const int DEFAULT_KEY_RETENTION_DAYS = 90;

    public function __construct(
        private SagaDefinitionRegistry $registry,
        private IdempotencyStore $store,
        private ConnectionInterface $connection,
    ) {}

    public function run(Saga $saga): SagaOutcome
    {
        // `run()` takes a model argument, so Eloquent cannot vouch for where it came from —
        // a queued job payload, an unscoped recovery sweep, a cache. Checked here, up front,
        // because the alternative is a cross-tenant refusal surfacing mid-run from the first
        // write, where the forward loop would mistake it for a step failure and unwind a
        // saga on the strength of a security error.
        $saga->assertBelongsToCurrentTenant('run');

        $definition = $this->registry->for($saga);
        $steps = $this->registry->stepsOf($definition);

        // Before any decision and before any execution: the steps exist on disk, and they
        // are the ones this code owns.
        $this->layOutSteps($saga, $definition);

        if ($saga->status === SagaStatus::Completed) {
            return SagaOutcome::completed($saga, replayed: true);
        }

        if ($saga->status === SagaStatus::Failed) {
            return SagaOutcome::compensated($saga, failedStep: $this->failedStepName($saga), replayed: true);
        }

        if ($saga->status->isCompensating() || $this->hasStartedUnwinding($saga)) {
            return $this->unwind($saga, $steps, $this->failedStepName($saga), cause: null);
        }

        return $this->runForward($saga, $steps);
    }

    /**
     * The forward pass: execute each step that still awaits execution, in order.
     *
     * `continue` past a non-`PENDING` step is how a resumed saga skips work that already
     * landed. Reaching the end therefore means every step is `DONE` — the only way a step
     * could be `FAILED` or `COMPENSATED` here is a state `run()` already routed to the
     * unwind before calling this.
     *
     * @param  array<string, SagaStepDefinition>  $steps
     */
    private function runForward(Saga $saga, array $steps): SagaOutcome
    {
        foreach ($saga->steps as $step) {
            if (! $step->awaitsExecution()) {
                continue;
            }

            try {
                $this->execute($saga, $step, $steps[$step->name]);
            } catch (OperationInFlightException $inFlight) {
                // Another worker owns this step. Not a failure of the step, and emphatically
                // not a reason to unwind a saga whose next effect may be in flight.
                throw $inFlight;
            } catch (Throwable $failure) {
                $this->markStepFailed($step, $failure);
                $this->markCompensating($saga, $failure);

                return $this->unwind($saga, $steps, $step->name, $failure);
            }
        }

        $saga->fill([
            'status' => SagaStatus::Completed,
            'current_step' => $saga->steps->count(),
            'last_error' => null,
            'completed_at' => now(),
        ])->save();

        return SagaOutcome::completed($saga);
    }

    /**
     * One forward action, deduplicated on `Saga::stepKey()`.
     *
     * The attempt counter and `current_step` are written **before** the action runs, so a
     * worker that dies inside it leaves evidence of having tried; and the step is settled
     * in a single write afterwards, so `DONE` and the compensation handle that unwinding
     * it requires can never disagree.
     */
    private function execute(Saga $saga, SagaStep $step, SagaStepDefinition $definition): void
    {
        $step->fill(['attempts' => $step->attempts + 1])->save();
        $saga->fill(['current_step' => $step->position])->save();

        $context = new SagaContext($saga, $step);

        try {
            $outcome = $this->store->once(
                Saga::IDEMPOTENCY_SCOPE,
                $saga->stepKey($step),
                static fn (): array => $definition->forward($context)->toArray(),
                $this->keyOptions($saga),
            );

            $result = SagaStepResult::fromLedger($outcome->value);
        } catch (UnrecordableResultException $unrecordable) {
            // The effect landed; only its record did not. Settle the step as DONE with no
            // handle, then let the failure abort the saga through the forward loop's normal
            // path: a DONE step is compensated and a FAILED one never is, so this ordering
            // is the difference between an undone effect and an orphaned one. `markStepFailed()`
            // will not overwrite the DONE it has just been given.
            $this->settleStep($step, SagaStepResult::none());

            throw $unrecordable;
        }

        $this->settleStep($step, $result);

        if ($result->state !== []) {
            $saga->fill(['state' => [...($saga->state ?? []), ...$result->state]])->save();
        }
    }

    /**
     * The unwind: compensate every step in `done[]`, in reverse execution order.
     *
     * @param  array<string, SagaStepDefinition>  $steps
     */
    private function unwind(Saga $saga, array $steps, ?string $failedStep, ?Throwable $cause): SagaOutcome
    {
        $this->markCompensating($saga, $cause);

        $compensated = [];
        $unfinished = [];
        $firstFailure = null;

        // Already reversed by the model — reversing it again would compensate forwards.
        foreach ($saga->compensatableSteps() as $step) {
            try {
                $this->compensate($saga, $step, $steps[$step->name]);
                $compensated[] = $step->name;
            } catch (OperationInFlightException $inFlight) {
                throw $inFlight;
            } catch (Throwable $failure) {
                // Deliberately not a `break`: the steps still to be undone are the *earlier*
                // ones, and leaving those effects in place because a later one refused would
                // make the partial state worse, not safer.
                $unfinished[] = $step->name;
                $firstFailure ??= $failure;
                $this->recordCompensationFailure($step, $failure);
            }
        }

        if ($unfinished === []) {
            $saga->fill([
                'status' => SagaStatus::Failed,
                'last_error' => $cause === null ? $saga->last_error : $this->describe($cause),
                'failed_at' => now(),
            ])->save();

            return SagaOutcome::compensated($saga, $failedStep, $compensated, $cause);
        }

        $outcome = SagaOutcome::compensationIncomplete($saga, $failedStep, $compensated, $unfinished, $cause ?? $firstFailure);

        // Stays COMPENSATING: resumable, and `Saga::scopeStalled()` surfaces it. `FAILED`
        // here would claim an unwind that has not happened.
        $saga->fill(['last_error' => SagaStep::truncateError($outcome->summary())])->save();

        return $outcome;
    }

    /**
     * One compensation, deduplicated on `Saga::compensationKey()` — **not**
     * `Saga::stepKey()`; see the class docblock's first deviation.
     */
    private function compensate(Saga $saga, SagaStep $step, SagaStepDefinition $definition): void
    {
        $step->fill(['compensation_attempts' => $step->compensation_attempts + 1])->save();

        $context = new SagaContext($saga, $step);

        $this->store->once(
            Saga::IDEMPOTENCY_SCOPE,
            $saga->compensationKey($step),
            static function () use ($definition, $context): ?array {
                $definition->compensate($context);

                // Nothing to replay: a compensation's whole output is the absence of the
                // effect. The key's existence is the record.
                return null;
            },
            $this->keyOptions($saga),
        );

        $step->fill([
            'status' => SagaStepStatus::Compensated,
            'last_error' => null,
            'compensated_at' => now(),
        ])->save();
    }

    /**
     * Settle a step that has just run its forward action: `DONE`, plus the handle its
     * compensation will need.
     */
    private function settleStep(SagaStep $step, SagaStepResult $result): void
    {
        $step->fill([
            'status' => SagaStepStatus::Done,
            'compensation_ref' => $result->compensationRef,
            'compensation_payload' => $result->compensationPayload,
            'last_error' => null,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Record a forward failure on the step.
     *
     * The transition is checked rather than assumed. A step whose forward action landed
     * but whose bookkeeping failed is already `DONE`, and `DONE → FAILED` is not a legal
     * edge (`SagaStepStatus::allowedNext()`) for a concrete reason: a `FAILED` step is
     * never compensated, so writing it over a `DONE` one would leave that step's effect
     * orphaned for ever. Such a step keeps its status, records the error, and is undone by
     * the unwind like any other completed step.
     */
    private function markStepFailed(SagaStep $step, Throwable $failure): void
    {
        $attributes = ['last_error' => SagaStep::truncateError($this->describe($failure))];

        if ($step->status->canTransitionTo(SagaStepStatus::Failed)) {
            $attributes['status'] = SagaStepStatus::Failed;
            $attributes['failed_at'] = now();
        }

        $step->fill($attributes)->save();
    }

    private function recordCompensationFailure(SagaStep $step, Throwable $failure): void
    {
        // Status untouched: the step is still `DONE`, which is precisely what makes it turn
        // up in `compensatableSteps()` again on the next run.
        $step->fill(['last_error' => SagaStep::truncateError($this->describe($failure))])->save();
    }

    /**
     * Move the saga into `COMPENSATING`, idempotently — a resumed unwind calls this on a
     * saga that is already there.
     */
    private function markCompensating(Saga $saga, ?Throwable $cause): void
    {
        if ($saga->status->isCompensating() && $cause === null) {
            return;
        }

        $saga->fill([
            'status' => SagaStatus::Compensating,
            'last_error' => $cause === null
                ? $saga->last_error
                : SagaStep::truncateError($this->describe($cause)),
        ])->save();
    }

    /**
     * Ensure this saga's steps exist on disk and are the ones the definition declares.
     *
     * A saga may be created with its steps (a caller that knows its payloads) or without
     * them; either way nothing executes until the rows are committed, which is what makes
     * a crash between two steps recoverable from the database alone.
     *
     * @throws InvalidSagaDefinitionException when persisted steps disagree with the code
     */
    private function layOutSteps(Saga $saga, SagaDefinition $definition): void
    {
        $saga->load('steps');

        $defined = array_map(
            static fn (SagaStepDefinition $step): string => $step->name(),
            $definition->steps(),
        );

        if ($saga->steps->isNotEmpty()) {
            $persisted = $saga->steps
                ->map(static fn (SagaStep $step): string => $step->name)
                ->values()
                ->all();

            if ($persisted !== $defined) {
                throw InvalidSagaDefinitionException::stepMismatch($saga, $persisted, $defined);
            }

            return;
        }

        // One transaction: a half-written step list would be a saga that silently omits
        // its last steps, and `uniq(saga_id, position)` is what makes a concurrent second
        // lay-out lose rather than duplicate.
        $this->connection->transaction(function () use ($saga, $defined): void {
            foreach ($defined as $position => $name) {
                $saga->steps()->create([
                    'position' => $position,
                    'name' => $name,
                    'status' => SagaStepStatus::Pending,
                ]);
            }
        });

        $saga->load('steps');
    }

    /**
     * Whether any step has already been failed or compensated — the durable evidence that
     * this saga is unwinding, even if the saga row itself has not been updated yet.
     */
    private function hasStartedUnwinding(Saga $saga): bool
    {
        return $saga->steps->contains(
            static fn (SagaStep $step): bool => $step->status === SagaStepStatus::Failed
                || $step->status === SagaStepStatus::Compensated
        );
    }

    /**
     * The step whose forward action failed, if the record names one.
     */
    private function failedStepName(Saga $saga): ?string
    {
        return $saga->steps
            ->first(static fn (SagaStep $step): bool => $step->status === SagaStepStatus::Failed)
            ?->name;
    }

    /**
     * How both directions claim their key.
     *
     * `failingFast()` because a saga runs on a queue worker: the job's own backoff is a
     * better place to wait for another worker than a blocked worker slot, and the work is
     * released rather than dropped. `forTenant()` for attribution and cascade only —
     * isolation is not at stake here, because a key already contains the saga's ULID and
     * so cannot collide across tenants.
     */
    private function keyOptions(Saga $saga): IdempotencyOptions
    {
        return IdempotencyOptions::default()
            ->failingFast()
            ->forTenant($saga->tenant_id)
            ->keptFor($this->keyRetentionDays());
    }

    private function keyRetentionDays(): int
    {
        $configured = config('wa.reliability.saga.key_retention_days');

        return is_int($configured) && $configured >= 0 ? $configured : self::DEFAULT_KEY_RETENTION_DAYS;
    }

    /**
     * Error text for `last_error`: the class as well as the message, because a bare
     * "Connection refused" does not say which dependency refused it, and the exception
     * type usually does.
     */
    private function describe(Throwable $failure): string
    {
        $message = trim($failure->getMessage());

        return $message === ''
            ? $failure::class
            : sprintf('%s: %s', $failure::class, $message);
    }
}
