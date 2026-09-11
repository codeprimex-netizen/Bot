<?php

declare(strict_types=1);

use App\Support\Pii\LogPiiScrubber;
use App\Support\Pii\PiiScanner;

/*
|--------------------------------------------------------------------------
| The one-way pass (Req 7.3 / A7)
|--------------------------------------------------------------------------
| The feature test proves this reaches every channel; this file proves the mask
| itself is worth reaching. Three properties matter:
|
|   1. what is left behind is useful — a phone keeps four digits, a card its last
|      four, an email its domain — and nothing more;
|   2. the mask is **inert**: masking twice changes nothing, and a hashed body is
|      not hashed again, so a record that crosses two channels is not mangled;
|   3. the walk is bounded, because it runs on every log line in the platform.
*/

function scrubber(int $maxStringLength = 4000, int $maxDepth = 8, int $maxItems = 100): LogPiiScrubber
{
    return new LogPiiScrubber(new PiiScanner, $maxStringLength, $maxDepth, $maxItems);
}

it('masks each kind so a little is left to correlate with', function (string $text, string $expected): void {
    expect(scrubber()->scrub($text))->toBe($expected);
})->with([
    'phone' => ['call +14155552671 now', 'call +14*******71 now'],
    'bare msisdn' => ['call 919876543210 now', 'call 91********10 now'],
    'card keeps last four' => ['paid 4111111111111111', 'paid ************1111'],
    'email keeps the domain' => ['mail jane.doe+shop@example.com', 'mail j***@example.com'],
]);

it('produces masks that cannot be detected again, so masking twice is a no-op', function (string $text): void {
    $scrubber = scrubber();

    $once = $scrubber->scrub($text);

    expect($scrubber->scrub($once))->toBe($once);
})->with([
    ['call +14155552671 or 415-555-2671'],
    ['mail a@b.io and jane.doe@mail.example.co.uk'],
    ['card 4111 1111 1111 1111'],
    ['ring ٩١٩٨٧٦٥٤٣٢١٠'],
]);

it('hashes a message body and never writes it', function (): void {
    $scrubbed = scrubber()->scrubPayload(['body' => 'the actual message', 'event' => 'reply.sent']);

    expect($scrubbed['body'])->toBe(sprintf('sha256:%s chars:18', hash('sha256', 'the actual message')))
        ->and($scrubbed['event'])->toBe('reply.sent');
});

it('does not hash a digest a second time', function (): void {
    $scrubber = scrubber();

    $once = $scrubber->scrubPayload(['body' => 'stable']);
    $twice = $scrubber->scrubPayload($once);

    expect($twice['body'])->toBe($once['body']);
});

it('records a digest, a ULID, and a correlation id verbatim', function (): void {
    // The bare-phone detector is `\d{7,15}` with digit boundaries, and hex digests and
    // base32 ids are full of digit runs separated by letters — so roughly one digest in
    // ten used to come out mangled (`…8f56*****94f41***28d`), destroying the evidence the
    // line exists for. Data-dependent, so it read as flakiness rather than a rule.
    $digest = 'ffdee58e35234afe735771fb59cd9abd9482b11c160b8f567743194f4164128d';

    $scrubbed = scrubber()->scrubPayload([
        'content_hash' => $digest,
        'subject_hash' => hash('sha256', 'subject'),
        'trace_id' => '01HX9M0123456789ABCDEFGHJK',
        'checksum' => 'crc32:1234567890abc',
        'session_key' => 'sess_01hx9m',
    ]);

    expect($scrubbed['content_hash'])->toBe($digest)
        ->and($scrubbed['subject_hash'])->toBe(hash('sha256', 'subject'))
        ->and($scrubbed['trace_id'])->toBe('01HX9M0123456789ABCDEFGHJK')
        ->and($scrubbed['checksum'])->toBe('crc32:1234567890abc')
        ->and($scrubbed['session_key'])->toBe('sess_01hx9m');
});

it('does not let an identifier key become a way to log PII', function (): void {
    $scrubbed = scrubber()->scrubPayload([
        // All digits: no letter, so the opaque exemption does not apply and the phone
        // detector still gets it.
        'customer_id' => '919876543210',
        // Structure the exemption does not accept.
        'external_id' => 'jane.doe@example.com',
        // An identity key wins over the opaque rule, whatever the value looks like.
        'wa_id' => '919876543210@s.whatsapp.net',
        // A caller-supplied key is not an opaque platform identifier: exempting every
        // `*_key` would turn a client-controlled field into a way to log a phone number.
        'idempotency_key' => 'msg-919876543210',
        'dedup_key' => 'evt-14155552671',
    ]);

    expect($scrubbed['customer_id'])->toBe('91********10')
        ->and($scrubbed['external_id'])->toBe('j***@example.com')
        ->and($scrubbed['wa_id'])->not->toContain('919876543210')
        ->and($scrubbed['idempotency_key'])->not->toContain('919876543210')
        ->and($scrubbed['dedup_key'])->not->toContain('14155552671');
});

it('drops a secret whatever its value looks like', function (): void {
    $scrubbed = scrubber()->scrubPayload([
        'api_key' => 'sk-live-1234',
        'authorization' => 'Bearer abc.def',
        'nested' => ['private_key' => '-----BEGIN-----'],
    ]);

    expect($scrubbed['api_key'])->toBe(LogPiiScrubber::REDACTED)
        ->and($scrubbed['authorization'])->toBe(LogPiiScrubber::REDACTED)
        ->and($scrubbed['nested']['private_key'])->toBe(LogPiiScrubber::REDACTED);
});

it('masks a value under an identity key even when its shape says nothing', function (): void {
    // Seven digits with no separators is below every structural threshold, but a value
    // under `phone` is a phone number by declaration.
    expect(scrubber()->scrubPayload(['phone' => '5551234']))->toBe(['phone' => '55***34']);
});

it('flattens an exception into a masked shape', function (): void {
    $shape = scrubber()->scrubPayload([
        'exception' => new RuntimeException('call +14155552671', 7, new LogicException('mail a@b.io')),
    ]);

    /** @var array<string, mixed> $exception */
    $exception = $shape['exception'];

    expect($exception['class'])->toBe(RuntimeException::class)
        ->and($exception['message'])->toBe('call +14*******71')
        ->and($exception['code'])->toBe(7)
        ->and($exception['line'])->toBeInt();

    /** @var array<string, mixed> $previous */
    $previous = $exception['previous'];

    expect($previous['class'])->toBe(LogicException::class)
        ->and($previous['message'])->toBe('mail a***@b.io');
});

it('bounds the work it will do on one record', function (): void {
    $scrubber = scrubber(maxStringLength: 20, maxDepth: 2, maxItems: 3);

    $scrubbed = $scrubber->scrubPayload([
        'long' => str_repeat('x', 50),
        'deep' => ['a' => ['b' => ['c' => ['d' => 'too far']]]],
        'wide' => [1, 2, 3, 4, 5],
    ]);

    expect($scrubbed['long'])->toBe(str_repeat('x', 20).'…[truncated]')
        ->and(json_encode($scrubbed['deep']))->toContain('depth limit')
        ->and($scrubbed['wide'])->toHaveKey('[capped]');
});

it('describes an object rather than dumping its properties', function (): void {
    $scrubbed = scrubber()->scrubPayload([
        'client' => new class
        {
            public string $phone = '+14155552671';
        },
    ]);

    expect(json_encode($scrubbed))->not->toContain('+14155552671');
});
