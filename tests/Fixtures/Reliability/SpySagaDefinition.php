<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Services\Reliability\SagaDefinition;
use App\Services\Reliability\SagaStepDefinition;

/**
 * A three-step saga definition over `SpySagaStep`, registered by the orchestrator tests.
 *
 * Its type matches `SagaFactory::ORDER_FULFILLMENT` so the model factories can be used
 * unchanged, and its step names are the first three of
 * `SagaStepFactory::ORDER_FULFILLMENT_STEPS` — the concrete order → payment → fulfilment
 * saga itself is Phase 5 and deliberately does not exist yet, which is what these doubles
 * stand in for.
 */
final class SpySagaDefinition implements SagaDefinition
{
    public const string TYPE = 'ORDER_FULFILLMENT';

    /**
     * @var list<string>
     */
    public const array STEPS = ['reserve_items', 'create_payment_link', 'fulfil_order'];

    /**
     * Step names for the current test, when it needs something other than `STEPS` — an
     * empty list, or a duplicated name, both of which the registry must refuse.
     *
     * @var list<string>|null
     */
    private static ?array $stepNames = null;

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return list<SagaStepDefinition>
     */
    public function steps(): array
    {
        return array_map(
            static fn (string $name): SagaStepDefinition => new SpySagaStep($name),
            self::$stepNames ?? self::STEPS,
        );
    }

    /**
     * Make this the only registered definition — how every orchestrator test starts.
     */
    public static function register(): void
    {
        config()->set('wa.reliability.saga.definitions', [self::class]);
    }

    /**
     * Register this definition with a step list of the test's choosing.
     *
     * @param  list<string>  $names
     */
    public static function withSteps(array $names): void
    {
        self::$stepNames = $names;

        self::register();
    }

    public static function reset(): void
    {
        self::$stepNames = null;
    }
}
