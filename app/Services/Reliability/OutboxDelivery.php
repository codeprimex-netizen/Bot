<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Models\OutboxMessage;

/**
 * One attempt at delivering one outbox row, as the transport sees it (Algorithm 6,
 * Correctness Property 16).
 *
 * The relay hands this to `OutboxTransport::deliver()` instead of the Eloquent model, for
 * two reasons that are the same reason twice: a transport must not be able to *change* the
 * row it is delivering (`status`, `attempts` and the two clocks belong to the relay
 * alone), and a Phase 5+ channel driver should be implementable against a value object
 * rather than against the platform's schema.
 *
 * ## `dedup_key` is the payload's most important part
 *
 * It travels as a **header** (`X-Dedup-Key`), not buried in the body, so a consumer can
 * dedup before it parses anything — which is precisely what turns Algorithm 6's
 * at-least-once delivery into an exactly-once *effect* (Property 16). The header names come
 * from `OutboxMessage::deliveryHeaders()` so the enqueuer, the relay, the transport and the
 * consumer-side contract test all read them from one place; `X-Delivery-Attempt` is added
 * here because it is a property of the attempt rather than of the row, and it lets a
 * consumer log "this is a redelivery" without guessing.
 */
final readonly class OutboxDelivery
{
    /**
     * Which attempt this is, 1-based — a consumer that sees `> 1` is being redelivered.
     */
    public const string ATTEMPT_HEADER = 'X-Delivery-Attempt';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public int $id,
        public ?string $tenantId,
        public string $aggregateType,
        public string $aggregateId,
        public string $eventType,
        public ?string $destination,
        public array $payload,
        public string $dedupKey,
        public int $attempt,
        public array $headers,
    ) {}

    /**
     * The attempt described by a claimed row, whose `attempts` the relay has already
     * incremented — so `attempt` is this attempt's number and not the previous one's.
     */
    public static function for(OutboxMessage $message): self
    {
        $attempt = max(1, $message->attempts);

        return new self(
            (int) $message->getKey(),
            $message->tenant_id,
            $message->aggregate_type,
            $message->aggregate_id,
            $message->event_type,
            $message->destination,
            $message->payload,
            $message->dedup_key,
            $attempt,
            $message->deliveryHeaders() + [self::ATTEMPT_HEADER => (string) $attempt],
        );
    }

    /**
     * Whether this is a redelivery of an effect the consumer may already have applied.
     */
    public function isRedelivery(): bool
    {
        return $this->attempt > 1;
    }

    /**
     * One header, or null when the transport is asked for one that is not set.
     */
    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * A short, stable, non-reversible stand-in for the dedup key, for messages and logs
     * that must not carry an order id or a gateway event id.
     */
    public function fingerprint(): string
    {
        return '#'.substr(hash('sha256', $this->dedupKey), 0, 8);
    }
}
