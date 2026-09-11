<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BspProvider;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Models\ChannelCredential;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelCredential>
 */
class ChannelCredentialFactory extends Factory
{
    protected $model = ChannelCredential::class;

    /**
     * A Cloud API credential set: the commonest official mode, with the config/secret split
     * design § Channel Mode 2.7 specifies.
     *
     * Secrets are present by default because a credential row without them is unusable
     * (`ChannelCredential::isUsable()`), so a factory that omitted them would make every
     * test that forgot to add them pass for the wrong reason.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'mode' => ChannelMode::CloudApi,
            'provider' => null,
            'label' => ChannelCredential::DEFAULT_LABEL,
            'config' => [
                'waba_id' => (string) fake()->numerify('1#########'),
                'phone_number_id' => (string) fake()->numerify('1#########'),
                'api_version' => 'v20.0',
            ],
            'secret_config' => [
                'access_token' => 'EAAG'.fake()->regexify('[A-Za-z0-9]{24}'),
                'verify_token' => fake()->regexify('[A-Za-z0-9]{16}'),
            ],
            'status' => ChannelCredentialStatus::Active,
            'verified_at' => now()->subHour(),
        ];
    }

    /**
     * Any mode, with the secret shape that mode actually uses.
     */
    public function forMode(ChannelMode $mode, ?BspProvider $provider = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => $mode,
            'provider' => $mode->usesProvider() ? ($provider ?? BspProvider::Twilio) : null,
            'config' => match ($mode) {
                ChannelMode::Baileys => ['endpoint' => 'http://127.0.0.1:3000'],
                ChannelMode::CloudApi => [
                    'waba_id' => (string) fake()->numerify('1#########'),
                    'phone_number_id' => (string) fake()->numerify('1#########'),
                    'api_version' => 'v20.0',
                ],
                ChannelMode::OnPremise => ['endpoint' => 'https://onprem.example.test', 'sender' => '919876500000'],
                ChannelMode::BspGateway => [
                    'endpoint' => 'https://api.example.test',
                    'sender' => '919876500000',
                ],
            },
            'secret_config' => match ($mode) {
                ChannelMode::Baileys => ['webhook_secret' => fake()->regexify('[a-f0-9]{32}')],
                ChannelMode::CloudApi => [
                    'access_token' => 'EAAG'.fake()->regexify('[A-Za-z0-9]{24}'),
                    'verify_token' => fake()->regexify('[A-Za-z0-9]{16}'),
                    'app_secret' => fake()->regexify('[a-f0-9]{32}'),
                ],
                ChannelMode::OnPremise => [
                    'password' => fake()->regexify('[A-Za-z0-9]{20}'),
                    'webhook_secret' => fake()->regexify('[a-f0-9]{32}'),
                ],
                ChannelMode::BspGateway => [
                    'api_key' => fake()->regexify('[A-Za-z0-9]{28}'),
                    'webhook_secret' => fake()->regexify('[a-f0-9]{32}'),
                ],
            },
        ]);
    }

    /**
     * A row the tenant named itself — part of the uniqueness, so two labels can coexist.
     */
    public function labelled(string $label): static
    {
        return $this->state(fn (array $attributes): array => ['label' => $label]);
    }

    /**
     * No secrets at all: the "credentials missing" state Req 8.13 keeps unselectable.
     */
    public function withoutSecrets(): static
    {
        return $this->state(fn (array $attributes): array => [
            'secret_config' => null,
            'verified_at' => null,
        ]);
    }

    /**
     * The driver rejected these credentials (task 7.6).
     */
    public function invalid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChannelCredentialStatus::Invalid,
            'verified_at' => null,
        ]);
    }

    /**
     * Switched off by the tenant, secrets retained.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChannelCredentialStatus::Disabled,
        ]);
    }

    /**
     * Never validated by a driver.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => ['verified_at' => null]);
    }
}
