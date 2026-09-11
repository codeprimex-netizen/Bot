<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuotaKind;
use App\Models\Tenant;
use App\Models\TenantUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantUsage>
 */
class TenantUsageFactory extends Factory
{
    protected $model = TenantUsage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kind = QuotaKind::MessagesMonthly;

        return [
            'tenant_id' => Tenant::factory(),
            'kind' => $kind,
            'period_key' => $kind->periodKey(),
            'used' => 0,
            'limit' => 1000,
        ];
    }

    public function ofKind(QuotaKind $kind): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => $kind,
            'period_key' => $kind->periodKey(),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'used' => $attributes['limit'] ?? 1000,
        ]);
    }
}
