<?php

declare(strict_types=1);

namespace App\Support\Pii;

use App\Enums\PiiKind;
use App\Exceptions\Security\PiiRedactionException;

/**
 * Finds the PII in a string: emails, phone numbers, card numbers, and any
 * operator-configured tenant patterns (Req 32.2 / NFR3, Correctness Property 15).
 *
 * This class does not mask, tokenise, or log anything — it only answers *where the
 * PII is*. The reversible egress redactor
 * (`App\Services\Security\Pii\TokenizingPiiRedactor`) and the irreversible log
 * scrubber (`LogPiiScrubber`) both consume the same answer, which is the point:
 * "what counts as PII" is defined once, so the LLM path and the logging path cannot
 * drift into disagreeing about it.
 *
 * ## The detectors, and why each is shaped the way it is
 *
 * | Kind | Matches | Discriminator |
 * |---|---|---|
 * | `EMAIL` | plus-addressing, dots, subdomains, unicode local parts, WhatsApp JIDs | structural (`@` + dotted domain) |
 * | `CARD` | 13–19 digits, with or without space/dash grouping | **Luhn** |
 * | `PHONE` | `+E.164`, spaced, dashed, parenthesised, and bare runs | 7–15 digits (E.164's maximum), minus dates and dotted quads |
 * | `CUSTOM` | whatever the tenant configured | validated + bounded by `TenantPatternCompiler` |
 *
 * **Why Luhn and not digit-run length.** "A long run of digits" would sweep up order
 * ids, WhatsApp message ids, invoice numbers, and timestamps. Since a card number
 * carries its own check digit, the shape test can be loose and the *decision* strict:
 * a 16-digit run is a card only if it checks out. The tradeoff is explicit — a
 * mistyped card number (one wrong digit) fails Luhn and is not treated as a card,
 * and roughly one in ten arbitrary 16-digit identifiers passes Luhn by chance and is.
 * Both errors are the safe way round: the false negative is still caught by nothing
 * (it is not a valid card and cannot be charged), and the false positive costs a
 * tokenised order id that rehydrates unchanged.
 *
 * **Why 15 digits bounds a phone number.** E.164 caps a subscriber number at 15
 * digits, so a bare run of 16 or more is definitionally not a phone number. That is
 * what lets a 16-digit non-card identifier survive redaction intact while
 * `+14155552671` and a bare `4155552671` do not.
 *
 * **Dates, IP addresses, and money.** `2024-06-13` is eight digits in three
 * separator-joined groups — phone-shaped by any structural test. Masking every date
 * an LLM is shown would be a functional regression, so a candidate that has no `+`
 * and parses as a real calendar date, or as a dotted quad in `0–255`, is left alone.
 * Thousands separators (`1,234,567`) and clock times (`10:30:45`) never match,
 * because `,` and `:` are not phone separators.
 *
 * ## Unicode
 *
 * Every digit rule is written with `\p{Nd}`, so a number typed with Arabic-Indic,
 * Devanagari, or fullwidth digits is detected exactly like an ASCII one. Text that
 * is *not* valid UTF-8 is scanned with the same patterns minus the `u` modifier
 * (ASCII digits only) rather than skipped — a malformed byte should not be a way to
 * smuggle a phone number past the redactor.
 *
 * ## Idempotence
 *
 * Already-emitted tokens (`[[PII:PHONE:…]]`) are treated as reserved ground and are
 * never re-matched or overlapped, so redacting redacted text is a no-op and no value
 * is ever double-tokenised.
 */
final class PiiScanner
{
    /**
     * The token grammar, shared with the redactor that mints them.
     *
     * The alphabet is chosen so a token is **inert**: no `@`, so it is not an email;
     * only a short numeric index, so it is neither a phone number nor a card. That
     * matters because a token travels through the same log lines and prompts as the
     * text it replaced.
     */
    public const string TOKEN_PATTERN = '/\[\[PII:[A-Z]{3,12}:[a-p]{8}:\d{1,6}\]\]/';

    /**
     * An email address. Bounded repetition throughout (`{1,64}`, `{1,63}`, `{1,8}`)
     * so this pattern cannot be the ReDoS vector that unbounded nested quantifiers
     * make of the textbook email regex. `*` is deliberately not a local-part
     * character, which is what makes `PiiMask::email()`'s output un-rematchable.
     */
    private const string EMAIL_PATTERN = '/[\p{L}\p{N}._%+\'\-]{1,64}@[\p{L}\p{N}\-]{1,63}(?:\.[\p{L}\p{N}\-]{1,63}){1,8}/';

    /**
     * 13–19 digits, optionally grouped in fours by a space or a dash. Validated by
     * Luhn before it is believed.
     */
    private const string CARD_PATTERN = '/(?<!\p{Nd})(?:\p{Nd}[ \-]?){12,18}\p{Nd}(?!\p{Nd})/';

    /**
     * Digit groups joined by spaces, dots, dashes, or parentheses — every human way
     * of writing a phone number. Validated for digit count and against the
     * date/IP shapes below.
     */
    private const string PHONE_GROUPED_PATTERN = '/(?<!\p{Nd})\+?\(?\p{Nd}{1,5}\)?(?:[ .\-]\(?\p{Nd}{1,5}\)?){1,6}(?!\p{Nd})/';

    /**
     * An unseparated run: `+14155552671`, `919876543210`, `٩١٩٨٧٦٥٤٣٢١٠`. The
     * lookarounds are what keep it from biting a 15-digit prefix out of a 16-digit
     * identifier.
     */
    private const string PHONE_BARE_PATTERN = '/(?<!\p{Nd})\+?\p{Nd}{7,15}(?!\p{Nd})/';

    /**
     * E.164's ceiling, and therefore the platform's definition of "too long to be a
     * phone number".
     */
    private const int MAX_PHONE_DIGITS = 15;

    private const int MIN_PHONE_DIGITS = 7;

    private const int MIN_CARD_DIGITS = 13;

    private const int MAX_CARD_DIGITS = 19;

    public function __construct(
        private readonly int $customBacktrackLimit = TenantPatternCompiler::DEFAULT_BACKTRACK_LIMIT,
    ) {}

    /**
     * Every PII span in `$text`, ordered by position and guaranteed not to overlap.
     *
     * @param  list<CompiledPiiPattern>  $custom  validated tenant patterns
     * @return list<PiiSpan>
     *
     * @throws PiiRedactionException when a built-in detector cannot be run — the
     *                               caller must not treat unscannable text as clean
     */
    public function scan(string $text, array $custom = []): array
    {
        if ($text === '') {
            return [];
        }

        $unicode = mb_check_encoding($text, 'UTF-8');

        // Ground already occupied by a token: never re-matched, never overlapped.
        $reserved = $this->spansOf(self::TOKEN_PATTERN, $text, $unicode, 'token', PiiKind::Custom);

        $candidates = [
            ...$this->emails($text, $unicode),
            ...$this->cards($text, $unicode),
            ...$this->phones($text, $unicode),
            ...$this->custom($text, $unicode, $custom),
        ];

        return $this->resolve($candidates, $reserved);
    }

    /**
     * @return list<PiiSpan>
     */
    private function emails(string $text, bool $unicode): array
    {
        return $this->spansOf(self::EMAIL_PATTERN, $text, $unicode, 'email', PiiKind::Email);
    }

    /**
     * @return list<PiiSpan>
     */
    private function cards(string $text, bool $unicode): array
    {
        $spans = [];

        foreach ($this->spansOf(self::CARD_PATTERN, $text, $unicode, 'card', PiiKind::Card) as $span) {
            $digits = Digits::ascii($span->text);
            $count = Digits::count($span->text);

            if ($digits === null || $count < self::MIN_CARD_DIGITS || $count > self::MAX_CARD_DIGITS) {
                continue;
            }

            if (Digits::isLuhn($digits)) {
                $spans[] = $span;
            }
        }

        return $spans;
    }

    /**
     * @return list<PiiSpan>
     */
    private function phones(string $text, bool $unicode): array
    {
        $spans = [];

        $candidates = [
            ...$this->spansOf(self::PHONE_GROUPED_PATTERN, $text, $unicode, 'phone_grouped', PiiKind::Phone),
            ...$this->spansOf(self::PHONE_BARE_PATTERN, $text, $unicode, 'phone_bare', PiiKind::Phone),
        ];

        foreach ($candidates as $span) {
            $count = Digits::count($span->text);

            if ($count < self::MIN_PHONE_DIGITS || $count > self::MAX_PHONE_DIGITS) {
                continue;
            }

            // An explicit `+` is an unambiguous statement of intent: whatever else it
            // looks like, it is a number somebody meant to be dialled.
            if (! str_contains($span->text, '+') && (self::isCalendarDate($span->text) || self::isDottedQuad($span->text))) {
                continue;
            }

            $spans[] = $span;
        }

        return $spans;
    }

    /**
     * Tenant patterns, each run under a lowered backtrack limit.
     *
     * A pattern that exhausts that budget contributes nothing for this text and does
     * not stop the others: the failure mode of a pathological tenant regex is
     * "this one pattern did not help here", never a stalled worker and never an
     * unredacted message (the built-ins have already run).
     *
     * @param  list<CompiledPiiPattern>  $custom
     * @return list<PiiSpan>
     */
    private function custom(string $text, bool $unicode, array $custom): array
    {
        if ($custom === []) {
            return [];
        }

        /** @var list<PiiSpan> $spans */
        $spans = BoundedPcre::within($this->customBacktrackLimit, function () use ($text, $unicode, $custom): array {
            $spans = [];

            foreach ($custom as $pattern) {
                $matched = @preg_match_all($pattern->for($unicode), $text, $matches, PREG_OFFSET_CAPTURE);

                if ($matched === false) {
                    continue;
                }

                /** @var array<int, array{0: string, 1: int}> $whole */
                $whole = $matches[0] ?? [];

                foreach ($whole as $match) {
                    if ($match[1] < 0 || $match[0] === '') {
                        continue;
                    }

                    $spans[] = new PiiSpan($match[1], $match[0], PiiKind::Custom);
                }
            }

            return $spans;
        });

        return $spans;
    }

    /**
     * Run one built-in pattern, adding the `u` modifier when the subject allows it.
     *
     * A failure here is fatal by design: a detector that could not run has told us
     * nothing about the text, and treating "no matches" as "no PII" is the exact
     * mistake Property 15 forbids.
     *
     * @return list<PiiSpan>
     *
     * @throws PiiRedactionException
     */
    private function spansOf(string $pattern, string $text, bool $unicode, string $detector, PiiKind $kind): array
    {
        $matched = preg_match_all($pattern.($unicode ? 'u' : ''), $text, $matches, PREG_OFFSET_CAPTURE);

        if ($matched === false) {
            throw PiiRedactionException::scanFailed($detector, preg_last_error());
        }

        $spans = [];

        /** @var array<int, array{0: string, 1: int}> $whole */
        $whole = $matches[0] ?? [];

        foreach ($whole as $match) {
            if ($match[1] < 0 || $match[0] === '') {
                continue;
            }

            $spans[] = new PiiSpan($match[1], $match[0], $kind);
        }

        return $spans;
    }

    /**
     * Reduce overlapping candidates to one deterministic, non-overlapping cover.
     *
     * The ordering is: earliest start first, then longest, then most specific kind.
     * "Longest wins" is the safe tie-break — when a custom pattern and a built-in
     * claim overlapping text, taking the larger span redacts more, and over-redaction
     * is recoverable (the token rehydrates) while under-redaction is a leak.
     *
     * @param  list<PiiSpan>  $candidates
     * @param  list<PiiSpan>  $reserved
     * @return list<PiiSpan>
     */
    private function resolve(array $candidates, array $reserved): array
    {
        usort($candidates, static function (PiiSpan $a, PiiSpan $b): int {
            return $a->start <=> $b->start
                ?: $b->length() <=> $a->length()
                ?: $a->kind->precedence() <=> $b->kind->precedence();
        });

        $accepted = [];

        foreach ($candidates as $candidate) {
            foreach ($reserved as $token) {
                if ($candidate->overlaps($token)) {
                    continue 2;
                }
            }

            foreach ($accepted as $existing) {
                if ($candidate->overlaps($existing)) {
                    continue 2;
                }
            }

            $accepted[] = $candidate;
        }

        return $accepted;
    }

    /**
     * Whether the candidate is a real calendar date rather than a phone number:
     * three groups joined by one repeated separator that `checkdate()` accepts, in
     * either `Y-m-d` or `d-m-Y` order.
     *
     * The false negative this accepts is a phone number written exactly like a valid
     * date (`1998-07-25`). The alternative — masking every date in every message —
     * degrades every LLM reply that reasons about delivery dates, so the tradeoff is
     * taken deliberately and in one place.
     */
    private static function isCalendarDate(string $candidate): bool
    {
        if (preg_match('/^(\d{1,4})([.\-])(\d{1,2})\2(\d{1,4})$/', trim($candidate), $parts) !== 1) {
            return false;
        }

        $first = (int) $parts[1];
        $middle = (int) $parts[3];
        $last = (int) $parts[4];

        return checkdate($middle, $last, $first) || checkdate($middle, $first, $last);
    }

    /**
     * Whether the candidate is a dotted quad in range — an IPv4 address, which is
     * operationally valuable in a log line and is not a subscriber identifier.
     */
    private static function isDottedQuad(string $candidate): bool
    {
        return filter_var(trim($candidate), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
