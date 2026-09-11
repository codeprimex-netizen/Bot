<?php

declare(strict_types=1);

namespace App\Exceptions\Channel;

use App\Enums\ChannelMode;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An inbound webhook payload did not prove it came from the provider it claims to — denied
 * with **403**, and it never becomes an `App\Services\Channel\InboundEvent`
 * (Req 8.4 / A8; Req 10.1 / B1; Req 32.1 / NFR3).
 *
 * ```php
 * // inside a driver's parseWebhook(), before a single field of the body is believed
 * if (! hash_equals($expected, $presented)) {
 *     throw WebhookVerificationException::badSignature(ChannelMode::CloudApi, 'X-Hub-Signature-256');
 * }
 * ```
 *
 * ## Why parsing throws instead of returning a verdict
 *
 * `ChannelDriver::parseWebhook()` promises a *verified* canonical event. If verification
 * were a boolean beside the parse, every caller would have to remember to consult it, and
 * the one that forgot would have injected a customer message — with a reply, an AI credit
 * spend, and an audit trail — from an unauthenticated POST to a URL that is by design
 * reachable from the public internet.
 *
 * So the failure is the *absence of a return value*. There is no `InboundEvent` whose
 * signature was not checked, which is a statement about the type rather than about the
 * discipline of eight drivers and their callers.
 *
 * ## The five checks this covers
 *
 * One exception, five named constructors, because the four wire formats fail differently
 * and an operator debugging a rotated secret needs to know which check refused:
 *
 * | Constructor | Mode | The check |
 * |---|---|---|
 * | `badSignature()` | all | HMAC over the raw body did not match (bridge shared secret, Meta app secret, BSP signing secret) |
 * | `missingSignature()` | all | the header carrying the proof was absent entirely |
 * | `badVerifyToken()` | `CLOUD_API`, `ON_PREMISE` | Meta's `hub.verify_token` handshake echoed a token this route was not registered with |
 * | `wrongRecipient()` | `CLOUD_API`, `BSP_GATEWAY` | verified, but addressed to a phone-number id these credentials do not own |
 * | `malformedPayload()` | all | verified, but the body is not the shape the provider documents |
 *
 * `wrongRecipient()` is the one worth arguing for. A signature proves *the provider* sent
 * the body; it does not prove the body is about *this* tenant's number, and a BSP account
 * fronting many numbers can sign a payload for any of them. Without this check a tenant
 * sharing a partner account could see another tenant's inbound traffic land on its own
 * session — a cross-tenant leak that passes every signature test (Req 8.4, Property 23).
 *
 * ## What ends up in a message
 *
 * Never the body, never a header value, never a secret, and never a phone number.
 * Signatures and tokens are *credentials*: quoting the presented one in a log lets whoever
 * can read logs replay it, and quoting the expected one is simply printing the secret. Ids
 * are **fingerprinted** the way `CrossTenantAccessException` fingerprints tenant ids, so
 * two refusals about the same number can be correlated without the number itself being
 * written down. What the message does carry is the mode, the name of the check, and the
 * name of the header — all fixed vocabulary from this codebase.
 */
final class WebhookVerificationException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * HTTP status for every flavour of this refusal.
     *
     * 403 rather than 400: the request was well-formed enough to be understood and was
     * *refused*. Providers treat 4xx as terminal and stop retrying, which is the correct
     * outcome — a payload we cannot authenticate will not become authentic on the fourth
     * attempt.
     */
    public const int STATUS = 403;

    /**
     * The only sentence a caller is ever shown.
     *
     * Deliberately uniform across all five constructors: telling an unauthenticated
     * client *which* check failed tells it how to get closer.
     */
    public const string PUBLIC_MESSAGE = 'This webhook payload could not be verified.';

    public const string ERROR_CODE = 'webhook_verification_failed';

    /**
     * The HMAC over the raw request body did not match.
     *
     * @param  string  $header  the header the proof was read from — a fixed protocol name, not input
     */
    public static function badSignature(ChannelMode $mode, string $header): self
    {
        return new self(sprintf(
            'Refusing a [%s] webhook: the signature presented in [%s] does not match the body.',
            $mode->value,
            self::sanitise($header),
        ));
    }

    /**
     * No signature header at all.
     *
     * Distinct from a bad one because it is usually a misconfiguration on the provider side
     * (a callback registered without a signing secret) rather than an attack, and the fix is
     * different.
     */
    public static function missingSignature(ChannelMode $mode, string $header): self
    {
        return new self(sprintf(
            'Refusing a [%s] webhook: it carries no [%s] header, so nothing about it can be verified.',
            $mode->value,
            self::sanitise($header),
        ));
    }

    /**
     * Meta's subscription handshake echoed a token this route was not registered with
     * (`ChannelWebhookRoute::matchesVerifyToken()`).
     */
    public static function badVerifyToken(ChannelMode $mode, string $routeKey): self
    {
        return new self(sprintf(
            'Refusing the [%s] webhook handshake on route %s: the echoed verify token is not this route\'s.',
            $mode->value,
            self::fingerprint($routeKey),
        ));
    }

    /**
     * Verified as coming from the provider, but about a number these credentials do not own.
     *
     * @param  string  $expected  the recipient identity the credentials name
     * @param  string  $presented  the recipient identity the payload names
     */
    public static function wrongRecipient(ChannelMode $mode, string $expected, string $presented): self
    {
        return new self(sprintf(
            'Refusing a [%s] webhook: it is addressed to %s, but these credentials own %s. '
            .'A provider signature proves who sent the payload, not whose number it is about.',
            $mode->value,
            self::fingerprint($presented),
            self::fingerprint($expected),
        ));
    }

    /**
     * Verified, but not the documented shape — so no canonical event can be built from it.
     *
     * @param  string  $reason  a fixed phrase from the driver, never a fragment of the body
     */
    public static function malformedPayload(ChannelMode $mode, string $reason): self
    {
        return new self(sprintf(
            'Refusing a [%s] webhook: %s.',
            $mode->value,
            self::sanitise($reason),
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
     * The sentence that may be shown to a caller.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    /**
     * A short, stable, non-reversible stand-in for an identifier.
     *
     * The same construction `CrossTenantAccessException::fingerprint()` uses, for the same
     * reason: two refusals about one number must be correlatable without the number ever
     * reaching a log line (Req 7.3 / A7).
     */
    private static function fingerprint(?string $value): string
    {
        if ($value === null || $value === '') {
            return '<none>';
        }

        return '#'.substr(hash('sha256', $value), 0, 8);
    }

    /**
     * Keep a value out of a message verbatim: control characters escaped, length bounded.
     *
     * Every caller of this passes a constant from this codebase, so nothing untrusted is
     * expected here — the guard is for the driver that one day interpolates a provider's
     * error string into `$reason`.
     */
    private static function sanitise(string $value): string
    {
        return mb_strimwidth(addcslashes($value, "\0..\37\177"), 0, 120, '…');
    }
}
