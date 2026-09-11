<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
{
    protected $model = TenantUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'role' => TenantRole::Operator,
            'invited_at' => now()->subDay(),
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => TenantRole::Owner,
        ]);
    }

    public function pendingInvite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'invited_at' => now(),
            'joined_at' => null,
        ]);
    }
}
