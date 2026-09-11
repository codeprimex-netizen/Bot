<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformSetting;
use App\Services\Platform\PlatformSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSetting>
 */
class PlatformSettingFactory extends Factory
{
    protected $model = PlatformSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'setting_'.fake()->unique()->numerify('####'),
            'value' => fake()->word(),
        ];
    }

    /**
     * The Req 9.3 canonical-base override.
     */
    public function baseUrl(string $url): static
    {
        return $this->state(fn (array $attributes): array => [
            'key' => PlatformSettings::BASE_URL,
            'value' => $url,
        ]);
    }
}
