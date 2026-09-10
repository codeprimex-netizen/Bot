<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\ChannelSendResult;
use App\Models\ChannelSendLog;
use App\Models\Session;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelSendLog>
 */
class ChannelSendLogFactory extends Factory
{
    protected $model = ChannelSendLog::class;

    /**
     * An accepted single send on the default mode.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'session_id' => Session::factory(),
            'mode' => ChannelMode::Baileys,
            'provider' => null,
            'capability' => ChannelCapability::SendSingle,
            'idempotency_key' => 'msg-'.Str::lower((string) Str::ulid()),
            'result' => ChannelSendResult::Sent,
            'block_reason' => null,
            'provider_message_id' => 'wamid.'.Str::random(20),
            'failover_from' => null,
            'created_at' => now(),
        ];
    }

    /**
     * A capability refusal: the provider was never contacted, so there is no message id.
     *
     * The row Property 21 / 26 assert on — the only trace an operation Req 8.3 forbade
     * dispatching is allowed to leave.
     */
    public function blocked(
        ChannelCapability $capability = ChannelCapability::Groups,
        ChannelMode $mode = ChannelMode::CloudApi,
        string $reason = 'MODE_CAPABILITY',
    ): static {
        return $this->state(fn (array $attributes): array => [
            'mode' => $mode,
            'capability' => $capability,
            'result' => ChannelSendResult::Blocked,
            'block_reason' => $reason,
            'provider_message_id' => null,
            // A block can precede any message, so it may have no idempotency key at all.
            'idempotency_key' => null,
        ]);
    }

    /**
     * Attempted, failed, and the dispatch moved on to the next mode in the chain.
     */
    public function failedOver(ChannelMode $to = ChannelMode::Baileys, ChannelMode $from = ChannelMode::CloudApi): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => $to,
            'failover_from' => $from,
            'result' => ChannelSendResult::FailedOver,
            'provider_message_id' => null,
        ]);
    }

    /**
     * Attempted and failed, with no further mode to try.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'result' => ChannelSendResult::Failed,
            'provider_message_id' => null,
        ]);
    }

    public function onMode(ChannelMode $mode, ?BspProvider $provider = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => $mode,
            'provider' => $mode->usesProvider() ? ($provider ?? BspProvider::Twilio) : null,
        ]);
    }
}
