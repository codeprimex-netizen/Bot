<?php

declare(strict_types=1);

namespace Tests\Fixtures\Billing;

/**
 * The observable side effects of a run of `PlanGatedPipeline` — what actually happened,
 * as opposed to what the pipeline says happened.
 *
 * Property 7's second half ("its pipeline stage is skipped") is the half that is easy to
 * fake: a pipeline can invoke every stage, throw away the answers of the unlicensed
 * ones, and report them as "skipped" in its result. By then the provider call has been
 * made and the money has been spent, so a test that trusted the report would pass over a
 * platform billing tenants for features they had not bought.
 *
 * So the property is asserted against this ledger. A stage writes here **before** it does
 * anything else, which makes an invocation impossible to hide: the only way a stage name
 * is absent is that the method was never entered.
 */
final class StageLedger
{
    /**
     * Stage names in invocation order.
     *
     * @var list<string>
     */
    private array $invocations = [];

    /**
     * What the run spent — the stand-in for a provider call that has already been paid
     * for by the time a discarded result is discarded.
     */
    private int $spendCents = 0;

    public function record(string $stage, int $cents): void
    {
        $this->invocations[] = $stage;
        $this->spendCents += $cents;
    }

    /**
     * @return list<string>
     */
    public function invocations(): array
    {
        return $this->invocations;
    }

    public function spendCents(): int
    {
        return $this->spendCents;
    }
}
