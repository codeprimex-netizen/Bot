<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainChallengeMethod;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\DomainVerifier;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    /**
     * An **unverified** claim by default: `verified_at` is the security boundary
     * (Req 9.7), so a factory that verified by default would make every test that
     * forgets to say so pass for the wrong reason.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'host' => 'chat-'.fake()->unique()->numerify('####').'.example.test',
            'verified_at' => null,
        ];
    }

    /**
     * A live challenge, unsatisfied — the state a claim is in between "the tenant asked
     * to verify" and "the tenant published the record".
     */
    public function challenged(?DomainChallengeMethod $method = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'challenge_method' => $method ?? DomainChallengeMethod::default(),
            'challenge_token' => Str::random(DomainVerifier::TOKEN_LENGTH),
            'challenge_issued_at' => now(),
            'challenge_expires_at' => now()->addHours(72),
        ]);
    }

    /**
     * Ownership challenge and TLS check both confirmed — the only state in which a
     * domain is used for URL generation.
     *
     * The challenge is left in place with **no expiry**, which is exactly how
     * `DomainVerifier` leaves a verified row: the token becomes the standing proof a
     * re-check re-runs. A `verified()` state without it would be a row no re-check could
     * confirm, so every sweep test would pass for the wrong reason.
     */
    public function verified(?DateTimeInterface $at = null): static
    {
        return $this->challenged()->state(fn (array $attributes): array => [
            'verified_at' => $at ?? now()->subDay(),
            'challenge_expires_at' => null,
            'last_checked_at' => $at ?? now()->subDay(),
            'last_failure_reason' => null,
            'tls_expires_at' => now()->addDays(60),
        ]);
    }
}
