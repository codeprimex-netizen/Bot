<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcome of presenting a signed URL (Req 9.6 / A9; design § Base URL §U.4, §U.5).
 *
 * `App\Services\Url\SignedUrlSigner::verify()` returns one of these for **every**
 * input — a valid link, a tampered one, a transplanted one, and a string that is not a
 * URL at all. Verification is total: an attacker-controlled URL can never make it raise,
 * because an exception is a second observable outcome and a 500-vs-403 difference is an
 * oracle. Same reason `SigningSecretStore::verify()` returns `false` for an unknown
 * scope instead of throwing.
 *
 * ## These cases are for logs, not for responses
 *
 * The distinctions below exist so an operator can tell "this export link expired" from
 * "somebody is replaying links at a host we do not serve". They must **never** reach the
 * client: every non-`Valid` case is one refusal with one status and one sentence
 * (`InvalidSignedUrlException`), so a caller cannot use the reason as a probe. If you
 * find yourself branching on a case outside a log line or a metric label, that is the
 * oracle Req 9.6 exists to prevent.
 *
 * ## Why there is no separate "host mismatch" case
 *
 * The canonical host is bound *into the HMAC* rather than carried beside it, so a link
 * signed for `bot.example.com` and presented at `attacker.example` produces a different
 * expected signature and lands on `SignatureMismatch`. The platform genuinely cannot
 * tell a transplant from any other forgery — which is the strongest form of the
 * guarantee, not a gap in it: there is no field an attacker can vary to learn which
 * host a link was issued for.
 */
enum SignedUrlVerdict: string
{
    /**
     * Signature valid, window within the cap, not yet expired. The only case that may
     * let a request proceed.
     */
    case Valid = 'VALID';

    /**
     * Not a signed URL of this platform's shape at all: unparseable, no host, no path,
     * a missing or repeated parameter, a parameter outside its alphabet, or a query
     * carrying anything the signer does not emit.
     *
     * An unexpected query parameter is refused rather than ignored because the
     * signature covers the path and the three parameters below and nothing else —
     * anything extra would be unsigned space an attacker could write in.
     */
    case Malformed = 'MALFORMED';

    /**
     * Signature valid, but the link's expiry is now in the past (Req 9.6). The expiry
     * second itself is *not* inside the window: a link expiring at `t` is refused from
     * `t` onwards.
     */
    case Expired = 'EXPIRED';

    /**
     * Signature valid, but the link claims — or has remaining — a validity window
     * longer than the 3600 seconds Req 9.6 allows.
     *
     * Unreachable through this platform's own signer, which refuses such a window at
     * *generation* time; a link that gets here was signed under a different ceiling or
     * against a clock that has since moved. Refused either way.
     */
    case WindowTooLong = 'WINDOW_TOO_LONG';

    /**
     * The signature does not match the URL as presented. Covers a tampered path or
     * expiry, a forged signature, an unknown or rotated-out secret, **and** a valid
     * signature transplanted onto another host (Req 9.6) — see the class docblock.
     */
    case SignatureMismatch = 'SIGNATURE_MISMATCH';

    /**
     * Whether the request the link authorises may proceed.
     */
    public function isValid(): bool
    {
        return $this === self::Valid;
    }

    /**
     * One line for an operator, safe to log: it describes the *link*, never the secret
     * and never the signature.
     */
    public function reason(): string
    {
        return match ($this) {
            self::Valid => 'the signature is valid and the link has not expired',
            self::Malformed => 'the URL is not a signed link of this platform (missing, repeated, or unexpected parameters)',
            self::Expired => 'the link expired',
            self::WindowTooLong => 'the link claims a validity window longer than the 3600-second maximum',
            self::SignatureMismatch => 'the signature does not match the URL as presented (tampered, forged, or replayed against another host)',
        };
    }
}
