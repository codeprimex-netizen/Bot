<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;
use InvalidArgumentException;

/**
 * A plain text message to one recipient — the one `OutboundContent` variant every mode
 * supports natively, and the shape every richer variant degrades into.
 *
 * ```php
 * $content = TextContent::to($jid, 'Your order shipped.', $idempotencyKey);
 * ```
 *
 * `SEND_SINGLE` is `✅` on all four modes (design § Channel Mode 2.3, first row), so a send
 * of this content is never refused for a capability reason. That makes it the only content
 * type this phase needs: it proves `ChannelDriver::send()` is a usable contract, and it is
 * the target of the degradation `plainText()` promises. Buttons, lists, product cards, media
 * and location are task 12.4's, and they arrive as new implementations of `OutboundContent`
 * rather than as changes to it or to this class.
 *
 * ## Both fields are validated, because both have a silent failure mode
 *
 * An empty `text` is a message that arrives blank — the protocol accepts it, the recipient
 * sees nothing, the delivery board shows a success, and quota was spent. An empty
 * `idempotencyKey` is worse: it disables the only thing standing between a retried job and
 * a duplicate send, and it does so without any error. Neither is representable.
 */
final readonly class TextContent implements OutboundContent
{
    /**
     * The longest body this will carry.
     *
     * WhatsApp's own limit for a text message is 4096 characters and the protocol truncates
     * silently past it — so the refusal happens here, where the caller can see it, rather
     * than as a message the recipient receives half of.
     */
    public const int MAX_LENGTH = 4096;

    /**
     * @throws InvalidArgumentException when the recipient, body, or key is unusable
     */
    public function __construct(
        private string $recipient,
        private string $text,
        private string $idempotencyKey,
    ) {
        if (trim($this->recipient) === '') {
            throw new InvalidArgumentException('Outbound content must name a recipient.');
        }

        if (trim($this->text) === '') {
            throw new InvalidArgumentException(
                'A text message needs a body: the protocol accepts an empty one, delivers nothing, '
                .'and reports success — so it is refused here instead.'
            );
        }

        if (mb_strlen($this->text) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A text message is limited to %d characters; got %d. The protocol truncates silently, '
                .'so the caller is told instead.',
                self::MAX_LENGTH,
                mb_strlen($this->text),
            ));
        }

        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'Outbound content needs an idempotency key: ChannelDriver::send() is required to be '
                .'idempotent on it, and an empty key makes that guarantee silently vacuous.'
            );
        }
    }

    /**
     * @throws InvalidArgumentException when the recipient, body, or key is unusable
     */
    public static function to(string $recipient, string $text, string $idempotencyKey): self
    {
        return new self($recipient, $text, $idempotencyKey);
    }

    public function capability(): ChannelCapability
    {
        return ChannelCapability::SendSingle;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    /**
     * The body.
     *
     * Never write it to a log: Req 7.3 / A7 permits a content *hash* and nothing more.
     */
    public function plainText(): string
    {
        return $this->text;
    }
}
