<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use Illuminate\Support\Str;

/**
 * Turns an arbitrary inbound (or outbound) string into a `NormalizedText` the
 * detectors can be run against — and decides, before any detector runs, whether the
 * text can be classified at all (Req 13.8 / B4; Req 32.7 / NFR3).
 *
 * ## The three fail-closed outcomes it produces
 *
 * | Condition | Flag | Why it blocks |
 * |---|---|---|
 * | not valid UTF-8 | `UNDECODABLE` | every detector works on characters; on bytes that do not form characters, "no match" means "did not look", not "clean" |
 * | longer than `maxChars` | `OVERSIZED` | the middle would go unexamined, and a payload placed there would pass |
 * | invisible/bidi control characters present | `OBFUSCATION` | evasion machinery; recorded, and on its own only a flag |
 *
 * The length ceiling defaults to 8 192 characters, which is *twice* WhatsApp's own
 * 4 096-character message limit: a legitimate customer message cannot reach it, so
 * exceeding it is itself anomalous rather than merely large. An oversized text is
 * still normalized — head and tail windows — so the recorded event names the payload
 * as well as the size.
 *
 * ## Views, in order of cost
 *
 * 1. **canonical** — invisible characters removed, whitespace collapsed, lower-cased.
 * 2. **transliterated** — `Str::ascii()`, which folds Cyrillic/Greek look-alikes onto
 *    Latin. This is why `іgnore` (Cyrillic і) and `ignore` match the same detector.
 *    Transliteration is *not* flagged as obfuscation: plenty of the platform's tenants
 *    write in scripts that transliterate, and flagging them would make the abuse feed
 *    a language detector.
 * 3. **leet-folded** — `0→o`, `1→l`, `3→e`, `@→a`, `$→s`, and separators between
 *    letters dropped, so `i.g.n.o.r.e` and `1gn0re` reduce to `ignore`.
 * 4. **decoded** — base64, percent-encoding, and `\uXXXX`/`&#NN;` escapes, each
 *    decoded and canonicalized. A decoded view is only added when it differs and
 *    looks like text; a random blob does not earn a pass.
 */
final class TextNormalizer
{
    /**
     * Zero-width, soft-hyphen, and bidirectional-override characters. They have no
     * legitimate place in a chat message and every use in an injection payload:
     * inserted between the letters of a keyword they defeat literal matching while
     * leaving the text visually unchanged.
     */
    public const string INVISIBLE_PATTERN = '/[\x{00AD}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * Characters an attacker sprinkles *between* letters to break a literal match.
     * Dropped only in the leet-folded view, never in the canonical one.
     */
    private const string SEPARATOR_PATTERN = '/(?<=\p{L})[.\-_*|\/\\\\+~`\'"]+(?=\p{L})/u';

    /**
     * A base64 run long enough to hide a sentence in.
     */
    private const string BASE64_PATTERN = '/[A-Za-z0-9+\/]{20,}={0,2}/';

    /**
     * Digit/symbol substitutions, applied only in the leet-folded views.
     *
     * Two maps, because the common substitutions are **ambiguous**: `1` stands for both
     * `l` and `i` (`a11` is "all", `1gnore` is "ignore"), and a single map would decode
     * one of them and miss the other. Both readings are produced and both are matched,
     * which is the cheap way to be right about either.
     *
     * @var array<int, array<array-key, string>>
     */
    private const array LEET_MAPS = [
        [
            '0' => 'o', '1' => 'l', '3' => 'e', '4' => 'a', '5' => 's',
            '7' => 't', '8' => 'b', '9' => 'g', '@' => 'a', '$' => 's', '!' => 'i',
        ],
        [
            '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's',
            '7' => 't', '8' => 'b', '9' => 'g', '@' => 'a', '$' => 's', '!' => 'i',
        ],
    ];

    /**
     * How many decoded views one text may contribute. Bounded because decoding is the
     * one step whose cost an attacker controls: a message packed with base64 runs must
     * not turn one inspection into hundreds of detector passes.
     */
    private const int MAX_DECODED_VIEWS = 8;

    /**
     * @param  int  $maxChars  ceiling above which a text is `OVERSIZED` (fails closed)
     * @param  int  $windowChars  characters examined from each end of an oversized text
     */
    public function __construct(
        private readonly int $maxChars = 8192,
        private readonly int $windowChars = 4096,
    ) {}

    public static function fromConfig(): self
    {
        $max = config('wa.security.guardrail.max_input_chars', 8192);
        $max = is_numeric($max) ? max(64, (int) $max) : 8192;

        return new self($max, (int) max(64, $max / 2));
    }

    public function normalize(string $text): NormalizedText
    {
        $hash = hash('sha256', $text);

        if (! mb_check_encoding($text, 'UTF-8')) {
            // Nothing below this point can be trusted to have examined anything, so no
            // detector is run and the text is refused (`AbuseSignal::Undecodable` blocks).
            return new NormalizedText(
                raw: $text,
                canonical: '',
                folded: [],
                decoded: [],
                flags: [AbuseSignal::Undecodable],
                contentHash: $hash,
                contentLength: strlen($text),
                complete: false,
            );
        }

        $length = mb_strlen($text);
        $flags = [];
        $complete = true;
        $subject = $text;

        if ($length > $this->maxChars) {
            $flags[] = AbuseSignal::Oversized;
            $complete = false;

            // Head *and* tail: a payload appended after a wall of filler is the obvious
            // way to exploit a head-only truncation, and both windows together still
            // bound the work.
            $subject = mb_substr($text, 0, $this->windowChars)."\n".mb_substr($text, -$this->windowChars);
        }

        $stripped = $this->stripInvisible($subject);

        if ($stripped !== $subject) {
            $flags[] = AbuseSignal::Obfuscation;
        }

        $canonical = $this->canonicalize($stripped);

        return new NormalizedText(
            raw: $text,
            canonical: $canonical,
            folded: $this->fold($canonical),
            // Decoding works on the case-preserving text: base64 is case-sensitive, so
            // decoding the lower-cased canonical view would find nothing.
            decoded: $this->decode($stripped),
            flags: $flags,
            contentHash: $hash,
            contentLength: $length,
            complete: $complete,
        );
    }

    /**
     * Remove the invisible and direction-overriding characters.
     */
    public function stripInvisible(string $text): string
    {
        return (string) preg_replace(self::INVISIBLE_PATTERN, '', $text);
    }

    /**
     * Fold full-width forms to ASCII, lower-case, collapse every run of whitespace to
     * one space, trim.
     *
     * The full-width fold is `mb_convert_kana(..., 'as')` rather than `Str::ascii()`,
     * because `Str::ascii()` *drops* characters like `ｉ` (U+FF49) instead of mapping
     * them — which would turn `ｉgnore all previous instructions` into
     * `gnore all previous instructions` and defeat every detector. Full-width text is a
     * legitimate way to write in several locales, so this is a fold and not a finding.
     */
    private function canonicalize(string $text): string
    {
        $halfWidth = mb_convert_kana($text, 'as');
        $lowered = mb_strtolower($halfWidth);
        $collapsed = preg_replace('/\s+/u', ' ', $lowered);

        return trim(is_string($collapsed) ? $collapsed : $lowered);
    }

    /**
     * The plain derived views: transliterated and leet-folded.
     *
     * @return list<string>
     */
    private function fold(string $canonical): array
    {
        if ($canonical === '') {
            return [];
        }

        $views = [];

        $ascii = mb_strtolower(Str::ascii($canonical));

        if ($ascii !== $canonical) {
            $views[] = $ascii;
        }

        foreach ([$canonical, $ascii] as $base) {
            foreach ($this->foldLookalikes($base) as $folded) {
                if ($folded !== $base) {
                    $views[] = $folded;
                }
            }
        }

        return array_values(array_unique($views));
    }

    /**
     * Drop inter-letter separators, then apply each leet map — one reading per map.
     *
     * @return list<string>
     */
    private function foldLookalikes(string $text): array
    {
        $joined = preg_replace(self::SEPARATOR_PATTERN, '', $text);
        $base = is_string($joined) ? $joined : $text;

        $folds = [$base];

        foreach (self::LEET_MAPS as $map) {
            $folds[] = strtr($base, $map);
        }

        return array_values(array_unique($folds));
    }

    /**
     * Decoded readings of the text — each canonicalized, each only kept when it
     * differs from its source and looks like text rather than like bytes.
     *
     * @return list<string>
     */
    private function decode(string $text): array
    {
        $views = [];

        // Percent-encoding: `%69gnore previous instructions`.
        if (str_contains($text, '%')) {
            $decoded = rawurldecode($text);

            if ($decoded !== $text && mb_check_encoding($decoded, 'UTF-8')) {
                $views[] = $this->canonicalize($this->stripInvisible($decoded));
            }
        }

        // Numeric and unicode escapes: `&#105;gnore`, `\u0069gnore`.
        $unescaped = $this->decodeEscapes($text);

        if ($unescaped !== $text) {
            $views[] = $this->canonicalize($this->stripInvisible($unescaped));
        }

        // Base64 blobs, decoded individually so a message may carry several.
        if (preg_match_all(self::BASE64_PATTERN, $text, $matches) > 0) {
            foreach ($matches[0] as $candidate) {
                if (count($views) >= self::MAX_DECODED_VIEWS) {
                    break;
                }

                $decoded = $this->decodeBase64($candidate);

                if ($decoded !== null) {
                    $views[] = $this->canonicalize($this->stripInvisible($decoded));
                }
            }
        }

        return array_values(array_filter(
            array_slice(array_unique($views), 0, self::MAX_DECODED_VIEWS),
            static fn (string $view): bool => $view !== '',
        ));
    }

    /**
     * `&#105;` / `&#x69;` / `\u0069` → the character they name.
     */
    private function decodeEscapes(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $decoded = preg_replace_callback(
            '/\\\\u\{?([0-9a-f]{4,6})\}?/i',
            static function (array $match): string {
                $codepoint = hexdec($match[1]);

                // Surrogates and out-of-range values have no character; leaving the
                // escape as-is is the honest reading.
                if ($codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                    return $match[0];
                }

                return mb_chr((int) $codepoint, 'UTF-8') ?: $match[0];
            },
            $decoded,
        );

        return is_string($decoded) ? $decoded : $text;
    }

    /**
     * Strict base64 decode, kept only when the result reads as text.
     *
     * "Reads as text" means valid UTF-8 that is at least three quarters printable —
     * without that check every random identifier in a message would decode to noise
     * and be matched against every detector for nothing.
     */
    private function decodeBase64(string $candidate): ?string
    {
        $decoded = base64_decode($candidate, true);

        if ($decoded === false || $decoded === '' || ! mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        $printable = preg_match_all('/[\p{L}\p{N}\p{P}\p{Zs}]/u', $decoded);

        if ($printable === false || $printable * 4 < mb_strlen($decoded) * 3) {
            return null;
        }

        return $decoded;
    }
}
