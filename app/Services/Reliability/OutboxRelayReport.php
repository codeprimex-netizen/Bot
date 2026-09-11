<?php

declare(strict_types=1);

namespace App\Services\Reliability;

/**
 * What one relay pass did — the value `Outbox::relayBatch()` returns and the scheduled
 * command prints (Req 31.4 / NFR2, Algorithm 6).
 *
 * Every claimed row lands in exactly one bucket, and the buckets are the only five things
 * that can happen to a claimed row:
 *
 * | Bucket | Meaning | Next |
 * |---|---|---|
 * | `delivered` | the receiver acked; the row is `SENT` | done, for ever |
 * | `retrying` | the attempt failed transiently; `next_attempt_at` holds the jittered backoff | claimed again when the gate opens |
 * | `parked` | the retry budget is spent, the failure is not retryable, or redelivery is no longer safe | **kept**, and waits for an operator (`Outbox::requeue()`) |
 * | `shed` | a circuit breaker refused the call, so no attempt was made | claimed again after the cool-down, with its budget intact |
 * | `raced` | delivered, but another worker had already recorded the row as `SENT` | nothing — the consumer dedups on `dedup_key` |
 *
 * …plus one bucket that is not a claimed row at all:
 *
 * | Bucket | Meaning | Next |
 * |---|---|---|
 * | `abandoned` | the row's budget was spent by claims that never reported an outcome; this pass parked it | **kept**, and waits for an operator, exactly like `parked` |
 *
 * The invariant a test can assert is `claimed === delivered + retrying + parked + shed`
 * (`isBalanced()`): no claimed row leaves a pass unaccounted for, which is how Req 31.4's
 * *"never lose the row"* stays checkable rather than merely asserted. `raced` is a
 * *subset* of `delivered` rather than a sixth bucket — the effect did reach the receiver,
 * it just was not this worker that recorded it — so it is reported alongside the four
 * exclusive outcomes instead of inside the sum.
 *
 * `abandoned` is outside the sum for the opposite reason: those rows were **not claimed by
 * this pass at all**. They are rows whose whole attempt budget was spent by claims that
 * never reported an outcome — a worker killed between the claim and the write — which
 * `DatabaseOutbox::reapAbandoned()` parks on sight before claiming, so that the parking they
 * are already subject to is visible instead of implicit. Adding them to the balance would
 * make `isBalanced()` false on every pass that repairs one.
 *
 * `parked`, `abandoned` and `raced` are the three numbers worth an operator's attention. A
 * rising `parked` means effects are queued up that nothing will deliver without a human; a
 * non-zero `abandoned` means relay workers are dying mid-attempt (OOM kills, a deploy that
 * does not drain, a lease shorter than the transport's timeout) and each one cost a row its
 * whole budget; a non-zero `raced` means two workers held the same row, i.e. a lease expired
 * mid-attempt — harmless for correctness (the dedup key covers it) and a sign the lease is
 * too short for the transport's timeouts.
 */
final class OutboxRelayReport
{
    private int $claimed = 0;

    private int $delivered = 0;

    private int $retrying = 0;

    private int $parked = 0;

    private int $shed = 0;

    private int $raced = 0;

    private int $abandoned = 0;

    public function recordClaimed(int $rows = 1): void
    {
        $this->claimed += max(0, $rows);
    }

    public function recordDelivered(): void
    {
        $this->delivered++;
    }

    public function recordRetrying(): void
    {
        $this->retrying++;
    }

    public function recordParked(): void
    {
        $this->parked++;
    }

    public function recordShed(): void
    {
        $this->shed++;
    }

    public function recordRaced(): void
    {
        $this->raced++;
        $this->delivered++;
    }

    /**
     * Rows this pass parked without claiming them, because their budget was already spent.
     */
    public function recordAbandoned(int $rows = 1): void
    {
        $this->abandoned += max(0, $rows);
    }

    public function claimed(): int
    {
        return $this->claimed;
    }

    /**
     * Rows the receiver acked in this pass — design.md's *"returns delivered count"*.
     */
    public function delivered(): int
    {
        return $this->delivered;
    }

    public function retrying(): int
    {
        return $this->retrying;
    }

    public function parked(): int
    {
        return $this->parked;
    }

    public function shed(): int
    {
        return $this->shed;
    }

    public function raced(): int
    {
        return $this->raced;
    }

    public function abandoned(): int
    {
        return $this->abandoned;
    }

    /**
     * Whether the pass found nothing to do — the steady state, and the one case the
     * scheduled command stays quiet about.
     *
     * A pass that claimed nothing but parked an abandoned row **did** do something, and
     * something an operator has to hear about: it is not empty, so the command reports it
     * rather than returning early.
     */
    public function isEmpty(): bool
    {
        return $this->claimed === 0 && $this->abandoned === 0;
    }

    /**
     * Whether every claimed row is accounted for. False can only mean a bug in the relay's
     * own bookkeeping, which is exactly why it is checkable.
     *
     * `abandoned` is not in the sum: those rows were never claimed by this pass.
     */
    public function isBalanced(): bool
    {
        return $this->claimed === $this->delivered + $this->retrying + $this->parked + $this->shed;
    }

    /**
     * Add another pass's counts to this one, so a draining run reports its total.
     */
    public function merge(self $other): void
    {
        $this->claimed += $other->claimed;
        $this->delivered += $other->delivered;
        $this->retrying += $other->retrying;
        $this->parked += $other->parked;
        $this->shed += $other->shed;
        $this->raced += $other->raced;
        $this->abandoned += $other->abandoned;
    }

    /**
     * @return array{claimed: int, delivered: int, retrying: int, parked: int, shed: int, raced: int, abandoned: int}
     */
    public function toArray(): array
    {
        return [
            'claimed' => $this->claimed,
            'delivered' => $this->delivered,
            'retrying' => $this->retrying,
            'parked' => $this->parked,
            'shed' => $this->shed,
            'raced' => $this->raced,
            'abandoned' => $this->abandoned,
        ];
    }
}
