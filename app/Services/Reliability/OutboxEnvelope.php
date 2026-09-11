<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\OutboxStatus;
use App\Models\Tenant;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;

/**
 * One side-effecting intent, on its way into the `outbox` table (Req 31.4 / NFR2,
 * Algorithm 6).
 *
 * ```php
 * DB::transaction(function () use ($order, $outbox) {
 *     $order->markPaid();                                   // the state change
 *
 *     $outbox->record(
 *         OutboxEnvelope::for('order', $order->id, 'order.paid', $order->webhookPayload(), "order.paid:{$order->id}")
 *             ->to($subscription->url)                      // where it goes
 *             ->forTenant($order->tenant_id)                // who it belongs to
 *             ->availableAt($order->notify_after),          // the *intent* clock, optional
 *     );
 * });                                                       // both commit, or neither does
 * ```
 *
 * `Outbox::enqueue()` is design.md's five-argument form and covers the common case; this
 * object exists for the three facts that form cannot carry — the tenant, the destination,
 * and a delivery that is deliberately scheduled for later — without growing the interface
 * a signature at a time.
 *
 * ## Why the widths are validated here
 *
 * `aggregate_type`, `event_type`, `destination` and `dedup_key` are bounded `VARCHAR`s.
 * MySQL in strict mode refuses an over-long value; **SQLite silently accepts it**, so a
 * test suite on SQLite would pass while production rejected the write — and a non-strict
 * MySQL deployment would *truncate*, which is worse than either: two different dedup keys
 * truncated to the same 191 characters become one row, and one of the two side effects is
 * skipped for ever. Same reasoning, same posture, as
 * `DatabaseIdempotencyStore::MAX_KEY_LENGTH`.
 *
 * The payload is checked for JSON-encodability at the same moment, because the alternative
 * is a `JsonException` thrown by the cast in the middle of the caller's transaction, where
 * it reads as a database failure rather than as "this payload cannot be a webhook body".
 */
final readonly class OutboxEnvelope
{
    /**
     * `outbox` column widths — see the class docblock for why PHP enforces them.
     */
    public const int MAX_AGGREGATE_TYPE_LENGTH = 64;

    public const int MAX_AGGREGATE_ID_LENGTH = 64;

    public const int MAX_EVENT_TYPE_LENGTH = 96;

    public const int MAX_DESTINATION_LENGTH = 255;

    public const int MAX_DEDUP_KEY_LENGTH = 191;

    /**
     * Depth `json_encode()` is allowed to walk when proving the payload storable. Deep
     * enough for any realistic webhook body, shallow enough that a self-referential
     * structure is reported as a bad payload instead of exhausting memory.
     */
    private const int MAX_PAYLOAD_DEPTH = 32;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public string $aggregateType,
        public string $aggregateId,
        public string $eventType,
        public array $payload,
        public string $dedupKey,
        public ?string $tenantId = null,
        public ?string $destination = null,
        public ?Carbon $availableAt = null,
    ) {}

    /**
     * The intent itself: what changed, what happened, and the key the consumer dedups on.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException a blank or over-long identifier, or a payload that
     *                                  cannot be stored as JSON
     */
    public static function for(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        string $dedupKey,
    ): self {
        return new self(
            self::assertIdentifier($aggregateType, 'aggregate type', self::MAX_AGGREGATE_TYPE_LENGTH),
            self::assertIdentifier($aggregateId, 'aggregate id', self::MAX_AGGREGATE_ID_LENGTH),
            self::assertIdentifier($eventType, 'event type', self::MAX_EVENT_TYPE_LENGTH),
            self::assertStorable($payload),
            self::assertIdentifier($dedupKey, 'dedup key', self::MAX_DEDUP_KEY_LENGTH),
        );
    }

    /**
     * Where the effect goes — a webhook URL, a queue name, a channel.
     *
     * Optional because the routing for some event types is resolved by the transport from
     * subscriptions rather than pinned at enqueue time (the migration says the same thing
     * about the nullable column).
     *
     * @throws InvalidArgumentException an over-long destination
     */
    public function to(?string $destination): self
    {
        return $this->with(destination: $destination === null
            ? null
            : self::assertIdentifier($destination, 'destination', self::MAX_DESTINATION_LENGTH));
    }

    /**
     * Attribute the effect to a tenant. Left unset, `Outbox::record()` falls back to the
     * ambient tenant context, and a platform-level effect ends up with `null` — which on
     * this table is a legal value rather than a missing one.
     */
    public function forTenant(Tenant|string|null $tenant): self
    {
        return $this->with(tenantId: $tenant instanceof Tenant ? $tenant->id : $tenant);
    }

    /**
     * The earliest this effect should ever be delivered — the *intent* clock.
     *
     * Written once to `available_at` and never moved again; the relay's backoff lives in
     * `next_attempt_at`, which is initialised from this. Passing a past instant is
     * harmless and means "as soon as possible".
     */
    public function availableAt(DateTimeInterface $at): self
    {
        return $this->with(availableAt: Carbon::instance($at));
    }

    /**
     * The same thing said in seconds from now, for a caller that has a delay rather than
     * an instant.
     */
    public function delayedBy(int $seconds): self
    {
        return $this->with(availableAt: Carbon::now()->addSeconds(max(0, $seconds)));
    }

    /**
     * The row to insert.
     *
     * Both clocks are set here, and set to the *same* instant: `available_at` is the
     * intent, `next_attempt_at` starts open at that intent and is only ever pushed
     * forward by the relay. A null retry gate would fall outside the claim's range
     * predicate and strand the row for ever, which is why neither is left to a default.
     *
     * @param  string|null  $fallbackTenantId  the ambient tenant, used when the envelope names none
     * @return array<string, mixed>
     */
    public function attributes(?string $fallbackTenantId = null): array
    {
        $availableAt = $this->availableAt ?? Carbon::now();

        return [
            'tenant_id' => $this->tenantId ?? $fallbackTenantId,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'event_type' => $this->eventType,
            'destination' => $this->destination,
            'payload' => $this->payload,
            'dedup_key' => $this->dedupKey,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'available_at' => $availableAt,
            'next_attempt_at' => $availableAt,
            'last_error' => null,
            'sent_at' => null,
        ];
    }

    /**
     * A copy with one or two facts replaced — the readonly equivalent of a setter.
     */
    private function with(?string $tenantId = null, ?string $destination = null, ?Carbon $availableAt = null): self
    {
        return new self(
            $this->aggregateType,
            $this->aggregateId,
            $this->eventType,
            $this->payload,
            $this->dedupKey,
            $tenantId ?? $this->tenantId,
            $destination ?? $this->destination,
            $availableAt ?? $this->availableAt,
        );
    }

    /**
     * A non-blank value that fits its column.
     *
     * @throws InvalidArgumentException
     */
    private static function assertIdentifier(string $value, string $what, int $max): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf('An outbox %s cannot be blank.', $what));
        }

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException(sprintf(
                'An outbox %s is limited to %d characters; %d given. Truncating it here would merge two '
                .'distinct effects into one row.',
                $what,
                $max,
                mb_strlen($trimmed),
            ));
        }

        return $trimmed;
    }

    /**
     * A payload the `payload` cast can really store.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function assertStorable(array $payload): array
    {
        try {
            json_encode($payload, JSON_THROW_ON_ERROR, self::MAX_PAYLOAD_DEPTH);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'An outbox payload must be JSON-encodable: '.$e->getMessage(),
                previous: $e,
            );
        }

        return $payload;
    }
}
