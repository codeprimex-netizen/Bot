<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Http\UploadedFile;

/**
 * Real bytes for media tests.
 *
 * MIME sniffing is content-based, so tests must hand it genuine file headers —
 * `UploadedFile::fake()->create()` produces filler bytes that sniff as
 * `text/plain` and would only ever exercise the rejection path.
 */
final class MediaFixtures
{
    /** A valid 1x1 PNG (68 bytes). */
    public static function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=',
            true,
        );
    }

    /** A minimal but well-formed PDF document. */
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** Content whose sniffed type (`text/plain`) is not an allowed media type. */
    public static function plainText(): string
    {
        return "just some text, not media\n";
    }

    /**
     * An upload whose declared name/extension/content-type may lie about the
     * bytes it carries.
     */
    public static function upload(string $contents, string $clientName, string $clientMimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-media-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary upload fixture.');
        }

        file_put_contents($path, $contents);

        return new UploadedFile($path, $clientName, $clientMimeType, null, true);
    }

    /**
     * An open read stream over the given contents.
     *
     * @return resource
     */
    public static function stream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open a temporary stream fixture.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
