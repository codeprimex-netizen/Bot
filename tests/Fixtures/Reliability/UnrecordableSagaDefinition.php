<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Services\Reliability\SagaContext;
use App\Services\Reliability\SagaDefinition;
use App\Services\Reliability\SagaStepDefinition;
use App\Services\Reliability\SagaStepResult;
use stdClass;

/**
 * A saga whose second step's forward action lands a real effect and then returns a result
 * the idempotency ledger cannot store (an object among its contributed state).
 *
 * A definition bug, and the point is what the orchestrator does with it: the effect
 * happened, so the step must end up `DONE` and be **compensated**, never marked `FAILED` —
 * a `FAILED` step is never compensated, which is how a bookkeeping error would turn into
 * the orphaned side effect Property 18 forbids.
 *
 * The step is an anonymous class so the double stays in one file; it needs no identity of
 * its own.
 */
final class UnrecordableSagaDefinition implements SagaDefinition
{
    public const string TYPE = 'ORDER_FULFILLMENT';

    /**
     * @var list<string>
     */
    public const array STEPS = ['reserve_items', 'create_payment_link'];

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return list<SagaStepDefinition>
     */
    public function steps(): array
    {
        return [
            new SpySagaStep(self::STEPS[0]),
            new class implements SagaStepDefinition
            {
                public function name(): string
                {
                    return UnrecordableSagaDefinition::STEPS[1];
                }

                public function forward(SagaContext $context): SagaStepResult
                {
                    $handle = ['reservation' => $this->name().'-handle'];

                    // The effect lands first, exactly as a real step's would.
                    SagaWorld::reserve($this->name(), $handle);

                    return SagaStepResult::compensateWith($handle)
                        ->contributing(['unstorable' => new stdClass]);
                }

                public function compensate(SagaContext $context): void
                {
                    SagaWorld::release($this->name());
                }
            },
        ];
    }

    public static function register(): void
    {
        config()->set('wa.reliability.saga.definitions', [self::class]);
    }
}
