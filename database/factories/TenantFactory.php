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

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantStatus::Suspended,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantStatus::Cancelled,
        ]);
    }
}
