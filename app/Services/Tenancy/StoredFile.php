<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\StorageArea;

/**
 * The outcome of writing one file into a tenant's storage prefix.
 *
 * Later phases persist `$disk` + `$path` (e.g. `media_assets`, `exports`,
 * `invoices` rows) and hand the pair back to `TenantStorage` — or, for
 * downloads, to the signed-URL builder (task 5.3) — never a raw absolute path.
 */
final readonly class StoredFile
{
    public function __construct(
        public StorageArea $area,
        public string $disk,
        public string $path,
        public string $mimeType,
        public string $extension,
        public int $bytes,
    ) {}

    /**
     * The server-generated ULID filename, e.g. `01JB....jpg`.
     */
    public function filename(): string
    {
        return basename($this->path);
    }

    /**
     * @return array{area: string, disk: string, path: string, mime_type: string, extension: string, bytes: int}
     */
    public function toArray(): array
    {
        return [
            'area' => $this->area->value,
            'disk' => $this->disk,
            'path' => $this->path,
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
            'bytes' => $this->bytes,
        ];
    }
}
