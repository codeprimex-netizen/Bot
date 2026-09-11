<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Exceptions\Tenancy\MediaRejectedException;
use finfo;
use Symfony\Component\Mime\MimeTypes;

/**
 * Content-based MIME detection for tenant media.
 *
 * The client's filename, extension, and `Content-Type` header are all
 * attacker-controlled, so they are never trusted: the stored extension is
 * derived from what `finfo` reads out of the bytes themselves. A file uploaded
 * as `invoice.png` that actually contains a PDF is stored as `.pdf` (or
 * rejected, if PDFs are not allowed).
 */
class MimeSniffer
{
    /**
     * Sniff the MIME type of a local file.
     */
    public function fromPath(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw MediaRejectedException::unreadable($path);
        }

        $mimeType = $this->finfo()->file($path);

        if (! is_string($mimeType) || $mimeType === '') {
            throw MediaRejectedException::notSniffable($path);
        }

        return $this->normalise($mimeType);
    }

    /**
     * Sniff the MIME type of an in-memory payload.
     */
    public function fromContents(string $contents): string
    {
        if ($contents === '') {
            throw MediaRejectedException::unreadable('empty payload');
        }

        $mimeType = $this->finfo()->buffer($contents);

        if (! is_string($mimeType) || $mimeType === '') {
            throw MediaRejectedException::notSniffable('in-memory payload');
        }

        return $this->normalise($mimeType);
    }

    /**
     * MIME types tenants may store as media (`wa.media.allowed_mimes`).
     *
     * @return list<string>
     */
    public function allowedMediaTypes(): array
    {
        $configured = config('wa.media.allowed_mimes', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $mimeType): string => $this->normalise((string) $mimeType),
            array_filter($configured, static fn (mixed $mimeType): bool => is_string($mimeType) && $mimeType !== ''),
        ));
    }

    public function isAllowedMediaType(string $mimeType): bool
    {
        return in_array($this->normalise($mimeType), $this->allowedMediaTypes(), true);
    }

    /**
     * @throws MediaRejectedException
     */
    public function assertAllowedMediaType(string $mimeType): string
    {
        $normalised = $this->normalise($mimeType);

        if (! $this->isAllowedMediaType($normalised)) {
            throw MediaRejectedException::disallowedMimeType($normalised, $this->allowedMediaTypes());
        }

        return $normalised;
    }

    /**
     * Filename extension to use for a sniffed MIME type.
     *
     * The explicit `wa.media.extensions` map wins (so `image/jpeg` becomes
     * `jpg`, not `jpeg`); anything else falls back to Symfony's MIME database.
     *
     * @throws MediaRejectedException
     */
    public function extensionFor(string $mimeType): string
    {
        $normalised = $this->normalise($mimeType);
        $configured = config('wa.media.extensions', []);

        if (is_array($configured) && isset($configured[$normalised])) {
            $extension = $configured[$normalised];

            if (is_string($extension) && $this->isUsableExtension($extension)) {
                return strtolower($extension);
            }
        }

        foreach (MimeTypes::getDefault()->getExtensions($normalised) as $extension) {
            if ($this->isUsableExtension($extension)) {
                return strtolower($extension);
            }
        }

        throw MediaRejectedException::unmappedExtension($normalised);
    }

    /**
     * Best-known MIME type for a server-chosen extension. Used for exports,
     * whose bytes the platform generates itself (a CSV sniffs as `text/plain`,
     * an XLSX as `application/zip`, so sniffing them back would be useless).
     */
    public function mimeTypeForExtension(string $extension): string
    {
        $candidates = MimeTypes::getDefault()->getMimeTypes(strtolower($extension));

        return $candidates[0] ?? 'application/octet-stream';
    }

    private function finfo(): finfo
    {
        return new finfo(FILEINFO_MIME_TYPE);
    }

    /**
     * Drop any `; charset=...` parameter and casing differences.
     */
    private function normalise(string $mimeType): string
    {
        $bare = trim(explode(';', $mimeType, 2)[0]);

        return strtolower($bare);
    }

    private function isUsableExtension(string $extension): bool
    {
        return preg_match('/^[A-Za-z0-9]{1,8}$/', $extension) === 1;
    }
}
