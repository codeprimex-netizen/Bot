<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Models\CircuitBreaker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CircuitBreaker>
 */
class CircuitBreakerFactory extends Factory
{
    protected $model = CircuitBreaker::class;

    /**
     * A healthy, never-tripped provider breaker.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => CircuitScope::Provider,
            'name' => $this->faker->unique()->slug(2),
            'state' => CircuitState::Closed,
            'failure_count' => 0,
            'success_count' => 0,
            'half_open_probes' => 0,
            'half_open_successes' => 0,
            'window_started_at' => null,
            'opened_at' => null,
            'last_failure_at' => null,
            'last_success_at' => null,
        ];
    }

    public function forKey(CircuitScope $scope, string $name): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => $scope,
            'name' => $name,
        ]);
    }

    /**
     * Tripped $secondsAgo ago, with a full failure window behind it.
     */
    public function open(int $secondsAgo = 0, int $failures = 5): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => CircuitState::Open,
            'failure_count' => $failures,
            'window_started_at' => now()->subSeconds($secondsAgo + 1),
            'opened_at' => now()->subSeconds($secondsAgo),
            'last_failure_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    /**
     * Probing, with $probes of its budget already spent.
     */
    public function halfOpen(int $probes = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => CircuitState::HalfOpen,
            'half_open_probes' => $probes,
            'opened_at' => now()->subMinute(),
        ]);
    }

    /**
     * A window with a known failure/success mix, for error-rate assertions.
     */
    public function withWindow(int $failures, int $successes, int $startedSecondsAgo = 5): static
    {
        return $this->state(fn (array $attributes): array => [
            'failure_count' => $failures,
            'success_count' => $successes,
            'window_started_at' => now()->subSeconds($startedSecondsAgo),
        ]);
    }
}
