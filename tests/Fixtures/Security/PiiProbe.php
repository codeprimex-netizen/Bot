<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The generator and the independent judge for Correctness Property 15
 * (Req 32.2 / NFR3; Req 13.7 / B4).
 *
 * A property test for PII redaction is only worth the name if two things are true of
 * it, and both of them live here rather than in the test body:
 *
 * 1. **The PII is drawn, not scripted.** `tests/Unit/Services/Security/PiiRedactorTest`
 *    already asserts a fixed corpus; repeating it with a loop around it would add
 *    nothing. So this class draws phone numbers in nine formats and six digit scripts,
 *    emails with plus-addressing and unicode local parts, Luhn-valid cards at four
 *    lengths and three groupings, and per-tenant custom patterns — then plants them at
 *    random positions, glued to words, next to punctuation, several per string, and
 *    inside nested structure.
 * 2. **The verdict comes from somewhere else.** `leaks()` is written from scratch, in
 *    terms of *shapes* rather than of `PiiScanner`'s rules, so "no PII in the output"
 *    is not the implementation agreeing with itself. It is deliberately **broader**
 *    than the scanner — it flags any 13–19 digit run whether or not it passes Luhn,
 *    and any `@`-shaped token whatever its local part — which is why it may only be
 *    pointed at text built from `plant()`, never at text holding one of the documented
 *    carve-outs.
 *
 * ## Reproducibility
 *
 * Every draw comes from one seeded engine, so a failure replays exactly:
 * `PII_EGRESS_SEED=<seed> vendor/bin/pest --filter='<test name>'`. The seed is printed
 * in every failure message.
 *
 * ## What is deliberately not generated
 *
 * `PiiScanner` documents five carve-outs it will not mask — real calendar dates,
 * in-range dotted quads, thousands-separated numbers, clock times, and digit runs of
 * 16 or more without a `+` — plus a card that fails Luhn. None of the drawn values can
 * collide with one: a dashed or dotted phone always has a three-digit middle group (a
 * date needs one or two), a grouped card always has four groups (a date needs three, a
 * quad four *in range*), and every phone lands inside E.164's 7–15 digit window. The
 * carve-outs are asserted **positively** instead, by `carveOuts()`, so relaxing one
 * fails a test rather than quietly widening what egresses.
 */
final class PiiProbe
{
    public const string SEED_ENV = 'PII_EGRESS_SEED';

    /**
     * Non-ASCII decimal-digit blocks, by the code point of each block's zero.
     *
     * All six are resolvable by `App\Support\Pii\Digits`, and all six are covered by
     * this class's own unicode detectors — a script the judge cannot see would make
     * "no PII in the output" vacuous for that script.
     *
     * @var array<string, int>
     */
    private const array SCRIPTS = [
        'arabic-indic' => 0x0660,
        'extended arabic-indic' => 0x06F0,
        'devanagari' => 0x0966,
        'bengali' => 0x09E6,
        'thai' => 0x0E50,
        'fullwidth' => 0xFF10,
    ];

    /**
     * The same blocks as a PCRE character class, for the independent detectors.
     */
    private const string NON_ASCII_DIGITS = '\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}'
        .'\x{09E6}-\x{09EF}\x{0E50}-\x{0E59}\x{FF10}-\x{FF19}';

    /**
     * The digit-shaped half of the independent judge.
     *
     * Deliberately broader than `PiiScanner`: seven digits in any script, however
     * separated, and any 13–19 digit run whether or not it passes Luhn. Breadth is what
     * makes an empty result mean something — and it is why these may only be pointed at
     * output built from `plant()`, never at a carve-out.
     *
     * @var array<string, string>
     */
    private const array DIGIT_DETECTORS = [
        'ascii phone' => '/(?<!\d)\+?\d(?:[ .()\-]?\d){6,}(?!\d)/',
        'unicode phone' => '/(?<!['.self::NON_ASCII_DIGITS.'])['.self::NON_ASCII_DIGITS.']'
            .'(?:[ .()\-]?['.self::NON_ASCII_DIGITS.']){6,}/u',
        'card shaped' => '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/',
    ];

    /**
     * Filler vocabulary: letters only, so a control word can never contribute a digit
     * run, an `@`, or a match for a drawn tenant pattern.
     *
     * @var list<string>
     */
    private const array WORDS = [
        'hello', 'please', 'could', 'you', 'check', 'my', 'delivery', 'status',
        'thanks', 'again', 'the', 'courier', 'never', 'arrived', 'yesterday',
        'reach', 'me', 'on', 'or', 'mail', 'kindly', 'confirm', 'urgent',
    ];

    /**
     * Punctuation a value may sit next to. Deliberately excludes `.` and `-`, which are
     * part of an email's local part and of a phone number's separator alphabet: a
     * value written `.jane@example.com` has a different extent from the value planted,
     * which would make "the masked text does not contain the value" test the wrong
     * string rather than test the redactor.
     *
     * @var list<string>
     */
    private const array PUNCTUATION = [',', '!', '?', ';', ':', '"'];

    /**
     * @var list<string>
     */
    private const array TLDS = ['com', 'io', 'co.uk', 'net', 'example', 'in', 'com.br'];

    /**
     * Keys `App\Support\Pii\PiiKeyRules::isOpaqueIdentifier()` exempts from value
     * scanning. None of them is caught by the secret, content, or identity rules
     * first — that ordering is the whole argument for why the exemption is not a hole,
     * so the keys used to test it must be ones where the exemption is actually reached.
     *
     * @var list<string>
     */
    private const array IDENTIFIER_KEYS = [
        'id', 'content_hash', 'trace_id', 'checksum', 'fingerprint', 'event_uuid',
        'chain_key', 'row_hash', 'payload.digest', 'etag',
    ];

    private readonly Randomizer $rng;

    private function __construct(public readonly int $seed)
    {
        $this->rng = new Randomizer(new Mt19937($seed));
    }

    /**
     * A probe seeded from the environment when replaying, or freshly at random.
     */
    public static function seeded(): self
    {
        $configured = getenv(self::SEED_ENV);

        return new self(
            is_string($configured) && $configured !== '' && ctype_digit($configured)
                ? (int) $configured
                : random_int(1, PHP_INT_MAX),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Draws
    |--------------------------------------------------------------------------
    */

    public function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->rng->getInt(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $items
     * @return TValue
     */
    public function pick(array $items): mixed
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    /**
     * @param  list<array{kind: string, format: string, text: string}>  $values
     * @return list<array{kind: string, format: string, text: string}>
     */
    public function shuffleValues(array $values): array
    {
        /** @var list<array{kind: string, format: string, text: string}> $shuffled */
        $shuffled = $this->rng->shuffleArray($values);

        return $shuffled;
    }

    /*
    |--------------------------------------------------------------------------
    | PII values
    |--------------------------------------------------------------------------
    */

    /**
     * One phone number, in one of nine written formats and one of seven digit scripts.
     *
     * Every format stays inside E.164's 7–15 digits, and every separated format is
     * shaped so it cannot be read as a calendar date or a dotted quad — the two
     * carve-outs a phone-shaped value could otherwise collide with. `long group` is
     * the `0755-1234567` shape: a group of more than five digits, which a
     * five-digit-per-group pattern misses entirely.
     *
     * @return array{kind: string, format: string, text: string}
     */
    public function phone(): array
    {
        $format = $this->pick(['e164', 'bare', 'spaced', 'dashed', 'dotted', 'parens', 'plus parens', 'long group', 'four groups']);
        $country = (string) $this->int(1, 99);

        $text = match ($format) {
            'e164' => '+'.$country.$this->digits($this->int(7, 12)),
            'bare' => $this->digits($this->int(7, 12)),
            'spaced' => '+'.$country.' '.$this->digits(5).' '.$this->digits(5),
            // A three-digit middle group: `isCalendarDate()` needs one or two.
            'dashed' => $this->digits(3).'-'.$this->digits(3).'-'.$this->digits(4),
            'dotted' => $this->digits(3).'.'.$this->digits(3).'.'.$this->digits(4),
            'parens' => '('.$this->digits(3).') '.$this->digits(3).'-'.$this->digits(4),
            'plus parens' => '+'.$country.' ('.$this->digits(3).') '.$this->digits(3).'-'.$this->digits(4),
            'long group' => $this->digits(4).'-'.$this->digits(7),
            // Four groups, so neither the three-group date shape nor an in-range quad.
            default => $this->digits(3).' '.$this->digits(3).' '.$this->digits(3).' '.$this->digits(3),
        };

        $script = $this->chance(30) ? $this->pick(array_keys(self::SCRIPTS)) : 'ascii';

        if ($script !== 'ascii') {
            $text = self::inScript($text, self::SCRIPTS[$script]);
        }

        return ['kind' => 'PHONE', 'format' => $format.'/'.$script, 'text' => $text];
    }

    /**
     * One email address: plain, dotted, plus-addressed, subdomained, unicode, or a
     * WhatsApp JID (structurally an address whose local part is an msisdn).
     *
     * @return array{kind: string, format: string, text: string}
     */
    public function email(): array
    {
        $format = $this->pick(['plain', 'dotted', 'plus addressing', 'subdomain', 'unicode local', 'whatsapp jid']);
        $domain = $this->letters($this->int(3, 8)).'.'.$this->pick(self::TLDS);

        $text = match ($format) {
            'plain' => $this->letters($this->int(3, 10)).'@'.$domain,
            'dotted' => $this->letters($this->int(2, 6)).'.'.$this->letters($this->int(2, 6)).'@'.$domain,
            'plus addressing' => $this->letters($this->int(3, 7)).'+'.$this->letters($this->int(3, 6)).'@'.$domain,
            'subdomain' => $this->letters($this->int(3, 8)).'@'.$this->letters($this->int(3, 6)).'.'.$domain,
            'unicode local' => $this->pick(['josé', 'piñón', 'müller', 'renée', 'øystein']).'@'.$domain,
            default => $this->digits($this->int(10, 13)).'@s.whatsapp.net',
        };

        return ['kind' => 'EMAIL', 'format' => $format, 'text' => $text];
    }

    /**
     * One **Luhn-valid** card number at 13, 15, 16, or 19 digits, unseparated or
     * grouped in fours by a space or a dash.
     *
     * Luhn-valid is the point: the scanner's discriminator is the check digit, not the
     * digit-run length, so a generator that emitted arbitrary 16-digit strings would be
     * testing the carve-out rather than the detector.
     *
     * @return array{kind: string, format: string, text: string}
     */
    public function card(): array
    {
        $length = $this->pick([13, 15, 16, 19]);
        $digits = self::withLuhnCheckDigit((string) $this->int(4, 6).$this->digits($length - 2));
        $format = $this->pick(['unseparated', 'spaced', 'dashed']);

        $text = $format === 'unseparated'
            ? $digits
            : implode($format === 'spaced' ? ' ' : '-', str_split($digits, 4));

        return ['kind' => 'CARD', 'format' => $format.'/'.$length, 'text' => $text];
    }

    /**
     * A tenant-configured pattern and a value that matches it.
     *
     * The prefix is drawn, so two tenants in one test get shapes that are genuinely
     * each other's business and not the platform's. Six digits keeps the value below
     * the phone threshold, so what redacts it is the tenant's pattern and nothing else.
     *
     * @return array{pattern: string, value: array{kind: string, format: string, text: string}}
     */
    public function customPattern(): array
    {
        $prefix = strtoupper($this->letters($this->int(3, 4)));

        return [
            'pattern' => '\b'.$prefix.'-\d{6}\b',
            'value' => ['kind' => 'CUSTOM', 'format' => 'tenant '.$prefix, 'text' => $prefix.'-'.$this->digits(6)],
        ];
    }

    /**
     * One value of a randomly chosen kind.
     *
     * @return array{kind: string, format: string, text: string}
     */
    public function value(): array
    {
        return match ($this->int(1, 3)) {
            1 => $this->phone(),
            2 => $this->email(),
            default => $this->card(),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Assembly
    |--------------------------------------------------------------------------
    */

    /**
     * A message with the given values planted in it, plus the anchors that must survive.
     *
     * Positions are drawn: a value may open or close the string, sit mid-sentence, be
     * glued directly to a word (phones only — gluing a letter to an email changes where
     * its local part begins, so the planted string would no longer be the value), or be
     * followed by punctuation. Values are separated by filler that contains a letter, so
     * each planted value is its own detectable value and the token count is meaningful.
     *
     * @param  list<array{kind: string, format: string, text: string}>  $values
     * @return array{text: string, anchors: list<string>}
     */
    public function plant(array $values): array
    {
        $opening = $this->letters(8);
        $closing = $this->letters(8);
        $parts = [$opening];

        foreach ($values as $value) {
            $glued = $value['kind'] === 'PHONE' && $this->chance(25);

            $parts[] = $glued
                ? $this->letters($this->int(3, 6)).$value['text'].$this->letters($this->int(3, 6))
                : $value['text'].($this->chance(40) ? $this->pick(self::PUNCTUATION) : '');

            $parts[] = $this->words($this->int(1, 3));
        }

        $parts[] = $closing;

        return ['text' => implode(' ', $parts), 'anchors' => [$opening, $closing]];
    }

    /**
     * Two values written side by side, separated by **nothing but a phone separator**.
     *
     * This is the adversarial shape, and it is the shape that found the leak this test
     * was written to close: a space between two grouped numbers joins them into one
     * digit-group run, and a detector that validates the run as a whole and discards it
     * for being too long leaves *both* numbers unredacted. Nothing in the tidy corpus
     * exercises it, because nothing in the tidy corpus puts two numbers next to each
     * other without a word in between.
     */
    public function adjacent(string $first, string $second): string
    {
        return $this->letters(6).' '.$first.$this->pick([' ', ' ', ' ', '.', '-']).$second.' '.$this->letters(6);
    }

    /**
     * Filler that holds no PII of any kind: words, and nothing else.
     */
    public function words(int $count): string
    {
        $words = [];

        for ($index = 0; $index < $count; $index++) {
            $words[] = $this->pick(self::WORDS);
        }

        return implode(' ', $words);
    }

    /**
     * A nested payload with the values scattered through it, for `redactStructure()`.
     *
     * Non-string scalars are included because they are the shape a walker gets wrong:
     * a redactor that coerces on the way through returns `"3"` where `3` was, and the
     * caller's JSON schema stops matching.
     *
     * @param  list<array{kind: string, format: string, text: string}>  $values
     * @return array<array-key, mixed>
     */
    public function structure(array $values): array
    {
        $leaves = [];

        foreach ($values as $index => $value) {
            $leaves['field_'.$index] = $this->chance(50)
                ? $value['text']
                : ['nested' => [$value['text'], $this->words(2)]];
        }

        return [
            'contact' => $leaves,
            'count' => $this->int(1, 99),
            'flag' => $this->chance(50),
            'nothing' => null,
            'note' => $this->words(3),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Opaque machine identifiers (PiiKeyRules::isOpaqueIdentifier)
    |--------------------------------------------------------------------------
    */

    public function identifierKey(): string
    {
        return $this->pick(self::IDENTIFIER_KEYS);
    }

    /**
     * A digest or a ULID — the values whose digit runs the phone detector was
     * partially masking before task 4.6 added the exemption.
     *
     * Regenerated in the vanishingly unlikely event of a digest with no letter in it,
     * because the exemption requires one: an all-digit value under an identifier key
     * must still be masked, and that half is asserted separately.
     */
    public function opaqueIdentifier(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $value = $this->chance(50)
                ? hash('sha256', (string) $this->int(1, PHP_INT_MAX))
                : (string) Str::ulid();

            if (preg_match('/\p{L}/u', $value) === 1) {
                return $value;
            }
        }

        return 'ulid'.hash('sha256', 'fallback');
    }

    /*
    |--------------------------------------------------------------------------
    | The documented carve-outs, asserted rather than avoided
    |--------------------------------------------------------------------------
    */

    /**
     * One of each shape `PiiScanner` documents that it will **not** mask, as
     * `label => text`.
     *
     * These are drawn too, so the assertion is "no real date is ever masked" rather
     * than "this one date is not masked". Every one of them is long enough to be
     * phone-shaped or card-shaped, so none of the assertions is vacuous.
     *
     * @return array<string, string>
     */
    public function carveOuts(): array
    {
        $year = $this->int(1971, 2035);
        $month = $this->int(1, 12);
        $day = $this->int(1, 28);
        $separator = $this->pick(['-', '.']);

        $identifier = $this->digits($this->int(16, 19));
        $card = self::withLuhnCheckDigit((string) $this->int(4, 6).$this->digits(14));

        return [
            'iso calendar date' => sprintf('%04d%s%02d%s%02d', $year, $separator, $month, $separator, $day),
            'day-first calendar date' => sprintf('%02d%s%02d%s%04d', $day, $separator, $month, $separator, $year),
            'in-range dotted quad' => implode('.', [
                $this->int(10, 255), $this->int(10, 255), $this->int(10, 255), $this->int(10, 255),
            ]),
            'thousands separated' => implode(',', [$this->digits(1), $this->digits(3), $this->digits(3)]),
            'clock time' => sprintf('%02d:%02d:%02d', $this->int(0, 23), $this->int(0, 59), $this->int(0, 59)),
            // 16+ digits and no `+`: longer than E.164 allows a subscriber number to be,
            // which is what lets an order id or a WhatsApp message id survive. Forced
            // Luhn-invalid so the assertion is about the length rule, not about chance.
            'long non-card identifier' => self::breakLuhn($identifier),
            // A 16-digit card with one digit wrong: it fails Luhn, so it is not a card,
            // and it is over E.164's ceiling, so it is not a phone number either. Both
            // rules have to hold for it to survive, which is why it is asserted at 16
            // digits — a *shorter* mistyped card is correctly masked as a phone number.
            'mistyped card' => self::breakLuhn($card),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The independent judge
    |--------------------------------------------------------------------------
    */

    /**
     * The labels of every independent detector that still finds PII in `$text`.
     *
     * Written in terms of shape, from scratch, and broader than `PiiScanner`: any
     * 13–19 digit run counts as card-shaped whether or not it passes Luhn, and any
     * `@`-joined pair counts as an address. That breadth is what makes a clean result
     * meaningful — and it is also why this may only be pointed at output built from
     * `plant()`, never at text containing a carve-out, which it would flag by design.
     *
     * @return list<string>
     */
    public static function leaks(string $text): array
    {
        return self::matching($text, self::DIGIT_DETECTORS + [
            // Anything `@`-shaped, including a WhatsApp JID.
            'address' => '/\S+@\S+\.\S{2,}/u',
        ]);
    }

    /**
     * The digit-shaped detectors only, for output that is allowed to keep an `@`.
     *
     * The **irreversible** masks are not tokens: `App\Support\Pii\PiiMask::email()`
     * deliberately keeps the domain, because in a log line the domain is the
     * operational signal and the user is not. So log-scrubber output is judged on
     * digits here, and on the local part having gone, rather than by `leaks()` — which
     *
     * would flag `j***@example.com` and be right to, for a *token*.
     *
     * @return list<string>
     */
    public static function digitLeaks(string $text): array
    {
        return self::matching($text, self::DIGIT_DETECTORS);
    }

    /**
     * @param  array<string, string>  $detectors
     * @return list<string>
     */
    private static function matching(string $text, array $detectors): array
    {
        $found = [];

        foreach ($detectors as $label => $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $found[] = $label.' ['.$matches[0].']';
            }
        }

        return $found;
    }

    /*
    |--------------------------------------------------------------------------
    | Primitives
    |--------------------------------------------------------------------------
    */

    /**
     * `$count` decimal digits, the first of them never zero, so a group never reads as
     * an octal-looking fragment and a bare run never loses a leading digit to a reader.
     */
    public function digits(int $count): string
    {
        $digits = (string) $this->int(1, 9);

        for ($index = 1; $index < $count; $index++) {
            $digits .= (string) $this->int(0, 9);
        }

        return $digits;
    }

    public function letters(int $count): string
    {
        $letters = '';

        for ($index = 0; $index < $count; $index++) {
            $letters .= chr($this->int(97, 122));
        }

        return $letters;
    }

    /**
     * The same value with its ASCII digits rewritten in another script's block.
     */
    private static function inScript(string $text, int $zero): string
    {
        $rewritten = '';

        foreach (str_split($text) as $character) {
            if (! ctype_digit($character)) {
                $rewritten .= $character;

                continue;
            }

            $mapped = mb_chr($zero + (int) $character, 'UTF-8');
            $rewritten .= $mapped === false ? $character : $mapped;
        }

        return $rewritten;
    }

    /**
     * `$partial` plus the check digit that makes it pass Luhn.
     */
    private static function withLuhnCheckDigit(string $partial): string
    {
        $sum = 0;
        $double = true;

        for ($index = strlen($partial) - 1; $index >= 0; $index--) {
            $digit = (int) $partial[$index];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $partial.(string) ((10 - $sum % 10) % 10);
    }

    /**
     * The same digits with the last one changed, so the value cannot pass Luhn.
     *
     * Without this, roughly one arbitrary 16-digit identifier in ten would be a valid
     * card by chance, and a carve-out assertion would fail about a tenth of the time
     * for a reason that is correct behaviour.
     */
    private static function breakLuhn(string $digits): string
    {
        $last = (int) substr($digits, -1);

        for ($candidate = 0; $candidate <= 9; $candidate++) {
            $attempt = substr($digits, 0, -1).(string) $candidate;

            if ($candidate !== $last && ! self::passesLuhn($attempt)) {
                return $attempt;
            }
        }

        return $digits;
    }

    private static function passesLuhn(string $digits): bool
    {
        return self::withLuhnCheckDigit(substr($digits, 0, -1)) === $digits;
    }
}
