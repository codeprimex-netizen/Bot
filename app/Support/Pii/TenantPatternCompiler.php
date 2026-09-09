<?php

declare(strict_types=1);

namespace App\Support\Pii;

/**
 * Validates and compiles the **operator-supplied** redaction patterns of Req 32.2 /
 * NFR3 ("configurable tenant patterns").
 *
 * A tenant pattern is a regex written by somebody outside the codebase and applied
 * to every message that tenant's bot handles. Three ways that goes wrong, and what
 * is done about each:
 *
 * | Failure                                   | Handling                                              |
 * |-------------------------------------------|-------------------------------------------------------|
 * | does not compile                          | rejected at compile time, reason recorded             |
 * | matches everything / almost everything    | rejected — it would tokenise the whole message        |
 * | backtracks catastrophically (ReDoS)       | heuristically rejected, and **bounded** at match time |
 *
 * The third is the interesting one. Deciding whether a regex backtracks
 * exponentially is not something a validator can do in general, so this class does
 * not pretend to: it rejects the shapes that are cheap to recognise (a quantified
 * group whose body is itself unbounded — `(a+)+`, `(\d*)*`) and leaves the actual
 * guarantee to `BoundedPcre`, which caps how many backtracking steps PCRE will
 * spend before giving up. A pattern that slips past the heuristic therefore costs a
 * bounded amount of work and then stops contributing, rather than pinning a worker.
 *
 * **Nothing here can fail a message.** A rejected pattern is skipped and reported;
 * the built-in detectors and the tenant's remaining patterns still run. The
 * alternative — refusing to redact, or nulling all content, because one line of
 * tenant configuration is wrong — would turn a settings typo into an outage or a
 * leak.
 *
 * Patterns are configured as **bodies**, not as delimited regexes: `\d{4}-[A-Z]{3}`,
 * not `/\d{4}-[A-Z]{3}/i`. Delimiters and flags are chosen here, so an operator
 * cannot append modifiers of their own (`e`-style evaluation is long gone, but `x`,
 * `S`, and `A` all change matching in ways the scanner's contract does not allow).
 */
final class TenantPatternCompiler
{
    public const int DEFAULT_MAX_LENGTH = 200;

    public const int DEFAULT_MAX_PATTERNS = 16;

    public const int DEFAULT_BACKTRACK_LIMIT = 100_000;

    /**
     * A deliberately ordinary sentence, used to measure how much of a benign message
     * a pattern would swallow. `.`, `\w*.`, and `[\s\S]+` all compile, none of them
     * match the empty string, and every one of them would replace a whole
     * conversation with tokens.
     */
    private const string CANARY = 'Order 1042 shipped to Mumbai on Monday, thanks for shopping with us!';

    /**
     * Fraction of the canary a pattern may cover before it counts as over-broad.
     */
    private const float MAX_CANARY_COVERAGE = 0.5;

    /**
     * Delimiters tried in order; the first one absent from the pattern body wins, so
     * an operator never has to escape ours.
     *
     * @var list<string>
     */
    private const array DELIMITERS = ['/', '#', '~', '%', '!', '@', ';', ',', '|', '=', '`'];

    public function __construct(
        private readonly int $maxLength = self::DEFAULT_MAX_LENGTH,
        private readonly int $maxPatterns = self::DEFAULT_MAX_PATTERNS,
        private readonly int $backtrackLimit = self::DEFAULT_BACKTRACK_LIMIT,
    ) {}

    /**
     * Compile what can be compiled; report the rest.
     *
     * @param  list<string>  $sources
     */
    public function compile(array $sources): PatternCompilation
    {
        $patterns = [];
        $rejections = [];

        foreach ($sources as $source) {
            $body = trim($source);

            if (count($patterns) >= $this->maxPatterns) {
                $rejections[$body === '' ? $source : $body] = 'too_many_patterns';

                continue;
            }

            $reason = $this->validate($source);

            if ($reason !== null) {
                $rejections[$body === '' ? $source : $body] = $reason;

                continue;
            }

            $delimited = $this->delimit($body);

            // `validate()` has already established this; the guard is here so the
            // types hold without an assertion that could be compiled out.
            if ($delimited === null) {
                $rejections[$body] = 'undelimitable';

                continue;
            }

            $patterns[] = new CompiledPiiPattern($body, $delimited.'u', $delimited);
        }

        return new PatternCompilation($patterns, $rejections);
    }

    /**
     * Why this pattern cannot be used, or `null` if it can.
     *
     * Reason codes are stable strings so a panel (task 4.5's settings screens) can
     * translate them; they are never the raw PCRE error, which leaks internals and
     * changes between PHP versions.
     */
    public function validate(string $source): ?string
    {
        $body = trim($source);

        if ($body === '') {
            return 'empty';
        }

        if (strlen($body) > $this->maxLength) {
            return 'too_long';
        }

        // Recursion, subroutine calls, conditionals, and callouts: each of them can
        // make matching cost more than the backtrack limit accounts for, and none of
        // them is needed to describe an identifier's shape.
        if (preg_match('/\(\?(?:R\)|\d|&|P>|C|\()/', $body) === 1) {
            return 'unsupported_construct';
        }

        if (self::hasNestedQuantifier($body)) {
            return 'nested_quantifier';
        }

        $delimited = $this->delimit($body);

        if ($delimited === null) {
            return 'undelimitable';
        }

        return BoundedPcre::within($this->backtrackLimit, function () use ($delimited): ?string {
            // Both compiled forms must work: the scanner falls back to the non-`u`
            // form for text that is not valid UTF-8, and a pattern that only compiles
            // one way would silently stop redacting there.
            foreach ([$delimited.'u', $delimited] as $pattern) {
                if (@preg_match($pattern, '') === false) {
                    return 'invalid';
                }
            }

            // A pattern that matches the empty string matches at every offset:
            // `.*`, `\d*`, `(x)?`. Applied to a message it would replace the whole
            // thing with tokens.
            if (@preg_match($delimited.'u', '') === 1) {
                return 'matches_everything';
            }

            $coverage = $this->coverage($delimited.'u', self::CANARY);

            if ($coverage === null) {
                return 'invalid';
            }

            return $coverage > self::MAX_CANARY_COVERAGE ? 'over_broad' : null;
        });
    }

    /**
     * How much of a benign sentence the pattern claims, as a fraction of its length.
     */
    private function coverage(string $pattern, string $canary): ?float
    {
        $matched = @preg_match_all($pattern, $canary, $matches, PREG_OFFSET_CAPTURE);

        if ($matched === false) {
            return null;
        }

        $covered = 0;

        /** @var array<int, array{0: string, 1: int}> $whole */
        $whole = $matches[0] ?? [];

        foreach ($whole as $match) {
            $covered += strlen($match[0]);
        }

        return strlen($canary) === 0 ? 0.0 : $covered / strlen($canary);
    }

    /**
     * Wrap the body in a delimiter it does not itself contain, inside a non-capturing
     * group so that alternation at the top level (`a|b`) cannot escape the wrapper.
     */
    private function delimit(string $body): ?string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (! str_contains($body, $delimiter)) {
                return $delimiter.'(?:'.$body.')'.$delimiter;
            }
        }

        return null;
    }

    /**
     * The cheap half of the ReDoS defence: is there a quantified group whose body is
     * itself unbounded?
     *
     * That shape — `(a+)+`, `(\d*)*`, `(x|xx)+` with an inner `+` — is the engine of
     * every classic catastrophic-backtracking example, because it makes the number of
     * ways to split the same text grow exponentially. Recognising it is a heuristic
     * with false negatives in both directions, which is why `BoundedPcre` exists;
     * rejecting it here just turns a slow pattern into an immediate, explainable
     * error.
     *
     * Character classes are skipped (a `+` inside `[...]` is a literal) and escapes
     * are honoured (`\(` is not a group).
     */
    private static function hasNestedQuantifier(string $body): bool
    {
        $length = strlen($body);

        /** @var list<bool> $stack whether each open group's body holds an unbounded quantifier */
        $stack = [];
        $inClass = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];

            if ($character === '\\') {
                $index++;

                continue;
            }

            if ($inClass) {
                if ($character === ']') {
                    $inClass = false;
                }

                continue;
            }

            if ($character === '[') {
                $inClass = true;

                continue;
            }

            if ($character === '(') {
                $stack[] = false;

                continue;
            }

            if ($character === ')') {
                $bodyUnbounded = array_pop($stack) ?? false;
                $quantifier = self::quantifierAt($body, $index + 1);
                $quantifiedUnbounded = $quantifier !== null && $quantifier['unbounded'];

                if ($bodyUnbounded && $quantifiedUnbounded) {
                    return true;
                }

                // Whatever the group contained is part of the enclosing group's body.
                if (($bodyUnbounded || $quantifiedUnbounded) && $stack !== []) {
                    $stack[count($stack) - 1] = true;
                }

                if ($quantifier !== null) {
                    $index += $quantifier['length'];
                }

                continue;
            }

            $quantifier = self::quantifierAt($body, $index);

            if ($quantifier === null) {
                continue;
            }

            if ($quantifier['unbounded'] && $stack !== []) {
                $stack[count($stack) - 1] = true;
            }

            $index += $quantifier['length'] - 1;
        }

        return false;
    }

    /**
     * The quantifier starting at `$index`, if any: how many bytes it occupies and
     * whether it has no upper bound.
     *
     * @return array{length: int, unbounded: bool}|null
     */
    private static function quantifierAt(string $body, int $index): ?array
    {
        if ($index >= strlen($body)) {
            return null;
        }

        $character = $body[$index];

        if ($character === '*' || $character === '+') {
            $length = 1;

            // Lazy (`*?`) and possessive (`*+`) suffixes are part of the quantifier.
            if (isset($body[$index + 1]) && ($body[$index + 1] === '?' || $body[$index + 1] === '+')) {
                $length = 2;
            }

            return ['length' => $length, 'unbounded' => true];
        }

        if ($character === '?') {
            return ['length' => 1, 'unbounded' => false];
        }

        if ($character !== '{') {
            return null;
        }

        if (preg_match('/^\{(\d*)(,?)(\d*)\}/', substr($body, $index), $matches) !== 1) {
            return null;
        }

        return [
            'length' => strlen($matches[0]),
            // `{2,}` is unbounded; `{2}` and `{2,5}` are not.
            'unbounded' => $matches[2] === ',' && $matches[3] === '',
        ];
    }
}
