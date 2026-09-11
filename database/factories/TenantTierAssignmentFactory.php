<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantTier;
use App\Models\Tenant;
use App\Models\TenantTierAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantTierAssignment>
 */
class TenantTierAssignmentFactory extends Factory
{
    protected $model = TenantTierAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'tier' => TenantTier::Shared,
            'lane_weight' => null,
            'data_region' => null,
            'shard_key' => null,
            'dedicated_conn' => null,
        ];
    }

    public function onTier(TenantTier $tier): static
    {
        return $this->state(fn (array $attributes): array => ['tier' => $tier]);
    }

    public function dedicatedWorkers(): static
    {
        return $this->onTier(TenantTier::DedicatedWorker);
    }

    /**
     * A tenant cut over to its own database connection.
     */
    public function dedicatedDatabase(?string $connection = null, ?string $shardKey = null): static
    {
        return $this->onTier(TenantTier::DedicatedDb)->state(fn (array $attributes): array => [
            'dedicated_conn' => $connection === null ? null : ['connection' => $connection],
            'shard_key' => $shardKey,
        ]);
    }

    public function withLaneWeight(int $weight): static
    {
        return $this->state(fn (array $attributes): array => ['lane_weight' => $weight]);
    }

    public function inRegion(string $region): static
    {
        return $this->state(fn (array $attributes): array => ['data_region' => $region]);
    }
}
