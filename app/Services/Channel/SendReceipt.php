<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Services\Bridge\SentMessageDto;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What one `ChannelDriver::send()` or `sendTemplate()` accomplished — the driver-level
 * acknowledgement, one layer above the transport's `SentMessageDto`
 * (design § Channel Mode 2.4).
 *
 * ```php
 * $receipt = $driver->send($session, $content);
 *
 * $receipt->providerMessageId;   // what every later delivery receipt is keyed on
 * $receipt->degraded;            // true when the rich content was flattened to text
 * ```
 *
 * ## Why this is not `SentMessageDto`
 *
 * `SentMessageDto` is the bridge's answer to *"put this on the wire"*: a WhatsApp message
 * id and the JID the protocol addressed. It is produced by `BridgeClient::sendText()` /
 * `sendMedia()` and knows nothing about modes, capabilities, or which idempotency key
 * caused it — because the transport does not have those, on purpose (see the table in
 * `BridgeClient`'s docblock).
 *
 * This type adds the four facts a *driver-level* send has and a wire operation does not:
 *
 * | Field | Why the transport cannot supply it |
 * |---|---|
 * | `mode` (+ `provider`) | which backend actually sent it — the audit `channel_send_log` needs it, and after a failover it is not the mode the caller asked for (task 8.5) |
 * | `idempotencyKey` | the key the send was deduplicated on; the transport never sees it |
 * | `capability` | which matrix cell the send was gated against, so `channel_send_log.capability` is what was actually decided rather than a guess |
 * | `degraded` | whether the content was flattened because the mode could not render it |
 *
 * `fromSentMessage()` is the seam: a driver that wraps the bridge (task 7.1's
 * `BaileysChannelDriver`) puts one message on the wire, gets a `SentMessageDto`, and adds
 * exactly those four facts. That is the whole of the boundary, and it is why
 * `BridgeClient`'s signatures did not have to change to be extended.
 *
 * ## `providerMessageId` is required, for `SentMessageDto`'s reason
 *
 * Delivery and read receipts arrive keyed on it (`InboundEvent::$providerMessageId`), the
 * campaign counters reconcile on it, and `channel_send_log.provider_message_id` records it.
 * A send acknowledged without one can never be reconciled — indistinguishable from a lost
 * one, but recorded as a success. So it is refused here, and the send is retried on the
 * `BRIDGE` budget instead. Duplicate delivery is recoverable; an unreconcilable "sent" is
 * not.
 *
 * There is deliberately no delivery status on this type, for the same reason
 * `SentMessageDto` has none: acceptance by a provider is not delivery, and a field here
 * would invite a caller to treat it as one.
 */
final readonly class SendReceipt
{
    /**
     * @param  string  $providerMessageId  the id the backend assigned — every later receipt is keyed on it
     * @param  string  $recipient  the identity the backend actually addressed
     * @param  string  $idempotencyKey  the key this send was deduplicated on
     * @param  ChannelCapability  $capability  the matrix cell the send was gated against
     * @param  bool  $degraded  true when rich content was flattened to `OutboundContent::plainText()`
     * @param  BspProvider|null  $provider  the partner, when `$mode` is `BSP_GATEWAY`
     * @param  CarbonImmutable|null  $acceptedAt  when the backend accepted it, when it says
     * @param  string|null  $templateName  the approved template used, for a `sendTemplate()` receipt
     *
     * @throws InvalidArgumentException when the receipt could never be reconciled
     */
    public function __construct(
        public ChannelMode $mode,
        public string $providerMessageId,
        public string $recipient,
        public string $idempotencyKey,
        public ChannelCapability $capability,
        public bool $degraded = false,
        public ?BspProvider $provider = null,
        public ?CarbonImmutable $acceptedAt = null,
        public ?string $templateName = null,
    ) {
        if (trim($this->providerMessageId) === '') {
            throw new InvalidArgumentException(sprintf(
                'A [%s] send was acknowledged without a provider message id, so no delivery receipt '
                .'could ever be matched to it. Treat this as a failed send, not a weaker success.',
                $this->mode->value,
            ));
        }

        if (trim($this->recipient) === '') {
            throw new InvalidArgumentException('A send receipt must name the recipient that was addressed.');
        }

        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'A send receipt must carry the idempotency key it was deduplicated on: it is what a '
                .'replay of the same send is recognised by.'
            );
        }

        if ($this->mode->usesProvider() && $this->provider === null) {
            throw new InvalidArgumentException(
                'A BSP_GATEWAY send receipt must name the partner that carried it: channel_send_log '
                .'records it per provider, and a failover chain across two partners is otherwise '
                .'indistinguishable from a retry on one.'
            );
        }

        if (! $this->mode->usesProvider() && $this->provider !== null) {
            throw new InvalidArgumentException(sprintf(
                'A [%s] send receipt must not name a BSP provider [%s].',
                $this->mode->value,
                $this->provider->value,
            ));
        }
    }

    /**
     * Promote one transport acknowledgement into a driver-level receipt.
     *
     * The boundary between `BridgeClient::sendText()`/`sendMedia()` and
     * `ChannelDriver::send()`, in one place: the wire supplied the message id and the
     * address it resolved to; the driver supplies the mode, the key, the capability, and
     * whether it had to degrade.
     */
    public static function fromSentMessage(
        ChannelMode $mode,
        SentMessageDto $sent,
        string $idempotencyKey,
        ChannelCapability $capability,
        bool $degraded = false,
        ?BspProvider $provider = null,
        ?string $templateName = null,
    ): self {
        return new self(
            mode: $mode,
            providerMessageId: $sent->waMessageId,
            recipient: $sent->jid,
            idempotencyKey: $idempotencyKey,
            capability: $capability,
            degraded: $degraded,
            provider: $provider,
            acceptedAt: $sent->sentAt,
            templateName: $templateName,
        );
    }

    /**
     * Whether this receipt came from an approved-template send.
     */
    public function isTemplated(): bool
    {
        return $this->templateName !== null;
    }
}
