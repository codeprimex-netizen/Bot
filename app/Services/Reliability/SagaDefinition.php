<?php

declare(strict_types=1);

namespace App\Services\Reliability;

/**
 * The ordered step list of one kind of saga — the code behind a `sagas.type`
 * (Req 15.5 / B6, Req 31.5 / NFR2, Algorithm 8).
 *
 * `sagas.type` is an open-ended string on purpose (`ORDER_FULFILLMENT|...`), and this is
 * what resolves it: `SagaDefinitionRegistry` maps every registered definition's
 * `type()` to its steps, and `SagaOrchestrator::run()` looks the persisted saga's type
 * up there. A later phase adds a saga by writing a definition and appending it to
 * `wa.reliability.saga.definitions` — nothing in the orchestrator changes.
 *
 * ## Order is the contract
 *
 * `steps()` is a **total order**, persisted to `saga_steps.position` the first time the
 * saga runs and compensated in exact reverse. Two consequences a definition author owns:
 *
 * 1. **Order side effects late.** Put steps whose effects live outside the database
 *    after the ones that do not, so the likely failure happens before anything external
 *    has been touched — the same rule `TenantProvisioningStep` states.
 * 2. **The list is immutable for sagas in flight.** Positions and names are persisted;
 *    the orchestrator refuses a saga whose stored steps no longer match this list
 *    (`InvalidSagaDefinitionException::stepMismatch()`) rather than re-running or
 *    skipping side effects. Changing an existing saga's shape is a migration, not an
 *    edit.
 *
 * ## Shape of an implementation
 *
 * ```php
 * final class OrderFulfilmentSaga implements SagaDefinition   // Phase 5
 * {
 *     public function __construct(
 *         private readonly ReserveItemsStep $reserve,
 *         private readonly CreatePaymentLinkStep $payment,
 *         private readonly FulfilOrderStep $fulfil,
 *     ) {}
 *
 *     public function type(): string { return 'ORDER_FULFILLMENT'; }
 *
 *     public function steps(): array { return [$this->reserve, $this->payment, $this->fulfil]; }
 * }
 * ```
 */
interface SagaDefinition
{
    /**
     * The `sagas.type` value this definition runs — `ORDER_FULFILLMENT`.
     *
     * Registered types are unique: two definitions claiming one type is a deployment
     * error, because which of them owns a persisted saga's side effects would be decided
     * by config ordering.
     */
    public function type(): string;

    /**
     * The steps, in forward execution order.
     *
     * Must be non-empty and must have distinct names — both are checked when the
     * registry resolves the definition, before any step runs.
     *
     * @return list<SagaStepDefinition>
     */
    public function steps(): array;
}
