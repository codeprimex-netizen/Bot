<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * The prompt guardrail of design § AI 1.3 (Req 13.8 / B4; Req 32.7 / NFR3):
 *
 * ```php
 * final class Guardrail {
 *     public function inspectInput(string $text): GuardVerdict;
 *     public function inspectOutput(string $reply, string $systemPrompt): GuardVerdict;
 * }
 * ```
 *
 * An interface rather than design's `final class`, for one reason: the LLM stage (task
 * 15.2) and the panel screens must be able to depend on the guardrail without depending
 * on which classifiers are configured — and the platform must be able to add a
 * model-backed classifier later without touching a single call site. The layered
 * implementation is `LayeredGuardrail`; the design's four layers live in it.
 *
 * ## The two defaults, stated once
 *
 * | Direction | Default when the platform cannot decide | Why |
 * |---|---|---|
 * | **input** | `BLOCK` (fail closed) | inbound text is attacker-controlled; refusing costs one unanswered message, allowing hands an unexamined instruction payload to a model that holds tenant data |
 * | **output** | `BLOCK`, i.e. suppress (fail safe) | the platform is the one about to speak; an unvalidatable reply is not sent, and the suppression is recorded |
 *
 * Neither direction has a "when in doubt, allow" path, and neither has a configuration
 * flag that produces one. `wa.security.guardrail` tunes thresholds, patterns, and how
 * much is recorded — never whether inspection happens.
 *
 * ## Recording is the guardrail's job, not the caller's
 *
 * Every `FLAG` and `BLOCK` is written to `abuse_events` before the verdict is returned
 * (Req 13.8: *"record an entry in the abuse-events store"*), best-effort, and never in a
 * way that can fail the request being protected (`AbuseRecorder`). A caller that ignores
 * the verdict still leaves the trail.
 */
interface Guardrail
{
    /**
     * Inspect untrusted inbound text before it reaches a model.
     *
     * @param  GuardContext|null  $context  session/conversation attribution; enables the
     *                                      kill-switch check and the per-conversation burst limit
     */
    public function inspectInput(string $text, ?GuardContext $context = null): GuardVerdict;

    /**
     * Validate a model reply against the system prompt it was produced under.
     *
     * @param  PromptFence|null  $fence  the fence the prompt used, when the caller has it
     */
    public function inspectOutput(
        string $reply,
        string $systemPrompt,
        ?GuardContext $context = null,
        ?PromptFence $fence = null,
    ): GuardVerdict;

    /**
     * `inspectInput()` for callers that have no "carry on without it" branch.
     *
     * @throws \App\Exceptions\Security\PromptInjectionBlockedException when the text is blocked
     * @throws \App\Exceptions\Security\SessionKilledException when the session is under a kill-switch
     */
    public function assertInput(string $text, ?GuardContext $context = null): GuardVerdict;

    /**
     * `inspectOutput()` for the same kind of caller.
     *
     * @throws \App\Exceptions\Security\PromptInjectionBlockedException when the reply is suppressed
     */
    public function assertOutput(
        string $reply,
        string $systemPrompt,
        ?GuardContext $context = null,
        ?PromptFence $fence = null,
    ): GuardVerdict;
}
