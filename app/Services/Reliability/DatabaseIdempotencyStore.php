<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\IdempotencyMode;
use App\Enums\IdempotencyState;
use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Exceptions\Reliability\UnrecordableResultException;
use App\Models\IdempotencyKey;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * `IdempotencyStore` on `idempotency_keys` — the only implementation, because the whole
 * point is that the guarantee comes from a database unique index rather than from a
 * coordination service (Req 31.2 / NFR2).
 *
 * ## The lifecycle, honestly
 *
 * ```
 *  (no row) --insert--> IN_FLIGHT --op returned--> COMPLETED   (replayed for ever after)
 *                           |
 *                           +----op threw--------> FAILED      (retryable; nothing landed)
 *                           |
 *                           +----holder died-----> IN_FLIGHT, lease stale --> retaken
 * ```
 *
 * Every transition is a **conditional** write — `UPDATE … WHERE state = <what we saw>` —
 * so two workers cannot both believe they hold the key. The same pattern as
 * `QuotaParkingLot::claim()`, for the same reason: a read-then-write would be a race, and
 * this is the one place where a race means a side effect happens twice.
 *
 * ## Why the operation runs outside the store's transaction by default
 *
 * The default (`IdempotencyMode::Lease`) commits the `IN_FLIGHT` claim *before* the
 * operation starts. That costs an extra round trip and creates the possibility of a stuck
 * lease, and it is still right: the operation is usually something the database cannot roll
 * back (an HTTP call to a gateway, a message handed to WhatsApp). If the claim were only
 * committed at the end, a duplicate arriving mid-flight would see no row at all and fire
 * the effect a second time.
 *
 * Callers whose operation is *purely* database work get the stronger guarantee by asking
 * for `IdempotencyMode::Transactional`: claim, operation and settle commit together, so a
 * crash leaves no trace at all. That is the mode `QuotaGuard::consume()` needs, and the
 * reason it must **not** take the default lease — its guarded operation is a single atomic
 * write, so a lease could only ever add a way for a dead worker to block a counter.
 *
 * One consequence worth stating, because it is invisible at the call site: a caller that
 * has **already opened its own transaction** cannot get the early-committed lease, since
 * every write below joins that transaction. Wrapping `once()` in a transaction therefore
 * *is* transactional mode — so say so with `IdempotencyOptions::transactional()`, and never
 * hold a lease-mode call open across an external effect that the surrounding transaction
 * might roll back underneath.
 *
 * ## Fairness of the wait
 *
 * A duplicate that finds a live lease waits a bounded budget (default
 * `wa.reliability.idempotency.wait_ms`), polling for the holder to settle, and then
 * refuses with 409 + `Retry-After`. Short waits are worth it — the overwhelmingly common
 * duplicate is a gateway retry arriving milliseconds behind the original, and turning that
 * into a correct replay is exactly Req 25.2. Long waits are not: they let one dead worker
 * pin every duplicate behind it. Queue workers should pass
 * `IdempotencyOptions::failingFast()` and let the job's own backoff do the waiting.
 *
 * ## Engine notes
 *
 * - `lockForUpdate()` on the pre-claim read (transactional mode only) serializes two
 *   workers on the row itself on **MySQL 8**; SQLite ignores the clause and serializes
 *   writers anyway, so that layer is only truly exercised in production. The unique index
 *   is what carries the guarantee on both.
 * - A duplicate insert **blocks** until the holder commits on MySQL (InnoDB gap/index
 *   lock) and fails immediately on SQLite. Both surface as a unique violation, which is
 *   handled identically.
 * - Column widths (`scope` 96, `key` 191) are validated in PHP because SQLite does not
 *   enforce them: an over-long key that MySQL would reject in strict mode would otherwise
 *   pass in tests and, in a non-strict deployment, silently *truncate* — which merges two
 *   different keys into one and skips a real side effect.
 */
final readonly class DatabaseIdempotencyStore implements IdempotencyStore
{
    /**
     * `idempotency_keys.scope` / `.key` column widths — see the class docblock.
     */
    public const int MAX_SCOPE_LENGTH = 96;

    public const int MAX_KEY_LENGTH = 191;

    /**
     * How many times `once()` re-reads after losing a claim race before it gives up and
     * refuses with 409.
     *
     * Bounded on purpose: every pass is a lost race against *some* other caller, so an
     * unbounded loop under contention would spin instead of letting the caller retry with
     * its own backoff.
     */
    private const int MAX_PASSES = 3;

    /**
     * Safety valve on `prune()` so one call cannot run until the end of time on a table
     * that is being written to as fast as it is drained.
     */
    private const int MAX_PRUNE_PASSES = 100;

    public function __construct(private TenantContext $context) {}

    /**
     * Run `$op` at most once per `(scope, key)`; replay the recorded result thereafter.
     *
     * @param  callable():mixed  $op
     *
     * @throws OperationInFlightException 409 — another caller holds the key
     * @throws IdempotencyKeyReuseException 422 — same key, different request
     * @throws UnrecordableResultException the result cannot be recorded for replay
     * @throws InvalidArgumentException a blank or over-long scope/key
     * @throws Throwable whatever `$op` threw
     */
    public function once(string $scope, string $key, callable $op, ?IdempotencyOptions $options = null): IdempotencyOutcome
    {
        $options ??= IdempotencyOptions::default();
        $scope = $this->assertIdentifier($scope, 'scope', self::MAX_SCOPE_LENGTH);
        $key = $this->assertIdentifier($key, 'key', self::MAX_KEY_LENGTH);

        if ($options->mode === IdempotencyMode::AtMostOnce) {
            return $this->onceAtMostOnce($scope, $key, $op, $options);
        }

        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            // Everything that can be decided without a transaction is decided here: a
            // completed key is replayed with one indexed read, and a live holder is waited
            // on *outside* any transaction we own — sleeping with one open would hold row
            // locks for the length of the nap.
            $conflict = $this->resolveConflict($scope, $key, $options);

            if ($conflict instanceof IdempotencyOutcome) {
                return $conflict;
            }

            try {
                return $options->mode->isTransactional()
                    ? $this->transaction(fn (): IdempotencyOutcome => $this->claimAndRun($scope, $key, $op, $options))
                    : $this->claimAndRun($scope, $key, $op, $options);
            } catch (ClaimRaceLost) {
                // Somebody claimed it between our read and our write. Loop: the next pass
                // replays their result if they have finished, or waits on their lease.
                continue;
            }
        }

        throw OperationInFlightException::lostClaimRace($scope, $key, $this->retryAfterSeconds());
    }

    /**
     * Delete keys past their retention horizon, in batches.
     *
     * Never touches a row with a null `expires_at`, at any age — see the interface.
     */
    public function prune(?int $batch = null): int
    {
        $size = max(1, $batch ?? $this->configInt('prune_batch', 1_000));
        $deleted = 0;

        for ($pass = 0; $pass < self::MAX_PRUNE_PASSES; $pass++) {
            /** @var list<int> $ids */
            $ids = IdempotencyKey::query()->prunable()->limit($size)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            // Delete by primary key rather than by the `expires_at` predicate: the horizon
            // moves while we work (`now()` advances between passes), and a keyed delete
            // cannot remove a row that was not in the batch we selected.
            $deleted += IdempotencyKey::query()->whereIn('id', $ids)->delete();

            if (count($ids) < $size) {
                break;
            }
        }

        return $deleted;
    }

    /*
    |--------------------------------------------------------------------------
    | Lease / transactional modes
    |--------------------------------------------------------------------------
    */

    /**
     * Handle an existing row before any claim is attempted: replay it, refuse it, or wait
     * for its holder.
     *
     * Returns an outcome when the call is already answerable, or null when the caller
     * should go on to claim the key.
     *
     * @throws IdempotencyKeyReuseException
     * @throws OperationInFlightException
     */
    private function resolveConflict(string $scope, string $key, IdempotencyOptions $options): ?IdempotencyOutcome
    {
        $existing = $this->find($scope, $key);

        if (! $existing instanceof IdempotencyKey) {
            return null;
        }

        $this->assertRequestMatches($existing, $scope, $key, $options);

        if ($existing->isReplayable()) {
            return $this->replay($existing, $scope, $key, $options);
        }

        if ($existing->isHeld($this->staleSeconds($options))) {
            $settled = $this->awaitSettlement($scope, $key, $options);

            if ($settled instanceof IdempotencyKey && $settled->isReplayable()) {
                return $this->replay($settled, $scope, $key, $options);
            }
        }

        // FAILED, or a lease whose holder is presumed dead: claimable.
        return null;
    }

    /**
     * Claim the key, run the operation, settle the row.
     *
     * In transactional mode this whole method runs inside one transaction, so the claim and
     * the operation's writes commit together — or not at all.
     *
     * @param  callable():mixed  $op
     *
     * @throws ClaimRaceLost when another caller holds or takes the key
     * @throws Throwable from `$op`, after the key has been released
     */
    private function claimAndRun(string $scope, string $key, callable $op, IdempotencyOptions $options): IdempotencyOutcome
    {
        $existing = $this->find($scope, $key, forUpdate: $options->mode->isTransactional());

        if ($existing instanceof IdempotencyKey) {
            $this->assertRequestMatches($existing, $scope, $key, $options);

            if ($existing->isReplayable()) {
                return $this->replay($existing, $scope, $key, $options);
            }

            if ($existing->isHeld($this->staleSeconds($options)) || ! $this->reclaim($existing, $options)) {
                throw ClaimRaceLost::on($scope, $key);
            }

            return $this->execute($existing, $scope, $key, $op, $options);
        }

        $claimed = $this->insertClaim($scope, $key, $options);

        if (! $claimed instanceof IdempotencyKey) {
            throw ClaimRaceLost::on($scope, $key);
        }

        return $this->execute($claimed, $scope, $key, $op, $options);
    }

    /**
     * Run the guarded operation under a claim we hold, then record its result.
     *
     * @param  callable():mixed  $op
     *
     * @throws Throwable
     */
    private function execute(IdempotencyKey $row, string $scope, string $key, callable $op, IdempotencyOptions $options): IdempotencyOutcome
    {
        try {
            $value = $op();
        } catch (Throwable $failure) {
            // The single most important line in this class: a failed operation must leave
            // the key *retryable*. In transactional mode the claim is rolled back by the
            // exception itself (nothing at all happened); in lease mode the row is settled
            // FAILED, which `IdempotencyState::allowsExecution()` treats as claimable.
            if ($options->mode->releasesKeyOnFailure() && ! $options->mode->isTransactional()) {
                $this->release($row);
            }

            throw $failure;
        }

        $this->settle($row, $scope, $key, $value, $options);

        return IdempotencyOutcome::executed($scope, $key, $value, $options->mode);
    }

    /*
    |--------------------------------------------------------------------------
    | At-most-once mode
    |--------------------------------------------------------------------------
    */

    /**
     * Claim the key as `COMPLETED` first, then run the operation at most once.
     *
     * The shape `QuotaNotifier` needs: `INSERT … IGNORE` on `uniq(scope, key)` decides who
     * acts, with no lease to go stale and no exception to catch. The price is that a
     * failed operation is never retried — see `IdempotencyMode::AtMostOnce`.
     *
     * @param  callable():mixed  $op
     *
     * @throws Throwable
     */
    private function onceAtMostOnce(string $scope, string $key, callable $op, IdempotencyOptions $options): IdempotencyOutcome
    {
        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $existing = $this->find($scope, $key);

            if ($existing instanceof IdempotencyKey) {
                $this->assertRequestMatches($existing, $scope, $key, $options);

                if ($existing->isReplayable()) {
                    return $this->replay($existing, $scope, $key, $options);
                }

                if ($existing->isHeld($this->staleSeconds($options))) {
                    // A row written by another mode is mid-flight. Wait for it rather than
                    // burning the key underneath it.
                    $settled = $this->awaitSettlement($scope, $key, $options);

                    if ($settled instanceof IdempotencyKey && $settled->isReplayable()) {
                        return $this->replay($settled, $scope, $key, $options);
                    }

                    continue;
                }

                if (! $this->burn($existing, $options)) {
                    continue;
                }

                return $this->runBurned($existing->refresh(), $scope, $key, $op, $options);
            }

            if ($this->insertBurnedClaim($scope, $key, $options) === 0) {
                // Lost the insert race: loop and replay the winner's row.
                continue;
            }

            $row = $this->find($scope, $key);

            if (! $row instanceof IdempotencyKey) {
                // Vanished between our insert and our read (a pruner with a very short
                // horizon). Retrying is safe: the effect has not run yet.
                continue;
            }

            return $this->runBurned($row, $scope, $key, $op, $options);
        }

        throw OperationInFlightException::lostClaimRace($scope, $key, $this->retryAfterSeconds());
    }

    /**
     * Run the operation for a key that is already recorded `COMPLETED`, then attach its
     * result.
     *
     * A failure here is **not** rolled back into a retryable key: that is the whole
     * at-most-once bargain. The exception still propagates, so the caller can log or alert.
     *
     * @param  callable():mixed  $op
     *
     * @throws Throwable
     */
    private function runBurned(IdempotencyKey $row, string $scope, string $key, callable $op, IdempotencyOptions $options): IdempotencyOutcome
    {
        $value = $op();

        $this->settle($row, $scope, $key, $value, $options);

        return IdempotencyOutcome::executed($scope, $key, $value, $options->mode);
    }

    /*
    |--------------------------------------------------------------------------
    | Row writes — every one of them conditional
    |--------------------------------------------------------------------------
    */

    /**
     * Insert the `IN_FLIGHT` claim. Null when the unique index says somebody else got
     * there first.
     */
    private function insertClaim(string $scope, string $key, IdempotencyOptions $options): ?IdempotencyKey
    {
        $now = now();

        try {
            return IdempotencyKey::query()->create([
                'tenant_id' => $this->tenantIdFor($options),
                'scope' => $scope,
                'key' => $key,
                'state' => IdempotencyState::InFlight,
                'result' => null,
                'response_hash' => null,
                'request_fingerprint' => $options->requestFingerprint(),
                'locked_at' => $now,
                'completed_at' => null,
                'expires_at' => $this->expiresAt($options, $now),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * `INSERT … IGNORE` a `COMPLETED` claim (at-most-once mode). Returns rows inserted: 1
     * when we won the right to act, 0 when somebody else did.
     */
    private function insertBurnedClaim(string $scope, string $key, IdempotencyOptions $options): int
    {
        $now = now();

        // The query builder rather than `create()`, because the insert must be *ignored* on
        // conflict instead of raising: no exception to catch and no read-then-write window.
        // That means the enum value and the timestamps are supplied explicitly.
        return IdempotencyKey::query()->insertOrIgnore([
            'tenant_id' => $this->tenantIdFor($options),
            'scope' => $scope,
            'key' => $key,
            'state' => IdempotencyState::Completed->value,
            'result' => null,
            'response_hash' => null,
            'request_fingerprint' => $options->requestFingerprint(),
            'locked_at' => null,
            'completed_at' => $now,
            'expires_at' => $this->expiresAt($options, $now),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Retake a claimable row — one that failed, or whose lease has gone stale.
     *
     * The `WHERE` clause repeats everything we believed when we read the row, so two
     * workers that both saw the same abandoned lease cannot both win.
     */
    private function reclaim(IdempotencyKey $row, IdempotencyOptions $options): bool
    {
        $now = now();
        $staleBefore = $now->copy()->subSeconds($this->staleSeconds($options));

        $claimed = IdempotencyKey::query()
            ->whereKey($row->getKey())
            ->where(function (Builder $claimable) use ($staleBefore): void {
                $claimable
                    ->where('state', IdempotencyState::Failed)
                    ->orWhere(function (Builder $abandoned) use ($staleBefore): void {
                        $abandoned
                            ->where('state', IdempotencyState::InFlight)
                            ->where(function (Builder $expired) use ($staleBefore): void {
                                $expired->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
                            });
                    });
            })
            ->update([
                'state' => IdempotencyState::InFlight,
                'locked_at' => $now,
                // Clear the previous attempt's replay material: it describes a run that did
                // not finish, and leaving it would let a reader replay half an answer.
                'result' => null,
                'response_hash' => null,
                'completed_at' => null,
                'request_fingerprint' => $options->requestFingerprint() ?? $row->request_fingerprint,
                'expires_at' => $this->expiresAt($options, $now),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $row->state = IdempotencyState::InFlight;
        $row->locked_at = $now;

        return true;
    }

    /**
     * Take over a claimable row in at-most-once mode: straight to `COMPLETED`, before the
     * operation runs.
     */
    private function burn(IdempotencyKey $row, IdempotencyOptions $options): bool
    {
        $now = now();
        $staleBefore = $now->copy()->subSeconds($this->staleSeconds($options));

        return IdempotencyKey::query()
            ->whereKey($row->getKey())
            ->where(function (Builder $claimable) use ($staleBefore): void {
                $claimable
                    ->where('state', IdempotencyState::Failed)
                    ->orWhere(function (Builder $abandoned) use ($staleBefore): void {
                        $abandoned
                            ->where('state', IdempotencyState::InFlight)
                            ->where(function (Builder $expired) use ($staleBefore): void {
                                $expired->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
                            });
                    });
            })
            ->update([
                'state' => IdempotencyState::Completed,
                'result' => null,
                'response_hash' => null,
                'completed_at' => $now,
                'request_fingerprint' => $options->requestFingerprint() ?? $row->request_fingerprint,
                'expires_at' => $this->expiresAt($options, $now),
            ]) > 0;
    }

    /**
     * Record the operation's result and mark the key `COMPLETED`.
     *
     * @throws UnrecordableResultException when the value cannot be stored — after the row
     *                                     has been settled, because the side effect has
     *                                     already happened and re-running it would be worse
     */
    private function settle(IdempotencyKey $row, string $scope, string $key, mixed $value, IdempotencyOptions $options): void
    {
        $unrecordable = null;
        $result = null;

        try {
            $result = $this->encode($scope, $key, $value);
        } catch (UnrecordableResultException $exception) {
            $unrecordable = $exception;
        }

        $now = now();

        $settled = IdempotencyKey::query()
            ->whereKey($row->getKey())
            ->update([
                'state' => IdempotencyState::Completed,
                // Encoded here rather than left to the model's `array` cast: a query-builder
                // update binds values directly and never runs casts, so an array would
                // reach PDO as an array. The model decodes it again on the way back out.
                'result' => $result === null ? null : json_encode($result),
                'response_hash' => $result === null ? null : IdempotencyKey::fingerprint($result),
                'completed_at' => $now,
                'expires_at' => $this->expiresAt($options, $now),
            ]);

        if ($settled > 0) {
            $row->state = IdempotencyState::Completed;
            $row->result = $result;
            $row->completed_at = $now;
        }

        if ($unrecordable instanceof UnrecordableResultException) {
            // In transactional mode this unwinds the operation as well, which is correct:
            // nothing happened, so the key is genuinely free. In lease mode the effect has
            // landed and the row above records it — the throw is there to make an
            // unreplayable call site impossible to miss.
            throw $unrecordable;
        }
    }

    /**
     * Settle a claim we hold as `FAILED`, so the key is retryable.
     *
     * Guarded on our own lease: if it went stale and another caller retook the key, their
     * claim must not be stamped `FAILED` by our late-arriving failure.
     */
    private function release(IdempotencyKey $row): void
    {
        IdempotencyKey::query()
            ->whereKey($row->getKey())
            ->where('state', IdempotencyState::InFlight)
            ->when(
                $row->locked_at instanceof Carbon,
                fn (Builder $query): Builder => $query->where('locked_at', $row->locked_at),
            )
            ->update([
                'state' => IdempotencyState::Failed,
                'result' => null,
                'response_hash' => null,
                'completed_at' => now(),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Reads, waiting, replay
    |--------------------------------------------------------------------------
    */

    /**
     * The single row for `(scope, key)`, if any.
     *
     * `$forUpdate` adds `FOR UPDATE` — meaningful on MySQL inside a transaction, ignored by
     * SQLite.
     */
    private function find(string $scope, string $key, bool $forUpdate = false): ?IdempotencyKey
    {
        $query = IdempotencyKey::query()->forKey($scope, $key);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Poll a held key until its holder settles, or until the wait budget runs out.
     *
     * Returns the settled row, or null when the row vanished (rolled back or pruned); it
     * never returns a row that is still held — that path throws.
     *
     * @throws OperationInFlightException
     */
    private function awaitSettlement(string $scope, string $key, IdempotencyOptions $options): ?IdempotencyKey
    {
        $budget = $this->waitMilliseconds($options);
        $poll = max(1, $this->configInt('poll_ms', 25));
        $waited = 0;

        while ($waited < $budget) {
            $nap = min($poll, $budget - $waited);
            Sleep::for($nap)->milliseconds();
            $waited += $nap;

            $fresh = $this->find($scope, $key);

            if (! $fresh instanceof IdempotencyKey) {
                return null;
            }

            if (! $fresh->isHeld($this->staleSeconds($options))) {
                return $fresh;
            }
        }

        throw OperationInFlightException::held($scope, $key, $waited, $this->retryAfterSeconds());
    }

    /**
     * Build the replay outcome for a completed row.
     */
    private function replay(IdempotencyKey $row, string $scope, string $key, IdempotencyOptions $options): IdempotencyOutcome
    {
        return IdempotencyOutcome::replayed($scope, $key, $this->decode($row->result), $options->mode);
    }

    /**
     * @throws IdempotencyKeyReuseException when this call's payload is not the one the key
     *                                      was first used with
     */
    private function assertRequestMatches(IdempotencyKey $row, string $scope, string $key, IdempotencyOptions $options): void
    {
        if ($options->request === null || $row->matchesRequest($options->request)) {
            return;
        }

        throw IdempotencyKeyReuseException::mismatch(
            $scope,
            $key,
            $row->request_fingerprint,
            (string) $options->requestFingerprint(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Result encoding
    |--------------------------------------------------------------------------
    */

    /**
     * Envelope key for results that are not plain arrays.
     *
     * `result` is a JSON *object* column, so a scalar or null return value needs a wrapper.
     * Arrays are stored **verbatim** whenever they can be — which is what keeps this
     * compatible with rows already written by `QuotaGuard` and `QuotaNotifier`, whose
     * `result` is a flat payload their own code reads back by key.
     */
    private const string ENVELOPE = '@idempotency';

    /**
     * The `result` payload for a return value, or null when there is nothing to record.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws UnrecordableResultException
     */
    private function encode(string $scope, string $key, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $this->assertRecordable($scope, $key, $value);

        $payload = is_array($value) && ! array_key_exists(self::ENVELOPE, $value)
            ? $value
            : [self::ENVELOPE => ['type' => get_debug_type($value), 'value' => $value]];

        try {
            json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw UnrecordableResultException::notEncodable($scope, $key, $exception->getMessage());
        }

        return $payload;
    }

    /**
     * The value recorded by `encode()`, unwrapped.
     *
     * @param  array<array-key, mixed>|null  $result
     */
    private function decode(?array $result): mixed
    {
        if ($result === null) {
            return null;
        }

        $envelope = $result[self::ENVELOPE] ?? null;

        if (is_array($envelope) && array_key_exists('value', $envelope)) {
            return $envelope['value'];
        }

        return $result;
    }

    /**
     * Refuse values the ledger cannot store *as themselves*.
     *
     * Objects are the interesting case: `json_encode` would happily flatten one into an
     * object literal, and the replay would then hand a later caller an array where the
     * first caller got a typed instance — the same call site returning two different shapes
     * depending on whether it was a retry. That is a bug waiting for production, so it is
     * refused here instead.
     *
     * @throws UnrecordableResultException
     */
    private function assertRecordable(string $scope, string $key, mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw UnrecordableResultException::notEncodable($scope, $key, 'nested deeper than 32 levels');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertRecordable($scope, $key, $item, $depth + 1);
            }

            return;
        }

        if ($value === null || is_scalar($value)) {
            return;
        }

        throw UnrecordableResultException::unsupportedType($scope, $key, $value);
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * Run `$callback` in a database transaction on the ledger's own connection.
     *
     * The model's connection rather than the default one, so a tenant on a dedicated
     * database (`TenantTier::DEDICATED_DB`) writes its ledger where its data lives.
     *
     * @template TValue
     *
     * @param  Closure():TValue  $callback
     * @return TValue
     *
     * @throws Throwable
     */
    private function transaction(Closure $callback): mixed
    {
        return $this->connection()->transaction($callback);
    }

    private function connection(): Connection
    {
        return IdempotencyKey::query()->getModel()->getConnection();
    }

    /**
     * The tenant a new row is attributed to.
     *
     * Attribution only — **not** isolation, which lives in the scope (see the interface).
     * A null here is legitimate and expected: pre-resolution webhook intake has no tenant
     * yet, and platform-mode callers have none at all.
     */
    private function tenantIdFor(IdempotencyOptions $options): ?string
    {
        if ($options->tenantId !== null) {
            return $options->tenantId;
        }

        return $options->attributeToContext ? $this->context->currentId() : null;
    }

    /**
     * The retention horizon for a row, or null when the caller asked to keep it for ever.
     */
    private function expiresAt(IdempotencyOptions $options, Carbon $now): ?Carbon
    {
        if ($options->keepsForever()) {
            return null;
        }

        $days = $options->retentionDays ?? $this->configInt('retention_days', 45);

        return $now->copy()->addDays(max(1, $days));
    }

    private function staleSeconds(IdempotencyOptions $options): int
    {
        return max(1, $options->staleSeconds ?? $this->configInt('stale_lock_seconds', IdempotencyKey::STALE_LOCK_SECONDS));
    }

    private function waitMilliseconds(IdempotencyOptions $options): int
    {
        return max(0, $options->waitMilliseconds ?? $this->configInt('wait_ms', 250));
    }

    private function retryAfterSeconds(): int
    {
        return max(1, $this->configInt('retry_after_seconds', OperationInFlightException::DEFAULT_RETRY_AFTER));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertIdentifier(string $value, string $label, int $max): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf(
                'An idempotency %s cannot be blank: without one there is nothing for uniq(scope, key) to deduplicate on.',
                $label,
            ));
        }

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException(sprintf(
                'An idempotency %s is limited to %d characters, got %d. A truncated %s would merge two different '
                .'units of work into one and skip a real side effect — hash the value at the call site instead.',
                $label,
                $max,
                mb_strlen($trimmed),
                $label,
            ));
        }

        return $trimmed;
    }

    /**
     * Whether a failed write lost a unique index — the expected outcome of two callers
     * racing on the same key.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        // SQLSTATE 23000 on both MySQL and SQLite; 23505 is PostgreSQL's, cheap to accept.
        if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }

    private function configInt(string $key, int $default): int
    {
        $value = config('wa.reliability.idempotency.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
