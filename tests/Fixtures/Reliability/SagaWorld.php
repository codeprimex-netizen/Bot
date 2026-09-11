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

    /**
     * Step names whose forward action lands its effect and then returns something the
     * idempotency ledger cannot store.
     *
     * @var list<string>
     */
    private static array $recordingFaults = [];

    /**
     * Step names whose forward action lands its effect and *then* throws — a step that
     * breaks its own contract.
     *
     * @var list<string>
     */
    private static array $orphanFaults = [];

    public static function reset(): void
    {
        self::$effects = [];
        self::$forwards = [];
        self::$compensations = [];
        self::$forwardFaults = [];
        self::$compensationFaults = [];
        self::$recordingFaults = [];
        self::$orphanFaults = [];
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
     * A forward action that ran and left **nothing** to undo — a validation, a read, a
     * naturally idempotent notify (`SagaStepResult::none()`).
     *
     * Recorded as a forward call but not as an effect, so a read-only step still appears
     * in the execution order while contributing nothing that `liveEffects()` could ever
     * report. Its `compensate()` is still invoked, exactly as the design requires, and
     * `release()` records that invocation without there being an effect to remove.
     */
    public static function performed(string $step): void
    {
        self::$forwards[] = $step;
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
     * This step's effect lands and its *result* is then unstorable, so
     * `IdempotencyStore::once()` raises `UnrecordableResultException` on the way out.
     *
     * The interesting half is what the step is left with: the ledger records `null`, so
     * the compensation handle is gone and the step must still be undone. A compensation
     * consults this to know that an absent handle is legitimate here and nowhere else.
     */
    public static function failRecordingAt(string $step): void
    {
        self::$recordingFaults[] = $step;
    }

    public static function recordingShouldFail(string $step): bool
    {
        return in_array($step, self::$recordingFaults, true);
    }

    /**
     * This step's effect lands and its forward action then throws — a step that breaks
     * `SagaStepDefinition::forward()`'s all-or-nothing contract.
     *
     * Kept separate from every other fault because it is the one case the orchestrator
     * cannot repair: a `FAILED` step is never compensated, so that effect is orphaned by
     * the step's own bug. Asserted on its own, positively, rather than folded into the
     * main property — see the boundary test in `SagaAtomicityPropertyTest`.
     */
    public static function orphanAt(string $step): void
    {
        self::$orphanFaults[] = $step;
    }

    public static function shouldOrphan(string $step): bool
    {
        return in_array($step, self::$orphanFaults, true);
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
