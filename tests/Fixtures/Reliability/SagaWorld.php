<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

/**
 * The observable world the saga test doubles act on (Req 31.5 / NFR2, Correctness
 * Property 18).
 *
 * This is not a call recorder with an "and it was compensated" flag — that shape is
 * exactly what let the design's same-key bug look correct. It holds **live effects**: a
 * forward action `reserve()`s something and the entry exists; its compensation
 * `release()`s it and the entry is gone. So "no orphaned side effect" is asserted as
 * `SagaWorld::liveEffects() === []`, which no amount of correct-looking bookkeeping can
 * satisfy on its own. A compensation that is never *invoked* — because it was replayed
 * off the forward action's idempotency key — leaves its effect standing and fails the
 * assertion.
 *
 * Call order is recorded too, separately, because reverse order is a distinct claim from
 * "everything was undone".
 */
final class SagaWorld
{
    /**
     * Effects that currently exist in the world, keyed by step name.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $effects = [];

    /**
     * Forward actions actually invoked, in call order.
     *
     * @var list<string>
     */
    private static array $forwards = [];

    /**
     * Compensations actually invoked, in call order — the reverse-order claim.
     *
     * @var list<string>
     */
    private static array $compensations = [];

    /**
     * Step names whose forward action must throw.
     *
     * @var list<string>
     */
    private static array $forwardFaults = [];

    /**
     * Step names whose compensation must throw.
     *
     * @var list<string>
     */
    private static array $compensationFaults = [];

    public static function reset(): void
    {
        self::$effects = [];
        self::$forwards = [];
        self::$compensations = [];
        self::$forwardFaults = [];
        self::$compensationFaults = [];
    }

    /*
    |--------------------------------------------------------------------------
    | The world
    |--------------------------------------------------------------------------
    */

    /**
     * A forward action's side effect comes into existence.
     *
     * @param  array<string, mixed>  $handle
     */
    public static function reserve(string $step, array $handle): void
    {
        self::$forwards[] = $step;
        self::$effects[$step] = $handle;
    }

    /**
     * An effect that already existed when this run started — a step completed by an
     * earlier process. Unlike `reserve()` it records no forward call, so a test can still
     * assert that no forward action ran *now*.
     *
     * @param  array<string, mixed>  $handle
     */
    public static function seed(string $step, array $handle): void
    {
        self::$effects[$step] = $handle;
    }

    /**
     * A compensation removes it. Idempotent — releasing twice is a success, exactly as
     * `SagaStepDefinition::compensate()` requires of a real one.
     */
    public static function release(string $step): void
    {
        self::$compensations[] = $step;

        unset(self::$effects[$step]);
    }

    /**
     * Effects still standing. `[]` is Property 18's "leaving no partial side effect".
     *
     * @return list<string>
     */
    public static function liveEffects(): array
    {
        return array_keys(self::$effects);
    }

    /**
     * The handle a step's forward action recorded, as the world saw it.
     *
     * @return array<string, mixed>|null
     */
    public static function handle(string $step): ?array
    {
        return self::$effects[$step] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function forwards(): array
    {
        return self::$forwards;
    }

    /**
     * @return list<string>
     */
    public static function compensations(): array
    {
        return self::$compensations;
    }

    /*
    |--------------------------------------------------------------------------
    | Fault injection
    |--------------------------------------------------------------------------
    */

    public static function failForwardAt(string $step): void
    {
        self::$forwardFaults[] = $step;
    }

    public static function failCompensationAt(string $step): void
    {
        self::$compensationFaults[] = $step;
    }

    public static function forwardShouldFail(string $step): bool
    {
        return in_array($step, self::$forwardFaults, true);
    }

    public static function compensationShouldFail(string $step): bool
    {
        return in_array($step, self::$compensationFaults, true);
    }

    /**
     * Stop a compensation from failing — how a test proves that a stranded saga is
     * genuinely resumable once the dependency recovers.
     */
    public static function healCompensationAt(string $step): void
    {
        self::$compensationFaults = array_values(
            array_filter(self::$compensationFaults, static fn (string $name): bool => $name !== $step)
        );
    }
}
