<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;

/**
 * The shipped `OutputValidator`: system-prompt-leak detection, fence-leak detection,
 * credential shapes, and configured policy patterns (design § AI 1.3 step 4;
 * Req 13.8 / B4; Req 32.7 / NFR3).
 *
 * ## System-prompt leak detection: word shingles, not similarity
 *
 * The reply is compared against the system prompt by **word shingles**: every window
 * of `shingleWords` consecutive words of the system prompt is looked for, verbatim, in
 * the normalized reply. One match is a leak.
 *
 * Shingles rather than a similarity score because a score has no defensible threshold.
 * A reply that legitimately restates the tenant's business hours is *highly* similar to
 * a system prompt containing those hours, while a reply that leaks one distinctive
 * sentence out of forty scores as barely similar — so any single cosine/Jaccard cutoff
 * is wrong in both directions at once. An eight-word verbatim run, by contrast, is
 * something a model reproduces because it is *copying*: the chance of eight consecutive
 * words coinciding by paraphrase is negligible, and the check is exact, cheap, and
 * explainable ("your reply repeated eight consecutive words of the system prompt").
 *
 * The window is configurable (`wa.security.guardrail.output.leak_shingle_words`) and
 * floored at 4 in code: a two- or three-word window would flag ordinary phrases like
 * "how can I help".
 *
 * ## The other three checks
 *
 * - **fence leak** — the reply contains the untrusted-content delimiters. Harmless in
 *   itself, and a precise map of the fence for the next attempt, so it is suppressed.
 * - **credential-shaped values** — `sk-…`, `ghp_…`, `Bearer …`, long hex/base64 secrets.
 *   These have no business in a customer reply whatever produced them.
 * - **configured policy patterns** — `wa.security.guardrail.output.banned_patterns`,
 *   for platform or tenant policy (a compliance phrase, a competitor mention, a claim
 *   the business may not make).
 *
 * ## Fail safe, which on output means suppress
 *
 * An unvalidatable reply is not sent. `LayeredGuardrail` turns a throw from here into a
 * block, so a validator defect suppresses a reply rather than shipping an unchecked
 * one — the opposite default from `inspectInput`, and for the same reason: on output the
 * platform is the one about to speak.
 */
final class PolicyOutputValidator implements OutputValidator
{
    /**
     * Credential shapes. Deliberately narrow — a false positive here suppresses a
     * customer reply, so each pattern names a real credential format rather than
     * "anything long and random".
     *
     * @var array<string, string>
     */
    private const array SECRET_PATTERNS = [
        'openai_key' => '/\bsk-[A-Za-z0-9_-]{20,}\b/',
        'github_token' => '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
        'slack_token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        'aws_key' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
        'bearer_header' => '/\bbearer\s+[A-Za-z0-9._~+\/-]{24,}={0,2}/i',
        'private_key_block' => '/-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/',
        'jwt' => '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
    ];

    /**
     * @param  list<string>  $bannedPatterns
     */
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly int $shingleWords = 8,
        private readonly array $bannedPatterns = [],
    ) {}

    public static function fromConfig(?TextNormalizer $normalizer = null): self
    {
        $words = config('wa.security.guardrail.output.leak_shingle_words', 8);
        $configured = config('wa.security.guardrail.output.banned_patterns', []);

        $patterns = [];

        if (is_array($configured)) {
            foreach ($configured as $pattern) {
                if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, '') !== false) {
                    $patterns[] = $pattern;
                }
            }
        }

        return new self(
            $normalizer ?? TextNormalizer::fromConfig(),
            is_numeric($words) ? (int) $words : 8,
            $patterns,
        );
    }

    /**
     * Validate a model reply against the system prompt it was produced under.
     *
     * @param  PromptFence|null  $fence  the fence the prompt used, when the caller has it;
     *                                   a nonce-agnostic probe otherwise
     */
    public function validate(string $reply, string $systemPrompt, ?PromptFence $fence = null): Classification
    {
        $normalized = $this->normalizer->normalize($reply);
        $classification = Classification::clean();

        foreach ($normalized->flags as $flag) {
            $classification = $classification->with($flag, 'normalizer.'.strtolower($flag->value));
        }

        if ($this->leaksSystemPrompt($normalized, $systemPrompt)) {
            $classification = $classification->with(AbuseSignal::SystemPromptLeak, 'output.shingle_overlap');
        }

        if (($fence ?? PromptFence::withNonce('probe'))->mentions($reply)) {
            $classification = $classification->with(AbuseSignal::FenceLeak, 'output.fence_delimiter');
        }

        foreach (self::SECRET_PATTERNS as $id => $pattern) {
            if (preg_match($pattern, $reply) === 1) {
                $classification = $classification->with(AbuseSignal::SecretShaped, 'output.secret.'.$id);
            }
        }

        foreach ($this->bannedPatterns as $index => $pattern) {
            if (preg_match($pattern, $normalized->canonical) === 1) {
                $classification = $classification->with(AbuseSignal::OutputPolicyViolation, 'output.policy.'.$index);
            }
        }

        return $classification;
    }

    /**
     * Whether any `shingleWords`-word run of the system prompt appears verbatim in the
     * reply.
     */
    private function leaksSystemPrompt(NormalizedText $reply, string $systemPrompt): bool
    {
        $window = max(4, $this->shingleWords);
        $replyWords = $this->wordsOf($reply->canonical);

        if ($replyWords === []) {
            return false;
        }

        $replyText = ' '.implode(' ', $replyWords).' ';
        $promptWords = $this->words($systemPrompt);

        if (count($promptWords) < $window) {
            // A system prompt shorter than one window cannot be shingled. Comparing what
            // there is *as a whole* keeps the short-prompt case covered instead of
            // silently unchecked.
            return $promptWords !== []
                && str_contains($replyText, ' '.implode(' ', $promptWords).' ');
        }

        $limit = count($promptWords) - $window;

        for ($offset = 0; $offset <= $limit; $offset++) {
            $shingle = implode(' ', array_slice($promptWords, $offset, $window));

            if (str_contains($replyText, ' '.$shingle.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The system prompt as normalized words — the same normalization the reply went
     * through, so a leak cannot hide behind capitalisation or re-wrapping.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        return $this->wordsOf($this->normalizer->normalize($text)->canonical);
    }

    /**
     * Words of an already-canonical string, with punctuation dropped.
     *
     * Punctuation has to go, and on **both** sides: a model that leaks a sentence
     * re-wraps and re-punctuates it constantly ("…internal policy." becomes "…internal
     * policy!"), and comparing punctuated words would let a leak through on a comma. What
     * is compared is the word sequence, which is the thing that was copied.
     *
     * @return list<string>
     */
    private function wordsOf(string $canonical): array
    {
        $letters = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $canonical);

        return array_values(array_filter(
            preg_split('/\s+/u', is_string($letters) ? $letters : $canonical) ?: [],
            static fn (string $word): bool => $word !== '',
        ));
    }
}
