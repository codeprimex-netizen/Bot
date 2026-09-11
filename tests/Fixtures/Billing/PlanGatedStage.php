<?php

declare(strict_types=1);

namespace Tests\Fixtures\Billing;

use App\Enums\PlanFeature;
use App\Models\Tenant;

/**
 * A spy resolution stage: it declares the `PlanFeature` it needs, and the only thing it
 * does is leave evidence that it ran.
 *
 * Test double only. The production stages this stands in for (`KeywordTriggerStage`,
 * `IntentFaqStage`, `ActiveFlowStage`, `LlmReplyStage`, `LiveAgentStage`) arrive with
 * Phase B tasks 11.3–16.x, and `ResolverStage` / `ConversationEngine` do not exist yet —
 * `app/Services/Chatbot` currently holds only the RAG vector seam. Property 7 is about
 * the *contract* between the gate and a stage that declares a feature, and that contract
 * is expressible now: a stage names a feature, and a stage whose feature is not in the
 * plan is never entered. When the real pipeline lands, this double is what its stages are
 * expected to behave like.
 */
final readonly class PlanGatedStage
{
    public function __construct(
        public string $name,
        public ?PlanFeature $feature,
        public bool $terminal,
        public int $cost,
        private StageLedger $ledger,
    ) {}

    /**
     * Do the stage's work, and answer the message when this stage is the one that
     * handles it.
     *
     * The ledger entry is written **first**, before any decision about the return value.
     * That ordering is the whole point: "skipped" must mean this method never ran, so an
     * invocation whose result is discarded has to be just as visible as one whose result
     * is used.
     */
    public function handle(Tenant $tenant): ?string
    {
        $this->ledger->record($this->name, $this->cost);

        return $this->terminal ? $this->name : null;
    }
}
