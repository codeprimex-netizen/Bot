<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Algorithm 7, against a persisted `circuit_breakers` row with a short-lived snapshot in
 * front of it (Req 31.3 / NFR2; Req 13.13 / B4; Correctness Property 13).
 *
 * See the `CircuitBreaker` interface for the API, the naming split against
 * `App\Models\CircuitBreaker`, and the consumers. This docblock is about *how* the three
 * hard parts are made to hold.
 *
 * ## 1. The operation is never invoked while OPEN
 *
 * There is exactly one `$operation()` call site in this class, and it is reached only
 * after `admitOrFail()` has returned — which it does only when the **row** (never the
 * snapshot) says a call may run:
 *
 * - `CLOSED` → admitted;
 * - `OPEN` and inside the cool-down → `CircuitOpenException`, no query, no invocation;
 * - `OPEN` and past the cool-down → one conditional `UPDATE` moves it to `HALF_OPEN`,
 *   then the probe rule below applies;
 * - `HALF_OPEN` → admitted only if this caller wins a probe from the budget.
 *
 * The cache can shorten that to "refused" earlier, never to "admitted" (see
 * `CircuitBreakerCache`). So Property 13 holds regardless of cache TTL, cache store, or
 * whether the cache is enabled at all.
 *
 * ## 2. Several workers share one row
 *
 * Every decision that must not be made twice is a **conditional `UPDATE`**, so the
 * database — not a lock, not a coordination service — decides who wins. This is the same
 * technique, and for the same reason, as `QuotaParkingLot::claim()`: it holds identically
 * on SQLite (where the suite runs) and MySQL 8 (production), unlike
 * `FOR UPDATE SKIP LOCKED`, which compiles away on SQLite. **Nothing here is
 * MySQL-only.**
 *
 * | Decision | Statement | Why it is safe |
 * |---|---|---|
 * | claim a probe | `UPDATE … SET half_open_probes = half_open_probes + 1 WHERE id = ? AND state = 'HALF_OPEN' AND half_open_probes < :limit` | the bound and the increment are one statement, so N workers claim N distinct probes and the N+1st is told no — two workers can never each admit 3 |
 * | `OPEN → HALF_OPEN` | `… WHERE id = ? AND state = 'OPEN'` | only one worker's move takes effect; the loser re-reads and finds the state the winner set |
 * | trip / re-open | `… WHERE id = ? AND state <> 'OPEN'` | a second concurrent trip is a no-op, so a cool-down is never silently restarted from under a probe |
 * | `HALF_OPEN → CLOSED` | `… WHERE id = ? AND state = 'HALF_OPEN'` | a success that lands after another worker's probe failure re-opened the breaker cannot close it — `OPEN → CLOSED` is not a legal edge, recovery must be *proven* |
 * | count an outcome | `failure_count = failure_count + 1` (relative, in SQL) | counters cannot fork: no worker ever writes back a total it read |
 * | rotate the window | `… WHERE id = ? AND (window_started_at IS NULL OR window_started_at < :cutoff)` | whoever rotates first wins; every later rotation for the same expired window is a no-op, so a freshly counted failure cannot be rotated away |
 *
 * The trip decision re-reads the row after counting, and that ordering is what makes it
 * exact: a worker always sees at least its own increment, and in-window counters only ever
 * grow, so the worker whose failure crosses a threshold cannot fail to notice — **no trip
 * is ever missed**, and a double trip is absorbed by the `state <> 'OPEN'` predicate.
 *
 * ## 3. `failure_count` counts only failures inside the current window
 *
 * Algorithm 7's loop invariant. The window is started on the first recorded outcome and
 * rotated — counters zeroed, `window_started_at` moved — before an outcome is counted,
 * whenever it has been open longer than `windowSeconds`. Rotating *before* counting is
 * what stops the invariant from losing the very failure that triggered the rotation, and
 * the window therefore rolls rather than accumulating for ever.
 *
 * ### One deliberate divergence from the pseudocode
 *
 * Algorithm 7 writes `b.failure_count <- 0` on **every** success. Taken literally that
 * turns the count arm into "5 *consecutive* failures" and makes the error-rate arm dead
 * code — the design's own threshold table (`≥5 fails / 30s **or** >50% err over 20`) and
 * Req 13.13 (*"at least 5 failures within a 30-second window or an error rate above 50%
 * over the last 20 calls"*) both describe a window, not a streak. The window reading is
 * the normative one and the strict superset, so that is what is implemented: successes are
 * counted alongside failures in the window, and the counters are zeroed on the two events
 * that genuinely end a window — a **rotation** (the failures aged out) and a transition to
 * **CLOSED** (recovery was proven, so the record before it is history). The pseudocode's
 * intent, that isolated noise must not accumulate into a trip, is served by rotation.
 */
final readonly class PersistedCircuitBreaker implements CircuitBreaker
{
    public function __construct(
        private CircuitBreakerCache $cache,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws CircuitOpenException the operation is not invoked
     * @throws Throwable whatever $operation threw
     */
    public function call(CircuitScope|string $scope, string $name, callable $operation): mixed
    {
        $key = CircuitBreakerKey::for($scope, $name);
        $thresholds = CircuitBreakerThresholds::forScope($key->scope);

        // Load shedding: while a breaker is open, refusing costs one cache read and no
        // query at all — which is the whole point, because that is exactly when every
        // worker is asking about the same breaker at once.
        $snapshot = $this->cache->get($key);

        if ($snapshot instanceof CircuitBreakerRecord && ! $this->couldAdmit($snapshot, $thresholds)) {
            throw $this->refusal($key, $snapshot, $thresholds);
        }

        $row = $this->record($key);

        $this->admitOrFail($key, $row, $thresholds);

        try {
            // The one and only invocation of the guarded operation in this class.
            $result = $operation();
        } catch (Throwable $failure) {
            $this->recordFailure($key, $row, $thresholds);

            throw $failure;
        }

        $this->recordSuccess($key, $row, $thresholds);

        return $result;
    }

    public function state(CircuitScope|string $scope, string $name): CircuitState
    {
        $key = CircuitBreakerKey::for($scope, $name);
        $row = $this->read($key);

        if (! $row instanceof CircuitBreakerRecord) {
            // Never exercised: no row, and a dependency nobody has called is not broken.
            return CircuitState::Closed;
        }

        $thresholds = CircuitBreakerThresholds::forScope($key->scope);

        if ($row->state->isOpen() && $row->openDurationHasElapsed($thresholds->openSeconds)) {
            // The state the next call will find it in. `call()` performs the persisted move.
            return CircuitState::HalfOpen;
        }

        return $row->state;
    }

    public function allows(CircuitScope|string $scope, string $name): bool
    {
        $key = CircuitBreakerKey::for($scope, $name);

        return $this->couldAdmit($this->read($key), CircuitBreakerThresholds::forScope($key->scope));
    }

    public function trip(CircuitScope|string $scope, string $name): void
    {
        $key = CircuitBreakerKey::for($scope, $name);
        $row = $this->record($key);

        $this->open($key, $row);
    }

    public function reset(CircuitScope|string $scope, string $name): void
    {
        $key = CircuitBreakerKey::for($scope, $name);
        $row = $this->record($key);
        $now = $this->now();

        $this->write($key, $row, [
            'state' => CircuitState::Closed,
            'failure_count' => 0,
            'success_count' => 0,
            'half_open_probes' => 0,
            'half_open_successes' => 0,
            'window_started_at' => $now,
            'opened_at' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Admission
    |--------------------------------------------------------------------------
    */

    /**
     * Settle whether this call may run, against the row.
     *
     * @throws CircuitOpenException when it may not — before the operation is reached
     */
    private function admitOrFail(CircuitBreakerKey $key, CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): void
    {
        if ($row->state->isOpen()) {
            if (! $row->openDurationHasElapsed($thresholds->openSeconds)) {
                throw $this->refusal($key, $row, $thresholds);
            }

            $this->openToHalfOpen($key, $row);

            if ($row->state->isOpen()) {
                // Another worker re-opened it between our cool-down check and the move —
                // a probe of theirs failed. Its fresh cool-down applies to us too.
                throw $this->refusal($key, $row, $thresholds);
            }
        }

        if ($row->state->isHalfOpen() && ! $this->claimProbe($key, $row, $thresholds)) {
            throw $this->refusal($key, $row, $thresholds);
        }
    }

    /**
     * Whether a call could be admitted, judged from a row or snapshot alone.
     *
     * Shared by `allows()` and by the cache fast path, so the two can never disagree
     * about what "the door is open" means. A null row is a breaker nobody has exercised.
     */
    private function couldAdmit(?CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): bool
    {
        if (! $row instanceof CircuitBreakerRecord) {
            return true;
        }

        if ($row->state->isOpen()) {
            // Past the cool-down a probe is due, so the door is open — for one caller.
            return $row->openDurationHasElapsed($thresholds->openSeconds);
        }

        if ($row->state->isHalfOpen()) {
            return $row->hasProbeCapacity($thresholds->probeLimit);
        }

        return true;
    }

    /**
     * Claim one probe from the half-open budget, atomically.
     *
     * The bound and the increment are a single statement, which is what makes the probe
     * limit a fleet-wide cap rather than a per-process one. A losing claim re-reads: the
     * budget may be spent, or the breaker may have been closed by a winner's success (in
     * which case this call is admitted as a normal closed-circuit call) or re-opened by a
     * winner's failure.
     */
    private function claimProbe(CircuitBreakerKey $key, CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): bool
    {
        $claimed = CircuitBreakerRecord::query()
            ->whereKey($row->getKey())
            ->where('state', CircuitState::HalfOpen)
            ->where('half_open_probes', '<', $thresholds->probeLimit)
            ->increment('half_open_probes');

        if ($claimed > 0) {
            $row->half_open_probes++;
            $this->cache->put($key, $row);

            return true;
        }

        $this->reread($key, $row);

        return $row->state->isClosed();
    }

    /*
    |--------------------------------------------------------------------------
    | Recording outcomes
    |--------------------------------------------------------------------------
    */

    /**
     * The operation failed: count it in the current window and open the breaker if an
     * arm of Algorithm 7's condition is crossed.
     */
    private function recordFailure(CircuitBreakerKey $key, CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): void
    {
        $this->startOrRotateWindow($key, $row, $thresholds);

        CircuitBreakerRecord::query()
            ->whereKey($row->getKey())
            ->increment('failure_count', 1, ['last_failure_at' => $this->now()]);

        // Re-read before judging: counters are shared, and this worker must see at least
        // its own increment. Within a window they only grow, so the worker whose failure
        // crosses a threshold always sees it — no trip is missed.
        $this->reread($key, $row);

        if ($thresholds->tripsOn($row)) {
            $this->open($key, $row);
        }
    }

    /**
     * The operation succeeded: count it, and close the breaker once enough probes have
     * proven recovery.
     */
    private function recordSuccess(CircuitBreakerKey $key, CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): void
    {
        $this->startOrRotateWindow($key, $row, $thresholds);

        $wasProbing = $row->state->isHalfOpen();
        $now = $this->now();

        if ($wasProbing) {
            CircuitBreakerRecord::query()
                ->whereKey($row->getKey())
                ->incrementEach(
                    ['success_count' => 1, 'half_open_successes' => 1],
                    ['last_success_at' => $now],
                );
        } else {
            CircuitBreakerRecord::query()
                ->whereKey($row->getKey())
                ->increment('success_count', 1, ['last_success_at' => $now]);
        }

        $this->reread($key, $row);

        // Only a probe can close a breaker, and only while it is still HALF_OPEN: a
        // success that lands after another worker's probe failure re-opened it must not
        // undo that (`OPEN -> CLOSED` is not a legal edge).
        if ($row->state->isHalfOpen() && $row->half_open_successes >= $thresholds->probeSuccesses) {
            $this->close($key, $row);
        }
    }

    /**
     * Start the failure window, or roll it if it has been open longer than
     * `windowSeconds` — always **before** an outcome is counted.
     *
     * One conditional statement, so concurrent workers rotate an expired window exactly
     * once between them; the second worker's rotation matches nothing and its outcome is
     * then counted into the window the first one started. That, plus relative SQL
     * increments, is Algorithm 7's loop invariant: `failure_count` holds the failures of
     * the current window and no others, and rotation never discards one.
     */
    private function startOrRotateWindow(CircuitBreakerKey $key, CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): void
    {
        if ($row->window_started_at !== null && ! $row->windowHasExpired($thresholds->windowSeconds)) {
            return;
        }

        $now = $this->now();

        $rotated = CircuitBreakerRecord::query()
            ->whereKey($row->getKey())
            ->where(function (Builder $stale) use ($now, $thresholds): void {
                $stale
                    ->whereNull('window_started_at')
                    ->orWhere('window_started_at', '<', $now->copy()->subSeconds($thresholds->windowSeconds));
            })
            ->update([
                'failure_count' => 0,
                'success_count' => 0,
                'window_started_at' => $now,
            ]);

        if ($rotated > 0) {
            $row->failure_count = 0;
            $row->success_count = 0;
            $row->window_started_at = $now;
            $this->cache->put($key, $row);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Open the breaker and start its cool-down — a trip, a re-opened probe, or the manual
     * kill-switch.
     *
     * `state <> OPEN` so a concurrent second trip is a no-op instead of restarting the
     * cool-down under a probe that is already in flight.
     */
    private function open(CircuitBreakerKey $key, CircuitBreakerRecord $row): void
    {
        $now = $this->now();

        $opened = CircuitBreakerRecord::query()
            ->whereKey($row->getKey())
            ->where('state', '!=', CircuitState::Open)
            ->update([
                'state' => CircuitState::Open,
                'opened_at' => $now,
                // Reset together with the transition: a probe budget belongs to one
                // half-open window, and carrying spent probes into the next one would
                // hand the recovering dependency fewer probes than configured.
                'half_open_probes' => 0,
                'half_open_successes' => 0,
            ]);

        if ($opened > 0) {
            $row->state = CircuitState::Open;
            $row->opened_at = $now;
            $row->half_open_probes = 0;
            $row->half_open_successes = 0;
        }

        $this->cache->put($key, $row);
    }

    /**
     * Start probing after the cool-down: `OPEN → HALF_OPEN` with a fresh probe budget.
     *
     * `opened_at` is deliberately left alone — it is only consulted while OPEN, and an
     * operator looking at a probing breaker still wants to know when it tripped.
     */
    private function openToHalfOpen(CircuitBreakerKey $key, CircuitBreakerRecord $row): void
    {
        $moved = CircuitBreakerRecord::query()
            ->whereKey($row->getKey())
            ->where('state', CircuitState::Open)
            ->update([
                'state' => CircuitState::HalfOpen,
                'half_open_probes' => 0,
                'half_open_successes' => 0,
            ]);

        if ($moved > 0) {
            $row->state = CircuitState::HalfOpen;
            $row->half_open_probes = 0;
            $row->half_open_successes = 0;
            $this->cache->put($key, $row);

            return;
        }

        // Lost the race: whatever the winner decided is now the truth.
        $this->reread($key, $row);
    }

    /**
     * Recovery proven: `HALF_OPEN → CLOSED`, on a clean window.
     */
    private function close(CircuitBreakerKey $key, CircuitBreakerRecord $row): void
    {
        $now = $this->now();

        $closed = CircuitBreakerRecord::query()
            ->whereKey($row->getKey())
            ->where('state', CircuitState::HalfOpen)
            ->update([
                'state' => CircuitState::Closed,
                // The window before a recovery is history: keeping its failures would let
                // a breaker that has just been proven healthy be re-opened by them.
                'failure_count' => 0,
                'success_count' => 0,
                'window_started_at' => $now,
                'opened_at' => null,
                'half_open_probes' => 0,
                'half_open_successes' => 0,
            ]);

        if ($closed > 0) {
            $row->state = CircuitState::Closed;
            $row->failure_count = 0;
            $row->success_count = 0;
            $row->window_started_at = $now;
            $row->opened_at = null;
            $row->half_open_probes = 0;
            $row->half_open_successes = 0;
        }

        $this->cache->put($key, $row);
    }

    /*
    |--------------------------------------------------------------------------
    | Row access
    |--------------------------------------------------------------------------
    */

    /**
     * The row for $key, created on first use.
     *
     * `createOrFirst()` rather than a read-then-create: two workers guarding the same
     * dependency for the first time in the same millisecond race on
     * `uniq(scope, name)`, and the loser is handed the winner's row instead of an
     * exception — which is also why the counters can never fork across that race.
     */
    private function record(CircuitBreakerKey $key): CircuitBreakerRecord
    {
        return CircuitBreakerRecord::query()->createOrFirst(
            ['scope' => $key->scope, 'name' => $key->name],
            [
                'state' => CircuitState::Closed,
                'failure_count' => 0,
                'success_count' => 0,
                'half_open_probes' => 0,
                'half_open_successes' => 0,
            ],
        );
    }

    /**
     * The current state of $key for a **read-only** caller: snapshot first, row on a miss,
     * null when the breaker has never been exercised.
     *
     * Does not create a row — asking a dashboard question must not populate the table with
     * breakers nobody has used.
     */
    private function read(CircuitBreakerKey $key): ?CircuitBreakerRecord
    {
        $snapshot = $this->cache->get($key);

        if ($snapshot instanceof CircuitBreakerRecord) {
            return $snapshot;
        }

        $row = CircuitBreakerRecord::query()->forKey($key->scope, $key->name)->first();

        if ($row instanceof CircuitBreakerRecord) {
            $this->cache->put($key, $row);
        }

        return $row;
    }

    /**
     * Re-read $row in place from the database and refresh its snapshot.
     *
     * Used after a lost race and after every counter increment, so the in-memory instance
     * and the cache both carry what the database actually holds rather than what this
     * worker last wrote.
     */
    private function reread(CircuitBreakerKey $key, CircuitBreakerRecord $row): void
    {
        $row->refresh();

        $this->cache->put($key, $row);
    }

    /**
     * Apply $attributes to the row unconditionally, and write the snapshot through.
     *
     * Only for the operator-driven paths (`reset()`), which are deliberately not
     * compare-and-set: an operator saying "this is fixed" outranks whatever the counters
     * currently say.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(CircuitBreakerKey $key, CircuitBreakerRecord $row, array $attributes): void
    {
        $row->forceFill($attributes)->save();

        $this->cache->put($key, $row);
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    /**
     * The typed fast failure for a breaker that will not admit this call.
     *
     * Built from whichever record the caller had in hand — row or snapshot — so the
     * `Retry-After` reflects the cool-down that is actually running.
     */
    private function refusal(CircuitBreakerKey $key, CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): CircuitOpenException
    {
        if ($row->state->isHalfOpen()) {
            return CircuitOpenException::probesExhausted($key, $thresholds->probeLimit);
        }

        return CircuitOpenException::open($key, $this->secondsUntilProbe($row, $thresholds));
    }

    /**
     * Whole seconds until an OPEN breaker admits a probe, floored at 1 so a `Retry-After`
     * never says "now" for a door that is still shut.
     */
    private function secondsUntilProbe(CircuitBreakerRecord $row, CircuitBreakerThresholds $thresholds): int
    {
        if ($row->opened_at === null) {
            return $thresholds->openSeconds;
        }

        $probeAt = $row->opened_at->copy()->addSeconds($thresholds->openSeconds);

        return max(1, (int) ceil($this->now()->diffInSeconds($probeAt, absolute: false)));
    }

    /**
     * One clock for the whole class, so a test that freezes time freezes all of it.
     */
    private function now(): Carbon
    {
        return Carbon::now();
    }
}
