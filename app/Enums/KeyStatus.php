<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one version of a per-tenant DEK sits in its rotation lifecycle
 * (design § Data Models → `encryption_keys.status`; design § Key rotation).
 *
 * ```
 *   provision ──▶ ACTIVE ──rotate──▶ RETIRING ──backfill verified──▶ RETIRED
 *                   ▲                    │                              │
 *                   └── exactly one per (tenant, purpose) ──┘        unusable
 * ```
 *
 * The states exist to make rotation *non-breaking*: `FieldCipher::encrypt` only
 * ever writes under the `ACTIVE` version, while `decrypt` still reads anything
 * written under a `RETIRING` one. A version only becomes `RETIRED` once no
 * ciphertext references it any more — and a `RETIRED` version refuses to unwrap,
 * so retiring a key that is still referenced fails closed with
 * `KeyUnavailableException` instead of silently returning garbage or plaintext.
 */
enum KeyStatus: string
{
    /**
     * The version new ciphertext is written under. Exactly one per
     * (tenant, purpose) — enforced by a unique index on the derived
     * `active_flag` column.
     */
    case Active = 'ACTIVE';

    /**
     * Rotated out but still readable: ciphertext written before the rotation
     * decrypts under this version until it is re-encrypted (lazily on its next
     * write, or by the backfill of task 4.2).
     */
    case Retiring = 'RETIRING';

    /**
     * Withdrawn from use. Neither encrypt nor decrypt may touch it — a ciphertext
     * that still names a retired version is a fail-closed error, never a fallback.
     */
    case Retired = 'RETIRED';

    /**
     * Whether this version may still be unwrapped to read existing ciphertext.
     */
    public function canDecrypt(): bool
    {
        return match ($this) {
            self::Active, self::Retiring => true,
            self::Retired => false,
        };
    }

    /**
     * Whether new ciphertext may be written under this version.
     */
    public function canEncrypt(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
