<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdempotencyState;
use App\Models\Scopes\TenantScope;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the generic side-effect dedup ledger (Req 31.2 / NFR2).
 *
 * `uniq(scope, key)` is the dedup: the insert is the lock, so a duplicate caller
 * loses on the unique index rather than on any coordination service.
 * `IdempotencyStore::once()` — the thing that performs that insert, waits on an
 * in-flight holder, and replays a completed result — is task 3.4.
 *
 * **Not tenant-scoped.** `tenant_id` is nullable and this model deliberately does not
 * use `BelongsToTenant`: gateway webhook intake dedups *before* it knows the tenant.
 * See the migration's docblock and `tenantScopeExemptions()`.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $scope
 * @property string $key
 * @property IdempotencyState $state
 * @property array<string, mixed>|null $result
 * @property string|null $response_hash
 * @property string|null $request_fingerprint
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    /**
     * How long an `IN_FLIGHT` lease is honoured before its holder is presumed dead.
     *
     * A worker that is `SIGKILL`ed mid-operation leaves the row locked for ever, so
     * the lease has to expire — otherwise one crash permanently blocks one key. The
     * window is deliberately generous relative to any single guarded operation
     * (which a circuit breaker and HTTP timeouts already bound), because breaking a
     * lease that is still live risks the double execution the row exists to prevent.
     */
    public const int STALE_LOCK_SECONDS = 300;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'scope',
        'key',
        'state',
        'result',
        'response_hash',
        'request_fingerprint',
        'locked_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => IdempotencyState::class,
            'result' => 'array',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The tenant this key was recorded for, or null when it was written before the
     * tenant was known (webhook intake).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, TenantScope::COLUMN);
    }

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    /**
     * A stable fingerprint of an arbitrary payload, for `response_hash` and
     * `request_fingerprint`.
     *
     * Keys are sorted recursively before hashing, so two logically equal payloads
     * that differ only in key order fingerprint the same — otherwise a retried
     * webhook whose JSON was re-serialised would look like a *different* request and
     * be rejected as a key reuse.
     *
     * @param  array<array-key, mixed>|string  $payload
     */
    public static function fingerprint(array|string $payload): string
    {
        if (is_array($payload)) {
            $payload = self::canonicalize($payload);
        }

        return hash('sha256', is_string($payload) ? $payload : (string) json_encode($payload));
    }

    /**
     * Recursively key-sort an array so its JSON encoding is canonical.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $offset => $value) {
            if (is_array($value)) {
                $payload[$offset] = self::canonicalize($value);
            }
        }

        return $payload;
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow to the single row identified by `(scope, key)`.
     *
     * The lookup task 3.4 builds on; `(scope, key)` is unique, so it is at most one
     * row.
     *
     * @param  Builder<IdempotencyKey>  $query
     * @return Builder<IdempotencyKey>
     */
    public function scopeForKey(Builder $query, string $scope, string $key): Builder
    {
        return $query->where('scope', $scope)->where('key', $key);
    }

    /**
     * Narrow to one dedup namespace.
     *
     * @param  Builder<IdempotencyKey>  $query
     * @return Builder<IdempotencyKey>
     */
    public function scopeInScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /**
     * Rows whose lease has expired: `IN_FLIGHT` but locked longer ago than
     * `STALE_LOCK_SECONDS`, so their holder is presumed dead and the key may be
     * retaken.
     *
     * @param  Builder<IdempotencyKey>  $query
     * @return Builder<IdempotencyKey>
     */
    public function scopeStale(Builder $query, ?int $staleSeconds = null): Builder
    {
        return $query
            ->where('state', IdempotencyState::InFlight)
            ->where('locked_at', '<=', now()->subSeconds($staleSeconds ?? self::STALE_LOCK_SECONDS));
    }

    /**
     * Rows past their retention horizon — what the pruner deletes.
     *
     * Rows with no `expires_at` are never selected: absent an explicit horizon the
     * ledger keeps the entry, because deleting it silently re-arms a side effect that
     * was already applied.
     *
     * @param  Builder<IdempotencyKey>  $query
     * @return Builder<IdempotencyKey>
     */
    public function scopePrunable(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a duplicate caller can be served from this row without re-running the
     * operation.
     */
    public function isReplayable(): bool
    {
        return $this->state->isReplayable();
    }

    /**
     * Whether another worker holds a *live* lease on this key.
     */
    public function isHeld(?int $staleSeconds = null): bool
    {
        return $this->state->isInFlight() && ! $this->lockHasExpired($staleSeconds);
    }

    /**
     * Whether an `IN_FLIGHT` lease is old enough to be presumed abandoned.
     */
    public function lockHasExpired(?int $staleSeconds = null): bool
    {
        if ($this->locked_at === null) {
            return true;
        }

        return $this->locked_at->addSeconds($staleSeconds ?? self::STALE_LOCK_SECONDS)->isPast();
    }

    /**
     * Whether the operation may be executed for this key: it failed, or its holder is
     * presumed dead.
     */
    public function allowsExecution(?int $staleSeconds = null): bool
    {
        return $this->state->allowsExecution()
            || ($this->state->isInFlight() && $this->lockHasExpired($staleSeconds));
    }

    /**
     * Whether $payload matches the request this key was first used with.
     *
     * `true` when no fingerprint was recorded: a caller that opted out of request
     * checking is not retroactively held to it.
     *
     * @param  array<array-key, mixed>|string  $payload
     */
    public function matchesRequest(array|string $payload): bool
    {
        return $this->request_fingerprint === null
            || $this->request_fingerprint === self::fingerprint($payload);
    }

    /**
     * Whether this row is past its retention horizon.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
