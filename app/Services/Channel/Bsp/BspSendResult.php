<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp;

use InvalidArgumentException;

/**
 * What a partner said when it accepted a send: its own message id, and the identity it
 * resolved the recipient to (Req 8.1 / A8).
 *
 * The one fact `BspGatewayChannelDriver` needs out of eight differently-shaped acceptance
 * bodies, and the reason it is a type rather than a string: `SendReceipt` refuses to exist
 * without a provider message id — *"a send acknowledged without a provider message id … could
 * never be matched to a delivery receipt; treat this as a failed send, not a weaker success"* —
 * so the adapter's answer has to be either a complete one or `null`, with no third state where
 * the id is `''`.
 *
 * | Partner | Where the id is |
 * |---|---|
 * | Twilio | `sid` |
 * | 360dialog | `messages[0].id` (Meta's `wamid.…`, passed through) |
 * | Gupshup | `messageId` |
 * | Vonage | `message_uuid` |
 * | MessageBird | `id` |
 * | Infobip | `messages[0].messageId` |
 * | WATI | `message.whatsappMessageId`, else `message.id` |
 * | Kaleyra | `id`, else `data.id` |
 *
 * `$recipient` is optional because only some partners echo the address they resolved (Twilio's
 * `to`, Infobip's `messages[0].to`). When a partner does echo it, it is preferred over the
 * digits the platform sent, for `CloudApiChannelDriver`'s reason: it is *"the identity the
 * backend actually addressed"*, and a partner that normalised a number differently is
 * reconciling receipts against its own spelling rather than ours.
 */
final readonly class BspSendResult
{
    /**
     * @param  string  $providerMessageId  the partner's own id for this message
     * @param  string|null  $recipient  the address the partner echoed, when it echoed one
     *
     * @throws InvalidArgumentException when the id is empty
     */
    public function __construct(
        public string $providerMessageId,
        public ?string $recipient = null,
    ) {
        if (trim($this->providerMessageId) === '') {
            throw new InvalidArgumentException(
                'A BSP send result must carry the partner\'s message id: every later delivery receipt '
                .'is correlated on it, and an unreconcilable "sent" is worse than a retry. An adapter '
                .'that cannot find one must return null so the driver can treat the response as '
                .'unreadable.'
            );
        }
    }

    /**
     * A result, or `null` when `$providerMessageId` is absent or blank.
     *
     * The shape every adapter's `readSendResult()` wants: it reads one or two fields out of a
     * decoded body and hands the decision about a missing id to the driver, which raises the
     * transport failure. Saves eight copies of the same `if`.
     */
    public static function tryFrom(?string $providerMessageId, ?string $recipient = null): ?self
    {
        if ($providerMessageId === null || trim($providerMessageId) === '') {
            return null;
        }

        return new self(
            trim($providerMessageId),
            $recipient === null || trim($recipient) === '' ? null : trim($recipient),
        );
    }
}
