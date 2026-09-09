<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a per-tenant Data Encryption Key (DEK) is allowed to protect
 * (design § Data Models → `encryption_keys.purpose`).
 *
 * Purpose is **cryptographic domain separation**, not a label: it is bound into
 * the additional authenticated data of every ciphertext *and* of the DEK wrap, so
 * a value encrypted for one purpose can never be decrypted under another
 * tenant's — or the same tenant's — key for a different purpose. Each purpose has
 * its own key lineage (its own versions, its own rotation clock), which is what
 * lets an export key be rotated on a different schedule from the field key
 * without touching stored field ciphertext.
 */
enum KeyPurpose: string
{
    /**
     * Field-level encryption of sensitive columns — lead PII, order details,
     * channel-mode credentials, WA auth state (Req 32.5 / NFR3).
     */
    case Field = 'FIELD';

    /**
     * GDPR/DPDP portability archives produced by `TenantLifecycle::export`.
     */
    case Export = 'EXPORT';

    /**
     * Per-tenant encrypted backups of auth state and engine data (Req 31.6 / NFR2).
     */
    case Backup = 'BACKUP';

    /**
     * Human-readable label for platform-admin key screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::Field => 'Field encryption',
            self::Export => 'Export archives',
            self::Backup => 'Backups',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
