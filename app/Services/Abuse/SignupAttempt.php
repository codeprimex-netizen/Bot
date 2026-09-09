<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Illuminate\Http\Request;

/**
 * One registration or OTP attempt, as the anti-fraud heuristics see it
 * (design § User Panel row 1: *"pre-tenant; … disposable-email/velocity block"*;
 * Req 32.7 / NFR3).
 *
 * ```php
 * $attempt = SignupAttempt::fromRequest($request, email: $data['email'], phone: $data['phone']);
 *
 * $antiFraud->assertSignup($attempt);      // 429 + Retry-After when refused
 * ```
 *
 * Every field is optional, because the registration path is anonymous and the panel
 * collects them at different steps: an email-first form has no phone number yet, an OTP
 * resend has no email in the request body, and a device fingerprint only exists once the
 * browser has run the panel's script. What the heuristics do with a *missing* signal is
 * the interesting part and it is decided in `HeuristicAntiFraudGuard`, not here — this is
 * a value object and nothing more.
 *
 * The raw values live in this object for the duration of the request only. Everything
 * that leaves it — cache keys, `abuse_events.subject_hash` — is a keyed digest
 * (`IdentityDigest`).
 */
final readonly class SignupAttempt
{
    public function __construct(
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $ip = null,
        public ?string $deviceFingerprint = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * Build from an HTTP request: the IP and user agent come from the request, the
     * identity from the validated input the caller already has.
     *
     * @param  string|null  $deviceFingerprint  the panel's fingerprint field, when present
     */
    public static function fromRequest(
        Request $request,
        ?string $email = null,
        ?string $phone = null,
        ?string $deviceFingerprint = null,
    ): self {
        return new self(
            email: self::clean($email),
            phone: self::clean($phone),
            ip: self::clean($request->ip()),
            deviceFingerprint: self::clean($deviceFingerprint),
            userAgent: self::clean($request->userAgent()),
        );
    }

    /**
     * Whether anything identity-like was supplied at all. An attempt with no email and no
     * phone number is not a signup the heuristics can reason about.
     */
    public function hasIdentity(): bool
    {
        return $this->email !== null || $this->phone !== null;
    }

    /**
     * Whether there is any signal the velocity counters can be keyed on.
     *
     * The negative case is deliberately *not* a block — see
     * `AbuseSignal::UncountableSignup`: console-driven provisioning, imports, and tests
     * legitimately have no address and no fingerprint, and refusing those would break
     * tenant creation while stopping no attacker (an attacker always has an address).
     */
    public function isCountable(): bool
    {
        return $this->ip !== null || $this->deviceFingerprint !== null || $this->hasIdentity();
    }

    /**
     * The identity this attempt is *about*, preferring the phone number: it is the field
     * the OTP is sent to and the harder of the two to farm.
     */
    public function primaryIdentity(): ?string
    {
        return $this->phone ?? $this->email;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
