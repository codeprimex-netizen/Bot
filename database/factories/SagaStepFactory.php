<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SagaStepStatus;
use App\Models\Saga;
use App\Models\SagaStep;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SagaStep>
 */
class SagaStepFactory extends Factory
{
    protected $model = SagaStep::class;

    /**
     * The step names of the design's order -> payment -> fulfilment saga, in order.
     *
     * @var list<string>
     */
    public const array ORDER_FULFILLMENT_STEPS = [
        'reserve_items',
        'create_payment_link',
        'await_payment',
        'fulfil_order',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'saga_id' => Saga::factory(),
            'position' => 0,
            'name' => self::ORDER_FULFILLMENT_STEPS[0],
            'status' => SagaStepStatus::Pending,
            'payload' => ['sku' => 'WIDGET-1', 'qty' => 2],
            'compensation_payload' => null,
            'compensation_ref' => null,
            'attempts' => 0,
            'compensation_attempts' => 0,
            'last_error' => null,
            'completed_at' => null,
            'compensated_at' => null,
            'failed_at' => null,
        ];
    }

    public function at(int $position, ?string $name = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'position' => $position,
            'name' => $name ?? (self::ORDER_FULFILLMENT_STEPS[$position] ?? 'step_'.$position),
        ]);
    }

    /**
     * Forward action succeeded, so the step now owes a compensation.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SagaStepStatus::Done,
            'attempts' => 1,
            'compensation_ref' => 'release-reservation:'.Str::ulid(),
            'compensation_payload' => ['reservation_id' => (string) Str::ulid()],
            'completed_at' => now(),
        ]);
    }

    public function compensated(): static
    {
        return $this->done()->state(fn (array $attributes): array => [
            'status' => SagaStepStatus::Compensated,
            'compensation_attempts' => 1,
            'compensated_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SagaStepStatus::Failed,
            'attempts' => 1,
            'last_error' => 'Payment gateway returned 502',
            'failed_at' => now(),
        ]);
    }

    /**
     * A read-only / naturally idempotent step: nothing to undo.
     */
    public function withoutCompensation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'compensation_ref' => null,
            'compensation_payload' => null,
        ]);
    }
}
