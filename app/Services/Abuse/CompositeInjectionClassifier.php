<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\AbuseSignal;
use Throwable;

/**
 * The deterministic classifier plus any number of optional ones, combined so that
 * adding a classifier can only ever make a verdict **stricter** (Req 13.8 / B4).
 *
 * ## The degradation rule, which is the reason this class exists
 *
 * ```
 * mandatory classifier throws  → rethrown → LayeredGuardrail records CLASSIFIER_UNAVAILABLE → BLOCK
 * optional classifier throws   → swallowed → CLASSIFIER_DEGRADED (a flag) + the deterministic verdict
 * optional classifier is clean → the deterministic verdict stands unchanged
 * ```
 *
 * An optional classifier — the obvious candidate being a model-backed one, once the
 * LLM layer lands in task 15.x — is an *additional* opinion. It can add signals; it
 * cannot remove them, and it cannot cause a "clean" answer, because its failure path
 * degrades to the deterministic classifier rather than to "allow". That is the
 * property design § AI 1.3 needs and the one a naive `try { $llm->classify() } catch {
 * return clean(); }` gets exactly backwards.
 *
 * The mandatory classifier is passed as its own constructor argument rather than as the
 * first element of the list, so "the deterministic classifier is present" is not a
 * configuration question. Emptying `wa.security.guardrail.classifiers` removes every
 * optional classifier and leaves the mandatory one running.
 */
final class CompositeInjectionClassifier implements InjectionClassifier
{
    /**
     * @param  list<InjectionClassifier>  $optional
     */
    public function __construct(
        private readonly InjectionClassifier $mandatory,
        private readonly array $optional = [],
    ) {}

    public function classify(NormalizedText $text): Classification
    {
        // Not wrapped: a failure here must reach the guardrail, which fails closed.
        $classification = $this->mandatory->classify($text);

        foreach ($this->optional as $classifier) {
            try {
                $classification = $classification->merge($classifier->classify($text));
            } catch (Throwable) {
                // Deliberately swallowed, and deliberately *recorded*: the deterministic
                // verdict above is complete on its own, so this is a degradation to note,
                // not a reason to refuse a customer's message. The exception itself is not
                // logged here because it may quote the text it was classifying.
                $classification = $classification->with(
                    AbuseSignal::ClassifierDegraded,
                    'composite.unavailable.'.class_basename($classifier),
                );
            }
        }

        return $classification;
    }
}
