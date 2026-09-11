<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;

/**
 * One outbound message, described in terms the send gate can decide about and every backend
 * can render — the argument to `ChannelDriver::send()` (design § Channel Mode 2.4;
 * § Advanced Chatbot Capabilities, *"Rich interactive WhatsApp messages & graceful
 * degradation"*).
 *
 * ```php
 * $content = TextContent::to('9198…@s.whatsapp.net', 'Your order shipped.', $idempotencyKey);
 *
 * // Algorithm 9, in the order it actually happens:
 * $router->assertSupported($session, $content->capability());   // task 6.3
 * $receipt = $driver->send($session, $content);                 // this phase
 * ```
 *
 * ## Four members, and why each one has to be here
 *
 * | Member | Who reads it | Why it cannot live elsewhere |
 * |---|---|---|
 * | `capability()` | `channelSendGate` (task 8.1), `ChannelRouter::assertSupported()` (6.3) | the gate must know what to check **before** touching a driver (Property 21) |
 * | `idempotencyKey()` | every driver's `send()` | design § 2.4: *"MUST be idempotent on `$content->idempotencyKey`"* |
 * | `recipient()` | every driver's `send()` | the one field the transport cannot derive from the session |
 * | `plainText()` | the driver, when it must degrade | degradation has to be **total**, and only the variant knows how to flatten itself |
 *
 * ## `plainText()` is mandatory, because degradation must never fail
 *
 * design.md's degradation table promises reply buttons become *"Reply 1/2/3"* numbered
 * text, a list message becomes a numbered menu, and a product card becomes text with name,
 * price and link — *"so flows work everywhere"*. That promise only holds if **every**
 * variant can render itself as text, so it is a required member rather than an optional
 * interface a rich variant might forget to implement. A variant with no textual rendering
 * would be a message that simply cannot be sent on a session whose mode reports
 * `Interactive` as anything but native — which is most of them.
 *
 * The `menu` flow node already parses numeric choices, so a degraded interactive message
 * remains *functionally* interactive. That is what makes flattening an acceptable answer
 * rather than a silent feature loss.
 *
 * ## `capability()` is the content's own requirement, not the operation's
 *
 * A `TextContent` requires `SEND_SINGLE`. It does **not** know that the campaign runner is
 * about to send it to nine thousand recipients, or that the recipient is a group JID — so it
 * cannot answer `SEND_BULK` or `GROUPS`. Algorithm 9's `capabilityFor(message)` is
 * therefore the *union* of what the content requires and what the operation requires, and
 * the second half is the caller's to add (tasks 8.1, and the group services of Phase 15).
 *
 * Resolving it the other way — having content claim `SEND_BULK` — would mean the same text
 * to the same person is a different capability depending on which button a tenant pressed,
 * and the gate would have to trust the content about the operation.
 *
 * ## What task 12.4 owns
 *
 * Only `TextContent` implements this today: it is what `send()` needs to be a usable
 * contract, and it is the target every degradation lands on. The rich family — buttons,
 * list messages, product/catalog cards, media, and location — belongs to the messaging
 * phase (design § 12.4), which adds implementations of this interface **and no changes to
 * it**: a new variant is a new class that answers these four questions.
 */
interface OutboundContent
{
    /**
     * The capability a backend must have to render this content at all.
     *
     * Consulted before dispatch; an unsupported answer is a `ModeCapabilityException` and
     * never a driver call (task 6.3, Property 21).
     */
    public function capability(): ChannelCapability;

    /**
     * The stable key that makes sending this content twice send it once.
     *
     * Chosen by the pipeline, not by the content: it has to survive a retry of the whole
     * job, so it cannot be derived from anything the retry recreates. Non-empty by
     * construction in every implementation — a blank key is an idempotency guarantee that
     * silently is not one.
     */
    public function idempotencyKey(): string;

    /**
     * Who this goes to, as the fully-qualified WhatsApp identity the transport addresses
     * (`…@s.whatsapp.net`, `…@g.us`) or the E.164 number an official API expects.
     *
     * Normalisation is the sending layer's business; this is what it decided.
     */
    public function recipient(): string;

    /**
     * This content flattened to text that any backend can send.
     *
     * For `TextContent`, the text itself. For a rich variant, the numbered-menu rendering of
     * design.md's degradation table. Never empty: it is the fallback of last resort, and an
     * empty fallback is a message that arrives blank.
     */
    public function plainText(): string;
}
