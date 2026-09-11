<?php

declare(strict_types=1);

namespace App\Support\Pii;

/**
 * Digit arithmetic for the PII detectors — deliberately **not** ASCII-only.
 *
 * `\d` and `/\D/` see ASCII digits and nothing else, so a phone number typed on an
 * Arabic or a Japanese keyboard (`٩١٩٨٧٦٥٤٣٢١٠`, `＋８１９０１２３４５６７８`) walks straight
 * past an ASCII redactor. A redactor that only catches the happy-path script is a
 * redactor that leaks, so every length check, every Luhn check, and every one-way
 * mask in this namespace resolves digits through this class instead.
 *
 * Two operations, with a deliberate difference in strictness:
 *
 * - `count()` answers "how many decimal digits are in here?" and is used for the
 *   *masking* decisions, where over-counting is safe and under-counting leaks. It
 *   trusts PCRE's `\p{Nd}` — every Unicode decimal digit, in any script.
 * - `ascii()` answers "what are those digits, as `0`–`9`?" and is used where a
 *   value is *interpreted* (Luhn). It returns `null` for a decimal digit from a
 *   script this class does not map, because guessing a digit's value would make
 *   Luhn answer a question it was not asked.
 */
final class Digits
{
    /**
     * Code point of the `0` of every decimal-digit block this class resolves.
     *
     * Unicode allocates decimal digits in contiguous runs of ten, so one code point
     * per script is the whole table. It covers the scripts a WhatsApp platform
     * plausibly sees plus the fullwidth and mathematical forms an attacker reaches
     * for; anything outside it is *detected* as a digit by `count()` and refused by
     * `ascii()` rather than mis-read.
     *
     * @var list<int>
     */
    private const array ZERO_POINTS = [
        0x0030, // ASCII
        0x0660, // Arabic-Indic
        0x06F0, // Extended Arabic-Indic (Persian/Urdu)
        0x07C0, // NKo
        0x0966, // Devanagari
        0x09E6, // Bengali
        0x0A66, // Gurmukhi
        0x0AE6, // Gujarati
        0x0B66, // Oriya
        0x0BE6, // Tamil
        0x0C66, // Telugu
        0x0CE6, // Kannada
        0x0D66, // Malayalam
        0x0DE6, // Sinhala
        0x0E50, // Thai
        0x0ED0, // Lao
        0x0F20, // Tibetan
        0x1040, // Myanmar
        0x17E0, // Khmer
        0x1810, // Mongolian
        0x1946, // Limbu
        0x19D0, // New Tai Lue
        0x1A80, // Tai Tham Hora
        0x1B50, // Balinese
        0x1BB0, // Sundanese
        0x1C40, // Lepcha
        0x1C50, // Ol Chiki
        0xA620, // Vai
        0xA8D0, // Saurashtra
        0xA900, // Kayah Li
        0xA9D0, // Javanese
        0xAA50, // Cham
        0xABF0, // Meetei Mayek
        0xFF10, // Fullwidth
        0x104A0, // Osmanya
        0x1D7CE, // Mathematical bold
        0x1D7D8, // Mathematical double-struck
        0x1D7E2, // Mathematical sans-serif
        0x1D7EC, // Mathematical sans-serif bold
        0x1D7F6, // Mathematical monospace
    ];

    /**
     * How many decimal digits — in any script — the value contains.
     *
     * Falls back to ASCII counting for text that is not valid UTF-8: `\p{Nd}` with
     * the `u` modifier refuses such a subject outright, and answering `0` there
     * would silently disable every length-based rule.
     */
    public static function count(string $text): int
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            $matched = preg_match_all('/\p{Nd}/u', $text);

            if ($matched !== false) {
                return $matched;
            }
        }

        return (int) preg_match_all('/\d/', $text);
    }

    /**
     * The value's digits rendered as ASCII `0`–`9`, or `null` when it holds a
     * decimal digit from a script outside `ZERO_POINTS`.
     *
     * Non-digit characters (spaces, dashes, parentheses, letters) are dropped, so
     * `"+1 (415) 555-2671"` becomes `"14155552671"`.
     */
    public static function ascii(string $text): ?string
    {
        $digits = '';

        foreach (self::characters($text) as $character) {
            if ($character >= '0' && $character <= '9' && strlen($character) === 1) {
                $digits .= $character;

                continue;
            }

            if (preg_match('/^\p{Nd}$/u', $character) !== 1) {
                continue;
            }

            $value = self::digitValue($character);

            if ($value === null) {
                return null;
            }

            $digits .= (string) $value;
        }

        return $digits;
    }

    /**
     * The Luhn check digit test (ISO/IEC 7812) over an ASCII digit string.
     *
     * This is the discriminator that keeps "long run of digits" from meaning "card
     * number": a 16-digit order id passes the *shape* test and fails this one.
     */
    public static function isLuhn(string $digits): bool
    {
        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * The value of one decimal-digit character, or `null` for a script this class
     * does not map.
     */
    private static function digitValue(string $character): ?int
    {
        $codePoint = mb_ord($character, 'UTF-8');

        if ($codePoint === false) {
            return null;
        }

        foreach (self::ZERO_POINTS as $zero) {
            if ($codePoint >= $zero && $codePoint <= $zero + 9) {
                return $codePoint - $zero;
            }
        }

        return null;
    }

    /**
     * The text as a list of characters — UTF-8 aware when it can be, byte-wise when
     * the input is malformed (where per-byte is the only honest reading).
     *
     * @return list<string>
     */
    private static function characters(string $text): array
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return mb_str_split($text, 1, 'UTF-8');
        }

        return $text === '' ? [] : str_split($text);
    }
}
