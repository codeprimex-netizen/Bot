<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Models\Saga;
use App\Models\SagaStep;

/**
 * What one step of a saga is handed when it runs, in either direction (Req 31.5 /
 * NFR2, Algorithm 8).
 *
 * The saga's `state` is the channel between steps — the order id the first step was
 * started with, the payment link the second one produced, the reservation the last one
 * consumes — and this object is the only way a step reaches it. A step therefore needs
 * no constructor arguments about the business transaction and can be a stateless
 * singleton resolved from the container, which is what makes the same definition safe
 * to run for two tenants on one long-lived worker.
 *
 * ## Reading is direct; writing goes through the return value
 *
 * `get()` reads the accumulated state. There is deliberately **no** `set()`: a step
 * contributes state by returning `SagaStepResult::…->contributing([...])`, so the
 * contribution passes through the idempotency ledger and a *replayed* step contributes
 * exactly what its first execution did. A setter here would be lost in precisely the
 * crash window Property 18 has to survive — see `SagaStepResult`.
 *
 * ## The compensation side
 *
 * During an unwind, `compensationPayload()` and `compensationRef()` return what the
 * forward action recorded on the step row when it succeeded. They are the compensation's
 * whole input: it must not recompute the handle from live data, because by then the
 * forward step's world may have moved on.
 */
final readonly class SagaContext
{
    public function __construct(
        private Saga $saga,
        private SagaStep $step,
    ) {}

    /**
     * The saga being run — its `type`, `correlation_id` (the business key), and tenant.
     */
    public function saga(): Saga
    {
        return $this->saga;
    }

    /**
     * The step row being executed, including its attempt counters and status.
     */
    public function step(): SagaStep
    {
        return $this->step;
    }

    /**
     * The business key this saga is about — an order id, a booking id.
     */
    public function correlationId(): ?string
    {
        return $this->saga->correlation_id;
    }

    /**
     * The step's own forward input, as persisted when the saga was laid out.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->step->payload ?? [];
    }

    /**
     * The saga's accumulated state — inputs plus every completed step's contribution.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->saga->state ?? [];
    }

    /**
     * One key of the accumulated state.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state()[$key] ?? $default;
    }

    /**
     * What the forward action recorded for its compensation to act on.
     *
     * @return array<string, mixed>
     */
    public function compensationPayload(): array
    {
        return $this->step->compensation_payload ?? [];
    }

    /**
     * The handle the forward action recorded — which compensator to invoke, or the
     * external id it must undo.
     */
    public function compensationRef(): ?string
    {
        return $this->step->compensation_ref;
    }
}
