<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboxStatus;
use App\Models\Scopes\TenantScope;
use Database\Factories\OutboxMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the transactional outbox: a side-effecting intent recorded in the same
 * transaction as the state change that caused it (Req 31.4 / NFR2, Algorithm 6,
 * Correctness Property 16).
 *
 * The model owns the *shape* of a row — casts, the claim predicate, the dedup key —
 * and nothing about delivery. `enqueue()`/`relay()` and the relay worker are task 3.3
 * (`App\Services\Reliability\Outbox`).
 *
 * **Not tenant-scoped.** `tenant_id` is nullable and this model deliberately does not
 * use `BelongsToTenant`; the migration's docblock records the reasoning and
 * `tenantScopeExemptions()` in `TenantOwnedModelsGuardTest` records the decision.
 * Tenant-facing reads use `forTenant()`, which states the tenant explicitly.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_type
 * @property string|null $destination
 * @property array<string, mixed> $payload
 * @property string $dedup_key
 * @property OutboxStatus $status
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $available_at
 * @property \Illuminate\Support\Carbon $next_attempt_at
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 */
class OutboxMessage extends Model
{
    /** @use HasFactory<OutboxMessageFactory> */
    use HasFactory;

    /**
     * The row-locking clause the relay claims a batch with (Algorithm 6).
     *
     * `SKIP LOCKED` is what lets several relay workers drain one queue without
     * blocking on each other or double-delivering: a row another worker already holds
     * is skipped rather than waited for.
     *
     * **Portability:** MySQL 8 emits the clause verbatim; SQLite's grammar compiles
     * every lock to the empty string, so the same builder call is a no-op there. That
     * is safe rather than merely convenient — the test suite runs single-threaded on
     * SQLite, where there is no concurrent claimer to skip. Concurrent-claim behaviour
     * is therefore a MySQL-only property and must be asserted against MySQL (task 3.3),
     * not inferred from a green SQLite run.
     */
    public const string CLAIM_LOCK = 'for update skip locked';

    /**
     * Cap on stored error text: enough to diagnose, not enough for a provider to
     * dump a response body into the database.
     */
    public const int MAX_ERROR_LENGTH = 2000;

    protected $table = 'outbox';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'destination',
        'payload',
        'dedup_key',
        'status',
        'attempts',
        'available_at',
        'next_attempt_at',
        'last_error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * The tenant this effect was produced for, or null for a platform-level effect.
     *
     * Declared by hand rather than inherited from `BelongsToTenant`: the relation is
     * wanted, the global scope is not.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, TenantScope::COLUMN);
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Rows the relay may deliver right now, in delivery order.
     *
     * Algorithm 6's claim predicate exactly — `status IN {PENDING, FAILED}` and
     * `next_attempt_at <= now()`, ordered by `id` so delivery is FIFO — and nothing
     * else. Task 3.3 wraps it:
     *
     * ```php
     * DB::transaction(function () use ($batch) {
     *     $rows = OutboxMessage::query()->claimable()->lockForClaim()->limit($batch)->get();
     *     // … deliver, then mark sent/failed
     * });
     * ```
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', OutboxStatus::claimableValues())
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id');
    }

    /**
     * Apply the relay's row lock (`FOR UPDATE SKIP LOCKED` on MySQL, no-op on SQLite).
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     *
     * @see self::CLAIM_LOCK for the portability contract
     */
    public function scopeLockForClaim(Builder $query): Builder
    {
        return $query->lock(self::CLAIM_LOCK);
    }

    /**
     * Narrow to one tenant's rows — the panel's delivery log.
     *
     * The explicit counterpart to the global scope this model does not have: the
     * tenant is named at the call site, so a tenant-facing read is visibly
     * constrained. Passing `null` selects the platform-level rows.
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeForTenant(Builder $query, Tenant|string|null $tenant): Builder
    {
        if ($tenant === null) {
            return $query->whereNull(TenantScope::COLUMN);
        }

        return $query->where(
            TenantScope::COLUMN,
            $tenant instanceof Tenant ? $tenant->id : $tenant,
        );
    }

    /**
     * Narrow to one row by its dedup key — the consumer-side and operator-side
     * lookup, served by the unique index.
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeForDedupKey(Builder $query, string $dedupKey): Builder
    {
        return $query->where('dedup_key', $dedupKey);
    }

    /**
     * Narrow to the effects produced by one aggregate ("why was this webhook sent?").
     *
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    public function scopeForAggregate(Builder $query, string $type, string $id): Builder
    {
        return $query->where('aggregate_type', $type)->where('aggregate_id', $id);
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the relay may attempt this row at this moment.
     *
     * The in-memory mirror of `scopeClaimable()`; both read the same two facts, so a
     * row loaded outside the claim query can be tested without a second round trip.
     */
    public function isClaimable(): bool
    {
        return $this->status->isClaimable() && ! $this->next_attempt_at->isFuture();
    }

    /**
     * Whether the row is waiting on its schedule or its backoff rather than on a
     * worker — the distinction an operator needs when a queue looks stalled.
     */
    public function isDeferred(): bool
    {
        return $this->status->isClaimable() && $this->next_attempt_at->isFuture();
    }

    /**
     * Whether the receiver has acked this effect.
     */
    public function isDelivered(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * How long the retry gate is still shut, in seconds (0 when it is open).
     */
    public function secondsUntilAvailable(): int
    {
        return $this->next_attempt_at->isFuture()
            ? (int) ceil(now()->diffInSeconds($this->next_attempt_at, absolute: true))
            : 0;
    }

    /**
     * Whether delivery has been retried at all beyond the first attempt.
     */
    public function hasBeenRetried(): bool
    {
        return $this->attempts > 1;
    }

    /**
     * The headers a consumer needs to dedup a redelivery (Property 16).
     *
     * Kept here so the enqueuer, the relay, and the consumer-side contract test all
     * read the same header name from one place.
     *
     * @return array<string, string>
     */
    public function deliveryHeaders(): array
    {
        return [
            'X-Dedup-Key' => $this->dedup_key,
            'X-Event-Type' => $this->event_type,
        ];
    }

    /**
     * Error text trimmed to `MAX_ERROR_LENGTH`, for writing to `last_error`.
     */
    public static function truncateError(string $error): string
    {
        return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }
}
