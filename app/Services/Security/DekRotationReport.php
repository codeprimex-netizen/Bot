<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\KeyPurpose;

/**
 * What one per-tenant DEK rotation sweep did (Req 32.6 / NFR3).
 *
 * `failed` is the number worth alerting on, and it is deliberately not fatal: one tenant
 * whose key store hiccups must not stop the other nine hundred from rotating, and the
 * lineage it failed on is left entirely untouched — its previous version is still `ACTIVE`,
 * so that tenant simply keeps encrypting under the key it already had. A failed rotation
 * costs a rotation, never data.
 */
final class DekRotationReport
{
    /**
     * @var list<array{tenant_id: string, purpose: string, from_version: int|null, to_version: int|null, failed: bool}>
     */
    private array $entries = [];

    public function recordRotated(string $tenantId, KeyPurpose $purpose, ?int $fromVersion, int $toVersion): void
    {
        $this->entries[] = [
            'tenant_id' => $tenantId,
            'purpose' => $purpose->value,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'failed' => false,
        ];
    }

    public function recordFailed(string $tenantId, KeyPurpose $purpose, ?int $fromVersion): void
    {
        $this->entries[] = [
            'tenant_id' => $tenantId,
            'purpose' => $purpose->value,
            'from_version' => $fromVersion,
            'to_version' => null,
            'failed' => true,
        ];
    }

    public function rotated(): int
    {
        return count(array_filter($this->entries, static fn (array $entry): bool => ! $entry['failed']));
    }

    public function failed(): int
    {
        return count(array_filter($this->entries, static fn (array $entry): bool => $entry['failed']));
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return list<array{tenant_id: string, purpose: string, from_version: int|null, to_version: int|null, failed: bool}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rotated' => $this->rotated(),
            'failed' => $this->failed(),
        ];
    }
}
