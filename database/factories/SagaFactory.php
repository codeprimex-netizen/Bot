<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SagaStatus;
use App\Models\Saga;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Saga>
 */
class SagaFactory extends Factory
{
    protected $model = Saga::class;

    /**
     * The design's canonical saga: order -> payment -> fulfilment, just started.
     */
    public const string ORDER_FULFILLMENT = 'ORDER_FULFILLMENT';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => self::ORDER_FULFILLMENT,
            'correlation_id' => (string) Str::ulid(),
            'status' => SagaStatus::Running,
            'state' => ['order_id' => (string) Str::ulid(), 'amount_micros' => 2_500_000],
            'current_step' => 0,
            'last_error' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }

    public function ofStatus(SagaStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'completed_at' => $status === SagaStatus::Completed ? now() : null,
            'failed_at' => $status === SagaStatus::Failed ? now() : null,
        ]);
    }

    public function completed(): static
    {
        return $this->ofStatus(SagaStatus::Completed);
    }

    public function compensating(): static
    {
        return $this->ofStatus(SagaStatus::Compensating);
    }

    public function failed(): static
    {
        return $this->ofStatus(SagaStatus::Failed);
    }

    /**
     * Non-terminal and untouched for $seconds — what the recovery sweep looks for.
     */
    public function stalled(int $seconds = 900): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SagaStatus::Running,
            'updated_at' => now()->subSeconds($seconds),
        ]);
    }

    /**
     * Without a business key, so `uniq(tenant_id, type, correlation_id)` does not
     * constrain it.
     */
    public function uncorrelated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'correlation_id' => null,
        ]);
    }
}
