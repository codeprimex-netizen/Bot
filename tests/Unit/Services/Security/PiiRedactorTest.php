<?php

declare(strict_types=1);

use App\Exceptions\Security\PiiRedactionException;
use App\Services\Security\Pii\PiiRedactor;
use App\Services\Security\Pii\TokenizingPiiRedactor;
use App\Services\Security\Pii\TokenMap;

/*
|--------------------------------------------------------------------------
| Both halves of Correctness Property 15 (Req 32.2 / NFR3; Req 13.7 / B4)
|--------------------------------------------------------------------------
| Property 15 is one sentence with two obligations, and a redactor that satisfies
| only one of them is useless:
|
|   1. `rehydrate(redact(x)) == x` — **exactly**. The customer reads the rehydrated
|      reply, so a lost character is a visible defect and a lost value is a broken
|      conversation.
|   2. the masked text matches **no** PII pattern. Asserted here the strong way:
|      none of the seeded values appears anywhere in the output, in any of its
|      formats — not "our own regex no longer matches", which would be circular.
|
| Plus the three properties that make the first two hold in practice: idempotence,
| token stability, and a token map whose lifetime cannot be extended.
|
| The dedicated property-based test for Property 15 is task 4.8; these are the unit
| obligations that must hold before it is written.
*/

/**
 * A redactor with no tenant bound — built-in detectors only.
 */
function redactor(): PiiRedactor
{
    return app(PiiRedactor::class);
}

/**
 * The adversarial corpus: several values per string, unusual formats, structure,
 * and digits from three scripts.
 *
 * @return list<array{0: string, 1: list<string>}> text and the values it must lose
 */
function piiCorpus(): array
{
    return [
        ['call +14155552671 now', ['+14155552671']],
        ['call +1 (415) 555-2671 or (555) 123-4567', ['+1 (415) 555-2671', '(555) 123-4567']],
        ['msisdn919876543210glued', ['919876543210']],
        ['ring ٩١٩٨٧٦٥٤٣٢١٠ or ４１５５５５２６７１', ['٩١٩٨٧٦٥٤٣٢١٠', '４１５５５５２６７１']],
        ['mail jane.doe+shop@mail.example.co.uk', ['jane.doe+shop@mail.example.co.uk']],
        ['from 919876543210@s.whatsapp.net', ['919876543210@s.whatsapp.net']],
        ['card 4111 1111 1111 1111 and 378282246310005', ['4111 1111 1111 1111', '378282246310005']],
        [
            '{"phone":"+919876543210","email":"a@b.io","card":"5500005555555559"}',
            ['+919876543210', 'a@b.io', '5500005555555559'],
        ],
        ['no pii at all, order 1234567890123456 on 2024-06-13 from 192.168.1.100', []],
    ];
}

/*
|--------------------------------------------------------------------------
| Half one: the round trip is exact
|--------------------------------------------------------------------------
*/

it('restores the original text byte for byte', function (): void {
    $redactor = redactor();

    foreach (piiCorpus() as [$text, $values]) {
        $result = $redactor->redact($text);

        expect($redactor->rehydrate($result->masked, $result->map))->toBe($text)
            ->and($result->isRedacted())->toBe($values !== []);
    }
});

it('restores a reply that only mentions some of the tokens', function (): void {
    $redactor = redactor();

    $result = $redactor->redact('reach me on +14155552671 or jane@example.com');

    expect(preg_match('/\[\[PII:PHONE:[a-p]{8}:\d+\]\]/', $result->masked, $matches))->toBe(1);

    // The model answers using one token and prose of its own.
    $reply = sprintf('Sure — I will text %s this afternoon.', $matches[0]);

    expect($redactor->rehydrate($reply, $result->map))->toContain('+14155552671')
        ->and($redactor->rehydrate($reply, $result->map))->not->toContain('[[PII:');
});

/*
|--------------------------------------------------------------------------
| Half two: nothing raw egresses
|--------------------------------------------------------------------------
*/

it('leaves none of the seeded values in the masked text', function (): void {
    $redactor = redactor();

    foreach (piiCorpus() as [$text, $values]) {
        $masked = $redactor->redact($text)->masked;

        foreach ($values as $value) {
            expect($masked)->not->toContain($value);
        }
    }
});

it('produces masked text that no independent PII pattern matches', function (): void {
    $redactor = redactor();

    // Written from scratch rather than reused from the scanner, so this assertion is
    // not the implementation agreeing with itself.
    $patterns = [
        'phone' => '/\+?\d[\d\s().\-]{5,}\d/u',
        'unicode digits' => '/[\x{0660}-\x{0669}\x{0966}-\x{096F}\x{FF10}-\x{FF19}]{7,}/u',
        'email' => '/[^\s@]+@[^\s@]+\.[^\s@]{2,}/u',
        'card' => '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/u',
    ];

    foreach (piiCorpus() as [$text, $values]) {
        if ($values === []) {
            continue;
        }

        $masked = $redactor->redact($text)->masked;

        foreach ($patterns as $label => $pattern) {
            expect(preg_match($pattern, $masked))->toBe(0, sprintf('%s survived in: %s', $label, $masked));
        }
    }
});

it('keeps a non-card long identifier readable so the model can still reason about it', function (): void {
    $result = redactor()->redact('order 1234567890123456 shipped on 2024-06-13 to 192.168.1.100');

    expect($result->masked)->toBe('order 1234567890123456 shipped on 2024-06-13 to 192.168.1.100')
        ->and($result->isRedacted())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Idempotence and token stability
|--------------------------------------------------------------------------
*/

it('does not double-tokenise text that is already redacted', function (): void {
    $redactor = redactor();

    $once = $redactor->redact('call +14155552671 about 4111111111111111')->masked;
    $twice = $redactor->redact($once)->masked;

    expect($twice)->toBe($once);
});

it('reuses one token for a value that appears more than once', function (): void {
    $redactor = redactor();

    $result = $redactor->redact('+14155552671 tried +14155552671 twice');

    expect($result->map)->toHaveCount(1)
        ->and($redactor->rehydrate($result->masked, $result->map))->toBe('+14155552671 tried +14155552671 twice');
});

it('labels a token with the kind it replaced so the prompt stays meaningful', function (): void {
    $result = redactor()->redact('phone +14155552671, mail a@b.io, card 4111111111111111');

    expect($result->masked)->toMatch('/\[\[PII:PHONE:[a-p]{8}:\d+\]\]/')
        ->and($result->masked)->toMatch('/\[\[PII:EMAIL:[a-p]{8}:\d+\]\]/')
        ->and($result->masked)->toMatch('/\[\[PII:CARD:[a-p]{8}:\d+\]\]/');
});

it('walks a nested structure, preserving its shape', function (): void {
    $redactor = redactor();

    $payload = [
        'contact' => ['phone' => '+14155552671', 'aliases' => ['jane@example.com', 'plain text']],
        'count' => 3,
        'flag' => true,
    ];

    $masked = $redactor->redactStructure($payload);

    expect($masked['count'])->toBe(3)
        ->and($masked['flag'])->toBeTrue()
        ->and($masked['contact']['aliases'][1])->toBe('plain text')
        ->and(json_encode($masked))->not->toContain('+14155552671')
        ->and(json_encode($masked))->not->toContain('jane@example.com')
        // Tokens from every string accumulate into one map, so one rehydration call
        // restores a reply that references any of them.
        ->and($redactor->rehydrate((string) $masked['contact']['phone'], $redactor->currentMap()))
        ->toBe('+14155552671');
});

/*
|--------------------------------------------------------------------------
| Token-map lifetime
|--------------------------------------------------------------------------
*/

it('cannot resolve a token after the map is forgotten', function (): void {
    $redactor = redactor();

    $masked = $redactor->redact('call +14155552671')->masked;

    $redactor->forget();

    expect($redactor->currentMap())->toHaveCount(0)
        // The token is left exactly as it is: an unresolvable token is inert, never a
        // guess at somebody's phone number.
        ->and($redactor->rehydrate($masked, $redactor->currentMap()))->toBe($masked);
});

it('re-keys its tokens when the map is forgotten, so an old token can never be resolved again', function (): void {
    $redactor = redactor();

    $before = $redactor->redact('call +14155552671')->masked;
    $redactor->forget();
    $after = $redactor->redact('call +14155552671')->masked;

    expect($after)->not->toBe($before);

    // Even holding the *new* map, the old token stays untouched.
    expect($redactor->rehydrate($before, $redactor->currentMap()))->toBe($before);
});

it('leaves a forged or stale token alone', function (): void {
    $redactor = redactor();

    $result = $redactor->redact('call +14155552671');
    $forged = 'see [[PII:PHONE:zzzzzzzz:9]] and [[PII:CARD:abcdefgh:1]]';

    expect($redactor->rehydrate($forged, $result->map))->toBe($forged);
});

/*
|--------------------------------------------------------------------------
| The token map refuses to outlive its request
|--------------------------------------------------------------------------
*/

it('refuses to be serialized', function (): void {
    $result = redactor()->redact('call +14155552671');

    expect(fn (): string => serialize($result->map))->toThrow(PiiRedactionException::class);
    // Serializing the result reaches the same guard through the map it carries.
    expect(fn (): string => serialize($result))->toThrow(PiiRedactionException::class);
});

it('refuses to be unserialized', function (): void {
    // What a cache entry written by an older, less careful version of this code would
    // look like: reading it back must fail rather than reconstitute a PII store.
    $payload = sprintf('O:%d:"%s":0:{}', strlen(TokenMap::class), TokenMap::class);

    expect(fn (): mixed => unserialize($payload))->toThrow(PiiRedactionException::class);
});

it('carries no values into json or a dump', function (): void {
    $result = redactor()->redact('call +14155552671');

    expect(json_encode($result->map))->toBe('{}')
        ->and(json_encode($result))->not->toContain('+14155552671')
        ->and($result->map->__debugInfo())->toBe(['tokens' => 1, 'values' => '[redacted]'])
        ->and(print_r($result->map, true))->not->toContain('+14155552671');
});

it('exposes token names but never a list of values', function (): void {
    $result = redactor()->redact('call +14155552671');

    expect($result->map->tokens())->toHaveCount(1)
        ->and(get_class_methods(TokenMap::class))->not->toContain('values')
        ->and(get_class_methods(TokenMap::class))->not->toContain('toArray');
});

it('refuses to bind one token to two different values', function (): void {
    $map = TokenMap::empty();

    $map->put('[[PII:PHONE:abcdefgh:1]]', '+14155552671');
    $map->put('[[PII:PHONE:abcdefgh:1]]', '+14155552671');

    expect(function () use ($map): void {
        $map->put('[[PII:PHONE:abcdefgh:1]]', '+919876543210');
    })->toThrow(PiiRedactionException::class);
});

it('merges maps so one rehydration call covers several redactions', function (): void {
    $redactor = redactor();

    $first = $redactor->redact('call +14155552671');
    $second = $redactor->redact('mail jane@example.com');

    $merged = $first->map->merge($second->map);

    expect($merged)->toHaveCount(2)
        ->and($redactor->rehydrate($first->masked.' / '.$second->masked, $merged))
        ->toBe('call +14155552671 / mail jane@example.com');
});

/*
|--------------------------------------------------------------------------
| Tenant patterns on the egress path
|--------------------------------------------------------------------------
*/

it('redacts what a tenant configured as identifying, and rehydrates it', function (): void {
    config()->set('wa.security.pii.tenant_patterns', ['*' => ['\bPOL-\d{6}\b']]);

    $redactor = app(PiiRedactor::class);
    $result = $redactor->redact('policy POL-778812 is active');

    expect($result->masked)->not->toContain('POL-778812')
        ->and($result->masked)->toContain('[[PII:CUSTOM:')
        ->and($redactor->rehydrate($result->masked, $result->map))->toBe('policy POL-778812 is active');
});

it('ignores a tenant pattern that cannot be trusted rather than failing the message', function (): void {
    config()->set('wa.security.pii.tenant_patterns', ['*' => ['.*', '(unclosed']]);

    $redactor = app(PiiRedactor::class);
    $result = $redactor->redact('hello, my number is +14155552671');

    // The bad patterns are skipped; the built-in detector still does its job.
    expect($result->masked)->toStartWith('hello, my number is ')
        ->and($result->masked)->not->toContain('+14155552671')
        ->and($result->map)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Container wiring
|--------------------------------------------------------------------------
*/

it('is bound once per unit of work and discarded at its boundary', function (): void {
    $first = app(PiiRedactor::class);

    expect(app(PiiRedactor::class))->toBe($first)
        ->and($first)->toBeInstanceOf(TokenizingPiiRedactor::class);

    // What the framework does between requests and between queued jobs.
    app()->forgetScopedInstances();

    expect(app(PiiRedactor::class))->not->toBe($first);
});
