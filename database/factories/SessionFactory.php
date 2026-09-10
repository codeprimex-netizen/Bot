<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SessionStatus;
use App\Models\Session;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    protected $model = Session::class;

    /**
     * A freshly created session: `INITIALIZING`, no number known yet, nothing paired.
     *
     * Deliberately *not* connected by default. `CONNECTED` is the only state in which a send can
     * succeed, so a factory that handed it out for free would make every test that forgot to say
     * "this session is online" pass for the wrong reason — and the fail-closed tests (a send
     * refused because the session is not connected) would pass vacuously.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Session '.fake()->unique()->numerify('####'),
            'phone' => null,
            'status' => SessionStatus::Initializing,
        ];
    }

    /**
     * Paired and online — the state a send needs.
     */
    public function connected(?string $phone = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SessionStatus::Connected,
            'phone' => $phone ?? fake()->numerify('9198#######'),
            'push_name' => fake()->firstName(),
            'device_id' => fake()->uuid(),
            'connected_at' => now()->subHour(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Online but held back by the anti-ban risk gate: `isOnline()` is true, `canSend()` is false.
     *
     * The state that keeps the `online()` and `sendable()` scopes honestly different.
     */
    public function throttled(): static
    {
        return $this->connected()->state(fn (array $attributes): array => [
            'status' => SessionStatus::Throttled,
        ]);
    }

    /**
     * Showing a QR and waiting for a human to scan it.
     */
    public function pairing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SessionStatus::QrPending,
        ]);
    }

    /**
     * The device was unlinked on the phone: terminal, credentials void.
     */
    public function loggedOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SessionStatus::LoggedOut,
            'connected_at' => now()->subDay(),
            'last_seen_at' => now()->subHour(),
        ]);
    }

    /**
     * Any explicit state, for the transition and scope tests.
     */
    public function withStatus(SessionStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }
}
