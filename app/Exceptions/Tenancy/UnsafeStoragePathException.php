<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;

/**
 * A caller asked `TenantStorage` for a path that is not provably inside the
 * requesting tenant's prefix, or that is not a safe relative path at all
 * (traversal, absolute path, null byte, percent-encoded separator, ...).
 *
 * Such input is **rejected**, never silently normalised: a request that tries
 * to escape its tenant prefix is a bug or an attack, and either way the caller
 * must see it fail (Req 1.4 / A1).
 */
final class UnsafeStoragePathException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('Storage path is empty.');
    }

    public static function nullByte(string $path): self
    {
        return new self(sprintf('Storage path contains a null byte: %s', self::redact($path)));
    }

    public static function encoded(string $path): self
    {
        return new self(sprintf(
            'Storage path contains percent-encoded characters and is rejected rather than decoded: %s',
            self::redact($path),
        ));
    }

    public static function absolute(string $path): self
    {
        return new self(sprintf('Storage path must be relative, got: %s', self::redact($path)));
    }

    public static function traversal(string $path): self
    {
        return new self(sprintf('Storage path contains a traversal segment: %s', self::redact($path)));
    }

    public static function illegalSegment(string $path, string $segment): self
    {
        return new self(sprintf(
            'Storage path segment %s is not allowed (expected [A-Za-z0-9._-]): %s',
            self::redact($segment),
            self::redact($path),
        ));
    }

    public static function segmentTooLong(string $path): self
    {
        return new self(sprintf('Storage path segment exceeds 255 bytes: %s', self::redact($path)));
    }

    public static function outsideTenantPrefix(string $path, string $prefix): self
    {
        return new self(sprintf(
            'Resolved storage path %s falls outside the tenant prefix %s.',
            self::redact($path),
            $prefix,
        ));
    }

    public static function invalidTenantId(string $tenantId): self
    {
        return new self(sprintf(
            'Tenant id %s is not a ULID, so no tenant storage prefix can be resolved for it.',
            self::redact($tenantId),
        ));
    }

    public static function invalidSessionId(string $sessionId): self
    {
        return new self(sprintf(
            'WhatsApp session id %s is not a ULID, so no auth-state directory can be resolved for it.',
            self::redact($sessionId),
        ));
    }

    public static function invalidExtension(string $extension): self
    {
        return new self(sprintf(
            'File extension %s is not allowed (expected 1-8 alphanumeric characters).',
            self::redact($extension),
        ));
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unsupportedExportExtension(string $extension, array $allowed): self
    {
        return new self(sprintf(
            'Export extension %s is not supported (allowed: %s).',
            self::redact($extension),
            implode(', ', $allowed),
        ));
    }

    /**
     * Keep untrusted input out of logs verbatim: control characters are escaped
     * and the value is truncated before it reaches a message or audit trail.
     */
    private static function redact(string $value): string
    {
        $escaped = addcslashes($value, "\0..\37\177");

        return mb_strimwidth($escaped, 0, 120, '…');
    }
}
