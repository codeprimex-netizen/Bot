<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\StorageArea;
use App\Exceptions\Tenancy\MediaRejectedException;
use App\Exceptions\Tenancy\UnsafeStoragePathException;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

/**
 * The single door to every per-tenant file the platform keeps: WhatsApp
 * auth state, generated exports, and uploaded media.
 *
 * Three guarantees, all enforced here rather than left to callers (Req 1.4 / A1):
 *
 * 1. **Namespaced.** Every path is resolved under `{storage_prefix}/{tenantId}/`
 *    on that area's private disk, and the resolved path is asserted to still be
 *    inside that prefix before it is handed back. Resolving anything requires a
 *    `Tenant` (or a ULID tenant id), so there is no "current tenant" ambient
 *    state to get wrong and no way to name a file without naming its owner.
 * 2. **Server-named.** Filenames are freshly generated ULIDs. A client-supplied
 *    filename is never stored, echoed, or reused, and the extension comes from
 *    `finfo` content sniffing — not from what the client claimed.
 * 3. **Fail loud.** Traversal (`..`), absolute paths, null bytes, backslashes,
 *    percent-encoded separators, and any attempt to reach another tenant's
 *    prefix raise `UnsafeStoragePathException`. Nothing is silently normalised.
 *
 * None of these disks is web-served: auth state is `0700`/`0600` and never
 * leaves the server, while exports and media are handed out only through
 * signed, expiring URLs built on top of the `{disk, path}` pair returned here
 * (URL generation itself lives in the Base URL layer).
 */
class TenantStorage
{
    /**
     * One path segment: no separators, no dot-segments, no whitespace, no
     * percent signs — a deliberately narrow allow-list.
     */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private const EXTENSION_PATTERN = '/^[a-z0-9]{1,8}$/';

    private const DEFAULT_EXPORT_EXTENSIONS = ['csv', 'txt', 'json', 'xlsx', 'vcf', 'pdf', 'zip'];

    private const STREAM_CHUNK_BYTES = 262144;

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly MimeSniffer $sniffer,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Disks
    |--------------------------------------------------------------------------
    */

    /**
     * The private disk backing an area.
     */
    public function disk(StorageArea $area): Filesystem
    {
        return $this->filesystems->disk($area->disk());
    }

    public function diskName(StorageArea $area): string
    {
        return $area->disk();
    }

    /*
    |--------------------------------------------------------------------------
    | Path resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant's prefix on any area disk: `tenants/{tenantId}`.
     */
    public function prefix(Tenant|string $tenant): string
    {
        if ($tenant instanceof Tenant) {
            $this->assertTenantId((string) $tenant->getKey());

            return $this->sanitizeRelative($tenant->storagePrefix());
        }

        $this->assertTenantId($tenant);

        return $this->rootPrefix().'/'.$tenant;
    }

    /**
     * Resolve a relative name (or an already-prefixed path) to a path inside
     * this tenant's prefix on the given area's disk.
     *
     * @throws UnsafeStoragePathException when the input is unsafe or belongs to another tenant
     */
    public function path(Tenant|string $tenant, StorageArea $area, string $filename): string
    {
        // The area is part of the public contract because it selects the disk
        // the returned path is valid on; resolving it also validates that the
        // area maps to a configured disk name.
        $this->diskName($area);

        return $this->resolve($tenant, $filename);
    }

    /**
     * Absolute local path — only meaningful for local-driver disks, and needed
     * because the Node/Baileys bridge opens the auth-state directory directly.
     */
    public function fullPath(Tenant|string $tenant, StorageArea $area, string $filename = ''): string
    {
        $relative = $filename === ''
            ? $this->prefix($tenant)
            : $this->path($tenant, $area, $filename);

        return $this->disk($area)->path($relative);
    }

    /**
     * A fresh, server-generated ULID filename with a validated extension.
     */
    public function newFilename(string $extension): string
    {
        return Str::ulid()->toBase32().'.'.$this->normaliseExtension($extension);
    }

    /*
    |--------------------------------------------------------------------------
    | WhatsApp auth state
    |--------------------------------------------------------------------------
    */

    /**
     * Directory holding a tenant's Baileys credentials, optionally narrowed to
     * one session. Session ids are ULIDs and are validated as such.
     */
    public function authStatePath(Tenant|string $tenant, ?string $sessionId = null): string
    {
        if ($sessionId === null) {
            return $this->prefix($tenant);
        }

        if (! Str::isUlid($sessionId)) {
            throw UnsafeStoragePathException::invalidSessionId($sessionId);
        }

        return $this->resolve($tenant, $sessionId);
    }

    /**
     * Absolute auth-state directory, for handing to the bridge process.
     */
    public function authStateFullPath(Tenant|string $tenant, ?string $sessionId = null): string
    {
        return $this->disk(StorageArea::AuthState)->path($this->authStatePath($tenant, $sessionId));
    }

    /**
     * Create the auth-state directory if it does not exist yet (idempotent).
     * Permissions come from the disk config: `0700` dirs, `0600` files.
     *
     * @return string the relative directory path
     */
    public function ensureAuthStateDirectory(Tenant|string $tenant, ?string $sessionId = null): string
    {
        $path = $this->authStatePath($tenant, $sessionId);

        // Flysystem's createDirectory is idempotent, so this is safe to repeat
        // on every session boot and keeps the configured 0700 permissions.
        if (! $this->disk(StorageArea::AuthState)->makeDirectory($path)) {
            throw new RuntimeException(sprintf('Unable to create auth-state directory [%s].', $path));
        }

        return $path;
    }

    /**
     * Drop a tenant's auth state (session unlink / offboarding).
     */
    public function deleteAuthState(Tenant|string $tenant, ?string $sessionId = null): bool
    {
        return $this->disk(StorageArea::AuthState)
            ->deleteDirectory($this->authStatePath($tenant, $sessionId));
    }

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    */

    /**
     * Store generated export/report/invoice bytes under the tenant prefix.
     *
     * Exports are produced by the platform, so the extension is supplied by the
     * caller and checked against `wa.storage.export_extensions` — never sniffed
     * (a CSV sniffs as `text/plain`, an XLSX as `application/zip`).
     */
    public function putExport(Tenant|string $tenant, string $contents, string $extension): StoredFile
    {
        $ext = $this->assertExportExtension($extension);
        $path = $this->resolve($tenant, $this->newFilename($ext));

        $this->disk(StorageArea::Exports)->put($path, $contents);

        return new StoredFile(
            area: StorageArea::Exports,
            disk: $this->diskName(StorageArea::Exports),
            path: $path,
            mimeType: $this->sniffer->mimeTypeForExtension($ext),
            extension: $ext,
            bytes: strlen($contents),
        );
    }

    /**
     * Streaming variant for large exports (contacts, conversation archives).
     *
     * `$stream` must be an open read resource; anything else is rejected.
     */
    public function putExportStream(Tenant|string $tenant, mixed $stream, string $extension): StoredFile
    {
        $this->assertStream($stream);

        $ext = $this->assertExportExtension($extension);
        $path = $this->resolve($tenant, $this->newFilename($ext));
        $disk = $this->disk(StorageArea::Exports);

        $disk->put($path, $stream);

        return new StoredFile(
            area: StorageArea::Exports,
            disk: $this->diskName(StorageArea::Exports),
            path: $path,
            mimeType: $this->sniffer->mimeTypeForExtension($ext),
            extension: $ext,
            bytes: (int) $disk->size($path),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    /**
     * Store an uploaded file (`Illuminate\Http\UploadedFile` is an
     * `SplFileInfo`) as tenant media.
     *
     * The client's filename and extension are discarded; the MIME type is
     * sniffed from the bytes, checked against `wa.media.allowed_mimes`, and the
     * extension is derived from it.
     */
    public function putMedia(Tenant|string $tenant, SplFileInfo $file): StoredFile
    {
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_file($realPath)) {
            throw MediaRejectedException::unreadable($file->getFilename());
        }

        $size = $file->getSize();
        $bytes = $size === false ? (int) filesize($realPath) : $size;

        return $this->storeSniffedMedia($tenant, $realPath, $bytes);
    }

    /**
     * Store in-memory media bytes (e.g. an inbound media payload already pulled
     * from the bridge).
     */
    public function putMediaContents(Tenant|string $tenant, string $contents): StoredFile
    {
        $bytes = strlen($contents);
        $this->assertWithinMediaSizeCap($bytes);

        $mimeType = $this->sniffer->assertAllowedMediaType($this->sniffer->fromContents($contents));
        $extension = $this->sniffer->extensionFor($mimeType);
        $path = $this->resolve($tenant, $this->newFilename($extension));

        $this->disk(StorageArea::Media)->put($path, $contents);

        return new StoredFile(
            area: StorageArea::Media,
            disk: $this->diskName(StorageArea::Media),
            path: $path,
            mimeType: $mimeType,
            extension: $extension,
            bytes: $bytes,
        );
    }

    /**
     * Store media from an open stream, enforcing the size cap while copying so
     * an oversized payload never lands in the tenant prefix.
     *
     * `$stream` must be an open read resource; anything else is rejected.
     */
    public function putMediaStream(Tenant|string $tenant, mixed $stream): StoredFile
    {
        $this->assertStream($stream);

        $temporary = tmpfile();

        if ($temporary === false) {
            throw new RuntimeException('Unable to open a temporary file for the media stream.');
        }

        try {
            $temporaryPath = $this->temporaryPath($temporary);
            $maxBytes = $this->mediaMaxBytes();
            $bytes = 0;

            while (! feof($stream)) {
                $chunk = fread($stream, self::STREAM_CHUNK_BYTES);

                if ($chunk === false) {
                    throw MediaRejectedException::unreadable('stream read failed');
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);

                if ($bytes > $maxBytes) {
                    throw MediaRejectedException::tooLarge($bytes, $maxBytes);
                }

                if (fwrite($temporary, $chunk) === false) {
                    throw new RuntimeException('Unable to buffer the media stream to a temporary file.');
                }
            }

            fflush($temporary);

            return $this->storeSniffedMedia($tenant, $temporaryPath, $bytes);
        } finally {
            fclose($temporary);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    public function exists(Tenant|string $tenant, StorageArea $area, string $path): bool
    {
        return $this->disk($area)->exists($this->path($tenant, $area, $path));
    }

    public function get(Tenant|string $tenant, StorageArea $area, string $path): string
    {
        $contents = $this->disk($area)->get($this->path($tenant, $area, $path));

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('File [%s] is missing from the [%s] area.', $path, $area->value));
        }

        return $contents;
    }

    /**
     * @return resource|null
     */
    public function readStream(Tenant|string $tenant, StorageArea $area, string $path): mixed
    {
        return $this->disk($area)->readStream($this->path($tenant, $area, $path));
    }

    public function size(Tenant|string $tenant, StorageArea $area, string $path): int
    {
        return (int) $this->disk($area)->size($this->path($tenant, $area, $path));
    }

    /**
     * Every file a tenant owns in one area. Used by the right-to-delete
     * verification pass, which must assert zero residual objects.
     *
     * @return list<string>
     */
    public function files(Tenant|string $tenant, StorageArea $area, bool $recursive = true): array
    {
        $prefix = $this->prefix($tenant);
        $disk = $this->disk($area);

        $files = $recursive ? $disk->allFiles($prefix) : $disk->files($prefix);
        $owned = [];

        foreach ((array) $files as $file) {
            // Belt and braces: only ever report paths inside this tenant's prefix.
            if (is_string($file) && str_starts_with($file, $prefix.'/')) {
                $owned[] = $file;
            }
        }

        return $owned;
    }

    /*
    |--------------------------------------------------------------------------
    | Deletion
    |--------------------------------------------------------------------------
    */

    /**
     * Delete one file. The path is resolved (and containment-checked) against
     * the owning tenant, so passing another tenant's path throws rather than
     * deleting their data.
     */
    public function delete(Tenant|string $tenant, StorageArea $area, string $path): bool
    {
        return $this->disk($area)->delete($this->path($tenant, $area, $path));
    }

    /**
     * Delete everything a tenant has in one area.
     */
    public function deleteArea(Tenant|string $tenant, StorageArea $area): bool
    {
        return $this->disk($area)->deleteDirectory($this->prefix($tenant));
    }

    /**
     * Delete every file the tenant owns across all areas (right-to-delete
     * purge / offboarding). Keyed by area so the caller can log per-area
     * outcomes in the deletion certificate.
     *
     * @return array<string, bool>
     */
    public function purge(Tenant|string $tenant): array
    {
        $results = [];

        foreach (StorageArea::cases() as $area) {
            $results[$area->value] = $this->deleteArea($tenant, $area);
        }

        return $results;
    }

    /**
     * Whether the tenant has no residual objects left anywhere — the assertion
     * the purge verification pass needs.
     */
    public function isPurged(Tenant|string $tenant): bool
    {
        foreach (StorageArea::cases() as $area) {
            if ($this->files($tenant, $area) !== []) {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function storeSniffedMedia(Tenant|string $tenant, string $localPath, int $bytes): StoredFile
    {
        $this->assertWithinMediaSizeCap($bytes);

        $mimeType = $this->sniffer->assertAllowedMediaType($this->sniffer->fromPath($localPath));
        $extension = $this->sniffer->extensionFor($mimeType);
        $path = $this->resolve($tenant, $this->newFilename($extension));

        $handle = fopen($localPath, 'rb');

        if ($handle === false) {
            throw MediaRejectedException::unreadable($localPath);
        }

        try {
            $this->disk(StorageArea::Media)->put($path, $handle);
        } finally {
            fclose($handle);
        }

        return new StoredFile(
            area: StorageArea::Media,
            disk: $this->diskName(StorageArea::Media),
            path: $path,
            mimeType: $mimeType,
            extension: $extension,
            bytes: $bytes,
        );
    }

    /**
     * Turn caller input into a path proven to sit inside this tenant's prefix.
     */
    private function resolve(Tenant|string $tenant, string $path): string
    {
        $prefix = $this->prefix($tenant);
        $relative = $this->sanitizeRelative($path);
        $root = $this->rootPrefix();

        if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
            $resolved = $relative;
        } elseif ($relative === $root || str_starts_with($relative, $root.'/')) {
            // Already tenant-namespaced, but for a different tenant: refuse
            // instead of re-nesting it under the caller's prefix.
            throw UnsafeStoragePathException::outsideTenantPrefix($path, $prefix);
        } else {
            $resolved = $prefix.'/'.$relative;
        }

        if ($resolved !== $prefix && ! str_starts_with($resolved, $prefix.'/')) {
            throw UnsafeStoragePathException::outsideTenantPrefix($path, $prefix);
        }

        return $resolved;
    }

    /**
     * Reject — never repair — anything that is not a plain relative path.
     */
    private function sanitizeRelative(string $path): string
    {
        if (trim($path) === '') {
            throw UnsafeStoragePathException::empty();
        }

        if (str_contains($path, "\0")) {
            throw UnsafeStoragePathException::nullByte($path);
        }

        // `%2e%2e%2f`, `%2f`, `%5c`, `%00`, ... are refused rather than decoded,
        // so no decode/normalise round trip can ever produce a traversal.
        if (rawurldecode($path) !== $path) {
            throw UnsafeStoragePathException::encoded($path);
        }

        if (str_contains($path, '\\')) {
            throw UnsafeStoragePathException::absolute($path);
        }

        if (preg_match('#^(/|~|[a-zA-Z]:)#', $path) === 1) {
            throw UnsafeStoragePathException::absolute($path);
        }

        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw UnsafeStoragePathException::traversal($path);
            }

            if (strlen($segment) > 255) {
                throw UnsafeStoragePathException::segmentTooLong($path);
            }

            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw UnsafeStoragePathException::illegalSegment($path, $segment);
            }
        }

        return implode('/', $segments);
    }

    private function rootPrefix(): string
    {
        $configured = config('wa.tenancy.storage_prefix', 'tenants');
        $prefix = is_string($configured) ? trim($configured, '/') : '';

        return $prefix === '' ? 'tenants' : $prefix;
    }

    private function assertTenantId(string $tenantId): void
    {
        if (! Str::isUlid($tenantId)) {
            throw UnsafeStoragePathException::invalidTenantId($tenantId);
        }
    }

    private function normaliseExtension(string $extension): string
    {
        $normalised = strtolower(ltrim($extension, '.'));

        if (preg_match(self::EXTENSION_PATTERN, $normalised) !== 1) {
            throw UnsafeStoragePathException::invalidExtension($extension);
        }

        return $normalised;
    }

    private function assertExportExtension(string $extension): string
    {
        $normalised = $this->normaliseExtension($extension);
        $allowed = $this->exportExtensions();

        if (! in_array($normalised, $allowed, true)) {
            throw UnsafeStoragePathException::unsupportedExportExtension($extension, $allowed);
        }

        return $normalised;
    }

    /**
     * @return list<string>
     */
    private function exportExtensions(): array
    {
        $configured = config('wa.storage.export_extensions', self::DEFAULT_EXPORT_EXTENSIONS);

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_EXPORT_EXTENSIONS;
        }

        return array_values(array_map(
            static fn (mixed $extension): string => strtolower((string) $extension),
            array_filter($configured, static fn (mixed $extension): bool => is_string($extension) && $extension !== ''),
        ));
    }

    private function mediaMaxBytes(): int
    {
        $configured = config('wa.media.max_bytes');

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : 16 * 1024 * 1024;
    }

    private function assertWithinMediaSizeCap(int $bytes): void
    {
        $maxBytes = $this->mediaMaxBytes();

        if ($bytes <= 0) {
            throw MediaRejectedException::unreadable('empty payload');
        }

        if ($bytes > $maxBytes) {
            throw MediaRejectedException::tooLarge($bytes, $maxBytes);
        }
    }

    /**
     * @phpstan-assert resource $stream
     */
    private function assertStream(mixed $stream): void
    {
        if (! is_resource($stream)) {
            throw MediaRejectedException::notAStream(get_debug_type($stream));
        }
    }

    /**
     * @param  resource  $handle
     */
    private function temporaryPath(mixed $handle): string
    {
        $uri = stream_get_meta_data($handle)['uri'] ?? null;

        if (! is_string($uri) || $uri === '') {
            throw new RuntimeException('Temporary media buffer has no filesystem path.');
        }

        return $uri;
    }
}
