<?php

declare(strict_types=1);

namespace App\Support\Audit;

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
 * **Scope note.** Task 4.4 introduces `PiiRedactor::redact/rehydrate` — the
 * reversible, tenant-configurable redactor used on the LLM egress path
 * (Correctness Property 15). This class is deliberately smaller and
 * **irreversible**: an audit row must not carry a token map that could rehydrate
 * the PII it just removed. When 4.4 lands, this class should delegate its pattern
 * *definitions* to the shared redactor and keep its one-way behaviour.
 */
final class AuditPayloadRedactor
{
    public const string REDACTED = '[redacted]';

    /**
     * Keys whose value never belongs in an audit row in any form.
     */
    private const string SECRET_KEY_PATTERN = '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|'
        .'credential|authorization|bearer|otp|\bpin\b|cvv|signature|\bdek\b|\bkek\b|cookie|session[_-]?id)/i';

    /**
     * Keys that hold message content: stored as a digest, never as text.
     */
    /**
     * Deliberately *not* here: `reason`, `note`, and `comment`. Those are written by an
     * operator to explain a decision, and an audit trail whose explanations are hashed
     * explains nothing. They still get the value-level phone masking below.
     */
    private const string CONTENT_KEY_PATTERN = '/^(body|text|message|content|caption|transcript|prompt|'
        .'completion|reply|answer|question)$/i';

    /**
     * Keys that hold a subscriber identity, masked whatever their shape.
     */
    private const string PHONE_KEY_PATTERN = '/(phone|msisdn|whatsapp|\bjid\b|wa[_-]?id|\bnumber\b|recipient|'
        .'\bto\b|\bfrom\b|contact[_-]?number)/i';

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
        if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
            return self::REDACTED;
        }

        if (preg_match(self::CONTENT_KEY_PATTERN, $key) === 1) {
            return $this->digestOf($value);
        }

        if (is_string($value) && preg_match(self::PHONE_KEY_PATTERN, $key) === 1) {
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
     * Keep the first two and last two digits — enough for an operator to correlate
     * an entry with a support ticket, not enough to be a subscriber identifier.
     */
    private function maskDigits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $prefix = str_starts_with(ltrim($value), '+') ? '+' : '';
        $length = strlen($digits);

        if ($length === 0) {
            return $value;
        }

        if ($length <= 4) {
            return $prefix.str_repeat('*', $length);
        }

        return $prefix.substr($digits, 0, 2).str_repeat('*', $length - 4).substr($digits, -2);
    }
}
