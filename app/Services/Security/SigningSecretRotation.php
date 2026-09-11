<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Carbon;

/**
 * What one dual-secret HMAC rotation did: which version now signs, which one is still
 * accepted, and until when.
 *
 * Carries **no secret material** — it is what the scheduled command prints and what the
 * audit entry records, both of which are places a secret must never appear. The peer's
 * new secret is fetched separately and deliberately, through
 * `SigningSecretStore::currentSecret()`.
 */
final readonly class SigningSecretRotation
{
    /**
     * @param  string  $scope  the rotated scope
     * @param  int  $version  the version that signs from now on
     * @param  int|null  $previousVersion  the version it replaced, or null when the scope
     *                                     had none (a first issue, not a rotation)
     * @param  Carbon|null  $acceptedUntil  when the previous version stops verifying;
     *                                      null when there was none
     */
    public function __construct(
        public string $scope,
        public int $version,
        public ?int $previousVersion = null,
        public ?Carbon $acceptedUntil = null,
    ) {}

    /**
     * Whether this was a real rotation (an overlap window is open) rather than a first
     * issue.
     */
    public function hasOverlap(): bool
    {
        return $this->previousVersion !== null && $this->acceptedUntil !== null;
    }

    /**
     * Audit/report payload — versions and a deadline, never a secret.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'version' => $this->version,
            'previous_version' => $this->previousVersion,
            'accepted_until' => $this->acceptedUntil?->toIso8601String(),
        ];
    }
}
