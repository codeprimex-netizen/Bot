<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SagaStepStatus;
use Database\Factories\SagaStepFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a saga: a forward action and the compensation that undoes it
 * (Req 31.5 / NFR2, Algorithm 8).
 *
 * `status` is the durable form of Algorithm 8's `done[]` set — `DONE` means "the
 * forward action landed and its compensation has not run yet" — so an unwind
 * interrupted by a crash can reconstruct exactly what it still owes.
 *
 * **No `tenant_id`, and that is deliberate.** A step is reachable only through its
 * saga, which is tenant-scoped, and cascade-deleted with it; ownership is transitive
 * with a single source of truth. What keeps that safe is an access rule this class
 * upholds rather than documents: it exposes **no by-id lookup and no route binding**,
 * so a step only ever enters memory through `$saga->steps`, after the parent's tenant
 * scope has already applied. Task 3.5 receives a `Saga` and walks its relation.
 *
 * @property string $id
 * @property string $saga_id
 * @property int $position
 * @property string $name
 * @property SagaStepStatus $status
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $compensation_payload
 * @property string|null $compensation_ref
 * @property int $attempts
 * @property int $compensation_attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $compensated_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Saga $saga
 */
class SagaStep extends Model
{
    /** @use HasFactory<SagaStepFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Cap on stored error text, matching the outbox's.
     */
    public const int MAX_ERROR_LENGTH = 2000;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'saga_id',
        'position',
        'name',
        'status',
        'payload',
        'compensation_payload',
        'compensation_ref',
        'attempts',
        'compensation_attempts',
        'last_error',
        'completed_at',
        'compensated_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => SagaStepStatus::class,
            'payload' => 'array',
            'compensation_payload' => 'array',
            'attempts' => 'integer',
            'compensation_attempts' => 'integer',
            'completed_at' => 'datetime',
            'compensated_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * The saga this step belongs to — and, transitively, the tenant that owns it.
     *
     * Deliberately left **tenant-scoped**: walking back to the parent is a read of
     * tenant data, so it is constrained like any other and fails closed with no tenant
     * bound. Some inverse relations in this codebase drop `TenantScope` on purpose
     * (`Tenant::usage()`), but they already name their tenant; this one does not, so
     * dropping it would turn a step — which carries no tenancy column of its own —
     * into a path to an arbitrary saga.
     *
     * @return BelongsTo<Saga, $this>
     */
    public function saga(): BelongsTo
    {
        return $this->belongsTo(Saga::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    | Scopes are for narrowing an *already scoped* relation (`$saga->steps()->…`).
    | They intentionally do not constrain `saga_id` themselves — a scope that could
    | be called on a bare `SagaStep::query()` and look safe would be the one seam
    | through which a step could be read outside its saga's tenant.
    */

    /**
     * Steps in Algorithm 8's `done[]` set: forward action succeeded, compensation not
     * yet run.
     *
     * @param  Builder<SagaStep>  $query
     * @return Builder<SagaStep>
     */
    public function scopeAwaitingCompensation(Builder $query): Builder
    {
        return $query->where('status', SagaStepStatus::Done);
    }

    /**
     * Steps whose forward action has not been attempted successfully yet.
     *
     * @param  Builder<SagaStep>  $query
     * @return Builder<SagaStep>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SagaStepStatus::Pending);
    }

    /**
     * Steps in reverse execution order — the order an unwind must run in.
     *
     * @param  Builder<SagaStep>  $query
     * @return Builder<SagaStep>
     */
    public function scopeInReverseOrder(Builder $query): Builder
    {
        return $query->reorder()->orderByDesc('position');
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this step still owes a compensation if the saga unwinds.
     */
    public function needsCompensation(): bool
    {
        return $this->status->needsCompensation();
    }

    /**
     * Whether the forward action may still be attempted.
     */
    public function awaitsExecution(): bool
    {
        return $this->status->awaitsExecution();
    }

    /**
     * Whether the step has a compensation to run at all.
     *
     * A step with neither a compensation reference nor a compensation payload is a
     * read-only or naturally-idempotent step (validate, notify); it can be marked
     * compensated without invoking anything.
     */
    public function hasCompensation(): bool
    {
        return $this->compensation_ref !== null || $this->compensation_payload !== null;
    }

    /**
     * Error text trimmed to `MAX_ERROR_LENGTH`, for writing to `last_error`.
     */
    public static function truncateError(string $error): string
    {
        return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }
}
