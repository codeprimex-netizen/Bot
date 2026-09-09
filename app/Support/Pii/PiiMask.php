<?php

declare(strict_types=1);

namespace App\Support\Pii;

use App\Enums\PiiKind;

/**
 * The **irreversible** masks — the ones used where nothing may ever be restored:
 * logs (Req 7.3 / A7) and audit rows (Req 24.2 / D1).
 *
 * This is the deliberate opposite of `App\Services\Security\Pii\TokenizingPiiRedactor`.
 * That class replaces PII with a token it can undo, because the LLM's reply has to be
 * readable by the customer who wrote in. A log line has no such need and an immutable
 * audit row must not carry a way back at all, so here the value is *destroyed* and
 * only a correlation handle is left behind.
 *
 * How much of each kind survives is a judgement about operability versus exposure:
 *
 * | Kind    | Left behind                       | Why that much                                     |
 * |---------|-----------------------------------|---------------------------------------------------|
 * | phone   | first two + last two digits       | enough to tie a log line to a support ticket      |
 * | card    | last four digits                  | the PCI convention operators already read         |
 * | email   | first local character + domain    | the domain is the operational signal, not the user|
 * | custom  | nothing                           | an operator pattern's shape is unknown to us      |
 *
 * Every mask is **inert**: no output of this class matches any detector in
 * `PiiScanner`, so masking is idempotent and a record that passes through two
 * layers is not mangled by the second.
 */
final class PiiMask
{
    public const string REDACTED = '[redacted]';

    /**
     * Mask a value according to what it is.
     */
    public static function forKind(PiiKind $kind, string $value): string
    {
        return match ($kind) {
            PiiKind::Email => self::email($value),
            PiiKind::Card => self::card($value),
            PiiKind::Phone => self::digits($value),
            PiiKind::Custom => self::REDACTED,
        };
    }

    /**
     * Keep the first two and last two digits — enough for an operator to correlate
     * an entry with a support ticket, not enough to be a subscriber identifier.
     *
     * Separators and letters around the digits are dropped rather than preserved: a
     * mask that kept `"+1 (415) ***-**71"` would leak the area code, which in most
     * countries narrows a subscriber to a city.
     */
    public static function digits(string $value): string
    {
        $count = Digits::count($value);

        if ($count === 0) {
            return $value;
        }

        $prefix = str_starts_with(ltrim($value), '+') ? '+' : '';
        $ascii = Digits::ascii($value);

        // Four digits or fewer would leave nothing masked, and a digit script this
        // class cannot read has no first-two/last-two to keep: mask the lot.
        if ($count <= 4 || $ascii === null || strlen($ascii) !== $count) {
            return $prefix.str_repeat('*', $count);
        }

        return $prefix.substr($ascii, 0, 2).str_repeat('*', $count - 4).substr($ascii, -2);
    }

    /**
     * Last four digits only, the way a receipt prints them.
     */
    public static function card(string $value): string
    {
        $ascii = Digits::ascii($value);
        $count = Digits::count($value);

        if ($ascii === null || strlen($ascii) !== $count || $count <= 4) {
            return str_repeat('*', max($count, 1));
        }

        return str_repeat('*', $count - 4).substr($ascii, -4);
    }

    /**
     * Mask the local part and keep the domain, so an address at `mail.example.com`
     * is written `j***` followed by that same domain.
     *
     * The result cannot be re-detected as an email — `*` is not a local-part
     * character in `PiiScanner`'s pattern — so masking twice is a no-op.
     */
    public static function email(string $value): string
    {
        $at = strrpos($value, '@');

        if ($at === false || $at === 0) {
            return self::REDACTED;
        }

        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        $first = mb_substr($local, 0, 1, 'UTF-8');

        return ($first === '' ? '' : $first).'***@'.$domain;
    }
}
