<?php

declare(strict_types=1);

use App\Enums\MediaKind;
use App\Services\Bridge\MediaPayload;
use App\Services\Channel\CloudApiMessage;

/*
|--------------------------------------------------------------------------
| CloudApiMessage (Req 8.2, 8.12 / A8)
|--------------------------------------------------------------------------
| The Cloud API's five request-body shapes. Two of them — `buttons()` and `list()` — have no
| production caller yet, and that is on purpose: `INTERACTIVE` is `✅` on `CLOUD_API` in the
| matrix, but no `OutboundContent` variant can express an interactive payload until task 12.4
| adds one, and inventing that interface here would fix its shape before the task that owns it
| exists (the argument `BaileysChannelDriver` makes about media).
|
| So the wire shape is built, validated and pinned here, and 12.4 adds one `match` arm to
| `CloudApiChannelDriver::send()`. What is asserted is what Meta actually rejects: a missing
| `messaging_product`, a fourth reply button, an over-long title, an empty body, a group
| recipient — every one of them a 4xx that would be charged to the number's quality rating.
*/

it('builds a text body Meta accepts, with previews off unless asked', function (): void {
    expect(CloudApiMessage::text('919812345678', 'Your order shipped.')->body())->toBe([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => '919812345678',
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => 'Your order shipped.'],
    ]);

    // A link preview makes Meta fetch whatever the tenant linked, so it is opt-in.
    $withPreview = CloudApiMessage::text('919812345678', 'See https://acme.test', previewUrl: true, replyTo: 'wamid.in');

    expect($withPreview->body()['text'])->toBe(['preview_url' => true, 'body' => 'See https://acme.test'])
        ->and($withPreview->body()['context'])->toBe(['message_id' => 'wamid.in'])
        ->and($withPreview->type)->toBe('text')
        ->and($withPreview->recipient())->toBe('919812345678');
});

it('normalises a recipient to E.164 digits and refuses what cannot be one', function (): void {
    foreach ([
        '919812345678' => '919812345678',
        '+91 98123 45678' => '919812345678',
        '919812345678@s.whatsapp.net' => '919812345678',
    ] as $given => $expected) {
        expect(CloudApiMessage::recipientDigits((string) $given))->toBe($expected, (string) $given);
    }

    // A group JID is refused rather than reduced: Meta has no group messaging, and a group id
    // stripped of its suffix is a plausible-looking number belonging to a stranger.
    expect(fn (): string => CloudApiMessage::recipientDigits('120363021234567890@g.us'))
        ->toThrow(InvalidArgumentException::class, 'group JID')
        // Too short, and not a number at all: refused rather than sent "best effort".
        ->and(fn (): string => CloudApiMessage::recipientDigits('12345'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => CloudApiMessage::recipientDigits('not-a-number'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a text body that is empty or over WhatsApp\'s own limit', function (): void {
    expect(fn (): CloudApiMessage => CloudApiMessage::text('919812345678', '   '))
        ->toThrow(InvalidArgumentException::class, 'needs a body')
        ->and(fn (): CloudApiMessage => CloudApiMessage::text(
            '919812345678',
            str_repeat('a', CloudApiMessage::MAX_TEXT_LENGTH + 1),
        ))->toThrow(InvalidArgumentException::class, 'limited to 4096');
});

it('builds each media shape with only the fields that kind displays', function (): void {
    $image = CloudApiMessage::media('919812345678', MediaPayload::fromUrl(
        url: 'https://cdn.test/a.png',
        mimeType: 'image/png',
        kind: MediaKind::Image,
        filename: 'a.png',
        caption: 'Look',
    ));

    // An image carries a caption and no filename — the protocol ignores one, so the body stays an
    // honest description of what was asked for.
    expect($image->body()['image'])->toBe(['link' => 'https://cdn.test/a.png', 'caption' => 'Look'])
        ->and($image->body()['type'])->toBe('image');

    $document = CloudApiMessage::media('919812345678', MediaPayload::fromUrl(
        url: 'https://cdn.test/invoice.pdf',
        mimeType: 'application/pdf',
        kind: MediaKind::Document,
        filename: 'invoice.pdf',
    ));

    expect($document->body()['document'])->toBe(['link' => 'https://cdn.test/invoice.pdf', 'filename' => 'invoice.pdf']);

    // Bytes have no inline field on the Graph API, so an uploaded id is required.
    $bytes = MediaPayload::fromBytes(bytes: 'png', mimeType: 'image/png', kind: MediaKind::Image);

    expect(CloudApiMessage::media('919812345678', $bytes, '1234567890')->body()['image'])
        ->toBe(['id' => '1234567890'])
        ->and(fn (): CloudApiMessage => CloudApiMessage::media('919812345678', $bytes))
        ->toThrow(InvalidArgumentException::class, 'already-uploaded attachment');
});

it('builds a template body with WhatsApp\'s underscore locale, and omits empty components', function (): void {
    $components = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Asha']]]];

    expect(CloudApiMessage::template('919812345678', 'order_update', 'en_US', $components)->body()['template'])
        ->toBe([
            'name' => 'order_update',
            'language' => ['code' => 'en_US'],
            'components' => $components,
        ])
        ->and(CloudApiMessage::template('919812345678', 'static_notice', 'en')->body()['template'])
        ->toBe(['name' => 'static_notice', 'language' => ['code' => 'en']]);

    // A hyphenated BCP-47 tag is the commonest way a template send fails at Meta with an
    // unhelpful error, so it is refused here where the caller can see it.
    expect(fn (): CloudApiMessage => CloudApiMessage::template('919812345678', 'order_update', 'en-US'))
        ->toThrow(InvalidArgumentException::class, 'underscore')
        ->and(fn (): CloudApiMessage => CloudApiMessage::template('919812345678', 'Order Update', 'en_US'))
        ->toThrow(InvalidArgumentException::class, 'template name');
});

it('builds an interactive button message in Meta\'s documented key order', function (): void {
    $message = CloudApiMessage::buttons(
        '919812345678',
        'What would you like to do?',
        ['track_order' => 'Track my order', 'talk_to_agent' => 'Talk to an agent'],
        header: 'ACME',
        footer: 'Reply any time',
    );

    expect($message->type)->toBe('interactive')
        ->and($message->body()['type'])->toBe('interactive')
        ->and($message->body()['interactive'])->toBe([
            'type' => 'button',
            'header' => ['type' => 'text', 'text' => 'ACME'],
            'body' => ['text' => 'What would you like to do?'],
            'footer' => ['text' => 'Reply any time'],
            'action' => ['buttons' => [
                // The id is the array key because it is what the inbound webhook echoes back and
                // what a flow correlates on — a positional index would make a caller renumber its
                // own flow when a button is removed.
                ['type' => 'reply', 'reply' => ['id' => 'track_order', 'title' => 'Track my order']],
                ['type' => 'reply', 'reply' => ['id' => 'talk_to_agent', 'title' => 'Talk to an agent']],
            ]],
        ]);

    // Meta drops a fourth button silently, so the choice it offered would simply disappear.
    expect(fn (): CloudApiMessage => CloudApiMessage::buttons('919812345678', 'Pick', [
        'a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D',
    ]))->toThrow(InvalidArgumentException::class, 'at most 3 reply buttons')
        ->and(fn (): CloudApiMessage => CloudApiMessage::buttons('919812345678', 'Pick', []))
        ->toThrow(InvalidArgumentException::class, 'at least one button')
        // Meta truncates an over-long title, so the choice the recipient reads is not the one that
        // was written.
        ->and(fn (): CloudApiMessage => CloudApiMessage::buttons('919812345678', 'Pick', [
            'a' => str_repeat('x', CloudApiMessage::MAX_BUTTON_TITLE_LENGTH + 1),
        ]))->toThrow(InvalidArgumentException::class, 'limited to 20')
        ->and(fn (): CloudApiMessage => CloudApiMessage::buttons('919812345678', '  ', ['a' => 'A']))
        ->toThrow(InvalidArgumentException::class, 'needs body text');
});

it('builds an interactive list message with one section', function (): void {
    $message = CloudApiMessage::list(
        '919812345678',
        'Choose a plan',
        'View plans',
        ['plan_pro' => 'Pro', 'plan_lite' => 'Lite'],
    );

    expect($message->body()['interactive'])->toBe([
        'type' => 'list',
        'body' => ['text' => 'Choose a plan'],
        'action' => [
            'button' => 'View plans',
            'sections' => [['rows' => [
                ['id' => 'plan_pro', 'title' => 'Pro'],
                ['id' => 'plan_lite', 'title' => 'Lite'],
            ]]],
        ],
    ]);

    expect(fn (): CloudApiMessage => CloudApiMessage::list('919812345678', 'Choose', 'Open', []))
        ->toThrow(InvalidArgumentException::class, 'at least one row')
        ->and(fn (): CloudApiMessage => CloudApiMessage::list(
            '919812345678',
            'Choose',
            'Open',
            array_combine(
                array_map(static fn (int $i): string => 'row_'.$i, range(1, CloudApiMessage::MAX_LIST_ROWS + 1)),
                array_map(static fn (int $i): string => 'Row '.$i, range(1, CloudApiMessage::MAX_LIST_ROWS + 1)),
            ),
        ))->toThrow(InvalidArgumentException::class, 'at most 10 rows');
});

it('carries messaging_product on every shape, because Meta refuses a body without it', function (): void {
    $shapes = [
        CloudApiMessage::text('919812345678', 'hi'),
        CloudApiMessage::media('919812345678', MediaPayload::fromUrl(
            url: 'https://cdn.test/a.png',
            mimeType: 'image/png',
            kind: MediaKind::Image,
        )),
        CloudApiMessage::template('919812345678', 'order_update', 'en_US'),
        CloudApiMessage::buttons('919812345678', 'Pick', ['a' => 'A']),
        CloudApiMessage::list('919812345678', 'Pick', 'Open', ['a' => 'A']),
    ];

    foreach ($shapes as $shape) {
        expect($shape->body()['messaging_product'])->toBe(CloudApiMessage::MESSAGING_PRODUCT, $shape->type)
            ->and($shape->body()['to'])->toBe('919812345678')
            ->and($shape->body())->toHaveKey($shape->type === 'interactive' ? 'interactive' : $shape->type);
    }
});
