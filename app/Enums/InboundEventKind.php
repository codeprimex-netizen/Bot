<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What one verified inbound webhook payload *means*, once the driver that received it has
 * normalised it (Req 8.4 / A8; Req 10.1 / B1; design § Channel Mode 2.5).
 *
 * Four wire formats — the bridge's HMAC callback, Meta's Cloud API webhook, the
 * On-Premise client's callback, and a BSP partner's signed POST — carry between them
 * exactly two things the platform acts on: *a customer said something*, and *something
 * happened to a message we sent*. This enum is that reduction, and it is the discriminator
 * of `App\Services\Channel\InboundEvent`.
 *
 * | Case | Wire examples | What consumes it |
 * |---|---|---|
 * | `MESSAGE` | bridge `messages.upsert`, Meta `entry[].changes[].value.messages[]`, BSP inbound POST | the ConversationEngine (Req 10.1 / B1) |
 * | `DELIVERY_RECEIPT` | bridge `messages.update` ack 2/3, Meta `statuses[].status` of `sent`/`delivered` | the delivery board (task 9.5) |
 * | `READ_RECEIPT` | bridge ack 4, Meta `statuses[].status=read` | the delivery board |
 * | `SEND_FAILURE` | Meta `statuses[].status=failed` with an error, BSP `undelivered` | the delivery board + retry accounting |
 * | `UNSUPPORTED` | a verified payload of a kind this platform does not act on | nothing; it is logged and dropped |
 *
 * ## Why `UNSUPPORTED` exists rather than a nullable parse
 *
 * `ChannelDriver::parseWebhook()` returns an `InboundEvent` or throws. Those are the only
 * two outcomes, deliberately: a `null` would collapse *"the signature did not check out"*
 * (an attack, or a rotated secret — must be a 403 and an alert) into *"Meta sent us a
 * `contacts` update we have no use for"* (routine, must be a 200 so the provider stops
 * retrying). Both would arrive at the controller as "nothing to do", and the difference is
 * the entire security value of the verification step.
 *
 * So verification failure throws `App\Exceptions\Channel\WebhookVerificationException`,
 * and a verified-but-inert payload becomes an `UNSUPPORTED` event: acknowledged, recorded,
 * acted on by nobody. A driver must never widen `UNSUPPORTED` into a guess.
 *
 * ## Not `MessageStatus`
 *
 * The three receipt cases describe *what the provider just told us*. The delivery
 * lifecycle a message row holds — and its never-downgrades rule (Req 20.6 / C3, task 9.5)
 * — is a separate vocabulary owned by the messaging phase, which maps from these. Keeping
 * them apart is what lets a late-arriving `DELIVERY_RECEIPT` be recognised as stale
 * instead of overwriting a `READ` that already landed.
 */
enum InboundEventKind: string
{
    /** A customer sent a message to one of the tenant's numbers. */
    case Message = 'MESSAGE';

    /** An outbound message reached the recipient's device. */
    case DeliveryReceipt = 'DELIVERY_RECEIPT';

    /** An outbound message was read. */
    case ReadReceipt = 'READ_RECEIPT';

    /** An outbound message was rejected or could not be delivered. */
    case SendFailure = 'SEND_FAILURE';

    /** Verified, understood to be none of the above, and acted on by nobody. */
    case Unsupported = 'UNSUPPORTED';

    /**
     * Whether this is inbound *content* — the thing the ConversationEngine reacts to.
     */
    public function isMessage(): bool
    {
        return $this === self::Message;
    }

    /**
     * Whether this reports the fate of a message the platform sent.
     */
    public function isReceipt(): bool
    {
        return match ($this) {
            self::DeliveryReceipt, self::ReadReceipt, self::SendFailure => true,
            self::Message, self::Unsupported => false,
        };
    }

    /**
     * Whether any subsystem acts on this at all.
     *
     * The one question the webhook controller (task 8.3) asks before dispatching: a
     * non-actionable event is still a successful parse and still deserves a `200`, so the
     * provider stops retrying a payload we will never want.
     */
    public function isActionable(): bool
    {
        return $this !== self::Unsupported;
    }

    /**
     * Whether an event of this kind is meaningless without a provider message id.
     *
     * True for a message (the id is what makes inbound processing idempotent — Property 5's
     * single-reply guarantee is keyed on it) and for every receipt (the id is the *only*
     * thing that says which outbound message the receipt is about). `InboundEvent`'s
     * constructor enforces it, so a driver cannot emit a receipt nothing can be correlated
     * with.
     */
    public function requiresProviderMessageId(): bool
    {
        return match ($this) {
            self::Message, self::DeliveryReceipt, self::ReadReceipt, self::SendFailure => true,
            self::Unsupported => false,
        };
    }

    /**
     * Whether an event of this kind must name who it came from.
     *
     * Only a message: a receipt is about a recipient the platform already chose, and
     * requiring a sender there would invite a driver to invent one.
     */
    public function requiresSender(): bool
    {
        return $this === self::Message;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
