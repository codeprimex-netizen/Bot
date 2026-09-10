<?php

declare(strict_types=1);

namespace App\Exceptions\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An operation was asked of a backend whose capability matrix refuses it — raised
 * **before any driver call**, so the operation is never dispatched and has no side effect
 * (Req 8.3 / A8; design § Channel Mode 2.3; Correctness Properties 21 and 26).
 *
 * ```php
 * // ChannelRouter::assertSupported() — the one place this is raised for a *session*
 * $router->assertSupported($session, ChannelCapability::Groups);
 * // CLOUD_API ⇒ throws here; the GroupService never reaches the driver, let alone the wire
 * ```
 *
 * ## Why the refusal is an exception and not a verdict
 *
 * Every capability-sensitive service — groups, welcome, extraction, tagging, channels,
 * templates — asks the same question first, and design.md's answer is emphatic about the
 * ordering: *"on an official mode this throws `ModeCapabilityException` **before** any
 * driver call — exactly how group-admin checks already reject ops pre-Bridge-call"*
 * (§ 2.5). A boolean beside the call would be one more thing eight services have to
 * remember; the one that forgot would send a group-create to Meta's Cloud API and get a
 * provider error mid-operation instead of a typed local refusal — Property 26's *"fails
 * typed, never crashes"*, inverted.
 *
 * ## Who raises it
 *
 * | Raiser | For |
 * |---|---|
 * | `ChannelRouter::assertSupported()` (task 6.3) | every capability gate on a session — the pre-dispatch check |
 * | `CloudApiChannelDriver`, `OnPremiseChannelDriver`, `BspGatewayChannelDriver` (7.2–7.4) | a driver method that exists on the interface but not on the backend (`sendTemplate()` on Baileys, a group op on an official mode) |
 * | `channelSendGate` (Algorithm 9, task 8.1) | the `IF NOT driver.supports(cap)` arm |
 * | task 8.8's property test | random `(mode, capability)` pairs |
 *
 * Note what is *not* in that table: `ModeGuardedChannelDriver` deliberately does not raise
 * this. It narrows `supports()` so a refusal cannot be widened, but refusing an operation
 * is the router's job — otherwise one operation could fail with two different exceptions
 * depending on which layer noticed first.
 *
 * ## 422, and never retried
 *
 * **422 Unprocessable Content**: the request was understood, addressed to the right
 * tenant and the right session, and is simply not something this backend can do. It is not
 * a 403 — nothing about permissions or ownership is wrong, and a tenant that wants group
 * management is not *forbidden* it, it just needs a Baileys session — and not a 501, which
 * would suggest the platform has not got round to implementing it.
 *
 * The status is also the retry classification. `PlatformErrorClassifier` maps any
 * `HttpExceptionInterface` in the `4xx` range that it has no specific opinion about to
 * `ErrorClass::Validation`, whose retry budget is `0` attempts (`wa.reliability.retry`) —
 * which is exactly right here, because no amount of waiting adds `GROUPS` to Cloud API.
 * That is why this class needs no entry in that classifier's typed map: the general rule
 * already produces the only sensible answer, and an entry would be a second place to keep
 * in step.
 *
 * ## What may be said out loud
 *
 * Both halves of the message are platform-authored vocabulary from this codebase —
 * `ChannelMode::label()` and `ChannelCapability::label()`, the matrix's own row and column
 * headings — so unlike most exceptions on this platform there is nothing here to redact:
 * no tenant id, no session id, no phone number, no credential, no caller input. That is
 * also why the public message is *specific* rather than the fixed sentence
 * `WebhookVerificationException` uses: the tenant asked for something its own connected
 * number cannot do, and Req 8.3 requires the platform *"surface an error indicating the
 * unsupported capability"* — a vague refusal would leave a panel unable to explain why a
 * button is disabled, and the remedy (use a Baileys session for groups, an official mode
 * for approved templates) is the thing the tenant most needs to be told.
 *
 * Labels are read from the enums rather than written out here for one reason: an error
 * message that drifts from the matrix it quotes fails no test. Renaming a matrix row
 * renames it everywhere it is spoken.
 */
final class ModeCapabilityException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * The request was understood and the backend cannot carry it out — see the class
     * docblock for why this is not a 403 and not a 501.
     */
    public const int STATUS = 422;

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'mode_capability_unsupported';

    private function __construct(
        public readonly ChannelMode $mode,
        public readonly ChannelCapability $capability,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The refusal for `$capability` on `$mode` — the only constructor.
     *
     * Named `for()` rather than exposing the constructor so the message is built in one
     * place and every raiser produces the same sentence: task 6.3's router, the three
     * official drivers of tasks 7.2–7.4, and Algorithm 9's capability arm all refuse
     * identically, which is what lets a caller match on the text and a panel render it.
     */
    public static function for(ChannelMode $mode, ChannelCapability $capability): self
    {
        return new self($mode, $capability, sprintf(
            '%s is not available on this session: %s does not support it%s.',
            $capability->label(),
            $mode->label(),
            $capability->isBaileysOnly() ? ', and it exists only on the Baileys bridge' : '',
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * The sentence a client may be shown — the same one, because it carries nothing but
     * this platform's own vocabulary.
     */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * Whether the capability exists on some other mode, so the remedy is a different
     * session rather than an upgrade or a support ticket.
     *
     * What the panel needs to choose between *"connect a Baileys session to manage
     * groups"* and *"no backend does this"*. Derived from the matrix, so it cannot drift.
     */
    public function isAvailableElsewhere(): bool
    {
        return $this->availableOn() !== [];
    }

    /**
     * Every mode that *does* support the refused capability, in declaration order.
     *
     * @return list<ChannelMode>
     */
    public function availableOn(): array
    {
        return array_values(array_filter(
            ChannelMode::cases(),
            fn (ChannelMode $mode): bool => $this->capability->supportedOn($mode),
        ));
    }
}
