<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What `ChannelDriver::register()` established about a number on one backend — the answer
 * task 9.1 consults **before** marking a session live (Req 8.1, 8.6 / A8; design § Channel
 * Mode 2.5: *"for official modes, calls `driver->register(...)` … before marking the session
 * live"*).
 *
 * ```php
 * $result = $driver->register($session, $credentials);
 *
 * if (! $result->isLive()) {
 *     return;   // pending provider-side verification: the session stays out of SENDABLE
 * }
 * ```
 *
 * ## Live and pending are different answers, and collapsing them loses a session
 *
 * Cloud API number registration and BSP number provisioning are not always synchronous: a
 * number can be accepted, then verified by the provider minutes later. A boolean would force
 * every caller to pick a wrong reading of that state — `false` looks like a failure and gets
 * retried or surfaced as an error, `true` marks a session sendable whose first send will be
 * rejected by the provider.
 *
 * So there are two named constructors and no public one:
 *
 * | Constructor | Means | What task 9.1 does |
 * |---|---|---|
 * | `live()` | the number is registered and usable now | mark the session live |
 * | `pending()` | accepted, awaiting provider-side verification | leave the session out of `SENDABLE`, re-check later |
 *
 * A registration that outright **failed** is not a value at all — it is the driver's typed
 * exception (a provider refusal, an unreachable endpoint). This type describes states the
 * number is in, not attempts that did not happen.
 *
 * ## `register()` also registers the callback, and that is why the URL is here
 *
 * design § Channel Mode 2.5 has the driver point the provider at the platform's webhook. The
 * URL is built by `App\Services\Url\UrlBuilder::webhook($provider, $routeKey, $tenant)` from
 * the mode's own slug (`ChannelMode::webhookSlug()`, or `BspProvider::webhookSlug()` for a
 * partner) and the unguessable `channel_webhook_routes.route_key`. Recording both on the
 * result is what lets the panel show a tenant the exact URL its provider will call, and lets
 * task 8.6 retire the route by key when the session changes mode.
 *
 * ## No secret ever reaches this object
 *
 * `providerNumberId` is an identifier — Meta's `phone_number_id`, a partner's sender id —
 * and lives in `channel_credentials.config`, not in the secret bag. `detail` is the one field
 * that can carry provider prose, so it is not a constructor argument a driver fills freely:
 * both named constructors require the `ChannelCredentials` the registration was performed
 * with and pass `detail` through `ChannelCredentials::redact()`, which removes any stored
 * secret value and bounds the length. A `401` body echoing part of an access token cannot
 * come back out of here, and the reason there is no public constructor is that it would be
 * the way around that.
 */
final readonly class RegistrationResult
{
    private function __construct(
        public ChannelMode $mode,
        public bool $live,
        public string $detail,
        public ?BspProvider $provider = null,
        public ?string $providerNumberId = null,
        public ?string $routeKey = null,
        public ?string $callbackUrl = null,
        public ?CarbonImmutable $completedAt = null,
    ) {}

    /**
     * The number is registered on this backend and may be sent from now.
     *
     * `$providerNumberId` is required: it is what every subsequent send addresses itself
     * with, so "live" without it is a claim nothing can act on.
     *
     * @throws InvalidArgumentException when the provider number id is missing
     */
    public static function live(
        ChannelCredentials $credentials,
        string $providerNumberId,
        ?string $routeKey = null,
        ?string $callbackUrl = null,
        string $detail = 'Registered.',
        ?CarbonImmutable $completedAt = null,
    ): self {
        if (trim($providerNumberId) === '') {
            throw new InvalidArgumentException(sprintf(
                'A live [%s] registration must name the provider number id every later send addresses '
                .'itself with; without it the session would be marked sendable and fail on its first send.',
                $credentials->mode->value,
            ));
        }

        return new self(
            mode: $credentials->mode,
            live: true,
            detail: $credentials->redact($detail),
            provider: $credentials->provider,
            providerNumberId: $providerNumberId,
            routeKey: $routeKey,
            callbackUrl: $callbackUrl,
            completedAt: $completedAt ?? CarbonImmutable::now(),
        );
    }

    /**
     * The provider accepted the request and has not finished verifying the number.
     *
     * The session must **not** be marked live on this answer.
     */
    public static function pending(
        ChannelCredentials $credentials,
        string $detail,
        ?string $providerNumberId = null,
        ?string $routeKey = null,
        ?string $callbackUrl = null,
    ): self {
        return new self(
            mode: $credentials->mode,
            live: false,
            detail: $credentials->redact($detail),
            provider: $credentials->provider,
            providerNumberId: $providerNumberId === null || trim($providerNumberId) === ''
                ? null
                : $providerNumberId,
            routeKey: $routeKey,
            callbackUrl: $callbackUrl,
        );
    }

    /**
     * Whether the session may be marked live.
     */
    public function isLive(): bool
    {
        return $this->live;
    }

    /**
     * Whether the provider still has verification to finish.
     */
    public function isPending(): bool
    {
        return ! $this->live;
    }

    /**
     * Whether a webhook callback was registered as part of this.
     */
    public function hasCallback(): bool
    {
        return $this->routeKey !== null && $this->callbackUrl !== null;
    }
}
