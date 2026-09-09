<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Services\Reliability\OutboxDelivery;
use App\Services\Reliability\OutboxTransport;
use Closure;
use RuntimeException;
use Throwable;

/**
 * A transport **and** the consumer on the other end of it, which is what makes Property 16
 * testable: exactly-once is a joint property of the relay delivering at least once and the
 * consumer deduping on `X-Dedup-Key`, so a fake that only counted calls could not observe it.
 *
 * Two collections, and the difference between them is the whole point:
 *
 * - `deliveries()` — every `deliver()` call, i.e. every attempt the relay made.
 * - `applied($dedupKey)` — how many times the *effect* landed. The fake dedups on the
 *   header exactly as a real consumer must, so this is 1 however often the relay delivers.
 *
 * ```php
 * $transport = RecordingOutboxTransport::crashingAfterAck(new RuntimeException('worker died'));
 * // …relay twice…
 * expect($transport->deliveryCount())->toBe(2)      // at least once
 *     ->and($transport->applied($key))->toBe(1);    // exactly one effect
 * ```
 *
 * A behaviour that throws *before* `accept()` is a receiver that refused (nothing applied);
 * one that throws *after* it is a crash between the ack and the relay's `SENT` write — the
 * interleaving Algorithm 6's postcondition allows and Property 16 has to survive.
 */
final class RecordingOutboxTransport implements OutboxTransport
{
    /**
     * @var list<OutboxDelivery>
     */
    private array $deliveries = [];

    /**
     * Effects the consumer actually applied, keyed by dedup key.
     *
     * @var array<string, int>
     */
    private array $applied = [];

    /**
     * @param  Closure(self, OutboxDelivery): void  $behaviour
     */
    private function __construct(private readonly Closure $behaviour) {}

    /**
     * The happy path: the receiver acks every delivery.
     */
    public static function acking(): self
    {
        return new self(static function (self $transport, OutboxDelivery $delivery): void {
            $transport->accept($delivery);
        });
    }

    /**
     * A receiver that refuses every delivery. Nothing is applied.
     */
    public static function failing(Throwable $error): self
    {
        return new self(static function (self $transport, OutboxDelivery $delivery) use ($error): never {
            throw $error;
        });
    }

    /**
     * A receiver that refuses the first `$times` attempts of each row and acks after that.
     */
    public static function failingTimes(int $times, Throwable $error): self
    {
        return new self(static function (self $transport, OutboxDelivery $delivery) use ($times, $error): void {
            if ($delivery->attempt <= $times) {
                throw $error;
            }

            $transport->accept($delivery);
        });
    }

    /**
     * The receiver acks and applies the effect, and *then* the worker dies before the relay
     * can record `SENT` — for the first `$times` attempts of each row.
     */
    public static function crashingAfterAck(Throwable $error, int $times = 1): self
    {
        return new self(static function (self $transport, OutboxDelivery $delivery) use ($error, $times): void {
            $transport->accept($delivery);

            if ($delivery->attempt <= $times) {
                throw $error;
            }
        });
    }

    /**
     * Anything else the test needs — the behaviour is handed this transport and the delivery.
     *
     * @param  Closure(self, OutboxDelivery): void  $behaviour
     */
    public static function behaving(Closure $behaviour): self
    {
        return new self($behaviour);
    }

    public function deliver(OutboxDelivery $delivery): void
    {
        $this->deliveries[] = $delivery;

        ($this->behaviour)($this, $delivery);
    }

    /**
     * The consumer side: apply the effect at most once per dedup key.
     *
     * A delivery with no dedup header is a bug in the relay, not something a consumer can
     * work around — so it is fatal here rather than quietly applied twice.
     */
    public function accept(OutboxDelivery $delivery): void
    {
        $key = $delivery->header('X-Dedup-Key');

        if ($key === null || $key === '') {
            throw new RuntimeException('Delivery carried no X-Dedup-Key header; the consumer cannot dedup it.');
        }

        // The dedup itself: a key already seen is a redelivery, and a redelivery does not
        // apply the effect a second time.
        if (! isset($this->applied[$key])) {
            $this->applied[$key] = 1;
        }
    }

    /**
     * @return list<OutboxDelivery>
     */
    public function deliveries(): array
    {
        return $this->deliveries;
    }

    /**
     * Attempts made, in total or for one dedup key.
     */
    public function deliveryCount(?string $dedupKey = null): int
    {
        if ($dedupKey === null) {
            return count($this->deliveries);
        }

        return count(array_filter(
            $this->deliveries,
            static fn (OutboxDelivery $delivery): bool => $delivery->dedupKey === $dedupKey,
        ));
    }

    /**
     * How many times the effect behind `$dedupKey` was applied — 1 for any number of
     * deliveries, 0 if the receiver never accepted one.
     */
    public function applied(string $dedupKey): int
    {
        return $this->applied[$dedupKey] ?? 0;
    }

    /**
     * Distinct effects applied, for a test that wants the total rather than one key.
     */
    public function appliedCount(): int
    {
        return array_sum($this->applied);
    }

    public function lastDelivery(): ?OutboxDelivery
    {
        return $this->deliveries === [] ? null : $this->deliveries[count($this->deliveries) - 1];
    }
}
