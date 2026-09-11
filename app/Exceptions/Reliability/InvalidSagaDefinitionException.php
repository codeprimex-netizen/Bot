<?php

declare(strict_types=1);

namespace App\Exceptions\Reliability;

use App\Models\Saga;
use App\Services\Reliability\SagaDefinition;
use App\Services\Reliability\SagaStepDefinition;
use LogicException;

/**
 * The saga a caller asked to run is not described by runnable code (Req 31.5 / NFR2,
 * Algorithm 8).
 *
 * Every constructor below is a **deployment or definition error**, raised before any
 * forward action of the affected saga runs — a class named in
 * `wa.reliability.saga.definitions` that does not exist, two definitions claiming one
 * `sagas.type`, a saga whose persisted steps no longer match the code that owns them.
 *
 * ## Why every one of these is fatal
 *
 * `wa.tenancy.resolvers` skips an entry it cannot resolve, because one fewer resolver
 * means one fewer door and the request fails closed. A saga is the opposite shape, for
 * the same reason `InvalidProvisioningStepException` is fatal: the steps *are* the
 * atomic unit. Skipping a bad one would run a saga that reserves stock and takes a
 * payment but never fulfils, and report `COMPLETED` — Property 18's "no partial side
 * effect" broken not by a fault but by a typo, with nothing downstream able to notice,
 * because a saga missing a step looks exactly like a saga that never had one.
 *
 * `stepMismatch()` deserves its own note. Step *names* are idempotency keys
 * (`Saga::stepKey()`), so renaming or reordering a step under a saga that is already in
 * flight silently redefines which side effects count as done: the renamed step's forward
 * action would run again against a world where it has already happened, and the old
 * name's compensation would never be reachable. Refusing to run such a saga leaves it
 * `RUNNING` and visible to `Saga::scopeStalled()`, which is a problem an operator can
 * act on. A deploy that must change a saga's shape drains the in-flight ones first.
 */
final class InvalidSagaDefinitionException extends LogicException
{
    /**
     * `wa.reliability.saga.definitions` holds something that is not a class name.
     */
    public static function notAClass(mixed $entry): self
    {
        return new self(sprintf(
            'wa.reliability.saga.definitions contains [%s], which is not a class name. '
            .'Every entry must be a class implementing %s.',
            get_debug_type($entry),
            SagaDefinition::class,
        ));
    }

    public static function missingClass(string $class): self
    {
        return new self(sprintf(
            'wa.reliability.saga.definitions names class [%s], which does not exist.',
            $class,
        ));
    }

    public static function notADefinition(string $class): self
    {
        return new self(sprintf(
            'wa.reliability.saga.definitions names [%s], which does not implement %s.',
            $class,
            SagaDefinition::class,
        ));
    }

    /**
     * Two registered definitions claim the same `sagas.type`.
     */
    public static function duplicateType(string $type, string $class): self
    {
        return new self(sprintf(
            'wa.reliability.saga.definitions registers [%s] for saga type [%s], which another '
            .'definition already owns. A saga type maps to exactly one definition: with two, '
            .'which code owns a persisted saga\'s side effects would be decided by config order.',
            $class,
            $type,
        ));
    }

    /**
     * A definition returned no steps.
     */
    public static function emptySteps(string $type, string $class): self
    {
        return new self(sprintf(
            'Saga definition [%s] for type [%s] declares no steps. An empty saga would report '
            .'COMPLETED for work that was never described, which hides the omission instead of '
            .'surfacing it.',
            $class,
            $type,
        ));
    }

    /**
     * A definition uses one step name twice.
     */
    public static function duplicateStepName(string $type, string $name): self
    {
        return new self(sprintf(
            'Saga definition for type [%s] declares two steps named [%s]. Step names are '
            .'idempotency keys (uniq(saga_id, name)), so duplicates would make one step\'s '
            .'forward action replay the other\'s recorded result.',
            $type,
            $name,
        ));
    }

    /**
     * A definition returned something that is not a `SagaStepDefinition`.
     */
    public static function notAStep(string $type, mixed $step): self
    {
        return new self(sprintf(
            'Saga definition for type [%s] returned [%s] among its steps, which is not a %s.',
            $type,
            get_debug_type($step),
            SagaStepDefinition::class,
        ));
    }

    /**
     * A saga was run whose `type` no definition claims.
     */
    public static function unknownType(Saga $saga): self
    {
        return new self(sprintf(
            'Saga [%s] has type [%s], which no definition in wa.reliability.saga.definitions '
            .'claims. Its steps cannot be run or compensated until one does — the saga is left '
            .'untouched and remains visible to Saga::scopeStalled().',
            $saga->id,
            $saga->type,
        ));
    }

    /**
     * The persisted steps of a saga disagree with the definition that owns its type.
     *
     * @param  list<string>  $persisted
     * @param  list<string>  $defined
     */
    public static function stepMismatch(Saga $saga, array $persisted, array $defined): self
    {
        return new self(sprintf(
            'Saga [%s] of type [%s] has persisted steps [%s] but its definition now declares '
            .'[%s]. Step names and positions are idempotency keys, so running it would '
            .'re-execute effects that already happened and leave the old names\' compensations '
            .'unreachable. Drain in-flight sagas before changing their shape.',
            $saga->id,
            $saga->type,
            implode(', ', $persisted),
            implode(', ', $defined),
        ));
    }
}
