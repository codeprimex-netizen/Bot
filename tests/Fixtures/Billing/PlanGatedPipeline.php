<?php

declare(strict_types=1);

namespace Tests\Fixtures\Billing;

use App\Models\Tenant;
use App\Services\Tenancy\PlanGate;

/**
 * A minimal resolution pipeline, written the way `PlanGate`'s docblock says the real one
 * (task 11.3) must consult it: ask the gate first, and on a refusal `CONTINUE` to the
 * next stage — Algorithm 1's skip branch.
 *
 * Test double only, and deliberately thin. It exists because Property 7 has two halves —
 * *"the feature is inaccessible **and** its pipeline stage is skipped"* — and the second
 * half needs a pipeline to be a property about. The stages it runs are spies
 * (`PlanGatedStage`) whose invocations land in a `StageLedger`, so the property is
 * asserted on what ran rather than on anything this class reports.
 *
 * Note what is **not** here: no list of skipped stages, no result object with a `skipped`
 * flag. A flag like that is exactly what a test must not trust, so this class does not
 * offer one to be tempted by.
 */
final readonly class PlanGatedPipeline
{
    /**
     * The name of the ungated tail stage — the one that must run for every tenant on
     * every plan, including a tenant with no plan at all. Gating decides which stages a
     * tenant gets, never whether it gets an answer.
     */
    public const string FALLBACK = 'fallback';

    /**
     * @param  list<PlanGatedStage>  $stages
     */
    public function __construct(private PlanGate $gate, private array $stages) {}

    /**
     * The first answer a stage the tenant is licensed for produces, or null.
     */
    public function run(Tenant $tenant): ?string
    {
        foreach ($this->stages as $stage) {
            $feature = $stage->feature;

            // The gate is consulted *before* the stage is constructed into action: a
            // stage that ran and had its answer discarded has already called the
            // provider and spent the money, so "skipped" can only mean "not entered".
            if ($feature !== null && $this->gate->denies($tenant, $feature)) {
                continue;
            }

            $reply = $stage->handle($tenant);

            if ($reply !== null) {
                return $reply;
            }
        }

        return null;
    }
}
