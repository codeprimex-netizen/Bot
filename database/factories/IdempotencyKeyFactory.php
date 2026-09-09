<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IdempotencyState;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    /**
     * A key held in flight by a live worker — the state `once()` creates first.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'scope' => 'gateway:razorpay',
            'key' => (string) Str::ulid(),
            'state' => IdempotencyState::InFlight,
            'result' => null,
            'response_hash' => null,
            'request_fingerprint' => null,
            'locked_at' => now(),
            'completed_at' => null,
            'expires_at' => now()->addDay(),
        ];
    }

    public function forTenant(Tenant|string $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant instanceof Tenant ? $tenant->id : $tenant,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function completed(array $result = ['ok' => true]): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => IdempotencyState::Completed,
            'result' => $result,
            'response_hash' => IdempotencyKey::fingerprint($result),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => IdempotencyState::Failed,
            'completed_at' => now(),
        ]);
    }

    /**
     * In flight, but locked long enough ago that its holder is presumed dead.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => IdempotencyState::InFlight,
            'locked_at' => now()->subSeconds(IdempotencyKey::STALE_LOCK_SECONDS + 60),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
