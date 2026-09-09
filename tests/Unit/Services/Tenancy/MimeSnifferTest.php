<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\MediaRejectedException;
use App\Services\Tenancy\MimeSniffer;
use Tests\Fixtures\MediaFixtures;

it('sniffs the MIME type from content, not from a filename', function (): void {
    $sniffer = new MimeSniffer;

    expect($sniffer->fromContents(MediaFixtures::png()))->toBe('image/png')
        ->and($sniffer->fromContents(MediaFixtures::pdf()))->toBe('application/pdf')
        ->and($sniffer->fromContents(MediaFixtures::plainText()))->toBe('text/plain');
});

it('sniffs the MIME type of a file on disk', function (): void {
    $sniffer = new MimeSniffer;
    $path = tempnam(sys_get_temp_dir(), 'sniff-');

    expect($path)->toBeString();

    // A PNG saved with a deliberately misleading name.
    $path = (string) $path;
    file_put_contents($path, MediaFixtures::png());

    expect($sniffer->fromPath($path))->toBe('image/png');

    unlink($path);
});

it('throws when the payload cannot be read or is empty', function (): void {
    $sniffer = new MimeSniffer;

    expect(fn (): string => $sniffer->fromPath(sys_get_temp_dir().'/does-not-exist-'.uniqid()))
        ->toThrow(MediaRejectedException::class)
        ->and(fn (): string => $sniffer->fromContents(''))
        ->toThrow(MediaRejectedException::class);
});

it('honours the configured allow-list', function (): void {
    $sniffer = new MimeSniffer;

    expect($sniffer->isAllowedMediaType('image/png'))->toBeTrue()
        ->and($sniffer->isAllowedMediaType('IMAGE/PNG'))->toBeTrue()
        ->and($sniffer->isAllowedMediaType('image/png; charset=binary'))->toBeTrue()
        ->and($sniffer->isAllowedMediaType('text/html'))->toBeFalse()
        ->and($sniffer->allowedMediaTypes())->toContain('application/pdf');

    expect(fn (): string => $sniffer->assertAllowedMediaType('text/html'))
        ->toThrow(MediaRejectedException::class);

    expect($sniffer->assertAllowedMediaType('image/jpeg'))->toBe('image/jpeg');
});

it('maps sniffed types to configured extensions', function (): void {
    $sniffer = new MimeSniffer;

    expect($sniffer->extensionFor('image/jpeg'))->toBe('jpg')
        ->and($sniffer->extensionFor('image/png'))->toBe('png')
        ->and($sniffer->extensionFor('audio/mpeg'))->toBe('mp3')
        ->and($sniffer->extensionFor('application/pdf'))->toBe('pdf');
});

it('falls back to the platform MIME database for unmapped types', function (): void {
    $sniffer = new MimeSniffer;
    config()->set('wa.media.extensions', []);

    expect($sniffer->extensionFor('image/png'))->toBe('png');
});

it('throws when no extension is known for a type', function (): void {
    $sniffer = new MimeSniffer;
    config()->set('wa.media.extensions', []);

    expect(fn (): string => $sniffer->extensionFor('application/x-not-a-real-type'))
        ->toThrow(MediaRejectedException::class);
});

it('ignores a non-string extension mapping and falls through', function (): void {
    $sniffer = new MimeSniffer;
    config()->set('wa.media.extensions', ['image/png' => ['nope']]);

    expect($sniffer->extensionFor('image/png'))->toBe('png');
});

it('resolves MIME types for server-chosen export extensions', function (): void {
    $sniffer = new MimeSniffer;

    expect($sniffer->mimeTypeForExtension('csv'))->toBe('text/csv')
        ->and($sniffer->mimeTypeForExtension('json'))->toBe('application/json')
        ->and($sniffer->mimeTypeForExtension('pdf'))->toBe('application/pdf')
        ->and($sniffer->mimeTypeForExtension('not-a-real-extension'))->toBe('application/octet-stream');
});

it('treats a malformed allow-list as empty rather than allowing everything', function (): void {
    $sniffer = new MimeSniffer;
    config()->set('wa.media.allowed_mimes', 'image/png');

    expect($sniffer->allowedMediaTypes())->toBe([])
        ->and($sniffer->isAllowedMediaType('image/png'))->toBeFalse();
});
