<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\OutboxStatus;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Models\OutboxMessage;
use App\Services\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `Outbox` on the `outbox` table: Algorithm 6, and the relay worker that runs it
 * (Req 31.4 / NFR2, Correctness Property 16).
 *
 * ## Enqueue joins the caller's transaction, and never opens one
 *
 * `record()` is a single `INSERT` and nothing else — no transaction of its own, no
 * `afterCommit` hook, no queue dispatch. That is the whole mechanism: called inside the
 * transaction that is changing state, the intent and the state change share one commit, so
 * there is no instant at which one exists without the other. Opening a transaction here
 * would commit the row independently and put the dual write straight back.
 *
 * ## The relay pass, and where it departs from the pseudocode
 *
 * Algorithm 6 is written as one loop inside the claim's transaction. This implementation
 * splits it in two — **claim in a short transaction, deliver outside it** — and keeps every
 * pre/postcondition and the loop invariant. Three reasons, in order of importance:
 *
 * 1. **A rollback would erase the backoff.** With delivery inside the claim transaction, an
 *    unexpected throw on row 5 rolls back the `FAILED` bookkeeping for rows 1–4 as well:
 *    their `attempts` and `next_attempt_at` revert, so the next pass retries them
 *    *immediately*, with no backoff, for ever. Persisting each outcome on its own is what
 *    makes "retry with backoff" true rather than intended.
 * 2. **Row locks would be held across the network.** A batch of 200 deliveries at a
 *    ten-second timeout can hold one transaction open for minutes; on MySQL that is a
 *    long-lived InnoDB transaction pinning undo history for the whole table.
 * 3. **`SKIP LOCKED` still does its job.** Exclusion is needed only for the claim itself,
 *    which is the only moment two workers can pick the same row.
 *
 * What replaces the lock during delivery is a **lease**: claiming increments `attempts` and
 * pushes `next_attempt_at` to `now() + lease_seconds` in the same statement, so a claimed
 * row is invisible to every other claimer (and to a re-entrant pass) until either its
 * outcome is written or the lease expires. A worker killed mid-attempt therefore delays its
 * row by the lease and loses nothing.
 *
 * Incrementing `attempts` *at claim time* is deliberate and is what makes a crash safe:
 * the attempt is counted before it is made, so a row that repeatedly kills its worker
 * exhausts its budget and parks instead of poisoning the queue for ever.
 *
 * It also has a consequence the relay has to own. A worker that dies between the claim and
 * the outcome write spends an attempt and records nothing, so a row can run out of budget
 * without any path in this class ever having written a failure for it — leaving a `PENDING`
 * row that no claim will take again and no parked-row query will find. `reapAbandoned()`
 * runs before every claim and parks exactly those rows, which is what keeps "parked" the
 * only way a row can stop moving.
 *
 * ## Crash semantics — the exactly-once part
 *
 * ```
 *  claim (attempts++, lease) --commit--> deliver --ack--> mark SENT
 *                                          |                  ^
 *                                          |  crash here      |
 *                                          +------------------+  lease expires, row is
 *                                                                claimed and delivered again
 * ```
 *
 * A crash between the ack and the `SENT` update redelivers, exactly as Algorithm 6's
 * postcondition allows: delivery is **at least once**, and the consumer's dedup on
 * `X-Dedup-Key` makes the *effect* exactly once (Property 16). The row is never lost in any
 * interleaving, because nothing in this class ever deletes one and every path out of an
 * attempt writes a state a later pass can act on.
 *
 * ## Claim eligibility — three predicates, not one
 *
 * `OutboxMessage::scopeClaimable()` is Algorithm 6's predicate verbatim (`status IN
 * {PENDING, FAILED}`, `next_attempt_at <= now()`, `ORDER BY id`). The relay adds two:
 *
 * - `available_at <= now()` — the *intent* clock. The gate is initialised from it and only
 *   moves forward, so this is normally implied; it is stated anyway because an operator
 *   requeue writes the gate directly, and a scheduled effect must not be delivered early
 *   just because somebody reopened its gate.
 * - `attempts < max_attempts` — the parking predicate. `OutboxStatus` has no `DEAD` state
 *   (by requirement), so a row that has run out of chances is held out of the claim by its
 *   **spent budget** rather than by a status the relay could drop work into. Parking a row
 *   is therefore "burn the remaining budget", and `requeue()` is "give it a fresh one".
 *
 * ## The dedup horizon — the guard that keeps Property 16 honest
 *
 * The consumer's exactly-once depends on it still *remembering* the dedup key. Inside this
 * platform that memory is `idempotency_keys`, pruned after
 * `wa.reliability.idempotency.retention_days` (45). A redelivery arriving after that would
 * be indistinguishable from a first delivery, and the effect would be applied twice — the
 * exact failure the outbox exists to prevent, arriving by the back door.
 *
 * So the relay refuses to be the cause of it. Every reschedule is clamped to
 * `enqueued_at + dedup horizon`, a row that would need a gate beyond it is parked instead,
 * and a claimed row already past it is parked *without being delivered*. The horizon itself
 * is clamped to the retention window it depends on, so it cannot be configured past it.
 * That is why the retry budget is finite and small: `max_attempts` failures at a 30-second
 * cap is minutes of wall clock, orders of magnitude inside the horizon, and the horizon
 * check is the assertion that keeps it that way rather than an assumption.
 *
 * ## Why the relay does not wrap delivery in a circuit breaker
 *
 * It would be actively harmful here. `CIRCUIT_OPEN` is a fail-fast class, so a breaker
 * tripping on one flaky receiver would spend the budget of every row aimed at it and park
 * them all — turning a two-minute provider blip into a queue full of effects that need a
 * human. Guarding belongs in the transport, which knows its provider scope; when a
 * transport does that, the relay recognises `CircuitOpenException` and reschedules the row
 * **without counting the attempt**, because a call that was never made did not fail.
 */
final readonly class DatabaseOutbox implements Outbox
{
    /**
     * Rows claimed per pass when config says nothing — design.md's `relay(int $batch = 200)`.
     */
    public const int DEFAULT_BATCH = 200;

    /**
     * How long a claimed row stays invisible to other claimers. Must comfortably outlive
     * one delivery attempt (the HTTP transport's timeout is seconds); breaking a live lease
     * risks two workers delivering the same row, which the consumer's dedup covers but
     * which wastes a call and confuses the `attempts` count.
     */
    public const int DEFAULT_LEASE_SECONDS = 300;

    /**
     * Total attempts a row gets before it parks for an operator. Bounded and modest: at the
     * retry matrix's 30-second cap this is minutes of wall clock, which keeps every
     * redelivery well inside the dedup horizon.
     */
    public const int DEFAULT_MAX_ATTEMPTS = 12;

    /**
     * Days after enqueue beyond which a row is no longer redelivered automatically, when
     * neither `wa.reliability.outbox.dedup_horizon_days` nor
     * `wa.reliability.idempotency.retention_days` is readable. Mirrors the latter's default.
     */
    public const int DEFAULT_DEDUP_HORIZON_DAYS = 45;

    public function __construct(
        private OutboxTransport $transport,
        private RetryPolicy $policy,
        private TenantContext $context,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Enqueue — inside the caller's transaction
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        string $dedupKey,
    ): void {
        $this->record(OutboxEnvelope::for($aggregateType, $aggregateId, $eventType, $payload, $dedupKey));
    }

    public function record(OutboxEnvelope $envelope): OutboxMessage
    {
        // One INSERT, joining whatever transaction the caller already has open. The tenant
        // falls back to the ambient context, and stays null in platform mode — on this
        // table that is a legal value, not a missing one.
        return OutboxMessage::query()->create($envelope->attributes($this->context->currentId()));
    }

    /*
    |--------------------------------------------------------------------------
    | Relay — Algorithm 6
    |--------------------------------------------------------------------------
    */

    public function relay(int $batch = self::DEFAULT_BATCH): int
    {
        return $this->relayBatch($batch)->delivered();
    }

    public function relayBatch(?int $batch = null): OutboxRelayReport
    {
        $report = new OutboxRelayReport;
        $size = $this->batchSize($batch);

        // Before claiming: park the rows whose budget was spent by claims nothing ever came
        // back from, so the parking they are already subject to is stated rather than
        // implied. See `reapAbandoned()` — this is the other half of counting the attempt at
        // claim time.
        $report->recordAbandoned($this->reapAbandoned($size));

        $claimed = $this->claim($size);

        $report->recordClaimed($claimed->count());

        foreach ($claimed as $message) {
            // INVARIANT: every row already marked SENT was acked by the receiver at least
            // once; every row still PENDING/FAILED with budget left is eligible for retry.
            $this->attempt($message, $report);
        }

        return $report;
    }

    public function requeue(OutboxMessage|int $message, ?DateTimeInterface $at = null): bool
    {
        $row = $message instanceof OutboxMessage
            ? OutboxMessage::query()->find($message->getKey())
            : OutboxMessage::query()->find($message);

        if (! $row instanceof OutboxMessage || $row->status->isTerminal()) {
            return false;
        }

        // Refused rather than reopened: past the horizon the consumer may have forgotten the
        // dedup key, so a redelivery could apply the effect a second time. The remedy is a
        // fresh intent with a fresh key, which is a decision only the owning subsystem can
        // make — not a gate this method can quietly reopen.
        if ($this->isPastDedupHorizon($row)) {
            return false;
        }

        $gate = $at === null ? Carbon::now() : Carbon::instance($at);

        // Never before the delivery intent: reopening a gate must not deliver a scheduled
        // effect early.
        if ($gate->lessThan($row->available_at)) {
            $gate = $row->available_at->copy();
        }

        return $this->persist($row, [
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => $gate,
        ]) > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Claiming
    |--------------------------------------------------------------------------
    */

    /**
     * Claim up to `$batch` due rows and lease them.
     *
     * The `SELECT … FOR UPDATE SKIP LOCKED` and the lease-stamping `UPDATE` share one short
     * transaction, which is what makes the pair atomic: on MySQL a competing worker skips
     * the locked rows outright, and by the time the lock is released their gate is already
     * in the future, so it would skip them anyway. SQLite compiles the lock away (see
     * `OutboxMessage::CLAIM_LOCK`) and serializes writers instead — same outcome, different
     * mechanism, which is why the lease and not the lock is what the test suite can assert.
     *
     * @return EloquentCollection<int, OutboxMessage>
     */
    private function claim(int $batch): EloquentCollection
    {
        /** @var EloquentCollection<int, OutboxMessage> $none */
        $none = new EloquentCollection;

        if ($batch < 1) {
            return $none;
        }

        $now = Carbon::now();
        $lease = $now->copy()->addSeconds($this->leaseSeconds());
        $maxAttempts = $this->maxAttempts();

        /** @var EloquentCollection<int, OutboxMessage> $claimed */
        $claimed = $this->connection()->transaction(
            function () use ($batch, $now, $lease, $maxAttempts): EloquentCollection {
                $rows = OutboxMessage::query()
                    ->claimable()                            // Algorithm 6: status + retry gate + FIFO
                    ->where('available_at', '<=', $now)      // the intent clock
                    ->where('attempts', '<', $maxAttempts)   // parked rows stay parked
                    ->lockForClaim()
                    ->limit($batch)
                    ->get();

                if ($rows->isEmpty()) {
                    return $rows;
                }

                // One statement: count the attempt and hide the row for the lease. Counting
                // before delivering is what stops a row that kills its worker from being
                // retried for ever.
                OutboxMessage::query()
                    ->whereKey($rows->modelKeys())
                    ->increment('attempts', 1, ['next_attempt_at' => $lease]);

                foreach ($rows as $row) {
                    $row->forceFill([
                        'attempts' => $row->attempts + 1,
                        'next_attempt_at' => $lease->copy(),
                    ])->syncOriginal();
                }

                return $rows;
            }
        );

        return $claimed;
    }

    /**
     * Park the rows whose attempt budget was spent by claims that never reported an outcome.
     *
     * ## The state this repairs, and why nothing else can
     *
     * `attempts` is incremented at claim time, so a worker killed *between* the claim and
     * the outcome write (OOM, SIGKILL, a deploy that does not drain) spends an attempt and
     * records nothing. Let that happen until the budget is gone and the row lands in the one
     * state no path in this class intends: `PENDING`, `attempts >= max_attempts`, its lease
     * expired. The claim's parking predicate then holds it out of every future pass — so it
     * *is* parked — while the row itself still reads as "enqueued, never attempted, due
     * now": not `FAILED`, so it is absent from every parked-row query an operator would
     * write, and carrying either no `last_error` at all or one left by a shed, which
     * describes a call that was never even made. Nobody will ever look at it and nothing
     * will ever deliver it, which is precisely the silent non-delivery Req 31.4 forbids,
     * arriving through the door that counting the attempt early leaves open.
     *
     * The dead worker cannot write that outcome, so the next live pass writes it for it:
     * one indexed query before each claim turns the implicit parking into `park()`'s
     * explicit kind — `FAILED`, budget spent, gate at the dedup deadline, and a reason in
     * the operator's words.
     *
     * ## Why only `PENDING`, and why this can never overwrite a diagnosis
     *
     * `PENDING` is written by `record()` and by `requeue()` and by nothing else; every
     * outcome inside the relay writes `SENT` or `FAILED`. A `PENDING` row with a spent
     * budget is therefore, by construction, a row whose whole budget went on attempts that
     * reported nothing — which is what makes the reason written below true of every row this
     * claims, rather than a guess. A row already marked `FAILED` is left alone even when its
     * budget was spent the same way: it carries its own last recorded failure and already
     * reads as the parked signature, so rewriting its `last_error` would cost an operator
     * the diagnosis and buy nothing.
     *
     * That is also what makes the repair a one-shot: a reaped row is `FAILED`, so it is no
     * longer a candidate, and no later pass rescans it.
     *
     * ## The lease is what tells a dead worker from a slow one
     *
     * A row claimed for its final attempt is `PENDING` with a spent budget too — and it is
     * *in flight*. Its gate sits `lease_seconds` in the future, so `next_attempt_at <=
     * now()` is the whole distinction between a worker that is still working and one that is
     * never coming back. If a lease does expire mid-attempt this write races the live
     * worker, and that race is already harmless: `persist()` refuses to walk a `SENT` row
     * back, a subsequent ack overwrites this park with `SENT`, a subsequent failure re-parks
     * it with the real reason, and the consumer's dedup key covers the delivery either way.
     *
     * @return int rows parked
     */
    private function reapAbandoned(int $batch): int
    {
        if ($batch < 1) {
            return 0;
        }

        $maxAttempts = $this->maxAttempts();

        $rows = OutboxMessage::query()
            ->where('status', OutboxStatus::Pending)         // never attempted, as far as the row says
            ->where('attempts', '>=', $maxAttempts)          // and yet its whole budget is gone
            ->where('next_attempt_at', '<=', Carbon::now())  // and the last claim's lease has expired
            ->orderBy('id')
            ->limit($batch)
            ->get();

        foreach ($rows as $row) {
            $this->park($row, sprintf(
                'Parked after %d of %d attempts (wa.reliability.outbox.max_attempts), none of which reported an '
                .'outcome: every claim on this row was made by a worker that did not survive it. The attempt is '
                .'counted before it is made, so the budget is spent; the row is kept and can be requeued.%s',
                $row->attempts,
                $maxAttempts,
                $row->last_error === null ? '' : ' Last recorded: '.$row->last_error,
            ));
        }

        return $rows->count();
    }

    /*
    |--------------------------------------------------------------------------
    | One attempt
    |--------------------------------------------------------------------------
    */

    private function attempt(OutboxMessage $message, OutboxRelayReport $report): void
    {
        $delivery = OutboxDelivery::for($message);

        if ($this->isPastDedupHorizon($message)) {
            $this->park($message, sprintf(
                'Not delivered: enqueued more than %d days ago, so the consumer may no longer hold the dedup key '
                .'for %s and a redelivery could apply the effect twice. Kept for an operator.',
                $this->dedupHorizonDays(),
                $delivery->fingerprint(),
            ));
            $report->recordParked();

            return;
        }

        try {
            $this->transport->deliver($delivery);
        } catch (CircuitOpenException $e) {
            // The call was never made, so it was not an attempt: give the budget back and
            // wait out the breaker's own cool-down.
            $this->shed($message, $e);
            $report->recordShed();

            return;
        } catch (Throwable $e) {
            $this->fail($message, $e, $report);

            return;
        }

        // The receiver acked. Only now — and conditionally, so a row another worker already
        // settled is reported rather than overwritten.
        $this->markSent($message)
            ? $report->recordDelivered()
            : $report->recordRaced();
    }

    /**
     * The receiver acked: the row is terminal.
     *
     * @return bool false when it was already `SENT` — two workers held the same row (a
     *              lease outlived its attempt), which the consumer's dedup covers
     */
    private function markSent(OutboxMessage $message): bool
    {
        $now = Carbon::now();

        return $this->persist($message, [
            'status' => OutboxStatus::Sent,
            'sent_at' => $now,
            // The lease is over; leaving a future gate on a delivered row would read as
            // "waiting" in the delivery log.
            'next_attempt_at' => $now,
            'last_error' => null,
        ]) > 0;
    }

    /**
     * The attempt failed: reschedule it on the policy's backoff, or park it.
     *
     * The delay is `RetryPolicy::decide()`'s and nothing else — one backoff formula for the
     * whole platform (exponential with full jitter, per error class), not a second one
     * here. `attempts` was already incremented at claim time, so the decision is made about
     * the attempt that just failed.
     */
    private function fail(OutboxMessage $message, Throwable $e, OutboxRelayReport $report): void
    {
        $decision = $this->policy->decide($e, $message->attempts);
        $budgetLeft = $message->attempts < $this->maxAttempts();
        $gate = $decision->shouldRetry && $budgetLeft
            ? $this->gateFor($message, $decision->delayMs)
            : null;

        if (! $gate instanceof Carbon) {
            $this->park($message, sprintf(
                '%s %s Failure: %s',
                $decision->describe(),
                $this->parkReason($decision, $budgetLeft, $message),
                $this->summarize($e),
            ));
            $report->recordParked();

            return;
        }

        $this->persist($message, [
            'status' => OutboxStatus::Failed,
            'next_attempt_at' => $gate,
            'last_error' => OutboxMessage::truncateError(sprintf('%s %s', $decision->describe(), $this->summarize($e))),
        ]);
        $report->recordRetrying();
    }

    /**
     * Park the row: kept, `FAILED`, out of the claim, waiting for `requeue()`.
     *
     * Two things hold it: its budget is spent (the claim predicate), and its gate is set to
     * the last instant a redelivery could ever have been safe. Either alone would do; both
     * together mean an operator who resets one column by hand cannot accidentally re-arm an
     * effect the consumer may already have forgotten.
     */
    private function park(OutboxMessage $message, string $reason): void
    {
        $this->persist($message, [
            'status' => OutboxStatus::Failed,
            'attempts' => max($message->attempts, $this->maxAttempts()),
            'next_attempt_at' => $this->dedupDeadline($message),
            'last_error' => OutboxMessage::truncateError($reason),
        ]);
    }

    /**
     * A breaker refused the call: hand the attempt back and wait out its cool-down.
     *
     * The status is left exactly as it was — a `PENDING` row that was never attempted has
     * not failed, and pretending otherwise would make the delivery log lie about which
     * effects have been tried.
     */
    private function shed(OutboxMessage $message, CircuitOpenException $e): void
    {
        $wait = max(1, $e->retryAfterSeconds ?? $this->leaseSeconds());
        $gate = Carbon::now()->addSeconds($wait);
        $deadline = $this->dedupDeadline($message);

        $this->persist($message, [
            'attempts' => max(0, $message->attempts - 1),
            'next_attempt_at' => $gate->greaterThan($deadline) ? $deadline : $gate,
            'last_error' => OutboxMessage::truncateError($this->summarize($e)),
        ]);
    }

    /**
     * When to try again, or null when no attempt can be made in time.
     *
     * The clamp is the dedup horizon: a gate beyond `enqueued_at + horizon` would schedule
     * a delivery the consumer might not recognise as a duplicate, so the row parks instead.
     * It bites in practice only when a receiver sends an absurd `Retry-After` (which
     * `RetryPolicy` honours verbatim, up to 45 days, for the rate-limit class).
     */
    private function gateFor(OutboxMessage $message, int $delayMs): ?Carbon
    {
        $gate = Carbon::now()->addMilliseconds(max(0, $delayMs));

        return $gate->greaterThan($this->dedupDeadline($message)) ? null : $gate;
    }

    /**
     * Why this row is parked, in the words an operator needs.
     */
    private function parkReason(RetryDecision $decision, bool $budgetLeft, OutboxMessage $message): string
    {
        if (! $budgetLeft) {
            return sprintf(
                'Parked after %d attempts (wa.reliability.outbox.max_attempts); the row is kept and can be requeued.',
                $message->attempts,
            );
        }

        if ($decision->shouldRetry) {
            return sprintf(
                'Parked: the next attempt would fall past the %d-day dedup horizon, after which a redelivery could '
                .'apply the effect twice.',
                $this->dedupHorizonDays(),
            );
        }

        return 'Parked: this failure will not improve by waiting; the row is kept and can be requeued.';
    }

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    */

    /**
     * Write an outcome, conditionally on the row not already being `SENT`, and keep the
     * in-memory model honest for the rest of the pass.
     *
     * Conditional because `SENT` is terminal and must stay that way: a lease that expired
     * while its attempt was still in flight is the one case where two workers hold the same
     * row, and the loser must not be able to walk a delivered effect back to `FAILED` and
     * cause a second delivery.
     *
     * @param  array<string, mixed>  $values
     * @return int rows updated — 0 means somebody else settled it first
     */
    private function persist(OutboxMessage $message, array $values): int
    {
        $updated = OutboxMessage::query()
            ->whereKey($message->getKey())
            ->where('status', '!=', OutboxStatus::Sent)
            ->update($values);

        $message->forceFill($values)->syncOriginal();

        return $updated;
    }

    private function connection(): Connection
    {
        return (new OutboxMessage)->getConnection();
    }

    /*
    |--------------------------------------------------------------------------
    | Clocks and knobs
    |--------------------------------------------------------------------------
    */

    /**
     * The instant after which this row must not be redelivered automatically.
     */
    private function dedupDeadline(OutboxMessage $message): Carbon
    {
        return $this->enqueuedAt($message)->copy()->addDays($this->dedupHorizonDays());
    }

    private function isPastDedupHorizon(OutboxMessage $message): bool
    {
        return $this->dedupDeadline($message)->isPast();
    }

    /**
     * When the intent was recorded. `created_at` is the enqueue instant; `available_at` is
     * the fallback for a row written by a migration or a fixture that set no timestamps.
     */
    private function enqueuedAt(OutboxMessage $message): Carbon
    {
        return $message->created_at ?? $message->available_at;
    }

    /**
     * How long a dedup key can be relied on downstream — never longer than the ledger that
     * remembers it (`wa.reliability.idempotency.retention_days`), whatever config asks for.
     */
    public function dedupHorizonDays(): int
    {
        $retention = $this->positiveConfig(
            'wa.reliability.idempotency.retention_days',
            self::DEFAULT_DEDUP_HORIZON_DAYS,
        );

        return max(1, min($this->positiveConfig('wa.reliability.outbox.dedup_horizon_days', $retention), $retention));
    }

    private function batchSize(?int $batch): int
    {
        return $batch ?? $this->positiveConfig('wa.reliability.outbox.batch', self::DEFAULT_BATCH);
    }

    private function leaseSeconds(): int
    {
        return $this->positiveConfig('wa.reliability.outbox.lease_seconds', self::DEFAULT_LEASE_SECONDS);
    }

    private function maxAttempts(): int
    {
        return $this->positiveConfig('wa.reliability.outbox.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
    }

    /**
     * A positive integer from config, or the compiled-in default — so a deleted or
     * nonsensical key degrades one knob instead of stopping the relay.
     */
    private function positiveConfig(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * One line of failure text for `last_error`: what threw, and what it said. Never a
     * response body and never a payload (see `OutboxMessage::MAX_ERROR_LENGTH`).
     */
    private function summarize(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message === ''
            ? $e::class
            : sprintf('%s: %s', class_basename($e), $message);
    }
}
