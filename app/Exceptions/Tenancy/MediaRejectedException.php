<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;

/**
 * A media payload was refused before anything was written to a tenant's
 * storage prefix: its **sniffed** MIME type is not on the allow-list, it
 * exceeds `wa.media.max_bytes`, or it could not be read at all.
 *
 * The client-supplied filename/extension/content-type never participates in
 * this decision — only `finfo` content sniffing does.
 */
final class MediaRejectedException extends RuntimeException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function disallowedMimeType(string $mimeType, array $allowed): self
    {
        return new self(sprintf(
            'Media type %s is not allowed (allowed: %s).',
            $mimeType,
            implode(', ', $allowed),
        ));
    }

    public static function tooLarge(int $bytes, int $maxBytes): self
    {
        return new self(sprintf(
            'Media payload of %d bytes exceeds the %d byte limit.',
            $bytes,
            $maxBytes,
        ));
    }

    public static function unreadable(string $description): self
    {
        return new self(sprintf('Media payload is not readable: %s.', $description));
    }

    public static function notSniffable(string $description): self
    {
        return new self(sprintf('Media payload MIME type could not be determined: %s.', $description));
    }

    public static function notAStream(string $given): self
    {
        return new self(sprintf('Expected an open stream resource, got %s.', $given));
    }

    public static function unmappedExtension(string $mimeType): self
    {
        return new self(sprintf(
            'No filename extension is configured or known for media type %s.',
            $mimeType,
        ));
    }
}
