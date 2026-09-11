<?php

declare(strict_types=1);

use App\Enums\StorageArea;

/*
|--------------------------------------------------------------------------
| Disk layout
|--------------------------------------------------------------------------
| These assertions run against the real disk configuration, so this file
| deliberately does not call Storage::fake() (which rewrites it).
*/

it('backs every tenant storage area with a private disk outside the web root', function (string $disk): void {
    $root = config("filesystems.disks.{$disk}.root");

    expect(config("filesystems.disks.{$disk}.driver"))->toBe('local')
        ->and(config("filesystems.disks.{$disk}.visibility"))->toBe('private')
        ->and(config("filesystems.disks.{$disk}.url"))->toBeNull()
        ->and(config("filesystems.disks.{$disk}.serve"))->toBeFalse()
        ->and(config("filesystems.disks.{$disk}.throw"))->toBeTrue()
        ->and($root)->toBeString()
        ->and($root)->toStartWith(storage_path('app/private'))
        ->and($root)->not->toStartWith(public_path());
})->with(['wa_auth', 'wa_exports', 'wa_media']);

it('gives WhatsApp auth state 0700 directories and 0600 files', function (): void {
    expect(config('filesystems.disks.wa_auth.permissions.dir.private'))->toBe(0700)
        ->and(config('filesystems.disks.wa_auth.permissions.dir.public'))->toBe(0700)
        ->and(config('filesystems.disks.wa_auth.permissions.file.private'))->toBe(0600)
        ->and(config('filesystems.disks.wa_auth.permissions.file.public'))->toBe(0600);
});

it('keeps exports and media private on disk too', function (string $disk): void {
    expect(config("filesystems.disks.{$disk}.permissions.dir.private"))->toBe(0750)
        ->and(config("filesystems.disks.{$disk}.permissions.file.private"))->toBe(0640);
})->with(['wa_exports', 'wa_media']);

it('never symlinks a tenant area into the public directory', function (): void {
    $links = config('filesystems.links');

    expect($links)->toBeArray()
        ->and(array_values((array) $links))->toBe([storage_path('app/public')]);
});

it('maps each storage area to its configured disk', function (): void {
    expect(StorageArea::AuthState->disk())->toBe(config('wa.storage.disks.auth'))
        ->and(StorageArea::Exports->disk())->toBe(config('wa.storage.disks.exports'))
        ->and(StorageArea::Media->disk())->toBe(config('wa.storage.disks.media'))
        ->and(StorageArea::AuthState->disk())->toBe('wa_auth')
        ->and(StorageArea::AuthState->isDownloadable())->toBeFalse()
        ->and(StorageArea::Exports->isDownloadable())->toBeTrue()
        ->and(StorageArea::Media->isDownloadable())->toBeTrue()
        ->and(StorageArea::AuthState->label())->toBe('WhatsApp auth state');
});

it('falls back to the built-in disk name when configuration is blank', function (): void {
    config()->set('wa.storage.disks.media', '');

    expect(StorageArea::Media->disk())->toBe('wa_media');
});
