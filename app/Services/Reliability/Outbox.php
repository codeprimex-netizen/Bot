<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Models\OutboxMessage;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The transactional outbox: record a side-effecting intent **in the same transaction** as
 * the state change that caused it, then relay it with a dedup key so the downstream effect
 * lands exactly once — even across relay crashes (Req 31.4 / NFR2, Algorithm 6, Correctness
 * Property 16; design.md §"Components and Interfaces → 5. Reliability primitives").
 *
 * ```php
 * DB::transaction(function () use ($order, $outbox) {
 *     $order->markPaid();                                    // the state change
 *     $outbox->enqueue('order', $order->id, 'order.paid', $payload, "order.paid:{$order->id}");
 * });                                                        // one commit, both facts
 * ```
 *
 * ## The problem it removes
 *
 * Committing a state change and then calling out is a **dual write**: crash in between and
 * the two disagree for ever — the order is paid and the webhook never fires, or the webhook
 * fires for an order whose transaction rolled back. There is no ordering of the two that
 * fixes it, so the outbox stops trying: the intent is written *as part of the state change*,
 * as a row, and a separate relay turns that row into the call. A crash can now only lose the
 * *attempt*, never the intent — and the attempt is retried.
 *
 * ## At-least-once delivery, exactly-once effect
 *
 * The relay guarantees **at least once**: a row is marked `SENT` only after the receiver
 * acks, so a crash after the call but before the update redelivers. The consumer closes the
 * gap by deduping on the `X-Dedup-Key` header every delivery carries (see
 * `OutboxMessage::deliveryHeaders()`); for a consumer inside this platform, that is
 * `IdempotencyStore::once()` with the dedup key. Delivery is at-least-once, the *effect* is
 * exactly-once, and neither half is optional — a consumer that ignores the header gets
 * duplicates and no amount of relay cleverness can prevent it.
 *
 * ## Two clocks, and why neither is redundant
 *
 * | Column | Meaning | Written by |
 * |---|---|---|
 * | `available_at` | the delivery *intent*: the earliest this effect should ever go out | the enqueuer, once, never moved |
 * | `next_attempt_at` | the *retry gate*: when the relay may next try | the relay, after every attempt |
 *
 * A row is only claimed when **both** have passed. Keeping the intent immutable is what
 * lets an operator tell "scheduled for 09:00" apart from "delayed by nine failed attempts",
 * and it is why the relay can rewrite the gate freely without ever losing the schedule the
 * caller asked for.
 *
 * ## Nothing is ever dropped
 *
 * Req 31.4 says the relay never loses the row, and `OutboxStatus` has no `DEAD` state to
 * lose it into. A row whose retry budget is spent — or whose failure is not the kind that
 * improves with waiting — is therefore **parked**: kept, `FAILED`, with its `attempts` and
 * `last_error` intact, excluded from further claims, and visible to operators, who can hand
 * it back with `requeue()`. Quarantine is an explicit human act against a real row, never
 * an implicit state the relay can drop work into.
 */
interface Outbox
{
    /**
     * Record an intent. **Must be called inside the caller's own transaction** — the one
     * that is making the state change this effect describes.
     *
     * This method deliberately does not open a transaction of its own: doing so would
     * commit the row independently of the state change and reintroduce the dual write the
     * outbox exists to remove. Called with no surrounding transaction it still works and is
     * still useful (a platform notification with no state change behind it), but the
     * atomicity guarantee is the caller's to provide.
     *
     * design.md's five-argument form. `record()` is the same operation with the tenant, the
     * destination, and a scheduled `available_at` available to it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException a blank/over-long identifier or an unstorable payload
     */
    public function enqueue(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        string $dedupKey,
    ): void;

    /**
     * Record an intent described by an envelope, and hand back the row.
     *
     * Same transactional contract as `enqueue()`, which is implemented in terms of this.
     *
     * @throws InvalidArgumentException as `enqueue()`
     */
    public function record(OutboxEnvelope $envelope): OutboxMessage;

    /**
     * Deliver a batch (Algorithm 6): claim up to `$batch` due rows, deliver each one, mark
     * it `SENT` on an ack and reschedule it on a failure.
     *
     * @return int rows the receiver acked in this pass
     */
    public function relay(int $batch = 200): int;

    /**
     * The same pass, with every outcome counted — what the scheduled command reports.
     *
     * @param  int|null  $batch  rows to claim, or null for `wa.reliability.outbox.batch`
     */
    public function relayBatch(?int $batch = null): OutboxRelayReport;

    /**
     * Hand a parked row back to the relay — the operator half of "never lose the row".
     *
     * The row's attempt budget is reset and its gate reopened; the dedup key, the payload
     * and the recorded `last_error` are left exactly as they are, so a redelivery is still
     * deduplicated by the consumer and the failure history survives.
     *
     * @param  DateTimeInterface|null  $at  when to try again, defaulting to immediately
     * @return bool false when the row does not exist, is already `SENT`, or can no longer be
     *              redelivered safely (see the dedup horizon in `DatabaseOutbox`)
     */
    public function requeue(OutboxMessage|int $message, ?DateTimeInterface $at = null): bool;
}
