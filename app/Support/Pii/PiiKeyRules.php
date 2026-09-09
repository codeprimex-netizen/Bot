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

    /**
     * Keys that hold an **opaque machine identifier**: a digest, a ULID, a correlation
     * id, a checksum. Their values are evidence, and masking them destroys the only
     * reason the record was written.
     *
     * ## Why this rule had to be added (task 4.6)
     *
     * `PiiScanner`'s bare-phone detector is `\+?\p{Nd}{7,15}` with digit boundaries — and
     * hex digests, ULIDs, and base32 ids are *full of* digit runs separated by letters. So
     * roughly one SHA-256 digest in ten came out of the scrubber looking like
     * `…8f56*****94f41***28d`, and one ULID in ten lost a chunk of itself. Nothing failed;
     * the log line was simply no longer usable for the thing it exists for. It was
     * data-dependent, so it looked like flakiness rather than a rule.
     *
     * That matters most on the path where a log line is the *last* copy of the evidence:
     * `DatabaseAbuseRecorder`'s escalation, which reports a blocked attack when the
     * `abuse_events` insert fails. A corrupted `content_hash` there cannot be correlated
     * with anything and a corrupted `trace_id` cannot be followed.
     *
     * ## Why exempting these is not a hole
     *
     * The exemption needs **both** halves, and it is checked *after* the secret, content,
     * and identity rules, so it can never override them:
     *
     * - the **key** must be an identifier key (`content_hash`, `trace_id`, `checksum`, …);
     *   an identity key like `wa_id` is caught by `IDENTITY_PATTERN` first and stays masked,
     *   and a caller-supplied `*_key` (`idempotency_key`, `dedup_key`) is not exempt at all;
     * - the **value** must look like an opaque token — token characters only, and at least
     *   one letter. A bare phone number under `id` is all digits, so it is still masked;
     *   an email contains `@`, so it is still masked.
     *
     * What passes is a mixed alphanumeric machine token, which is not a subscriber
     * identity in any of the shapes `PiiScanner` detects.
     */
    public const string OPAQUE_KEY_PATTERN = '/(^|[_.\-])(id|ids|hash|hashes|digest|checksum|'
        .'fingerprint|sequence|ulid|uuid|guid|etag|nonce)$'
        // Platform-generated correlation keys, named individually. A bare `*_key` is
        // deliberately **not** exempt: `idempotency_key` and `dedup_key` are supplied by
        // the caller, so `msg-919876543210` is a shape they can really have, and exempting
        // them would turn a client-controlled field into a way to log a phone number.
        .'|^(session|conversation|chain)_key$/i';

    /**
     * The value shape the opaque-key exemption requires: token characters only, and at
     * least one letter, so no all-digit value can slip through.
     */
    public const string OPAQUE_VALUE_PATTERN = '/^(?=[^\p{L}]*\p{L})[A-Za-z0-9][A-Za-z0-9._:\-]{4,190}$/';

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

    /**
     * Whether `$value` under `$key` is an opaque machine identifier that must be recorded
     * verbatim.
     *
     * Both halves are required, and callers must consult this **after** the secret,
     * content, and identity rules — see `OPAQUE_KEY_PATTERN` for the argument.
     */
    public static function isOpaqueIdentifier(string $key, string $value): bool
    {
        return preg_match(self::OPAQUE_KEY_PATTERN, $key) === 1
            && preg_match(self::OPAQUE_VALUE_PATTERN, $value) === 1;
    }
}
