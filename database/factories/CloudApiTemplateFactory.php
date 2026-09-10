<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChannelTemplateCategory;
use App\Enums\ChannelTemplateStatus;
use App\Models\ChannelCredential;
use App\Models\CloudApiTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudApiTemplate>
 */
class CloudApiTemplateFactory extends Factory
{
    protected $model = CloudApiTemplate::class;

    /**
     * A submitted template awaiting review.
     *
     * `PENDING` rather than `APPROVED` by default, deliberately: approval is the only state
     * that permits a send outside the 24-hour window, so handing it out for free would make
     * every `TemplateRequiredException` test pass vacuously.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'credential_id' => ChannelCredential::factory(),
            'name' => 'template_'.fake()->unique()->numerify('####'),
            'language' => 'en_US',
            'category' => ChannelTemplateCategory::Utility,
            'body' => 'Hello {{1}}, your order {{2}} is on its way.',
            'components' => null,
            'status' => ChannelTemplateStatus::Pending,
            'provider_template_id' => null,
            'synced_at' => null,
        ];
    }

    /**
     * Approved by the provider — the one sendable state.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChannelTemplateStatus::Approved,
            'provider_template_id' => (string) fake()->numerify('9########'),
            'synced_at' => now()->subMinutes(5),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChannelTemplateStatus::Rejected,
            'synced_at' => now()->subDay(),
        ]);
    }

    /**
     * Paused by the provider for quality reasons: mirrored, and not sendable.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChannelTemplateStatus::Paused,
            'provider_template_id' => (string) fake()->numerify('9########'),
            'synced_at' => now()->subHours(2),
        ]);
    }

    public function ofCategory(ChannelTemplateCategory $category): static
    {
        return $this->state(fn (array $attributes): array => ['category' => $category]);
    }

    public function named(string $name, string $language = 'en_US'): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name,
            'language' => $language,
        ]);
    }
}
