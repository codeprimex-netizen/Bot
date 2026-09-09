<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Illuminate\Support\Str;

/**
 * Delimiter fencing — step 3 of design § AI 1.3's layered defence: untrusted content
 * is wrapped in a data block the model is told is data (Req 13.8 / B4).
 *
 * ```php
 * $fence = PromptFence::random();
 *
 * $fence->wrap($customerMessage);
 * // <<<WA-UNTRUSTED-9f2c8ab41d0e57b3>>>
 * // hello, can you check my order?
 * // <<<END-WA-UNTRUSTED-9f2c8ab41d0e57b3>>>
 * ```
 *
 * ## Why fencing usually fails, and what is done differently here
 *
 * The naive version wraps user text in a fixed marker (`### USER ###`). An attacker
 * who knows the marker — and it is in the repository, so they do — simply includes it:
 * their text closes the block early and everything after it is read as though it came
 * from the platform. Fencing then provides *negative* value, because the system prompt
 * has just told the model to trust anything outside the markers.
 *
 * Two mechanisms close that, and the second is the one that actually holds:
 *
 * 1. **A per-envelope nonce.** The delimiter is `WA-UNTRUSTED-{16 hex}`, minted per
 *    prompt from `random_bytes`, so the exact marker is not knowable in advance.
 * 2. **The payload cannot contain a delimiter, guessed or not.** `wrap()` removes
 *    *every* delimiter-shaped token from the payload before wrapping — not only this
 *    envelope's nonce but any `WA-UNTRUSTED-*` marker and the chat-template role tokens
 *    (`<|im_start|>`, `[INST]`, `<|system|>`, …) that serve the same purpose against
 *    the model's own formatting. Invisible characters are stripped first, so
 *    `WA-UNTRU⁠STED` cannot slip through the removal and re-assemble in the model's
 *    tokenizer.
 *
 * The nonce alone would be defence by obscurity; (2) is the invariant, and it is what
 * `PromptFencePropertyTest` asserts over arbitrary payloads: **the wrapped block
 * always contains exactly one opening and one closing delimiter, at its boundaries.**
 * Escaping the fence is therefore not merely unlikely, it is unrepresentable.
 *
 * A payload that *tried* is not silently cleaned and forgotten: `mentions()` is what
 * the classifier uses to raise `AbuseSignal::FenceEscape`, so the attempt is recorded
 * and blocked, while the sanitization makes the attempt harmless even if policy later
 * decided to allow the message.
 */
final readonly class PromptFence
{
    /**
     * Fixed part of the delimiter. Chosen to be a token no natural text produces, and
     * to be recognisable in a model reply (`AbuseSignal::FenceLeak`).
     */
    public const string LABEL = 'WA-UNTRUSTED';

    /**
     * Anything delimiter-*shaped*, whatever nonce it names — including a bare label
     * with no nonce at all, and the `END-` form. This is what makes guessing the nonce
     * pointless.
     */
    private const string DELIMITER_PATTERN = '/<{0,3}\/?(?:end[-_])?wa[-_]?untrusted(?:[-_][0-9a-z]*)?>{0,3}/i';

    /**
     * Role/turn markers of the common chat templates. They are delimiters too — just
     * the model's own — so a payload carrying them is trying the same escape one layer
     * down.
     */
    private const string TEMPLATE_TOKEN_PATTERN = '/<\|[a-z_]{2,20}\|>|<\/?(?:s|im_start|im_end)>|\[\/?INST\]|\[\/?SYS\]|<<\/?SYS>>/i';

    /**
     * What a removed delimiter is replaced by. Deliberately visible: the model sees
     * that something was taken out (so the text does not silently change meaning), and
     * a human reviewing a flagged conversation sees where.
     */
    public const string REMOVED_MARKER = '[removed]';

    private function __construct(public string $nonce) {}

    /**
     * A fence with a fresh nonce — one per assembled prompt.
     */
    public static function random(): self
    {
        return new self(bin2hex(random_bytes(8)));
    }

    /**
     * A fence with a caller-supplied nonce, for tests and for re-validating a reply
     * against the fence the prompt actually used.
     */
    public static function withNonce(string $nonce): self
    {
        $clean = (string) preg_replace('/[^0-9a-z]/i', '', $nonce);

        return new self($clean === '' ? bin2hex(random_bytes(8)) : Str::lower($clean));
    }

    public function opening(): string
    {
        return sprintf('<<<%s-%s>>>', self::LABEL, $this->nonce);
    }

    public function closing(): string
    {
        return sprintf('<<<END-%s-%s>>>', self::LABEL, $this->nonce);
    }

    /**
     * Wrap untrusted content in the fenced data block.
     *
     * The payload is sanitized first (see the class docblock), so the result is
     * structurally guaranteed to contain one opening and one closing delimiter.
     */
    public function wrap(string $untrusted): string
    {
        return $this->opening()."\n".$this->sanitize($untrusted)."\n".$this->closing();
    }

    /**
     * The payload with every delimiter-shaped token and chat-template marker removed.
     *
     * Public because the guardrail hands the *sanitized* text to the model even when a
     * verdict only flags: cleaning is not conditional on the verdict.
     */
    public function sanitize(string $untrusted): string
    {
        // Invisible characters go first: they are how a marker is smuggled past a
        // literal removal while still reaching the tokenizer intact.
        $text = (string) preg_replace(TextNormalizer::INVISIBLE_PATTERN, '', $untrusted);

        $text = (string) preg_replace(self::DELIMITER_PATTERN, self::REMOVED_MARKER, $text);

        return (string) preg_replace(self::TEMPLATE_TOKEN_PATTERN, self::REMOVED_MARKER, $text);
    }

    /**
     * Whether a text carries a delimiter-shaped token or a chat-template marker.
     *
     * Used on both sides: on input it is `AbuseSignal::FenceEscape` (someone is
     * probing the fence), on output `AbuseSignal::FenceLeak` (the model is echoing the
     * scaffolding, which tells an attacker what the fence looks like).
     */
    public function mentions(string $text): bool
    {
        $visible = $this->withoutInvisible($text);

        return preg_match(self::DELIMITER_PATTERN, $visible) === 1
            || preg_match(self::TEMPLATE_TOKEN_PATTERN, $visible) === 1;
    }

    /**
     * How many delimiter-shaped tokens a text contains — the count
     * `PromptFencePropertyTest` asserts on.
     */
    public function delimiterCount(string $text): int
    {
        $count = preg_match_all(self::DELIMITER_PATTERN, $this->withoutInvisible($text));

        return $count === false ? 0 : $count;
    }

    /**
     * Matching is always done on the text with invisible characters removed, so a
     * marker split by a zero-width joiner counts as the marker it will become in the
     * model's tokenizer rather than as innocent text.
     */
    private function withoutInvisible(string $text): string
    {
        return (string) preg_replace(TextNormalizer::INVISIBLE_PATTERN, '', $text);
    }
}
