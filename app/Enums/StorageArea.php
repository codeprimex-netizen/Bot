<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three kinds of per-tenant files the platform keeps on disk.
 *
 * Each area maps to its own **private** filesystem disk (see
 * `config/filesystems.php`), and every path inside a disk is namespaced under
 * the tenant prefix `{wa.tenancy.storage_prefix}/{tenantId}/...` so a
 * filesystem bug cannot leak across tenants (Req 1.4 / A1).
 *
 * Areas are separate disks rather than sub-directories of one disk so that
 * operations can (a) keep auth state on restrictive, node-local storage the WA
 * Bridge can read directly, while (b) relocating exports/media to object
 * storage per data-region without touching application code.
 */
enum StorageArea: string
{
    /** Baileys session credentials. Never web-served, `0700`/`0600` on disk. */
    case AuthState = 'auth';

    /** Generated CSV/TXT/JSON/XLSX/vCard/PDF artifacts, served via signed URLs only. */
    case Exports = 'exports';

    /** Tenant-uploaded images/video/audio/documents used for sends. */
    case Media = 'media';

    /**
     * Disk name fallbacks, used when `config/wa.php` is missing or overridden
     * with a non-string value.
     */
    private const DEFAULT_DISKS = [
        'auth' => 'wa_auth',
        'exports' => 'wa_exports',
        'media' => 'wa_media',
    ];

    /**
     * Name of the configured filesystem disk backing this area.
     */
    public function disk(): string
    {
        $configured = config('wa.storage.disks.'.$this->value);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::DEFAULT_DISKS[$this->value];
    }

    /**
     * Human-readable label for panels and audit entries.
     */
    public function label(): string
    {
        return match ($this) {
            self::AuthState => 'WhatsApp auth state',
            self::Exports => 'Exports',
            self::Media => 'Media',
        };
    }

    /**
     * Whether files in this area may ever be handed out over HTTP (always
     * through a signed, expiring URL — never a public disk).
     */
    public function isDownloadable(): bool
    {
        return $this !== self::AuthState;
    }
}
