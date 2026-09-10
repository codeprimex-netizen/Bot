<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Enums\DomainVerificationFailure;
use Illuminate\Support\Carbon;

/**
 * The outcome of one domain check: whether it passed, and if not, exactly why
 * (Req 9.7 / A9).
 *
 * Returned rather than thrown, because a failed check is an ordinary, expected state a
 * tenant iterates on — DNS has not propagated, the certificate is not installed yet —
 * and the caller almost always wants to *render* the reason rather than handle an
 * exception. The one thing that does throw is the platform's own inability to look
 * (`DomainProbeUnavailableException`), and `DomainVerifier` catches even that and turns
 * it into `DomainVerificationFailure::ProbeUnavailable` here, so a caller has exactly
 * one shape to deal with.
 *
 * @immutable
 */
final readonly class DomainVerification
{
    /**
     * @param  bool  $verified  true only when the ownership challenge **and** TLS both passed
     * @param  DomainVerificationFailure|null  $failure  null exactly when `$verified` is true
     * @param  bool  $revoked  whether this check cleared an existing verification
     * @param  Carbon|null  $tlsExpiresAt  notAfter of the certificate seen, when one was
     */
    private function __construct(
        public bool $verified,
        public ?DomainVerificationFailure $failure,
        public Carbon $checkedAt,
        public bool $revoked = false,
        public ?Carbon $tlsExpiresAt = null,
    ) {}

    public static function passed(Carbon $checkedAt, ?Carbon $tlsExpiresAt = null): self
    {
        return new self(true, null, $checkedAt, false, $tlsExpiresAt);
    }

    /**
     * @param  bool  $revoked  true when the row had been verified and this check cleared it
     */
    public static function failed(
        DomainVerificationFailure $failure,
        Carbon $checkedAt,
        bool $revoked = false,
        ?Carbon $tlsExpiresAt = null,
    ): self {
        return new self(false, $failure, $checkedAt, $revoked, $tlsExpiresAt);
    }

    /**
     * Whether the check said nothing about the domain — the platform could not look.
     */
    public function isInconclusive(): bool
    {
        return $this->failure !== null && ! $this->failure->isConclusive();
    }

    /**
     * The sentence the tenant is shown, for either outcome.
     */
    public function publicMessage(): string
    {
        return $this->failure?->publicMessage()
            ?? 'The domain is verified and is now used for this workspace\'s links.';
    }

    /**
     * Log/audit shape: codes and timestamps, never a host-supplied string.
     *
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'failure' => $this->failure?->value,
            'revoked' => $this->revoked,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'tls_expires_at' => $this->tlsExpiresAt?->toIso8601String(),
        ];
    }
}
