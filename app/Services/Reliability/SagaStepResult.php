<?php

declare(strict_types=1);

namespace App\Services\Reliability;

/**
 * What a saga step's forward action hands back: the handle its **compensation** will
 * need, and whatever it contributes to the saga's shared state (Req 31.5 / NFR2,
 * Algorithm 8).
 *
 * ## Why the forward action returns this instead of mutating the context
 *
 * A step could plausibly write its compensation handle straight onto the step row —
 * "I reserved stock, remember reservation id X so you can release it". That shape has
 * a crash window that Property 18 cannot survive.
 *
 * The forward action's *effect* is committed by the outside world (a reservation
 * exists, a payment link was created) before this process gets to persist anything.
 * If the worker dies in that gap, a later run finds the forward action's idempotency
 * key already `COMPLETED`, so `IdempotencyStore::once()` **replays** it — the closure
 * does not run a second time, and any handle it had recorded in memory is gone
 * for ever. The unwind would then own a side effect it has no way to address.
 *
 * So the handle travels through the ledger. `once()` records this object's
 * `toArray()` as the key's result, and a replay reconstructs it with
 * `fromLedger()` — meaning a resumed saga recovers the reservation id it never saw
 * created. That is the whole reason this is a return value with a JSON shape rather
 * than a setter on `SagaContext`.
 *
 * ```php
 * public function forward(SagaContext $context): SagaStepResult
 * {
 *     $reservation = $this->inventory->reserve($context->get('order_id'));
 *
 *     return SagaStepResult::compensateWith(
 *         ['reservation_id' => $reservation->id],
 *         ref: 'inventory:release:'.$reservation->id,
 *     )->contributing(['reserved_at' => $reservation->created_at->toIso8601String()]);
 * }
 * ```
 *
 * A step with nothing to undo — a validation, a read, a naturally idempotent notify —
 * returns `SagaStepResult::none()`. That is an explicit statement, and it is *not* how
 * the orchestrator decides whether to compensate: `compensate()` is called for every
 * step that completed, because a null column cannot be told apart from a handle that
 * was lost.
 */
final readonly class SagaStepResult
{
    /**
     * Ledger keys. Short and stable: they are written into `idempotency_keys.result`
     * and read back by `fromLedger()` on a resumed saga, so renaming one orphans the
     * compensation handles of every saga in flight at deploy time.
     */
    private const string REF_KEY = 'ref';

    private const string PAYLOAD_KEY = 'payload';

    private const string STATE_KEY = 'state';

    /**
     * @param  string|null  $compensationRef  handle for the compensating action — which compensator, or the external id it must act on; persisted to `saga_steps.compensation_ref`
     * @param  array<string, mixed>|null  $compensationPayload  what the compensation needs to run; persisted to `saga_steps.compensation_payload`
     * @param  array<string, mixed>  $state  merged into `sagas.state` so later steps can read it
     */
    private function __construct(
        public ?string $compensationRef = null,
        public ?array $compensationPayload = null,
        public array $state = [],
    ) {}

    /**
     * This step left nothing that needs undoing.
     *
     * Its `compensate()` is still called during an unwind — an empty method there
     * documents "read-only" in code, where a reviewer can see it.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Record what this step's compensation will need.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function compensateWith(?array $payload, ?string $ref = null): self
    {
        return new self(compensationRef: $ref, compensationPayload: $payload);
    }

    /**
     * Contribute to the saga's shared state — a payment link for the next step, an id
     * the fulfilment step needs.
     *
     * Merged into `sagas.state` (top-level keys overwrite), and carried through the
     * ledger, so a replayed step contributes exactly what its first execution did.
     *
     * @param  array<string, mixed>  $state
     */
    public function contributing(array $state): self
    {
        return new self($this->compensationRef, $this->compensationPayload, [...$this->state, ...$state]);
    }

    /**
     * The ledger shape — what `IdempotencyStore::once()` records as this step's result.
     *
     * @return array{ref: string|null, payload: array<string, mixed>|null, state: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            self::REF_KEY => $this->compensationRef,
            self::PAYLOAD_KEY => $this->compensationPayload,
            self::STATE_KEY => $this->state,
        ];
    }

    /**
     * Rebuild from a replayed ledger result, mirroring `QuotaConsumption::fromLedger()`.
     *
     * Everything is re-narrowed rather than trusted: the value has been through JSON and
     * may predate a deploy. Anything unrecognised degrades to "no handle, no state",
     * which makes the unwind of such a step a no-op it can report — never a type error
     * in the middle of a compensation run.
     */
    public static function fromLedger(mixed $value): self
    {
        if (! is_array($value)) {
            return self::none();
        }

        $ref = $value[self::REF_KEY] ?? null;
        $payload = $value[self::PAYLOAD_KEY] ?? null;
        $state = $value[self::STATE_KEY] ?? [];

        return new self(
            compensationRef: is_string($ref) ? $ref : null,
            compensationPayload: is_array($payload) ? self::stringKeyed($payload) : null,
            state: is_array($state) ? self::stringKeyed($state) : [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $keyed = [];

        foreach ($value as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        return $keyed;
    }
}
