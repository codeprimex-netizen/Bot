<?php

declare(strict_types=1);

use App\Enums\StorageArea;
use App\Exceptions\Tenancy\MediaRejectedException;
use App\Exceptions\Tenancy\UnsafeStoragePathException;
use App\Models\Tenant;
use App\Services\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\MediaFixtures;

/**
 * Every area is faked so nothing touches the real per-tenant directories.
 */
beforeEach(function (): void {
    foreach (StorageArea::cases() as $area) {
        Storage::fake($area->disk());
    }
});

function tenantStorage(): TenantStorage
{
    return app(TenantStorage::class);
}

/*
|--------------------------------------------------------------------------
| Namespacing (Req 1.4 / A1)
|--------------------------------------------------------------------------
*/

it('namespaces every area under the tenant prefix', function (): void {
    $tenant = Tenant::factory()->create();

    expect(tenantStorage()->prefix($tenant))->toBe('tenants/'.$tenant->id)
        ->and(tenantStorage()->prefix($tenant))->toBe($tenant->storagePrefix());

    foreach (StorageArea::cases() as $area) {
        expect(tenantStorage()->path($tenant, $area, 'file.csv'))
            ->toBe('tenants/'.$tenant->id.'/file.csv');
    }
});

it('never lets two tenants resolve to the same path', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $minePath = tenantStorage()->path($mine, StorageArea::Media, 'photo.png');
    $theirsPath = tenantStorage()->path($theirs, StorageArea::Media, 'photo.png');

    expect($minePath)->not->toBe($theirsPath)
        ->and($minePath)->toStartWith('tenants/'.$mine->id.'/')
        ->and($theirsPath)->toStartWith('tenants/'.$theirs->id.'/')
        ->and($minePath)->not->toContain($theirs->id)
        ->and($theirsPath)->not->toContain($mine->id);
});

it('accepts a bare tenant id but rejects anything that is not a ULID', function (): void {
    $tenant = Tenant::factory()->create();

    expect(tenantStorage()->prefix($tenant->id))->toBe(tenantStorage()->prefix($tenant));

    foreach (['', 'not-a-ulid', '../../etc', '1', str_repeat('Z', 26)] as $bad) {
        expect(fn (): string => tenantStorage()->prefix($bad))
            ->toThrow(UnsafeStoragePathException::class);
    }
});

it('keeps every resolved path inside the tenant prefix', function (): void {
    $tenant = Tenant::factory()->create();
    $prefix = tenantStorage()->prefix($tenant);

    $names = ['report.csv', 'nested/dir/file.json', 'a.b.c-d_e.txt', $prefix.'/already-prefixed.csv'];

    for ($i = 0; $i < 25; $i++) {
        $names[] = tenantStorage()->newFilename('csv');
    }

    foreach ($names as $name) {
        foreach (StorageArea::cases() as $area) {
            expect(tenantStorage()->path($tenant, $area, $name))->toStartWith($prefix.'/');
        }
    }
});

it('refuses a path that is already namespaced to another tenant', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    expect(fn (): string => tenantStorage()->path(
        $mine,
        StorageArea::Media,
        'tenants/'.$theirs->id.'/photo.png',
    ))->toThrow(UnsafeStoragePathException::class);
});

/*
|--------------------------------------------------------------------------
| Path traversal
|--------------------------------------------------------------------------
*/

it('rejects unsafe paths instead of normalising them', function (string $path): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): string => tenantStorage()->path($tenant, StorageArea::Exports, $path))
        ->toThrow(UnsafeStoragePathException::class);
})->with([
    'parent traversal' => '../secret.csv',
    'nested traversal' => 'reports/../../secret.csv',
    'trailing traversal' => 'reports/..',
    'bare dot segment' => './secret.csv',
    'absolute unix path' => '/etc/passwd',
    'home relative path' => '~/secret.csv',
    'absolute windows path' => 'C:\\windows\\system32',
    'backslash separator' => 'reports\\..\\secret.csv',
    'encoded traversal' => '%2e%2e%2fsecret.csv',
    'encoded separator' => 'reports%2Fsecret.csv',
    'encoded null byte' => 'report.csv%00.png',
    'empty path' => '',
    'whitespace path' => '   ',
    'double slash' => 'reports//secret.csv',
    'space in name' => 'my report.csv',
    'glob characters' => 'report*.csv',
    'shell characters' => 'report;rm -rf.csv',
]);

it('rejects a raw null byte in a path', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): string => tenantStorage()->path($tenant, StorageArea::Exports, "report\0.csv"))
        ->toThrow(UnsafeStoragePathException::class);
});

it('rejects an over-long path segment', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): string => tenantStorage()->path($tenant, StorageArea::Media, str_repeat('a', 256).'.png'))
        ->toThrow(UnsafeStoragePathException::class);
});

/*
|--------------------------------------------------------------------------
| Server-generated ULID filenames
|--------------------------------------------------------------------------
*/

it('generates unique ULID filenames', function (): void {
    $names = [];

    for ($i = 0; $i < 200; $i++) {
        $name = tenantStorage()->newFilename('csv');
        $names[] = $name;

        expect(Str::isUlid(pathinfo($name, PATHINFO_FILENAME)))->toBeTrue()
            ->and($name)->toEndWith('.csv');
    }

    expect(array_unique($names))->toHaveCount(200);
});

it('rejects an unusable extension for a generated filename', function (string $extension): void {
    expect(fn (): string => tenantStorage()->newFilename($extension))
        ->toThrow(UnsafeStoragePathException::class);
})->with([
    'traversal' => '../sh',
    'separator' => 'a/b',
    'empty' => '',
    'too long' => 'extension',
    'illegal characters' => 'p h p',
]);

/*
|--------------------------------------------------------------------------
| Exports
|--------------------------------------------------------------------------
*/

it('stores an export under the tenant prefix with a ULID filename', function (): void {
    $tenant = Tenant::factory()->create();

    $stored = tenantStorage()->putExport($tenant, "id,name\n1,Ada\n", 'csv');

    expect($stored->area)->toBe(StorageArea::Exports)
        ->and($stored->disk)->toBe(StorageArea::Exports->disk())
        ->and($stored->path)->toBe('tenants/'.$tenant->id.'/'.$stored->filename())
        ->and($stored->extension)->toBe('csv')
        ->and($stored->mimeType)->toBe('text/csv')
        ->and($stored->bytes)->toBe(14)
        ->and(Str::isUlid(pathinfo($stored->filename(), PATHINFO_FILENAME)))->toBeTrue()
        ->and($stored->toArray())->toHaveKeys(['area', 'disk', 'path', 'mime_type', 'extension', 'bytes']);

    Storage::disk($stored->disk)->assertExists($stored->path);

    expect(tenantStorage()->get($tenant, StorageArea::Exports, $stored->filename()))
        ->toBe("id,name\n1,Ada\n")
        ->and(tenantStorage()->size($tenant, StorageArea::Exports, $stored->filename()))->toBe(14);
});

it('never reuses a client-supplied export filename', function (): void {
    $tenant = Tenant::factory()->create();

    $first = tenantStorage()->putExport($tenant, 'a', 'json');
    $second = tenantStorage()->putExport($tenant, 'b', 'json');

    expect($first->path)->not->toBe($second->path);
});

it('stores an export from a stream', function (): void {
    $tenant = Tenant::factory()->create();
    $stream = MediaFixtures::stream('line-1'.PHP_EOL.'line-2'.PHP_EOL);

    $stored = tenantStorage()->putExportStream($tenant, $stream, 'txt');
    fclose($stream);

    expect($stored->path)->toStartWith('tenants/'.$tenant->id.'/')
        ->and($stored->extension)->toBe('txt')
        ->and($stored->bytes)->toBe(14);

    Storage::disk($stored->disk)->assertExists($stored->path);
});

it('only allows configured export extensions', function (): void {
    $tenant = Tenant::factory()->create();

    foreach (['csv', 'txt', 'json', 'xlsx', 'vcf', 'pdf'] as $extension) {
        expect(tenantStorage()->putExport($tenant, 'x', $extension)->extension)->toBe($extension);
    }

    foreach (['php', 'phtml', 'html', 'sh'] as $extension) {
        expect(fn () => tenantStorage()->putExport($tenant, 'x', $extension))
            ->toThrow(UnsafeStoragePathException::class);
    }
});

it('rejects a non-resource passed as an export stream', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn () => tenantStorage()->putExportStream($tenant, 'not a stream', 'csv'))
        ->toThrow(MediaRejectedException::class);
});

/*
|--------------------------------------------------------------------------
| Media + MIME sniffing
|--------------------------------------------------------------------------
*/

it('stores an upload with a server-generated ULID name and sniffed extension', function (): void {
    $tenant = Tenant::factory()->create();
    $upload = MediaFixtures::upload(MediaFixtures::png(), 'holiday photo.png', 'image/png');

    $stored = tenantStorage()->putMedia($tenant, $upload);

    expect($stored->area)->toBe(StorageArea::Media)
        ->and($stored->mimeType)->toBe('image/png')
        ->and($stored->extension)->toBe('png')
        ->and($stored->bytes)->toBe(strlen(MediaFixtures::png()))
        ->and($stored->path)->toBe('tenants/'.$tenant->id.'/'.$stored->filename())
        ->and($stored->filename())->not->toContain('holiday')
        ->and(Str::isUlid(pathinfo($stored->filename(), PATHINFO_FILENAME)))->toBeTrue();

    Storage::disk($stored->disk)->assertExists($stored->path);
});

it('derives the extension from sniffed content when the client extension lies', function (): void {
    $tenant = Tenant::factory()->create();

    // A PDF dressed up as a PNG, with a matching lie in the content-type header.
    $upload = MediaFixtures::upload(MediaFixtures::pdf(), 'invoice.png', 'image/png');

    $stored = tenantStorage()->putMedia($tenant, $upload);

    expect($stored->mimeType)->toBe('application/pdf')
        ->and($stored->extension)->toBe('pdf')
        ->and($stored->path)->toEndWith('.pdf')
        ->and($stored->path)->not->toContain('.png');
});

it('rejects media whose sniffed type is not allowed, whatever the client claims', function (): void {
    $tenant = Tenant::factory()->create();
    $upload = MediaFixtures::upload(MediaFixtures::plainText(), 'photo.png', 'image/png');

    expect(fn () => tenantStorage()->putMedia($tenant, $upload))
        ->toThrow(MediaRejectedException::class);

    expect(tenantStorage()->files($tenant, StorageArea::Media))->toBe([]);
});

it('rejects media larger than the configured cap', function (): void {
    config()->set('wa.media.max_bytes', 32);
    $tenant = Tenant::factory()->create();

    $stream = MediaFixtures::stream(MediaFixtures::png());

    expect(fn () => tenantStorage()->putMediaContents($tenant, MediaFixtures::png()))
        ->toThrow(MediaRejectedException::class)
        ->and(fn () => tenantStorage()->putMediaStream($tenant, $stream))
        ->toThrow(MediaRejectedException::class);

    fclose($stream);

    expect(tenantStorage()->files($tenant, StorageArea::Media))->toBe([]);
});

it('stores media from raw contents and from a stream', function (): void {
    $tenant = Tenant::factory()->create();

    $fromContents = tenantStorage()->putMediaContents($tenant, MediaFixtures::png());

    $stream = MediaFixtures::stream(MediaFixtures::pdf());
    $fromStream = tenantStorage()->putMediaStream($tenant, $stream);
    fclose($stream);

    expect($fromContents->extension)->toBe('png')
        ->and($fromStream->extension)->toBe('pdf')
        ->and($fromStream->bytes)->toBe(strlen(MediaFixtures::pdf()))
        ->and(tenantStorage()->files($tenant, StorageArea::Media))->toHaveCount(2);

    expect(tenantStorage()->get($tenant, StorageArea::Media, $fromContents->filename()))
        ->toBe(MediaFixtures::png());
});

it('rejects an empty media payload', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn () => tenantStorage()->putMediaContents($tenant, ''))
        ->toThrow(MediaRejectedException::class);
});

/*
|--------------------------------------------------------------------------
| WhatsApp auth state
|--------------------------------------------------------------------------
*/

it('namespaces auth state per tenant and per session', function (): void {
    $tenant = Tenant::factory()->create();
    $sessionId = (string) Str::ulid();

    expect(tenantStorage()->authStatePath($tenant))->toBe('tenants/'.$tenant->id)
        ->and(tenantStorage()->authStatePath($tenant, $sessionId))
        ->toBe('tenants/'.$tenant->id.'/'.$sessionId);

    $created = tenantStorage()->ensureAuthStateDirectory($tenant, $sessionId);

    expect($created)->toBe('tenants/'.$tenant->id.'/'.$sessionId);
    Storage::disk(StorageArea::AuthState->disk())->assertExists($created);

    // Idempotent: the bridge re-creates it on every session boot.
    expect(tenantStorage()->ensureAuthStateDirectory($tenant, $sessionId))->toBe($created);
});

it('exposes an absolute auth-state path inside the tenant prefix for the bridge', function (): void {
    $tenant = Tenant::factory()->create();
    $sessionId = (string) Str::ulid();

    $full = tenantStorage()->authStateFullPath($tenant, $sessionId);

    expect($full)->toContain('tenants/'.$tenant->id.'/'.$sessionId)
        ->and($full)->toBe(tenantStorage()->fullPath($tenant, StorageArea::AuthState, $sessionId))
        ->and(str_starts_with($full, public_path()))->toBeFalse();
});

it('deletes auth state for one session without touching the tenant others', function (): void {
    $tenant = Tenant::factory()->create();
    $first = (string) Str::ulid();
    $second = (string) Str::ulid();
    $disk = Storage::disk(StorageArea::AuthState->disk());

    foreach ([$first, $second] as $sessionId) {
        tenantStorage()->ensureAuthStateDirectory($tenant, $sessionId);
        $disk->put(tenantStorage()->authStatePath($tenant, $sessionId).'/creds.json', '{}');
    }

    expect(tenantStorage()->deleteAuthState($tenant, $first))->toBeTrue();

    $disk->assertMissing(tenantStorage()->authStatePath($tenant, $first).'/creds.json');
    $disk->assertExists(tenantStorage()->authStatePath($tenant, $second).'/creds.json');
});

it('rejects a session id that is not a ULID', function (string $sessionId): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): string => tenantStorage()->authStatePath($tenant, $sessionId))
        ->toThrow(UnsafeStoragePathException::class);
})->with([
    'traversal' => '../../other',
    'absolute' => '/etc',
    'empty' => '',
    'arbitrary' => 'session-one',
]);

/*
|--------------------------------------------------------------------------
| Tenant-scoped deletion
|--------------------------------------------------------------------------
*/

it('deletes a single file only within the owning tenant prefix', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $ours = tenantStorage()->putExport($mine, 'ours', 'csv');
    $others = tenantStorage()->putExport($theirs, 'theirs', 'csv');

    expect(tenantStorage()->delete($mine, StorageArea::Exports, $ours->filename()))->toBeTrue();

    Storage::disk($ours->disk)->assertMissing($ours->path);
    Storage::disk($others->disk)->assertExists($others->path);

    // Naming the other tenant's file from our context is refused outright.
    expect(fn (): bool => tenantStorage()->delete($mine, StorageArea::Exports, $others->path))
        ->toThrow(UnsafeStoragePathException::class);

    Storage::disk($others->disk)->assertExists($others->path);
});

it('deletes one area for one tenant without touching the other tenant', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $ourMedia = tenantStorage()->putMediaContents($mine, MediaFixtures::png());
    $ourExport = tenantStorage()->putExport($mine, 'ours', 'csv');
    $theirMedia = tenantStorage()->putMediaContents($theirs, MediaFixtures::png());

    expect(tenantStorage()->deleteArea($mine, StorageArea::Media))->toBeTrue();

    Storage::disk($ourMedia->disk)->assertMissing($ourMedia->path);
    Storage::disk($theirMedia->disk)->assertExists($theirMedia->path);
    Storage::disk($ourExport->disk)->assertExists($ourExport->path);

    expect(tenantStorage()->files($mine, StorageArea::Media))->toBe([])
        ->and(tenantStorage()->files($theirs, StorageArea::Media))->toHaveCount(1);
});

it('purges every area for one tenant and leaves other tenants intact', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $sessionId = (string) Str::ulid();

    tenantStorage()->ensureAuthStateDirectory($mine, $sessionId);
    Storage::disk(StorageArea::AuthState->disk())
        ->put(tenantStorage()->authStatePath($mine, $sessionId).'/creds.json', '{}');
    tenantStorage()->putMediaContents($mine, MediaFixtures::png());
    tenantStorage()->putExport($mine, 'ours', 'csv');

    tenantStorage()->ensureAuthStateDirectory($theirs, (string) Str::ulid());
    $theirExport = tenantStorage()->putExport($theirs, 'theirs', 'csv');
    $theirMedia = tenantStorage()->putMediaContents($theirs, MediaFixtures::png());

    expect(tenantStorage()->isPurged($mine))->toBeFalse();

    $results = tenantStorage()->purge($mine);

    expect($results)->toHaveKeys(['auth', 'exports', 'media'])
        ->and(tenantStorage()->isPurged($mine))->toBeTrue()
        ->and(tenantStorage()->isPurged($theirs))->toBeFalse();

    Storage::disk($theirExport->disk)->assertExists($theirExport->path);
    Storage::disk($theirMedia->disk)->assertExists($theirMedia->path);
});

it('reports existence and file listings per tenant', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $ours = tenantStorage()->putExport($mine, 'ours', 'csv');
    tenantStorage()->putExport($theirs, 'theirs', 'csv');

    expect(tenantStorage()->exists($mine, StorageArea::Exports, $ours->filename()))->toBeTrue()
        ->and(tenantStorage()->exists($theirs, StorageArea::Exports, $ours->filename()))->toBeFalse()
        ->and(tenantStorage()->files($mine, StorageArea::Exports))->toBe([$ours->path]);
});

it('reads a stored file back through a stream', function (): void {
    $tenant = Tenant::factory()->create();
    $stored = tenantStorage()->putExport($tenant, 'streamed', 'txt');

    $stream = tenantStorage()->readStream($tenant, StorageArea::Exports, $stored->filename());

    expect(is_resource($stream))->toBeTrue();

    $contents = is_resource($stream) ? stream_get_contents($stream) : '';

    if (is_resource($stream)) {
        fclose($stream);
    }

    expect($contents)->toBe('streamed');
});

it('throws when reading a file that does not exist', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): string => tenantStorage()->get($tenant, StorageArea::Exports, 'missing.csv'))
        ->toThrow(RuntimeException::class);
});
