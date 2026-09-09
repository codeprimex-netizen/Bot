<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Services\Reliability\SagaDefinition;
use App\Services\Reliability\SagaStepDefinition;

/**
 * A saga definition whose step list is supplied by the test — any length, any mix of
 * compensating and read-only steps.
 *
 * `SpySagaDefinition` has a fixed three-step cast, which is right for the scripted
 * orchestrator suite and wrong for a property test: Correctness Property 18 is a claim
 * about sagas of every shape, and a one-step saga and a nine-step saga fail in different
 * places. `SpySagaDefinition::withSteps()` was not extended to carry per-step shape
 * because its `withSteps()` exists for the *registry's* refusals (an empty list, a
 * duplicated name) and every scripted test depends on its names being `STEPS`.
 *
 * Its type is `SagaFactory::ORDER_FULFILLMENT` so the model factories work unchanged.
 */
final class GeneratedSagaDefinition implements SagaDefinition
{
    public const string TYPE = 'ORDER_FULFILLMENT';

    /**
     * The drawn shape: one entry per step, in execution order.
     *
     * @var list<array{name: string, compensates: bool, contributes: array<string, mixed>}>
     */
    private static array $plan = [];

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * Resolved fresh on every registry lookup, so a step can hold no state between runs.
     *
     * @return list<SagaStepDefinition>
     */
    public function steps(): array
    {
        return array_map(
            static fn (array $step): SagaStepDefinition => new GeneratedSagaStep(
                $step['name'],
                $step['compensates'],
                $step['contributes'],
            ),
            self::$plan,
        );
    }

    /**
     * Make this the only registered definition, with the given shape.
     *
     * @param  list<array{name: string, compensates: bool, contributes: array<string, mixed>}>  $plan
     */
    public static function register(array $plan): void
    {
        self::$plan = $plan;

        config()->set('wa.reliability.saga.definitions', [self::class]);
    }

    /**
     * @return list<array{name: string, compensates: bool, contributes: array<string, mixed>}>
     */
    public static function plan(): array
    {
        return self::$plan;
    }

    /**
     * Step names in execution order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (array $step): string => $step['name'], self::$plan);
    }

    /**
     * The steps that create a real effect — what `SagaWorld::liveEffects()` can ever
     * report, and therefore what "no orphaned side effect" is a claim about.
     *
     * @return list<string>
     */
    public static function effectfulNames(): array
    {
        return array_values(array_map(
            static fn (array $step): string => $step['name'],
            array_filter(self::$plan, static fn (array $step): bool => $step['compensates']),
        ));
    }

    /**
     * Whether the named step leaves an effect behind.
     */
    public static function isEffectful(string $name): bool
    {
        return in_array($name, self::effectfulNames(), true);
    }

    /**
     * Everything the drawn steps contribute to `sagas.state`, merged in execution order.
     *
     * @return array<string, mixed>
     */
    public static function contributionsOf(string ...$names): array
    {
        $state = [];

        foreach (self::$plan as $step) {
            if (in_array($step['name'], $names, true)) {
                $state = [...$state, ...$step['contributes']];
            }
        }

        return $state;
    }

    public static function reset(): void
    {
        self::$plan = [];
    }
}
