<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use Carbon\CarbonImmutable;

/**
 * Whether one set of credentials actually works — the answer task 7.6 requires **before**
 * activating them (Req 8.6, 8.13 / A8; design § Channel Mode 2.4).
 *
 * ```php
 * $health = $driver->healthCheck($candidateCredentials);
 *
 * if (! $health->healthy) {
 *     // keep the previous working credentials; the new ones are recorded INVALID
 *     throw ChannelCredentialException::rejected($health->detail);   // task 7.6
 * }
 * ```
 *
 * ## Why this returns a value where the rest of the contract throws
 *
 * The same argument `BridgeClient::isReachable()` makes: an unhealthy answer *is* the answer.
 * A probe that threw when the thing it probes is broken would have to be wrapped in a `try`
 * by every caller, and the two callers here both want the failure as data — task 7.6 records
 * it on `channel_credentials.status` and shows the tenant why, and the panel's connection
 * screen renders a red light with a reason.
 *
 * The exception is reserved for the case where the *probe itself* could not be performed —
 * an unreachable endpoint is `BridgeUnreachableException`'s territory, and a driver may let
 * that propagate rather than reporting "unhealthy", because "we could not ask" and "the
 * provider said no" lead to different operator actions.
 *
 * ## `detail` is scrubbed before it exists
 *
 * A credential probe's whole job is to send a secret somewhere and read the refusal — which
 * is precisely the response most likely to quote what it was sent. Meta and several BSP
 * partners echo part of the `Authorization` header on a `401`, and a driver that interpolates
 * the response body into a message would put an access-token fragment into
 * `channel_credentials`, an audit row, and a panel screen at once.
 *
 * So there is no public constructor. Both named constructors take the `ChannelCredentials`
 * the probe was made with and run `detail` through `ChannelCredentials::redact()`, which
 * removes any stored secret value and bounds the length to
 * `ChannelCredentials::MAX_DETAIL_LENGTH`. The mode and provider are read from the same
 * credentials, so a health report cannot be attributed to a mode other than the one that
 * was probed.
 *
 * The residual case — a provider that echoes a *truncated* token — is covered one layer out
 * by `PiiKeyRules::SECRET_PATTERN`, which redacts secret-named keys in log context whatever
 * their value.
 */
final readonly class ChannelHealth
{
    private function __construct(
        public ChannelMode $mode,
        public bool $healthy,
        public string $detail,
        public ?BspProvider $provider,
        public ?int $latencyMs,
        public CarbonImmutable $checkedAt,
    ) {}

    /**
     * The credentials work.
     */
    public static function healthy(
        ChannelCredentials $credentials,
        string $detail = 'Credentials accepted.',
        ?int $latencyMs = null,
        ?CarbonImmutable $checkedAt = null,
    ): self {
        return new self(
            mode: $credentials->mode,
            healthy: true,
            detail: $credentials->redact($detail),
            provider: $credentials->provider,
            latencyMs: self::normaliseLatency($latencyMs),
            checkedAt: $checkedAt ?? CarbonImmutable::now(),
        );
    }

    /**
     * The provider rejected them, or the account is not in a state that can send.
     *
     * `$detail` is what a tenant is shown, so it should say what to fix — *"the access token
     * is expired"*, *"this phone number id is not registered on the WABA"* — in the
     * provider's own words where those are useful, and never with the provider's echo of
     * what it was sent.
     */
    public static function unhealthy(
        ChannelCredentials $credentials,
        string $detail,
        ?int $latencyMs = null,
        ?CarbonImmutable $checkedAt = null,
    ): self {
        return new self(
            mode: $credentials->mode,
            healthy: false,
            detail: $credentials->redact($detail),
            provider: $credentials->provider,
            latencyMs: self::normaliseLatency($latencyMs),
            checkedAt: $checkedAt ?? CarbonImmutable::now(),
        );
    }

    /**
     * Whether these credentials may be activated (task 7.6).
     */
    public function isUsable(): bool
    {
        return $this->healthy;
    }

    /**
     * A negative measurement is a clock artefact, not a fast probe.
     *
     * Monotonic time is not guaranteed across a request that spans an NTP step, and a
     * negative latency on a health dashboard is read as a bug in the dashboard.
     */
    private static function normaliseLatency(?int $latencyMs): ?int
    {
        if ($latencyMs === null) {
            return null;
        }

        return max(0, $latencyMs);
    }
}
