<?php

declare(strict_types=1);

namespace App\Exceptions\Channel;

use App\Enums\ChannelMode;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A **transport** operation was asked of a backend that has no such operation — refused
 * before any request, and never silently absorbed (Req 8.1, 8.3 / A8; Correctness
 * Property 26: *"fails typed, never crashes"*).
 *
 * ```php
 * // CloudApiChannelDriver: an official number is not paired, it is registered.
 * public function pairingCode(string $sessionId, string $phone): string
 * {
 *     throw ChannelOperationException::unsupported(
 *         ChannelMode::CloudApi,
 *         'pairingCode',
 *         'a Cloud API number is registered under a WABA rather than paired with a phone',
 *     );
 * }
 * ```
 *
 * ## Why this is not `ModeCapabilityException`
 *
 * They look alike and are not interchangeable, and the difference is *what the caller asked
 * for*:
 *
 * | | `ModeCapabilityException` | this |
 * |---|---|---|
 * | Subject | a `ChannelCapability` — a **feature** a tenant chose (groups, templates, tagging) | a `BridgeClient` **transport method** — QR pairing, a pairing code, a presence update |
 * | In the matrix? | yes: it *is* a `❌` cell of design § 2.3 | no: the matrix has no row for it |
 * | Raised by | `ChannelRouter::assertSupported()` before dispatch, and drivers for a capability their backend lacks | a driver, for a method the interface declares and the backend does not have |
 * | Who can provoke it | a tenant, by using a feature | the platform, by not branching on the mode |
 *
 * `ModeCapabilityException::for()` takes a `ChannelCapability`, and there is deliberately no
 * capability for *"pairs by QR"*: the thirteen cases are the matrix's rows, and inventing a
 * fourteenth so this refusal could reuse that exception would put a non-feature into the
 * gate every capability-sensitive service consults, and into the set task 8.2 persists.
 *
 * `ChannelDriver extends BridgeClient` — the design's own choice (§ 2.4), so that Baileys'
 * existing `HttpBridgeClient` satisfies the contract unchanged — which means every driver
 * inherits eleven methods shaped for the WhatsApp Web protocol. Most map onto an official
 * API (`sendText`, `sendMedia`, `sessionState`); a few have no counterpart at all. This
 * exception is what those few answer, so that the alternative — a silent no-op, a `null`
 * masquerading as an answer, or an `Error` from an unimplemented method — is not needed.
 *
 * A silent no-op is the outcome this class exists to prevent. `startSession()` that quietly
 * did nothing would let a session-restart sweep report success for a number it never
 * touched, and the symptom would surface days later as a session nobody could explain.
 *
 * ## 422, and never retried
 *
 * **422**, the status `ModeCapabilityException` and `ChannelCredentialException` already use:
 * the request was understood, addressed to the right tenant and the right session, and this
 * backend simply cannot do it. `PlatformErrorClassifier`'s HTTP fallback maps a 4xx it has no
 * specific opinion about to `ErrorClass::Validation` — zero attempts — which is exactly right,
 * because no amount of waiting gives Meta's Cloud API a pairing code.
 *
 * ## What may be said out loud
 *
 * The mode's label, the method name, and a platform-authored phrase saying what the backend
 * does instead. All three are this codebase's own vocabulary — no tenant input, no
 * identifier, no credential — which is why, like `ModeCapabilityException`, the public
 * message is specific rather than a fixed sentence: the useful thing to tell a caller is
 * *what to do instead*.
 */
final class ChannelOperationException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 422;

    public const string ERROR_CODE = 'channel_operation_unsupported';

    private function __construct(
        public readonly ChannelMode $mode,
        public readonly string $operation,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * This backend has no such operation.
     *
     * @param  string  $operation  the method name, as declared on the contract
     * @param  string  $because  a platform-authored phrase saying what the backend does instead —
     *                           never provider prose, never caller input
     */
    public static function unsupported(ChannelMode $mode, string $operation, string $because): self
    {
        return new self($mode, self::sanitise($operation), sprintf(
            '%s has no [%s] operation: %s. Refused rather than treated as a no-op, because a '
            .'transport call that silently did nothing would be reported as a success.',
            $mode->label(),
            self::sanitise($operation),
            self::sanitise($because),
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
     * The sentence a caller may be shown — see the class docblock for why it is specific.
     */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * Control characters escaped, length bounded — the discipline
     * `WebhookVerificationException` applies to everything it interpolates.
     */
    private static function sanitise(string $value): string
    {
        return mb_strimwidth(addcslashes($value, "\0..\37\177"), 0, 160, '…');
    }
}
