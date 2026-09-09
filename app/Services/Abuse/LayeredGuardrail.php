<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;
use App\Exceptions\Security\PromptInjectionBlockedException;
use App\Support\Cache\VersionedCache;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The four-layer guardrail of design § AI 1.3, assembled (Req 13.8 / B4;
 * Req 32.7 / NFR3).
 *
 * | Layer | Where it lives | What it stops |
 * |---|---|---|
 * | 1. instruction hierarchy | `InstructionHierarchy` + `InstructionLayer` | user content being read as an instruction |
 * | 2. input classifier | `CompositeInjectionClassifier` → `HeuristicInjectionClassifier` | override / role-reassignment / probe / exfil phrasing, encoded or obfuscated |
 * | 3. delimiter fencing | `PromptFence` | escaping the untrusted-data block |
 * | 4. output validation | `OutputValidator` | system-prompt leakage, fence leakage, credential-shaped values, policy breaches |
 *
 * This class is the ordering and the policy around those four: the kill-switch check
 * that comes before all of them, the fail-closed defaults, the `abuse_events` write, and
 * the per-conversation rate limit design asks for (*"Injection attempts are logged to
 * `abuse_events` and can trip a per-conversation rate limit"*).
 *
 * ## Order of `inspectInput`, and why it is this order
 *
 * ```
 * 1. kill-switch          — a killed session is refused before any work is done (one cache read)
 * 2. normalize            — decides whether classification is even possible
 * 3. classify             — deterministic first, optional classifiers can only add
 * 4. record               — FLAG/BLOCK to abuse_events, before the caller is told anything
 * 5. burst limit          — repeated blocks in one conversation trip a bounded kill-switch
 * ```
 *
 * The kill-switch is first because it is the cheapest and the most absolute: a session an
 * operator has stopped must not consume classifier cycles, and it must be refused even
 * for text that is perfectly clean. Recording precedes the return so that a caller cannot
 * suppress the trail by ignoring the verdict.
 *
 * ## Fail-closed input, fail-safe output — implemented, not aspirational
 *
 * - A classifier that **throws** becomes `CLASSIFIER_UNAVAILABLE` → `BLOCK`. There is no
 *   `catch` in this class that returns a clean verdict.
 * - Text that is not decodable UTF-8, or longer than
 *   `wa.security.guardrail.max_input_chars`, becomes `UNDECODABLE` / `OVERSIZED` →
 *   `BLOCK`, because in both cases "no detector matched" would mean "no detector looked".
 * - On output, a validator that throws becomes `BLOCK` → the reply is **suppressed**, and
 *   the suppression is recorded. Silence with no record is the one outcome the design
 *   forbids, so the record is written on the same path as the suppression.
 *
 * The deliberate exception: **empty text is allowed.** A caption-less image or a sticker
 * arrives as an empty body, and treating "nothing to inspect" as "could not inspect"
 * would refuse ordinary traffic while adding no protection — there is no payload in an
 * empty string.
 */
final class LayeredGuardrail implements Guardrail
{
    public function __construct(
        private readonly InjectionClassifier $classifier,
        private readonly OutputValidator $outputValidator,
        private readonly AbuseRecorder $recorder,
        private readonly SessionKillSwitch $killSwitch,
        private readonly TextNormalizer $normalizer,
        private readonly VersionedCache $burstCache,
        private readonly bool $recordFlags = true,
        private readonly int $burstWindowSeconds = 900,
        private readonly int $burstBlockLimit = 5,
        private readonly int $autoKillSeconds = 3600,
    ) {}

    public function inspectInput(string $text, ?GuardContext $context = null): GuardVerdict
    {
        $context = ($context ?? GuardContext::none())->on('guardrail.input');
        $fence = PromptFence::withNonce('probe');
        $normalized = $this->normalizer->normalize($text);

        // Layer 0: an operator-stopped session is refused whatever the text says.
        if ($context->sessionKey !== null && $this->killSwitch->isKilled($context->sessionKey)) {
            return $this->recordAndReturn(
                GuardVerdict::from(
                    Classification::clean()->with(AbuseSignal::SessionKilled, 'kill_switch.engaged'),
                    AbuseVector::SessionRisk,
                    'guardrail.input',
                    $normalized,
                    '',
                ),
                $context,
            );
        }

        $classification = $this->classify($normalized);

        $verdict = GuardVerdict::from(
            $classification,
            AbuseVector::PromptInjection,
            'guardrail.input',
            $normalized,
            // Sanitized whatever the verdict: a caller that forwards `text()` after a
            // mere flag still cannot forward a delimiter escape.
            $fence->sanitize($text),
        );

        $verdict = $this->recordAndReturn($verdict, $context);

        if ($verdict->blocks()) {
            $this->noteBlock($context, $verdict);
        }

        return $verdict;
    }

    public function inspectOutput(
        string $reply,
        string $systemPrompt,
        ?GuardContext $context = null,
        ?PromptFence $fence = null,
    ): GuardVerdict {
        $context = ($context ?? GuardContext::none())->on('guardrail.output');
        $normalized = $this->normalizer->normalize($reply);

        try {
            $classification = $this->outputValidator->validate($reply, $systemPrompt, $fence);
        } catch (Throwable) {
            // Fail safe: an unvalidatable reply is not sent. Recorded below like any other
            // suppression, so this never becomes a silent drop.
            $classification = Classification::clean()
                ->with(AbuseSignal::OutputPolicyViolation, 'output.validator_unavailable');
        }

        return $this->recordAndReturn(
            GuardVerdict::from($classification, AbuseVector::OutputPolicy, 'guardrail.output', $normalized, $reply),
            $context,
        );
    }

    public function assertInput(string $text, ?GuardContext $context = null): GuardVerdict
    {
        // Before inspecting: a killed session gets the typed refusal (and its recorded
        // attempt) rather than a generic content block, because the two are different
        // operational facts.
        if ($context?->sessionKey !== null) {
            $this->killSwitch->assertUsable($context->sessionKey, $context->on('guardrail.input'));
        }

        $verdict = $this->inspectInput($text, $context);

        if ($verdict->blocks()) {
            throw PromptInjectionBlockedException::onInput($verdict);
        }

        return $verdict;
    }

    public function assertOutput(
        string $reply,
        string $systemPrompt,
        ?GuardContext $context = null,
        ?PromptFence $fence = null,
    ): GuardVerdict {
        $verdict = $this->inspectOutput($reply, $systemPrompt, $context, $fence);

        if ($verdict->blocks()) {
            throw PromptInjectionBlockedException::onOutput($verdict);
        }

        return $verdict;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Classify, converting a classifier failure into the fail-closed signal.
     *
     * The `catch` is wide on purpose — a classifier may fail in any way at all — and it
     * escalates rather than softens: whatever went wrong, the text has not been
     * examined.
     */
    private function classify(NormalizedText $text): Classification
    {
        if ($text->isEmpty() && $text->flags === []) {
            // Nothing to inspect (an image with no caption). Not a failure to classify.
            return Classification::clean();
        }

        try {
            return $this->classifier->classify($text);
        } catch (Throwable) {
            return Classification::clean()->with(AbuseSignal::ClassifierUnavailable, 'classifier.threw');
        }
    }

    /**
     * Write the abuse event for a recordable verdict, then hand the verdict back.
     */
    private function recordAndReturn(GuardVerdict $verdict, GuardContext $context): GuardVerdict
    {
        if (! $verdict->action->isRecordable()) {
            return $verdict;
        }

        if ($verdict->action === GuardAction::Flag && ! $this->recordFlags) {
            // A platform may decide flags are noise; a *block* is never optional, which is
            // why this test is on the flag branch only.
            return $verdict;
        }

        $this->recorder->record(AbuseEventDraft::fromVerdict($verdict, $context));

        return $verdict;
    }

    /**
     * The per-conversation rate limit of design § AI 1.3.
     *
     * Counts blocks in a fixed window and, on crossing the threshold, engages a
     * **bounded** kill-switch for the session. Bounded because this arm is automatic and
     * therefore reachable by an attacker who wants a tenant's session silenced: an
     * expiring kill contains the damage of both the attack and a false positive, while an
     * indefinite one remains an operator decision.
     */
    private function noteBlock(GuardContext $context, GuardVerdict $verdict): void
    {
        $burstKey = $context->burstKey();

        if ($burstKey === null || $this->burstBlockLimit <= 0) {
            return;
        }

        $window = max(1, $this->burstWindowSeconds);
        $bucket = intdiv(Carbon::now()->getTimestamp(), $window);
        $key = sprintf('burst:%s:%d', $burstKey, $bucket);

        $count = $this->burstCache->get($key);
        $count = (is_int($count) ? $count : 0) + 1;

        $this->burstCache->put($key, $count);

        if ($count < $this->burstBlockLimit) {
            return;
        }

        $evidence = [
            'blocks' => $count,
            'threshold' => $this->burstBlockLimit,
            'window_seconds' => $window,
            'conversation' => $context->conversationKey === null ? null : substr(hash('sha256', $context->conversationKey), 0, 32),
            'last_signals' => $verdict->signalValues(),
        ];

        $this->recorder->record(new AbuseEventDraft(
            vector: AbuseVector::SessionRisk,
            action: GuardAction::Flag,
            signals: [AbuseSignal::ConversationBlockBurst],
            evidence: $evidence,
            surface: 'guardrail.input',
            sessionKey: $context->sessionKey,
            conversationKey: $context->conversationKey,
        ));

        if ($context->sessionKey !== null && $this->autoKillSeconds > 0) {
            $this->killSwitch->killForBurst($context->sessionKey, $this->autoKillSeconds, $evidence);
        }
    }
}
