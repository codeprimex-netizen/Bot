<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Pii\PiiKeyRules;
use App\Support\Pii\PiiMask;

/**
 * The privacy pass every audit payload goes through before it is hashed and
 * stored (Req 24.2 / D1; the design's "phone numbers redacted, message bodies
 * never logged — only content hashes" discipline).
 *
 * An audit row is written on the paths that handle the most sensitive data the
 * platform touches (impersonation, compliance actions, group/session settings), it
 * is retained for as long as the audit trail is, and it is **immutable** — a
 * phone number written here cannot be deleted later without breaking the hash
 * chain. So redaction happens on the way *in*, not on the way out.
 *
 * Three rules, applied top-down over the payload:
 *
 * | Rule                                   | Result                                        |
 * |----------------------------------------|-----------------------------------------------|
 * | key looks like a secret                | `[redacted]`                                  |
 * | key looks like message content         | `sha256:<digest>` + character count only      |
 * | value contains a phone-shaped number   | digits masked, first two and last two kept    |
 *
 * The value rule also runs on strings reached under harmless-looking keys, because
 * a phone number pasted into a `reason` or a `note` is still a phone number.
 *
 * **Scope note.** Task 4.4's `PiiRedactor::redact/rehydrate` is the *reversible*,
 * tenant-configurable redactor used on the LLM egress path (Correctness Property 15).
 * This class stays deliberately smaller and **irreversible**: an audit row must not
 * carry a token map that could rehydrate the PII it just removed. What the two share
 * is the *definitions* — the key rules come from `App\Support\Pii\PiiKeyRules` and the
 * digit mask from `App\Support\Pii\PiiMask`, so a key spelled `apiKey` here and
 * `api_key` in a log line cannot be treated differently.
 */
final class AuditPayloadRedactor
{
    public const string REDACTED = PiiMask::REDACTED;

    /**
     * An international-format number: a `+` is required, so dates and ids with
     * separators (`2024-06-06`, `1.2.3`) are never touched.
     */
    private const string FORMATTED_PHONE_PATTERN = '/\+\d[\d\s().-]{5,}\d/';

    /**
     * A long run of contiguous digits — a bare msisdn, a JID prefix, a card number.
     */
    private const string DIGIT_RUN_PATTERN = '/\d{7,}/';

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        /** @var array<array-key, mixed> $redacted */
        $redacted = $this->walk($payload);

        return $redacted;
    }

    /**
     * Mask the phone-shaped numbers in one string, leaving everything else intact.
     */
    public function maskNumbers(string $value): string
    {
        $masked = preg_replace_callback(
            self::FORMATTED_PHONE_PATTERN,
            fn (array $match): string => $this->maskDigits((string) $match[0]),
            $value,
        ) ?? $value;

        return preg_replace_callback(
            self::DIGIT_RUN_PATTERN,
            fn (array $match): string => $this->maskDigits((string) $match[0]),
            $masked,
        ) ?? $masked;
    }

    private function walk(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->forKey((string) $key, $item);
            }

            return $result;
        }

        return is_string($value) ? $this->maskNumbers($value) : $value;
    }

    private function forKey(string $key, mixed $value): mixed
    {
        if (PiiKeyRules::isSecret($key)) {
            return self::REDACTED;
        }

        if (PiiKeyRules::isContent($key)) {
            return $this->digestOf($value);
        }

        if (is_string($value) && PiiKeyRules::isIdentity($key)) {
            // A bare number is masked whole (so even a short one is masked); anything
            // with more structure — a WhatsApp JID, "+91 98765 43210 (work)" — is masked
            // in place, so the rest of the value survives for an operator to read.
            return preg_match('/^[+\d\s().-]+$/', $value) === 1
                ? $this->maskDigits($value)
                : $this->maskNumbers($value);
        }

        return $this->walk($value);
    }

    /**
     * Message content is recorded as a digest plus its size: enough to prove two
     * entries refer to the same text, never enough to read it.
     */
    private function digestOf(mixed $value): string
    {
        $text = is_string($value)
            ? $value
            : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');

        return sprintf('sha256:%s chars:%d', hash('sha256', $text), mb_strlen($text));
    }

    /**
     * Keep the first two and last two digits, through the shared mask — so an audit
     * row and a log line describe the same number the same way, and a digit script
     * `/\D/` cannot see is masked here too.
     */
    private function maskDigits(string $value): string
    {
        return PiiMask::digits($value);
    }
}
