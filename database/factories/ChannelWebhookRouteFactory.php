<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChannelMode;
use App\Models\ChannelWebhookRoute;
use App\Models\Session;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelWebhookRoute>
 */
class ChannelWebhookRouteFactory extends Factory
{
    protected $model = ChannelWebhookRoute::class;

    /**
     * A live Cloud API route with a full-entropy key.
     *
     * The key is a ULID plus random token characters rather than anything derived from the
     * tenant or the session: `route_key` is what resolves the tenant on an unauthenticated
     * request, so a factory that made it guessable would be modelling the one property this
     * table's isolation depends on incorrectly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'session_id' => Session::factory(),
            'mode' => ChannelMode::CloudApi,
            'route_key' => self::routeKey(),
            'verify_token_hash' => ChannelWebhookRoute::hashVerifyToken(Str::random(32)),
            'signing_secret_ref' => null,
            'active' => true,
        ];
    }

    /**
     * A key of the shape `UrlBuilder::webhook()` accepts.
     */
    public static function routeKey(): string
    {
        return strtolower((string) Str::ulid()).'-'.Str::random(16);
    }

    public function forMode(ChannelMode $mode): static
    {
        return $this->state(fn (array $attributes): array => ['mode' => $mode]);
    }

    /**
     * Retired: still registered with the provider, no longer resolvable.
     */
    public function retired(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }

    /**
     * A route whose payloads are verified against a named `signing_secrets.scope`.
     */
    public function signedBy(string $scope): static
    {
        return $this->state(fn (array $attributes): array => ['signing_secret_ref' => $scope]);
    }

    /**
     * A specific verify token, so a handshake test can present the plaintext.
     */
    public function withVerifyToken(string $token): static
    {
        return $this->state(fn (array $attributes): array => [
            'verify_token_hash' => ChannelWebhookRoute::hashVerifyToken($token),
        ]);
    }
}
