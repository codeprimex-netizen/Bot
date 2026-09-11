<?php

declare(strict_types=1);

namespace App\Services\Channel;

/**
 * The two answers one credential probe produced, carried together so
 * `ChannelCredentialValidator` can hand them from the probe to the activation and the audit
 * without a tuple (Req 8.6 / A8).
 *
 * Deliberately thin, and deliberately *not* `ChannelCredentialValidation`: this exists before
 * anything has been written, and describes what a driver said. The validation describes what
 * the platform then did about it — which set is stored, which one was retained, whether this
 * was a first save. Collapsing the two would mean returning an object whose `credential` was
 * null half the time, and callers checking for that null are exactly what the validator's
 * "only an accepted outcome is a value" rule exists to avoid.
 *
 * A refused probe is never one of these: `refuse()` raises before it could be constructed.
 */
final readonly class ChannelCredentialProbe
{
    /**
     * @param  ChannelHealth  $health  always present, and always `healthy` — an unhealthy verdict raised instead
     * @param  RegistrationResult|null  $registration  present only where the mode needed provider-side registration and a session was named
     */
    public function __construct(
        public ChannelHealth $health,
        public ?RegistrationResult $registration = null,
    ) {}
}
