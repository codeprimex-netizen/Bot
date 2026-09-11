<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * Step 2 of design § AI 1.3's layered defence: *"an input classifier flags
 * prompt-injection patterns"* (Req 13.8 / B4).
 *
 * ## The contract, and the one rule every implementation obeys
 *
 * A classifier answers "which injection patterns are present in this text?" and
 * nothing else — it does not decide policy (that is `AbuseSignal::action()`), does not
 * record anything (that is `AbuseRecorder`), and does not throw for suspicious input.
 * It receives a `NormalizedText` rather than a raw string so that every implementation
 * sees the same set of views, and so no implementation can be weaker simply because it
 * forgot to fold look-alikes or decode base64.
 *
 * **An implementation that cannot answer must throw, never return `clean()`.** The
 * distinction is the whole fail-closed posture: `LayeredGuardrail` turns a thrown
 * classifier into `AbuseSignal::ClassifierUnavailable`, which blocks, whereas a
 * "clean" answer from a classifier that did not actually run would silently let an
 * unexamined payload through — and it would look identical, in the logs and in the
 * abuse feed, to a genuinely clean message.
 *
 * ## Implementations
 *
 * - `HeuristicInjectionClassifier` — deterministic, dependency-free, always available.
 *   This is the mandatory one.
 * - `CompositeInjectionClassifier` — runs the mandatory classifier plus any optional
 *   ones (e.g. a future model-backed classifier from task 15.x), and degrades to the
 *   deterministic verdict — never to "allow" — when an optional one fails.
 */
interface InjectionClassifier
{
    /**
     * Classify already-normalized text.
     *
     * @throws \Throwable when the classifier could not run; the caller fails closed
     */
    public function classify(NormalizedText $text): Classification;
}
