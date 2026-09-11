<?php

declare(strict_types=1);

use App\Enums\PiiKind;
use App\Support\Pii\PiiScanner;
use App\Support\Pii\PiiSpan;
use App\Support\Pii\TenantPatternCompiler;

/*
|--------------------------------------------------------------------------
| What counts as PII, tested adversarially (Req 32.2 / NFR3, Property 15)
|--------------------------------------------------------------------------
| A redactor is only as good as its worst case, so this file is deliberately
| unkind: PII embedded mid-word, several values in one string, values inside a
| JSON blob, non-ASCII digit scripts, and every human way of writing a phone
| number. A detector that only catches the tidy format is a detector that leaks.
|
| The other half is just as important and is easier to get wrong: an order id, a
| date, an IP address, and a money amount must all come out the other side
| **untouched**, because a redactor that eats them destroys the context an LLM
| needs and the operability a log line exists for.
*/

/**
 * Every span the scanner finds, as `[kind, text]` pairs.
 *
 * @return list<array{0: string, 1: string}>
 */
function scannerSpans(string $text): array
{
    return array_map(
        static fn (PiiSpan $span): array => [$span->kind->value, $span->text],
        (new PiiScanner)->scan($text),
    );
}

/**
 * The texts the scanner claims, for one kind.
 *
 * @return list<string>
 */
function scannerFound(string $text, PiiKind $kind): array
{
    $found = [];

    foreach ((new PiiScanner)->scan($text) as $span) {
        if ($span->kind === $kind) {
            $found[] = $span->text;
        }
    }

    return $found;
}

/*
|--------------------------------------------------------------------------
| Phone numbers, in every shape people write them
|--------------------------------------------------------------------------
*/

it('detects a phone number in every format it is written in', function (string $text, string $expected): void {
    expect(scannerFound($text, PiiKind::Phone))->toBe([$expected]);
})->with([
    'E.164' => ['ring +14155552671 today', '+14155552671'],
    'E.164 spaced' => ['ring +1 415 555 2671 today', '+1 415 555 2671'],
    'parenthesised' => ['ring (555) 123-4567 today', '(555) 123-4567'],
    'dashed' => ['ring 415-555-2671 today', '415-555-2671'],
    'dotted' => ['ring 555.123.4567 today', '555.123.4567'],
    'india spaced' => ['ring +91 98765 43210 today', '+91 98765 43210'],
    'country code and parens' => ['ring +1 (415) 555-2671 today', '+1 (415) 555-2671'],
    'bare msisdn' => ['ring 919876543210 today', '919876543210'],
    // No word boundary is required: a number glued to a word is still a number.
    'embedded mid-word' => ['whatsapp919876543210now', '919876543210'],
    'arabic-indic digits' => ['ring ٩١٩٨٧٦٥٤٣٢١٠ today', '٩١٩٨٧٦٥٤٣٢١٠'],
    'fullwidth digits' => ['ring ４１５５５５２６７１ today', '４１５５５５２６７１'],
    'devanagari digits' => ['ring ९१९८७६५४३२१० today', '९१९८७६५४३२१०'],
]);

/*
|--------------------------------------------------------------------------
| Emails
|--------------------------------------------------------------------------
*/

it('detects an email address including plus-addressing, subdomains and unicode', function (string $text, string $expected): void {
    expect(scannerFound($text, PiiKind::Email))->toBe([$expected]);
})->with([
    'plain' => ['mail jane@example.com please', 'jane@example.com'],
    'plus addressing' => ['mail jane.doe+shop@example.com please', 'jane.doe+shop@example.com'],
    'subdomains' => ['mail jane@mail.corp.example.co.uk please', 'jane@mail.corp.example.co.uk'],
    'unicode local part' => ['mail josé.piñón@example.com please', 'josé.piñón@example.com'],
    'embedded mid-word' => ['contactjane@example.com!', 'contactjane@example.com'],
    // A WhatsApp JID is structurally an address, and its local part is an msisdn.
    'whatsapp jid' => ['from 919876543210@s.whatsapp.net', '919876543210@s.whatsapp.net'],
]);

/*
|--------------------------------------------------------------------------
| Cards — Luhn, not digit-run length
|--------------------------------------------------------------------------
*/

it('detects a card number with and without separators', function (string $text, string $expected): void {
    expect(scannerFound($text, PiiKind::Card))->toBe([$expected]);
})->with([
    'visa unseparated' => ['paid with 4111111111111111 yesterday', '4111111111111111'],
    'visa spaced' => ['paid with 4111 1111 1111 1111 yesterday', '4111 1111 1111 1111'],
    'mastercard dashed' => ['paid with 5500-0055-5555-5559 yesterday', '5500-0055-5555-5559'],
    // 15 digits, and Luhn-valid: the length bound is 13–19, not "16".
    'amex' => ['paid with 378282246310005 yesterday', '378282246310005'],
    'discover' => ['paid with 6011111111111117 yesterday', '6011111111111117'],
]);

it('leaves a long identifier that is not a card alone', function (): void {
    // 1234567890123456 fails Luhn (its check sum is 64), so the *shape* test passing
    // is not enough — this is the discriminator that keeps order ids and message ids
    // out of the redactor.
    expect(scannerSpans('order 1234567890123456 shipped'))->toBe([])
        // Longer than E.164 allows and longer than any card: not a phone either.
        ->and(scannerSpans('trace 12345678901234567890'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The things that must survive
|--------------------------------------------------------------------------
*/

it('leaves dates, IP addresses, money, times and ids untouched', function (string $text): void {
    expect(scannerSpans($text))->toBe([]);
})->with([
    'iso date' => ['delivered on 2024-06-13 as promised'],
    'dotted date' => ['delivered on 13.06.2024 as promised'],
    'slashed date' => ['delivered on 06/13/2024 as promised'],
    'ipv4' => ['request from 192.168.1.100 failed'],
    'ipv4 public' => ['request from 203.0.113.42 failed'],
    'money' => ['total 1,234,567 rupees'],
    'clock time' => ['at 10:30:45 sharp'],
    'ulid' => ['id 01HZY8Q0J9ABCDEFGHJKMNPQRS'],
    'version' => ['build 1.2.3'],
    'short number' => ['order #1234'],
]);

/*
|--------------------------------------------------------------------------
| Several values at once, and values buried in structure
|--------------------------------------------------------------------------
*/

it('finds every value in a string that holds several', function (): void {
    $spans = scannerSpans('call +14155552671 or +919876543210, mail a@b.co and c@d.io, card 4111111111111111');

    expect($spans)->toBe([
        ['PHONE', '+14155552671'],
        ['PHONE', '+919876543210'],
        ['EMAIL', 'a@b.co'],
        ['EMAIL', 'c@d.io'],
        ['CARD', '4111111111111111'],
    ]);
});

it('finds values inside serialised structure', function (): void {
    $json = json_encode([
        'contact' => ['phone' => '+14155552671', 'email' => 'jane@example.com'],
        'orders' => [['id' => '1234567890123456', 'paid_with' => '4111111111111111']],
    ], JSON_THROW_ON_ERROR);

    $kinds = array_column(scannerSpans($json), 0);

    expect($kinds)->toBe(['PHONE', 'EMAIL', 'CARD'])
        // The order id inside the JSON is not a card and is left readable.
        ->and($json)->toContain('1234567890123456');
});

/*
|--------------------------------------------------------------------------
| Overlap and idempotence
|--------------------------------------------------------------------------
*/

it('never returns overlapping spans', function (): void {
    // The Amex number is claimed by both the card detector (15 digits, Luhn) and the
    // bare-phone detector (15 digits); exactly one span must survive, and it must be
    // the structurally verified one.
    $spans = scannerSpans('paid 378282246310005');

    expect($spans)->toBe([['CARD', '378282246310005']]);
});

it('treats an existing token as ground it must not touch', function (): void {
    $text = 'call [[PII:PHONE:abcdefgh:1]] or +14155552671';

    expect(scannerSpans($text))->toBe([['PHONE', '+14155552671']]);
});

/*
|--------------------------------------------------------------------------
| Malformed input is scanned, not skipped
|--------------------------------------------------------------------------
*/

it('still scans text that is not valid UTF-8', function (): void {
    $text = "call +14155552671 \xC3\x28 now";

    expect(mb_check_encoding($text, 'UTF-8'))->toBeFalse()
        ->and(scannerFound($text, PiiKind::Phone))->toBe(['+14155552671']);
});

/*
|--------------------------------------------------------------------------
| Tenant patterns
|--------------------------------------------------------------------------
*/

it('applies a validated tenant pattern alongside the built-ins', function (): void {
    $compiled = (new TenantPatternCompiler)->compile(['\bPOL-\d{6}\b']);

    $spans = (new PiiScanner)->scan('policy POL-123456 for jane@example.com', $compiled->patterns);

    expect(array_map(static fn ($span): array => [$span->kind->value, $span->text], $spans))->toBe([
        ['CUSTOM', 'POL-123456'],
        ['EMAIL', 'jane@example.com'],
    ]);
});
