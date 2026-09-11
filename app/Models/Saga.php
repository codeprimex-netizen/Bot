<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SagaStatus;
use App\Enums\SagaStepStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SagaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A persisted multi-step business transaction with per-step compensations
 * (Req 31.5 / NFR2, Algorithm 8, Correctness Property 18).
 *
 * The rows are the orchestrator's memory: `steps` with `status = DONE` *is*
 * Algorithm 8's `done[]` set, which is why a crash mid-flight can resume and an
 * interrupted unwind keeps unwinding. `SagaOrchestrator::run()` is task 3.5; this
 * model only describes and queries the record.
 *
 * **Tenant-owned.** Unlike the other reliability tables, a saga carries tenant
 * business content (`state` holds the order, the amounts, the customer) and always
 * belongs to exactly one tenant, so `BelongsToTenant` applies in full and
 * `tenant_id` is not null. The crash-recovery sweep, which is the one caller that
 * spans tenants, is written as:
 *
 * ```php
 * foreach (Saga::withoutTenantScope()->resumable()->cursor() as $saga) {
 *     $tenants->runFor($saga->tenant, fn () => $orchestrator->run($saga));
 * }
 * ```
 *
 * — `withoutTenantScope()` to find the work, `runFor()` so the run itself is scoped
 * to the owner. No platform-mode bypass is needed anywhere in saga recovery.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string|null $correlation_id
 * @property SagaStatus $status
 * @property array<string, mixed>|null $state
 * @property int $current_step
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SagaStep> $steps
 */
class Saga extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SagaFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Separator between a saga id and a step name in an idempotency key
     * (`{sagaId}:{stepName}`, Algorithm 8).
     */
    public const string STEP_KEY_SEPARATOR = ':';

    /**
     * The `idempotency_keys.scope` sagas dedup their step executions under.
     */
    public const string IDEMPOTENCY_SCOPE = 'saga';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'type',
        'correlation_id',
        'status',
        'state',
        'current_step',
        'last_error',
        'completed_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SagaStatus::class,
            'state' => 'array',
            'current_step' => 'integer',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * The saga's steps in execution order.
     *
     * Ordered in the relation itself, not at each call site: every consumer of a saga
     * needs the same total order (forward runs walk it, unwinds reverse it), and an
     * unordered read would make the compensation order — the part Property 18 is
     * about — depend on whatever the storage engine returned.
     *
     * @return HasMany<SagaStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SagaStep::class)->orderBy('position');
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Sagas the orchestrator still owes work on (`RUNNING` or `COMPENSATING`), oldest
     * stall first — the crash-recovery sweep's query.
     *
     * @param  Builder<Saga>  $query
     * @return Builder<Saga>
     */
    public function scopeResumable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', SagaStatus::resumableValues())
            ->orderBy('updated_at');
    }

    /**
     * Sagas that have been in a non-terminal state for longer than $seconds — the
     * subset of `resumable()` worth alerting on rather than merely resuming.
     *
     * @param  Builder<Saga>  $query
     * @return Builder<Saga>
     */
    public function scopeStalled(Builder $query, int $seconds): Builder
    {
        return $query
            ->whereIn('status', SagaStatus::resumableValues())
            ->where('updated_at', '<=', now()->subSeconds($seconds));
    }

    /**
     * Narrow to the saga for one business key — how a caller asks "has the fulfilment
     * saga for this order already been started?".
     *
     * With the tenant scope applied this hits `uniq(tenant_id, type, correlation_id)`,
     * so it is at most one row.
     *
     * @param  Builder<Saga>  $query
     * @return Builder<Saga>
     */
    public function scopeForCorrelation(Builder $query, string $type, string $correlationId): Builder
    {
        return $query->where('type', $type)->where('correlation_id', $correlationId);
    }

    /**
     * Narrow to one saga type.
     *
     * @param  Builder<Saga>  $query
     * @return Builder<Saga>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /*
    |--------------------------------------------------------------------------
    | What the record says
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the orchestrator still has work to do here.
     */
    public function isResumable(): bool
    {
        return $this->status->isResumable();
    }

    /**
     * Whether the saga has reached `COMPLETED` or `FAILED`.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * The steps whose forward action succeeded and whose compensation has not run —
     * Algorithm 8's `done[]`, **in reverse order**, i.e. exactly the sequence an
     * unwind must follow.
     *
     * Read from the already-loaded relation rather than re-queried, so an unwind that
     * runs inside a transaction sees the same rows it has been marking.
     *
     * @return Collection<int, SagaStep>
     */
    public function compensatableSteps(): Collection
    {
        return $this->steps
            ->filter(static fn (SagaStep $step): bool => $step->status->needsCompensation())
            ->reverse()
            ->values();
    }

    /**
     * The next step awaiting its forward action, or null when every step is settled.
     */
    public function nextPendingStep(): ?SagaStep
    {
        return $this->steps
            ->first(static fn (SagaStep $step): bool => $step->status->awaitsExecution());
    }

    /**
     * The step at `current_step`, or null if the index points past the last step.
     */
    public function currentStep(): ?SagaStep
    {
        return $this->steps
            ->first(fn (SagaStep $step): bool => $step->position === $this->current_step);
    }

    /**
     * Whether every step's forward action has succeeded — the precondition for
     * `COMPLETED`.
     *
     * A saga with no steps is not complete: an empty saga is a definition bug, and
     * reporting success for work that was never described would hide it.
     */
    public function allStepsDone(): bool
    {
        return $this->steps->isNotEmpty()
            && $this->steps->every(
                static fn (SagaStep $step): bool => $step->status === SagaStepStatus::Done
            );
    }

    /**
     * Whether nothing is left to undo — the precondition for `FAILED` at the end of an
     * unwind (Property 18: "no partial side effect").
     */
    public function isFullyCompensated(): bool
    {
        return $this->compensatableSteps()->isEmpty();
    }

    /**
     * The idempotency key for a step's **forward** action: `{sagaId}:{stepName}`
     * (Algorithm 8's `key := saga.id + ':' + step.name`).
     *
     * Defined here so the orchestrator, the store, and any test agree on the string.
     */
    public function stepKey(SagaStep|string $step): string
    {
        return $this->id.self::STEP_KEY_SEPARATOR.($step instanceof SagaStep ? $step->name : $step);
    }

    /**
     * The idempotency key for a step's **compensation**:
     * `{sagaId}:{stepName}:compensate`.
     *
     * Algorithm 8's pseudocode passes the *same* literal key to
     * `executeIdempotent(forward, …)` and `compensateIdempotent(compensation, …)`.
     * Taken literally against a real `IdempotencyStore`, that is a bug rather than a
     * feature: the forward action has already recorded that key as `COMPLETED`, so
     * `once()` would replay its result and the compensation would never run — turning
     * every unwind into a silent no-op and breaking the very property the saga exists
     * to hold (Property 18, "leaving no partial side effect").
     *
     * The two directions are therefore keyed distinctly. Each stays idempotent on its
     * own key, which is what the algorithm's preconditions actually require. Task 3.5
     * MUST use this for compensations and `stepKey()` for forwards.
     */
    public function compensationKey(SagaStep|string $step): string
    {
        return $this->stepKey($step).self::STEP_KEY_SEPARATOR.'compensate';
    }
}
