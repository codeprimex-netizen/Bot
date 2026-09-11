<?php

declare(strict_types=1);

use App\Enums\MediaKind;
use App\Services\Bridge\MediaPayload;

/*
|--------------------------------------------------------------------------
| The media attachment on the wire
|--------------------------------------------------------------------------
| Two invariants, both with a silent failure mode if they are not enforced here: a payload
| has exactly one source (neither means a blank attachment arrives at a customer, both
| means the bridge picks one arbitrarily), and it never carries a field the protocol would
| drop. The size and MIME allow-list are deliberately *not* re-checked — `TenantStorage`
| owns those at the point the bytes are accepted, and a second copy could disagree.
*/

it('sends stored media as a url the bridge fetches for itself', function (): void {
    $payload = MediaPayload::fromUrl('https://cdn.test/a.jpg', 'image/jpeg', caption: 'Look');

    expect($payload->kind)->toBe(MediaKind::Image)
        ->and($payload->url)->toBe('https://cdn.test/a.jpg')
        ->and($payload->base64)->toBeNull()
        ->and($payload->toRequest())->toBe([
            'kind' => 'image',
            'mime_type' => 'image/jpeg',
            'url' => 'https://cdn.test/a.jpg',
            'caption' => 'Look',
        ]);
});

it('sends in-process bytes inline, base64-encoded', function (): void {
    $payload = MediaPayload::fromBytes('hello', 'audio/ogg');

    expect($payload->kind)->toBe(MediaKind::Audio)
        ->and($payload->base64)->toBe(base64_encode('hello'))
        ->and($payload->url)->toBeNull();
});

it('refuses a payload with no source and one with two', function (): void {
    expect(fn () => new MediaPayload(MediaKind::Image, 'image/jpeg'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MediaPayload(MediaKind::Image, 'image/jpeg', url: 'https://a.test/x', base64: 'eA=='))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a payload with no mime type, because the bridge selects the message shape from it', function (): void {
    expect(fn () => new MediaPayload(MediaKind::Image, '  ', url: 'https://a.test/x'))
        ->toThrow(InvalidArgumentException::class);
});

it('emits a filename only for the kind that displays one', function (): void {
    $document = MediaPayload::fromUrl('https://a.test/x.pdf', 'application/pdf', filename: 'invoice.pdf');
    $image = MediaPayload::fromUrl('https://a.test/x.jpg', 'image/jpeg', filename: 'ignored.jpg');

    expect($document->toRequest())->toHaveKey('filename')
        // A field the protocol ignores is dead weight, and a request body that lists it is a
        // dishonest description of what was asked for.
        ->and($image->toRequest())->not->toHaveKey('filename');
});

it('refuses a caption on a sticker where the caller can still see the mistake', function (): void {
    // The protocol drops it silently, which is the worst possible outcome: the tenant sees a
    // successful send and the customer sees a sticker with no message.
    expect(fn () => MediaPayload::fromUrl('https://a.test/x.webp', 'image/webp', caption: 'hi'))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps inline bytes out of a debug dump', function (): void {
    $payload = MediaPayload::fromBytes('a voice note, or somebody\'s ID document', 'audio/ogg');

    $described = print_r($payload->__debugInfo(), true);

    expect($described)->not->toContain('ID document')
        ->and($payload->__debugInfo()['source'])->toBe('bytes')
        ->and($payload->__debugInfo()['bytes'])->toBeInt();
});
