<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\QuotaKind;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * A well-formed, sellable plan: every `QuotaKind` priced (so nothing reads as an
     * accidental 0) and a small feature set granted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'price_cents' => fake()->numberBetween(0, 50_000),
            'currency' => 'USD',
            'interval' => BillingInterval::Month,
            'features' => self::features(['flows' => true, 'keyword_triggers' => true, 'faq' => true]),
            'limits' => self::limits(),
            'active' => true,
            'sort' => 0,
        ];
    }

    /**
     * Grant or revoke specific feature flags on top of the defaults.
     *
     * @param  array<string, bool>  $features
     */
    public function withFeatures(array $features): static
    {
        return $this->state(function (array $attributes) use ($features): array {
            $current = is_array($attributes['features'] ?? null) ? $attributes['features'] : [];

            return ['features' => [...$current, ...$features]];
        });
    }

    /**
     * Override specific quota ceilings; `null` means unlimited.
     *
     * @param  array<string, int|null>  $limits  keyed by `QuotaKind::value`
     */
    public function withLimits(array $limits): static
    {
        return $this->state(function (array $attributes) use ($limits): array {
            $current = is_array($attributes['limits'] ?? null) ? $attributes['limits'] : [];

            return ['limits' => [...$current, ...$limits]];
        });
    }

    /**
     * Every quota kind unlimited.
     */
    public function unlimited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'limits' => array_fill_keys(QuotaKind::values(), null),
        ]);
    }

    /**
     * A plan that declares no limits at all — every kind therefore grants 0.
     */
    public function withoutLimits(): static
    {
        return $this->state(fn (array $attributes): array => ['limits' => []]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes): array => ['interval' => BillingInterval::Year]);
    }

    /**
     * The default feature map, with $granted merged over it.
     *
     * @param  array<string, bool>  $granted
     * @return array<string, bool>
     */
    private static function features(array $granted = []): array
    {
        return [
            'flows' => false,
            'keyword_triggers' => false,
            'faq' => false,
            'ai' => false,
            'campaigns' => false,
            'api' => false,
            ...$granted,
        ];
    }

    /**
     * A modest ceiling for every quota kind, so a factory-made plan is complete.
     *
     * @return array<string, int|null>
     */
    private static function limits(): array
    {
        return array_fill_keys(QuotaKind::values(), 100);
    }
}
