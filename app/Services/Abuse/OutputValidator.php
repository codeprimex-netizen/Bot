<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * Step 4 of design § AI 1.3's layered defence: *"output validation rejects replies that
 * leak the system prompt or violate policy"* (Req 13.8 / B4; Req 32.7 / NFR3).
 *
 * ## The contract
 *
 * A validator answers "what is wrong with this reply?" and nothing else: it does not
 * decide policy (`AbuseSignal::action()` does), does not record (`AbuseRecorder` does),
 * and — like `InjectionClassifier` — **must throw rather than return `clean()`** when it
 * could not do its job. `LayeredGuardrail` turns a throw into a block, which on this side
 * means the reply is *suppressed*: an unvalidatable reply is not sent, and the suppression
 * is recorded.
 *
 * An interface rather than one final class so the fail-safe path is provable (a test can
 * install a validator that fails) and so a later policy engine — a moderation endpoint, a
 * tenant-authored rule set — can be added without touching `LayeredGuardrail`. The shipped
 * implementation is `PolicyOutputValidator`.
 */
interface OutputValidator
{
    /**
     * Validate a model reply against the system prompt it was produced under.
     *
     * @param  PromptFence|null  $fence  the fence the prompt used, when the caller has it
     *
     * @throws \Throwable when the reply could not be validated; the caller suppresses it
     */
    public function validate(string $reply, string $systemPrompt, ?PromptFence $fence = null): Classification;
}
