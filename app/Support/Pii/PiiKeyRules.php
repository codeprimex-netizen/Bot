<?php

declare(strict_types=1);

namespace App\Support\Pii;

/**
 * What a *key* says about its value, for the two places the platform writes
 * structured records it does not control the shape of: audit payloads
 * (`App\Support\Audit\AuditPayloadRedactor`) and log context
 * (`App\Support\Pii\LogPiiScrubber`).
 *
 * Value-level scanning catches PII wherever it appears, but some values must be
 * dropped for reasons no pattern can infer: an API key is a random string, and a
 * message body is *legitimate text* whose only problem is that Req 7.3 / A7 says it
 * must never be logged. Both are properties of the field, so the key is the only
 * place the answer can come from.
 *
 * The three rules live here — rather than being duplicated per consumer — so a key
 * spelled `apiKey` in one subsystem and `api_key` in another cannot be redacted in
 * one record and printed in the next.
 */
final class PiiKeyRules
{
    /**
     * Keys whose value never belongs in a record in any form.
     */
    public const string SECRET_PATTERN = '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|'
        .'credential|authorization|bearer|otp|\bpin\b|cvv|signature|\bdek\b|\bkek\b|cookie|session[_-]?id)/i';

    /**
     * Keys that hold message content: recorded as a digest, never as text
     * (Req 7.3 / A7 — "SHALL NOT log message bodies, only content hashes").
     *
     * Deliberately *not* here: `reason`, `note`, and `comment`. Those are written by
     * an operator to explain a decision, and a trail whose explanations are hashed
     * explains nothing. They still get value-level PII masking.
     */
    public const string CONTENT_PATTERN = '/^(body|text|message|content|caption|transcript|prompt|'
        .'completion|reply|answer|question)$/i';

    /**
     * Keys that hold a subscriber identity, masked whatever their shape — a bare
     * `"5551234"` under `phone` is a phone number even though nothing about the
     * digits says so.
     */
    public const string IDENTITY_PATTERN = '/(phone|msisdn|whatsapp|\bjid\b|wa[_-]?id|\bnumber\b|recipient|'
        .'\bto\b|\bfrom\b|contact[_-]?number)/i';

    public static function isSecret(string $key): bool
    {
        return preg_match(self::SECRET_PATTERN, $key) === 1;
    }

    public static function isContent(string $key): bool
    {
        return preg_match(self::CONTENT_PATTERN, $key) === 1;
    }

    public static function isIdentity(string $key): bool
    {
        return preg_match(self::IDENTITY_PATTERN, $key) === 1;
    }
}
