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
 * | `PHONE` | `+E.164`, spaced, dashed, dotted, parenthesised, bare runs, and **several numbers in a row** | 7–15 digits (E.164's maximum), minus dates and dotted quads |
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
 *
 * ## Nothing is left behind
 *
 * Two mechanisms exist for one reason: a *rejected* candidate must never mean
 * *unredacted text*. Both were added after the Property 15 property test
 * (`tests/Feature/Security/PiiEgressPropertyTest`) found real leaks that the scripted
 * corpus could not.
 *
 * - **Runs are decomposed, not validated whole** (`phonesWithin()`). Matching a
 *   bounded number of bounded groups and then rejecting the match for holding too many
 *   digits left `"415-555-2671 415-555-2671"` matching *nothing at all*, and capping a
 *   group at five digits missed `"0755-1234567"` outright.
 * - **The remainder is scanned again** (`coverRemainder()`). Overlap resolution has to
 *   drop a losing candidate, and a Luhn-valid card candidate bridging two adjacent
 *   numbers made that drop a leak.
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
     * One digit group of a phone number, optionally parenthesised: `415`, `(0)`,
     * `98765`. Bounded at 24 digits — beyond any card and well beyond E.164, so the
     * bound only ever excludes something that was never going to be believed anyway.
     *
     * A closing parenthesis is only allowed where an opening one was matched, so a
     * number written `4155552671)` does not absorb the bracket that follows it.
     */
    private const string PHONE_GROUP = '(?:\(\p{Nd}{1,24}\)|\p{Nd}{1,24})';

    /**
     * A **maximal run** of digit groups joined by spaces, dots, dashes, or
     * parentheses — every human way of writing a phone number, and an unseparated
     * run (`+14155552671`, `٩١٩٨٧٦٥٤٣٢١٠`) as the one-group case.
     *
     * The repetition is deliberately unbounded, and the group size is deliberately
     * larger than a phone number needs, because this pattern's job is *not* to decide
     * what a phone number is — `phonesWithin()` does that, over the run's digit
     * groups. Writing the bound into the pattern instead is what leaked: a pattern
     * that stops after seven groups of five digits hands back a candidate that spans
     * two adjacent numbers, and rejecting that candidate for being too long left both
     * of them unredacted (`"415-555-2671 415-555-2671"` matched nothing at all),
     * while capping a group at five digits missed every number written `0755-1234567`.
     *
     * It is not a ReDoS shape: the separator class is disjoint from `\p{Nd}`, so
     * there is exactly one way to split any input between iterations.
     */
    private const string PHONE_RUN_PATTERN = '/(?<!\p{Nd})\+?'.self::PHONE_GROUP.'(?:[ .\-]'.self::PHONE_GROUP.')*(?!\p{Nd})/';

    /**
     * E.164's ceiling, and therefore the platform's definition of "too long to be a
     * phone number".
     */
    private const int MAX_PHONE_DIGITS = 15;

    private const int MIN_PHONE_DIGITS = 7;

    private const int MIN_CARD_DIGITS = 13;

    private const int MAX_CARD_DIGITS = 19;

    /**
     * How many times `coverRemainder()` will re-scan the ground nothing claimed.
     *
     * One round is what a message needs; the cap is what keeps an adversarial subject
     * from turning a scan into a loop.
     */
    private const int MAX_COVER_ROUNDS = 4;

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

        $accepted = $this->resolve($this->candidatesIn($text, 0, $unicode, $custom), $reserved);

        return $this->coverRemainder($text, $accepted, $reserved, $unicode, $custom);
    }

    /**
     * Every detector's opinion about one stretch of text, in the whole subject's
     * coordinates.
     *
     * @param  list<CompiledPiiPattern>  $custom
     * @return list<PiiSpan>
     *
     * @throws PiiRedactionException
     */
    private function candidatesIn(string $text, int $offset, bool $unicode, array $custom): array
    {
        $candidates = [
            ...$this->emails($text, $unicode),
            ...$this->cards($text, $unicode),
            ...$this->phones($text, $unicode),
            ...$this->custom($text, $unicode, $custom),
        ];

        if ($offset === 0) {
            return $candidates;
        }

        return array_map(
            static fn (PiiSpan $span): PiiSpan => new PiiSpan($span->start + $offset, $span->text, $span->kind),
            $candidates,
        );
    }

    /**
     * Re-ask the same question of whatever ground no accepted span claimed.
     *
     * `resolve()` has to drop a candidate that overlaps a span it already accepted, and
     * *that* is how "longest wins" can turn into a leak. The case is real and it is not
     * exotic: two subscriber numbers written side by side, and the card detector's
     * grouping — which allows a space between digits — bridging the gap between them.
     * Roughly one such bridged digit string in ten passes Luhn by chance, and when it
     * does, the card span covers the first number plus the *front* of the second, the
     * second number's own span overlaps it and is discarded, and the tail of a real
     * phone number egresses in clear:
     *
     * ```
     * "4039572391 7460-6718274"  ->  "[[PII:CARD:…:1]]-6718274"
     * ```
     *
     * So the leftovers are scanned again. This is a safety pass, not a heuristic: it can
     * only ever add spans in text that nothing else claimed, it runs the same detectors
     * under the same carve-outs, and it re-checks its findings against the token
     * reservations. It is bounded twice over — each round strictly shrinks the ground in
     * question, and the round count is capped — so a pathological subject costs a fixed
     * multiple of one scan rather than an unbounded loop. In practice a message needs one
     * extra round to confirm there is nothing left.
     *
     * @param  list<PiiSpan>  $accepted
     * @param  list<PiiSpan>  $reserved
     * @param  list<CompiledPiiPattern>  $custom
     * @return list<PiiSpan>
     *
     * @throws PiiRedactionException
     */
    private function coverRemainder(
        string $text,
        array $accepted,
        array $reserved,
        bool $unicode,
        array $custom,
    ): array {
        for ($round = 0; $round < self::MAX_COVER_ROUNDS; $round++) {
            // Nothing was claimed, so the remainder is the whole subject and re-scanning
            // it would ask a question that has already been answered.
            if ($accepted === []) {
                break;
            }

            $extra = [];

            foreach ($this->uncovered($text, $accepted) as [$start, $slice]) {
                foreach ($this->candidatesIn($slice, $start, $unicode, $custom) as $span) {
                    $extra[] = $span;
                }
            }

            if ($extra === []) {
                break;
            }

            $before = count($accepted);
            $accepted = $this->resolve([...$accepted, ...$extra], $reserved);

            if (count($accepted) === $before) {
                break;
            }
        }

        return $accepted;
    }

    /**
     * The stretches of `$text` no accepted span covers, as `[offset, text]`.
     *
     * Span boundaries fall on character boundaries, so a slice of valid UTF-8 is itself
     * valid UTF-8 and can be scanned under the same modifier as the whole.
     *
     * @param  non-empty-list<PiiSpan>  $accepted
     * @return list<array{0: int, 1: string}>
     */
    private function uncovered(string $text, array $accepted): array
    {
        usort($accepted, static fn (PiiSpan $a, PiiSpan $b): int => $a->start <=> $b->start);

        $gaps = [];
        $cursor = 0;

        foreach ($accepted as $span) {
            if ($span->start > $cursor) {
                $gaps[] = [$cursor, substr($text, $cursor, $span->start - $cursor)];
            }

            $cursor = max($cursor, $span->start + $span->length());
        }

        if ($cursor < strlen($text)) {
            $gaps[] = [$cursor, substr($text, $cursor)];
        }

        return array_values(array_filter($gaps, static fn (array $gap): bool => $gap[1] !== ''));
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
     *
     * @throws PiiRedactionException
     */
    private function phones(string $text, bool $unicode): array
    {
        $spans = [];

        foreach ($this->spansOf(self::PHONE_RUN_PATTERN, $text, $unicode, 'phone_run', PiiKind::Phone) as $run) {
            foreach ($this->phonesWithin($run, $unicode) as $span) {
                $spans[] = $span;
            }
        }

        return $spans;
    }

    /**
     * The phone numbers inside one maximal digit-group run.
     *
     * A run is not a phone number; it is *where* phone numbers are. One run may hold
     * exactly one (`+1 (415) 555-2671`), a carve-out and nothing else (`2024-06-13`),
     * or several numbers a customer typed one after another
     * (`415-555-2671 020-7946-0958`). So the run is walked group by group, left to
     * right, and each step takes the **longest** stretch of groups that is still a
     * plausible number — at most `MAX_PHONE_DIGITS` digits, at least
     * `MIN_PHONE_DIGITS`, and not one of the documented carve-outs.
     *
     * Where that stretch ends is decided by a preference, not a guess: a stretch that
     * ends at a **space** wins over a longer one that ends mid-separator, because when
     * two numbers sit side by side the space between them is the boundary and the
     * dashes or dots inside them are not. That is what makes
     * `"415-555-2671 415-555-2671"` come back as two numbers rather than as one
     * over-long candidate — the case that used to come back as nothing.
     *
     * When no stretch starting at a group is plausible, that group is skipped and the
     * walk continues, so a fragment too short to be a subscriber number never blocks
     * the numbers after it.
     *
     * @return list<PiiSpan>
     *
     * @throws PiiRedactionException
     */
    private function phonesWithin(PiiSpan $run, bool $unicode): array
    {
        $groups = $this->digitGroups($run->text, $unicode);
        $count = count($groups);
        $spans = [];
        $index = 0;

        while ($index < $count) {
            $carveOut = $this->carveOutAt($run->text, $groups, $index, $count);

            if ($carveOut !== null) {
                $index += $carveOut;

                continue;
            }

            $longest = null;
            $longestAtBoundary = null;
            $digits = 0;

            for ($last = $index; $last < $count; $last++) {
                $digits += $groups[$last]['digits'];

                if ($digits > self::MAX_PHONE_DIGITS) {
                    break;
                }

                if ($digits < self::MIN_PHONE_DIGITS) {
                    continue;
                }

                [, $candidate] = self::subRun($run->text, $groups, $index, $last, $count);

                // An explicit `+` is an unambiguous statement of intent: whatever else
                // it looks like, it is a number somebody meant to be dialled.
                if (! self::hasPlus($run->text, $index)
                    && (self::isCalendarDate($candidate) || self::isDottedQuad($candidate))) {
                    continue;
                }

                $longest = $last;

                if (self::endsAtBoundary($run->text, $groups, $last, $count)) {
                    $longestAtBoundary = $last;
                }
            }

            $chosen = $longestAtBoundary ?? $longest;

            if ($chosen === null) {
                $index++;

                continue;
            }

            [$offset, $chosenText] = self::subRun($run->text, $groups, $index, $chosen, $count);
            $spans[] = new PiiSpan($run->start + $offset, $chosenText, PiiKind::Phone);
            $index = $chosen + 1;
        }

        return $spans;
    }

    /**
     * The run's digit groups, as byte offsets into its text.
     *
     * @return list<array{start: int, length: int, digits: int}>
     *
     * @throws PiiRedactionException
     */
    private function digitGroups(string $runText, bool $unicode): array
    {
        $matched = preg_match_all(
            '/\p{Nd}{1,24}/'.($unicode ? 'u' : ''),
            $runText,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matched === false) {
            throw PiiRedactionException::scanFailed('phone_groups', preg_last_error());
        }

        $groups = [];

        foreach ($matches[0] as $match) {
            if ($match[1] < 0 || $match[0] === '') {
                continue;
            }

            $groups[] = [
                'start' => $match[1],
                'length' => strlen($match[0]),
                'digits' => Digits::count($match[0]),
            ];
        }

        return $groups;
    }

    /**
     * How many groups a documented carve-out occupies at `$index`, or `null`.
     *
     * A date and a dotted quad are only spared where they **stand alone** inside the
     * run — space-delimited or at its edges — which is what lets
     * `"2024-06-13 2024-06-14"` keep both dates while `"99-2024-06-13"` is still
     * treated as the digit string it is. Without the delimiting condition a run
     * holding two dates would be decomposed *through* them, and masking every date an
     * LLM is shown is the regression these carve-outs exist to prevent.
     *
     * @param  list<array{start: int, length: int, digits: int}>  $groups
     */
    private static function carveOutAt(string $runText, array $groups, int $index, int $count): ?int
    {
        if (self::hasPlus($runText, $index)) {
            return null;
        }

        if ($index > 0 && ! str_contains(self::separatorAfter($runText, $groups, $index - 1), ' ')) {
            return null;
        }

        foreach ([3, 4] as $length) {
            $last = $index + $length - 1;

            if ($last >= $count) {
                continue;
            }

            if ($last < $count - 1 && ! str_contains(self::separatorAfter($runText, $groups, $last), ' ')) {
                continue;
            }

            [, $candidate] = self::subRun($runText, $groups, $index, $last, $count);

            if ($length === 3 ? self::isCalendarDate($candidate) : self::isDottedQuad($candidate)) {
                return $length;
            }
        }

        return null;
    }

    /**
     * The text of groups `$from`–`$to`, and its offset within the run.
     *
     * The run's own edges are taken verbatim at either end, so a leading `+` and a
     * trailing `)` stay with the number they belong to; a parenthesis that brackets an
     * interior group is picked up the same way.
     *
     * @param  list<array{start: int, length: int, digits: int}>  $groups
     * @return array{0: int, 1: string}
     */
    private static function subRun(string $runText, array $groups, int $from, int $to, int $count): array
    {
        $start = $groups[$from]['start'];

        if ($from === 0) {
            $start = 0;
        } elseif ($start > 0 && $runText[$start - 1] === '(') {
            $start--;
        }

        $end = $groups[$to]['start'] + $groups[$to]['length'];

        if ($to === $count - 1) {
            $end = strlen($runText);
        } elseif (($runText[$end] ?? '') === ')') {
            $end++;
        }

        return [$start, substr($runText, $start, $end - $start)];
    }

    /**
     * The characters between two groups — the separator, plus any parentheses.
     *
     * @param  list<array{start: int, length: int, digits: int}>  $groups
     */
    private static function separatorAfter(string $runText, array $groups, int $index): string
    {
        $end = $groups[$index]['start'] + $groups[$index]['length'];

        return substr($runText, $end, $groups[$index + 1]['start'] - $end);
    }

    /**
     * Whether a stretch ending at group `$index` ends where one number plausibly stops
     * and the next begins.
     *
     * Two signals, and the second needs the first: a bracket **opening** right after
     * the gap starts a new number (`"…2671 (415) 555…"`), and otherwise a gap of plain
     * whitespace does. The parenthesis clause is not decoration — in
     * `"(415) 555-2671 (415) 555-2671"` the gap *inside* each number (`") "`) also
     * holds a space, so whitespace alone cannot tell the two apart and the split lands
     * one group late.
     *
     * @param  list<array{start: int, length: int, digits: int}>  $groups
     */
    private static function endsAtBoundary(string $runText, array $groups, int $index, int $count): bool
    {
        if ($index === $count - 1) {
            return true;
        }

        $gap = self::separatorAfter($runText, $groups, $index);

        if (str_ends_with($gap, '(')) {
            return true;
        }

        return str_contains($gap, ' ') && ! str_contains($gap, '(') && ! str_contains($gap, ')');
    }

    /**
     * Whether the stretch starting at `$index` carries the run's leading `+`.
     */
    private static function hasPlus(string $runText, int $index): bool
    {
        return $index === 0 && str_starts_with($runText, '+');
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
