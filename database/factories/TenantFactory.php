<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(6));

        return [
            'name' => $name,
            'slug' => $slug,
            'subdomain' => $slug,
            'status' => TenantStatus::Active,
            'plan_id' => null,
            'trial_ends_at' => null,
            'timezone' => 'UTC',
            'locale' => 'en',
        ];
    }

    public function trial(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->addDays((int) config('wa.tenancy.trial_days', 14)),
        ]);
    }

    /**
     * A suspended tenant, timestamped as `TenantLifecycle::suspend()` would leave it:
     * outbound blocked, inbound still logged, panels read-only.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now()->subDay(),
        ]);
    }

    /**
     * A cancelled tenant still inside its retention window — `cancelled_at` is set, so
     * the purge (task 34.3) is scheduled but not yet due.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantStatus::Cancelled,
            'cancelled_at' => now()->subDay(),
        ]);
    }
}
