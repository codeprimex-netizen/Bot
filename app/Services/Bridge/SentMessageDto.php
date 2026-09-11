<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Exceptions\Bridge\BridgeUnreachableException;
use Carbon\CarbonImmutable;

/**
 * The bridge's acknowledgement of one accepted outbound message (Req 3.2, 3.3 / A3).
 *
 * ## `waMessageId` is required, and that is the whole point of this DTO
 *
 * Everything downstream of a send is keyed on the id WhatsApp assigned: delivery and read
 * receipts arrive by `wa_message_id` (task 9.5), the campaign counters reconcile on it, and
 * the "zero duplicate `wa_message_id`" assertion of the concurrency test is stated in terms
 * of it. An acknowledgement without one is therefore not a weaker success — it is a send
 * whose outcome can never be reconciled, which is indistinguishable from a lost one.
 *
 * So `fromPayload()` refuses it as a malformed response, and the send is retried on the
 * `BRIDGE` budget instead of being recorded as sent. That is the fail-closed direction:
 * duplicate delivery is recoverable (the idempotency key of the send pipeline catches it), a
 * message recorded as sent that nothing can ever confirm is not.
 *
 * `status` is deliberately absent. A bridge acknowledgement means *accepted for
 * transmission*, never *delivered*; the delivery lifecycle is `MessageStatus` and arrives
 * later, over webhooks, and letting this DTO carry a status would invite a caller to treat
 * an acceptance as a delivery.
 */
final readonly class SentMessageDto
{
    /**
     * @param  string  $waMessageId  the id WhatsApp assigned — every later receipt is keyed on it
     * @param  string  $jid  the recipient the bridge actually addressed, normalised by the protocol
     * @param  CarbonImmutable|null  $sentAt  when the bridge put it on the wire, when it says
     */
    public function __construct(
        public string $waMessageId,
        public string $jid,
        public ?CarbonImmutable $sentAt = null,
    ) {}

    /**
     * Read a decoded bridge response body.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BridgeUnreachableException when no usable `wa_message_id` came back
     */
    public static function fromPayload(string $operation, string $fallbackJid, array $payload): self
    {
        $waMessageId = BridgeWire::stringOrNull($payload['wa_message_id'] ?? null);

        if ($waMessageId === null) {
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'the send was acknowledged without a "wa_message_id", so it could never be reconciled',
            );
        }

        return new self(
            waMessageId: $waMessageId,
            jid: BridgeWire::stringOrNull($payload['jid'] ?? null) ?? $fallbackJid,
            sentAt: BridgeWire::timestampOrNull($payload['sent_at'] ?? null),
        );
    }
}
