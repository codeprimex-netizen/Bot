<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Exceptions\Reliability\InvalidSagaDefinitionException;
use App\Models\Saga;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a `sagas.type` to the code that runs it, from
 * `wa.reliability.saga.definitions` (Req 15.5 / B6, Req 31.5 / NFR2).
 *
 * `sagas.type` is an open-ended string — the design's `ORDER_FULFILLMENT|...` grows a
 * new member every phase (drip, export, integration sagas) — so the orchestrator cannot
 * contain a `match` over it. This registry is the seam: **appending a class to that
 * config array is the entire cost of adding a saga**, exactly as
 * `TenantProvisioningStepRegistry` is for provisioning concerns.
 *
 * ## An empty list is legal here, unlike the provisioning pipeline
 *
 * `TenantProvisioningStepRegistry` treats an empty list as fatal, because a tenant
 * always needs provisioning. There are legitimately **no saga definitions yet** — the
 * concrete order → payment → fulfilment saga belongs to Phase 5 — and an orchestrator
 * that refused to boot until one existed would be a stub in disguise. The failure is
 * moved to the precise moment it means something instead: running a saga whose type
 * nobody claims raises `InvalidSagaDefinitionException::unknownType()`.
 *
 * ## Definitions are resolved from the container, freshly, on every lookup
 *
 * The same two reasons `TenantProvisioningStepRegistry` gives. Container resolution lets
 * a step declare its collaborators (a payment gateway, an inventory service) in its
 * constructor instead of reaching for facades, so it is testable in isolation and a fake
 * can be bound under `tests/`. Fresh instances mean a definition cannot carry state — a
 * remembered order, a half-built handle — from one tenant's saga into the next one's,
 * which on a long-lived queue worker would be a cross-tenant leak.
 *
 * ## Every misconfiguration is fatal
 *
 * A missing class, a class that is not a definition, two definitions claiming one type,
 * a definition with no steps or with a duplicated step name: all raise. The argument is
 * `InvalidSagaDefinitionException`'s — a skipped step produces a saga that reserves
 * stock and takes a payment but never fulfils, and reports `COMPLETED`.
 */
final class SagaDefinitionRegistry
{
    /**
     * The config key holding the ordered list of definition classes.
     */
    public const string CONFIG_KEY = 'wa.reliability.saga.definitions';

    public function __construct(private readonly Container $container) {}

    /**
     * The definition that owns this saga's type.
     *
     * @throws InvalidSagaDefinitionException when no definition claims the type, or the
     *                                        configured list is not runnable
     */
    public function for(Saga $saga): SagaDefinition
    {
        return $this->ofType($saga->type) ?? throw InvalidSagaDefinitionException::unknownType($saga);
    }

    /**
     * The definition for a type, or null when none claims it.
     *
     * @throws InvalidSagaDefinitionException when the configured list is not runnable
     */
    public function ofType(string $type): ?SagaDefinition
    {
        foreach ($this->definitions() as $definition) {
            if ($definition->type() === $type) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Whether a definition claims this type — the check a caller makes *before* creating
     * a saga row, so a typo in a type name fails at the point it is written rather than
     * at the point the saga is run.
     */
    public function has(string $type): bool
    {
        return $this->ofType($type) !== null;
    }

    /**
     * Every registered definition, validated, keyed by the type it claims.
     *
     * @return array<string, SagaDefinition>
     *
     * @throws InvalidSagaDefinitionException
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->configuredClasses() as $class) {
            $definition = $this->container->make($class);

            if (! $definition instanceof SagaDefinition) {
                // Reachable despite the class-level check below: a container binding may
                // resolve the name to something else entirely.
                throw InvalidSagaDefinitionException::notADefinition($class);
            }

            $type = $definition->type();

            if (array_key_exists($type, $definitions)) {
                throw InvalidSagaDefinitionException::duplicateType($type, $class);
            }

            $this->assertRunnableSteps($definition, $class);

            $definitions[$type] = $definition;
        }

        return $definitions;
    }

    /**
     * The registered saga types — what a panel lists, and what a test pins.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * The steps of a definition, keyed by name, for the orchestrator's per-step lookup.
     *
     * @return array<string, SagaStepDefinition>
     */
    public function stepsOf(SagaDefinition $definition): array
    {
        $steps = [];

        foreach ($definition->steps() as $step) {
            $steps[$step->name()] = $step;
        }

        return $steps;
    }

    /**
     * A definition must declare at least one step, and no two steps may share a name —
     * `uniq(saga_id, name)` would reject the second row, and both halves of the step's
     * idempotency keys are built from that name.
     *
     * @throws InvalidSagaDefinitionException
     */
    private function assertRunnableSteps(SagaDefinition $definition, string $class): void
    {
        $steps = $definition->steps();
        $type = $definition->type();

        if ($steps === []) {
            throw InvalidSagaDefinitionException::emptySteps($type, $class);
        }

        $names = [];

        foreach ($steps as $step) {
            if (! $step instanceof SagaStepDefinition) {
                throw InvalidSagaDefinitionException::notAStep($type, $step);
            }

            $name = $step->name();

            if (in_array($name, $names, true)) {
                throw InvalidSagaDefinitionException::duplicateStepName($type, $name);
            }

            $names[] = $name;
        }
    }

    /**
     * The configured class names, validated but not instantiated.
     *
     * @return list<class-string<SagaDefinition>>
     *
     * @throws InvalidSagaDefinitionException
     */
    private function configuredClasses(): array
    {
        $configured = config(self::CONFIG_KEY);
        $classes = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw InvalidSagaDefinitionException::notAClass($entry);
            }

            $class = trim($entry);

            if (! class_exists($class)) {
                throw InvalidSagaDefinitionException::missingClass($class);
            }

            if (! is_subclass_of($class, SagaDefinition::class)) {
                throw InvalidSagaDefinitionException::notADefinition($class);
            }

            /** @var class-string<SagaDefinition> $class */
            $classes[] = $class;
        }

        return $classes;
    }
}
