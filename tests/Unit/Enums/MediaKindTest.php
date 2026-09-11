<?php

declare(strict_types=1);

use App\Enums\MediaKind;

it('exposes the five protocol message shapes', function (): void {
    expect(MediaKind::values())->toBe(['image', 'video', 'audio', 'document', 'sticker']);
});

it('maps a mime type to the kind it most plausibly is', function (string $mime, MediaKind $expected): void {
    expect(MediaKind::fromMimeType($mime))->toBe($expected);
})->with([
    ['image/jpeg', MediaKind::Image],
    ['IMAGE/PNG', MediaKind::Image],
    // WebP is WhatsApp's sticker format, so it is the one image type that is not an image.
    ['image/webp', MediaKind::Sticker],
    ['video/mp4', MediaKind::Video],
    ['audio/ogg', MediaKind::Audio],
    ['application/pdf', MediaKind::Document],
    // Universal fallback: an unknown type still reaches the recipient as a document, which
    // beats refusing a send over a classification we did not need to make.
    ['application/x-who-knows', MediaKind::Document],
]);

it('names which kinds display a filename and which render a caption', function (): void {
    expect(MediaKind::Document->usesFilename())->toBeTrue()
        ->and(MediaKind::Image->usesFilename())->toBeFalse()
        ->and(MediaKind::Sticker->usesCaption())->toBeFalse();

    foreach (MediaKind::cases() as $kind) {
        if ($kind !== MediaKind::Sticker) {
            expect($kind->usesCaption())->toBeTrue($kind->value.' should render a caption');
        }

        expect($kind->label())->not->toBe('');
    }
});
