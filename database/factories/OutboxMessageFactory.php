<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxMessage>
 */
class OutboxMessageFactory extends Factory
{
    protected $model = OutboxMessage::class;

    /**
     * A freshly enqueued, immediately claimable platform-level effect.
     *
     * `tenant_id` is null by default because that is the case a tenant-scoped model
     * could not express — tests that want an attributed row say so with `forTenant()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'aggregate_type' => 'order',
            'aggregate_id' => (string) Str::ulid(),
            'event_type' => 'order.paid',
            'destination' => 'https://example.test/webhooks/order',
            'payload' => ['order_id' => (string) Str::ulid(), 'amount_micros' => 1_250_000],
            'dedup_key' => (string) Str::ulid(),
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'next_attempt_at' => now(),
            'last_error' => null,
            'sent_at' => null,
        ];
    }

    public function forTenant(Tenant|string $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant instanceof Tenant ? $tenant->id : $tenant,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OutboxStatus::Sent,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }

    /**
     * Failed $attempts times and gated for $backoffSeconds more.
     */
    public function failed(int $attempts = 1, int $backoffSeconds = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OutboxStatus::Failed,
            'attempts' => $attempts,
            'next_attempt_at' => now()->addSeconds($backoffSeconds),
            'last_error' => 'Connection timed out',
        ]);
    }

    /**
     * Scheduled for the future: not claimable yet, and not because it failed.
     */
    public function deferred(int $seconds = 300): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_at' => now()->addSeconds($seconds),
            'next_attempt_at' => now()->addSeconds($seconds),
        ]);
    }
}
