<?php

declare(strict_types=1);

namespace App\Services\Reliability;

/**
 * One step of a saga: a forward action and the compensation that undoes it
 * (Req 15.5 / B6, Req 31.5 / NFR2, Algorithm 8, Correctness Property 18).
 *
 * The `saga_steps` row is the *record* of a step; this is the *behaviour*. They are
 * separate because the row must survive a crash and a redeploy while the behaviour is
 * code that gets resolved fresh from the container on every run — a step definition
 * therefore holds no per-saga state, and everything it needs arrives in `SagaContext`.
 *
 * ## The two obligations, and why they are not symmetric
 *
 * `forward()` may fail. When it does, the saga unwinds and this step is never
 * compensated — a forward action that threw has, by the idempotency contract, left
 * nothing to undo.
 *
 * `compensate()` **must be idempotent and must be safe to retry** (Req 31.5's
 * "idempotent compensations"). The orchestrator guards it with its own idempotency key
 * so a *completed* compensation is never re-run, but that guard only closes the window
 * after success; a compensation that fails halfway will be entered again on the next
 * run, and it must tolerate finding its work partly or wholly done. "The reservation is
 * already released" is a success, not an error.
 *
 * ## Do not skip a compensation because there is nothing to undo
 *
 * A read-only or naturally idempotent step implements `compensate()` as an empty
 * method, exactly as `TenantProvisioningStep::rollback()` does. The orchestrator calls
 * it for every completed step and never consults the row's nullable
 * `compensation_ref`/`compensation_payload` to decide — a null handle cannot be told
 * apart from a handle that was lost to a crash, and guessing wrong there is precisely
 * the orphaned side effect Property 18 forbids.
 *
 * ## Shape of an implementation
 *
 * ```php
 * final class ReserveItemsStep implements SagaStepDefinition   // Phase 5
 * {
 *     public function __construct(private readonly Inventory $inventory) {}
 *
 *     public function name(): string { return 'reserve_items'; }
 *
 *     public function forward(SagaContext $context): SagaStepResult
 *     {
 *         $reservation = $this->inventory->reserve($context->get('order_id'), $context->payload());
 *
 *         return SagaStepResult::compensateWith(['reservation_id' => $reservation->id]);
 *     }
 *
 *     public function compensate(SagaContext $context): void
 *     {
 *         // Tolerates "already released": that is the idempotent outcome, not a fault.
 *         $this->inventory->release($context->compensationPayload()['reservation_id'] ?? null);
 *     }
 * }
 * ```
 */
interface SagaStepDefinition
{
    /**
     * Stable, snake-case step name — `reserve_items`, `create_payment_link`.
     *
     * It is not decoration: it is the second half of both idempotency keys
     * (`Saga::stepKey()`, `Saga::compensationKey()`) and it is unique per saga in the
     * database. Renaming a step therefore changes which side effects are considered
     * already done, and the orchestrator refuses to run a persisted saga whose step
     * names no longer match its definition rather than silently re-executing them.
     */
    public function name(): string;

    /**
     * Do this step's work, or throw.
     *
     * Runs at most once per saga through `IdempotencyStore::once()`, so it may be
     * *entered* only once even across retries and crashes — but a later run may
     * **replay** its recorded result instead of calling it, which is why everything the
     * compensation needs must travel in the returned `SagaStepResult` and not in memory.
     *
     * Throwing aborts the saga: this step is marked `FAILED` and every previously
     * completed step is compensated in reverse order. There is no partial success to
     * report.
     *
     * The result is JSON-encoded into `idempotency_keys.result`, so it must contain only
     * arrays, scalars and nulls — no objects, no closures, no models.
     */
    public function forward(SagaContext $context): SagaStepResult;

    /**
     * Undo what `forward()` did, using only what it recorded
     * (`$context->compensationPayload()`, `$context->compensationRef()`).
     *
     * Must be idempotent and safe to retry. May throw when it genuinely could not undo
     * the effect — the orchestrator then continues with the remaining compensations and
     * leaves the saga `COMPENSATING` with the failure recorded, so an operator sees a
     * saga that still owes work instead of a `FAILED` one that quietly does not.
     * Throwing to mean "there was nothing left to undo" would strand the saga for ever.
     */
    public function compensate(SagaContext $context): void;
}
