<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use Database\Factories\CircuitBreakerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted state of one circuit breaker, identified by `(scope, name)`
 * (Req 31.3 / NFR2, Algorithm 7, Correctness Property 13).
 *
 * This is the breaker's *memory*, not its behaviour: the row records what has
 * happened to a dependency recently and which state that put it in, and it answers
 * questions about that record. Deciding to open, rotate the window, or admit a probe
 * belongs to `App\Services\Reliability\CircuitBreaker` (task 3.2), which is also the
 * only place the thresholds from the design's breaker table are read.
 *
 * **Platform-level, no `tenant_id`.** Per-tenant breakers are keyed by putting the
 * tenant id in `name` (`compositeName('openai', $tenantId)`); the migration's
 * docblock records why a scoped column would break the primitive.
 *
 * @property int $id
 * @property CircuitScope $scope
 * @property string $name
 * @property CircuitState $state
 * @property int $failure_count
 * @property int $success_count
 * @property int $half_open_probes
 * @property int $half_open_successes
 * @property \Illuminate\Support\Carbon|null $window_started_at
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $last_failure_at
 * @property \Illuminate\Support\Carbon|null $last_success_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CircuitBreaker extends Model
{
    /** @use HasFactory<CircuitBreakerFactory> */
    use HasFactory;

    /**
     * Separator between the parts of a composite breaker name.
     *
     * Chosen so a name reads as a path (`openai:01HZ…`) and so the tenant id — a
     * ULID, which is alphanumeric — can never contain it and split the name
     * ambiguously.
     */
    public const string NAME_SEPARATOR = ':';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scope',
        'name',
        'state',
        'failure_count',
        'success_count',
        'half_open_probes',
        'half_open_successes',
        'window_started_at',
        'opened_at',
        'last_failure_at',
        'last_success_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CircuitScope::class,
            'state' => CircuitState::class,
            'failure_count' => 'integer',
            'success_count' => 'integer',
            'half_open_probes' => 'integer',
            'half_open_successes' => 'integer',
            'window_started_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    /**
     * Build a composite breaker name from its parts, skipping empties.
     *
     * The single place the per-tenant encoding is defined, so a breaker opened by
     * the send path and read by the panel cannot disagree about its own name:
     *
     * ```php
     * CircuitBreaker::compositeName('openai', $tenantId);   // 'openai:01HZ…'
     * CircuitBreaker::compositeName('openai');              // 'openai'
     * ```
     */
    public static function compositeName(string ...$parts): string
    {
        return implode(self::NAME_SEPARATOR, array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow to the single breaker identified by `(scope, name)`.
     *
     * The primary lookup for task 3.2 — `(scope, name)` is unique, so this is
     * always at most one row:
     *
     * ```php
     * CircuitBreaker::query()->forKey(CircuitScope::Provider, $name)->first();
     * ```
     *
     * @param  Builder<CircuitBreaker>  $query
     * @return Builder<CircuitBreaker>
     */
    public function scopeForKey(Builder $query, CircuitScope|string $scope, string $name): Builder
    {
        return $query
            ->where('scope', $scope instanceof CircuitScope ? $scope : CircuitScope::from($scope))
            ->where('name', $name);
    }

    /**
     * Narrow to one breaker family.
     *
     * @param  Builder<CircuitBreaker>  $query
     * @return Builder<CircuitBreaker>
     */
    public function scopeInScope(Builder $query, CircuitScope|string $scope): Builder
    {
        return $query->where('scope', $scope instanceof CircuitScope ? $scope : CircuitScope::from($scope));
    }

    /**
     * Breakers that are not healthy — the health dashboard's query.
     *
     * @param  Builder<CircuitBreaker>  $query
     * @return Builder<CircuitBreaker>
     */
    public function scopeUnhealthy(Builder $query): Builder
    {
        return $query->whereIn('state', [CircuitState::Open, CircuitState::HalfOpen]);
    }

    /*
    |--------------------------------------------------------------------------
    | What the record says
    |--------------------------------------------------------------------------
    */

    /**
     * Calls recorded in the current failure window.
     */
    public function windowCalls(): int
    {
        return $this->failure_count + $this->success_count;
    }

    /**
     * Failure ratio over the current window, in `[0.0, 1.0]`.
     *
     * An empty window is `0.0` — no evidence is not evidence of failure, so a fresh
     * breaker can never be opened by the error-rate arm of Algorithm 7 before it has
     * seen any calls.
     */
    public function errorRate(): float
    {
        $calls = $this->windowCalls();

        return $calls === 0 ? 0.0 : $this->failure_count / $calls;
    }

    /**
     * Whether the failure window has been open for at least $windowSeconds.
     *
     * Task 3.2 rotates the window when this is true, which is what keeps
     * Algorithm 7's loop invariant ("`failure_count` counts only failures within the
     * current rolling window") true. A breaker with no window has nothing to rotate.
     */
    public function windowHasExpired(int $windowSeconds): bool
    {
        return $this->window_started_at !== null
            && $this->window_started_at->addSeconds($windowSeconds)->isPast();
    }

    /**
     * Whether an `OPEN` breaker has served its cool-down and may start probing.
     */
    public function openDurationHasElapsed(int $openSeconds): bool
    {
        return $this->opened_at !== null
            && $this->opened_at->addSeconds($openSeconds)->isPast();
    }

    /**
     * Probes still available in the current half-open window.
     */
    public function probesRemaining(int $probeLimit): int
    {
        return max(0, $probeLimit - $this->half_open_probes);
    }

    /**
     * Whether another half-open probe may be admitted.
     *
     * The row-level half of `CircuitState::admitsCalls()`: the state says *whether*
     * calls are permitted, this says whether the budget for one is left. Both must
     * hold before task 3.2 invokes the guarded operation.
     */
    public function hasProbeCapacity(int $probeLimit): bool
    {
        return $this->probesRemaining($probeLimit) > 0;
    }

    /**
     * Whether the dependency behind this breaker is currently considered healthy.
     */
    public function isHealthy(): bool
    {
        return $this->state->isClosed();
    }

    /**
     * The breaker's full key, for logs and metric labels.
     */
    public function key(): string
    {
        return $this->scope->value.self::NAME_SEPARATOR.$this->name;
    }
}
