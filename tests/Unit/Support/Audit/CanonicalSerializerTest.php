<?php

declare(strict_types=1);

use App\Exceptions\Audit\AuditPayloadException;
use App\Support\Audit\CanonicalSerializer;

/*
|--------------------------------------------------------------------------
| Deterministic canonical serialization (Req 24.5 / D1, Correctness Property 17)
|--------------------------------------------------------------------------
| A hash chain is only as meaningful as the bytes it hashes. Two failure modes matter,
| and they pull in opposite directions:
|
|   • *instability* — the same entry encoding differently on a different run, PHP
|     version, or locale, which makes an honest row look tampered;
|   • *ambiguity* — two different entries encoding identically, which lets a forged row
|     keep a stolen hash.
|
| Every test below is one or the other.
*/

it('is insensitive to key order but sensitive to key names', function (): void {
    $serializer = new CanonicalSerializer;

    expect($serializer->encode(['b' => 1, 'a' => 2]))->toBe($serializer->encode(['a' => 2, 'b' => 1]))
        ->and($serializer->encode(['a' => 1, 'b' => 2]))->not->toBe($serializer->encode(['a' => 2, 'b' => 1]))
        ->and($serializer->encode(['a' => 1]))->not->toBe($serializer->encode(['A' => 1]));
});

it('sorts nested keys too, at every depth', function (): void {
    $serializer = new CanonicalSerializer;

    $first = ['outer' => ['z' => ['b' => 1, 'a' => 2], 'y' => 3]];
    $second = ['outer' => ['y' => 3, 'z' => ['a' => 2, 'b' => 1]]];

    expect($serializer->encode($first))->toBe($serializer->encode($second));
});

it('is sensitive to list order, because order is meaning in a list', function (): void {
    $serializer = new CanonicalSerializer;

    expect($serializer->encode([1, 2, 3]))->not->toBe($serializer->encode([3, 2, 1]));
});

it('distinguishes values that PHP would happily compare equal', function (): void {
    $serializer = new CanonicalSerializer;

    $encodings = array_map(
        fn (mixed $value): string => $serializer->encode(['v' => $value]),
        [null, false, true, 0, 1, 1.0, '1', '', '0', [], [0]],
    );

    // No two of these may share an encoding: `1`, `1.0`, `'1'`, and `true` are different
    // audit facts, and a chain that cannot tell them apart proves less than it claims.
    expect(array_unique($encodings))->toHaveCount(count($encodings));
});

it('cannot be fooled by a value that contains the delimiters', function (): void {
    $serializer = new CanonicalSerializer;

    // Length-prefixed strings: no payload can be crafted whose encoding collides with a
    // differently-shaped one.
    expect($serializer->encode(['a' => 's1:b;']))->not->toBe($serializer->encode(['a' => 'b']))
        ->and($serializer->encode(['ab' => 'c']))->not->toBe($serializer->encode(['a' => 'bc']))
        ->and($serializer->encode(['a' => 'b;c', 'd' => 'e']))
        ->not->toBe($serializer->encode(['a' => 'b', 'c' => 'd', 'e' => '']));
});

it('encodes a string by its byte length, so multibyte content round-trips', function (): void {
    $serializer = new CanonicalSerializer;

    expect($serializer->encode('héllo'))->toBe('s6:héllo;')
        ->and($serializer->encode('a'))->toBe('s1:a;')
        ->and($serializer->encode(''))->toBe('s0:;');
});

it('hashes floats by their exact bits, not by their text form', function (): void {
    $serializer = new CanonicalSerializer;

    // 0.1 + 0.2 is not 0.3 in IEEE-754, and an audit hash must reflect the value that
    // was actually stored rather than a rounded rendering of it.
    expect($serializer->encode(0.1 + 0.2))->not->toBe($serializer->encode(0.3))
        ->and($serializer->encode(1.0))->not->toBe($serializer->encode(1))
        ->and($serializer->encode(1.5))->toBe('d3ff8000000000000;')
        ->and($serializer->encode(-0.0))->not->toBe($serializer->encode(0.0));
});

it('is stable across runs and identical for structurally identical values', function (): void {
    $serializer = new CanonicalSerializer;

    $value = [
        'action' => 'tenant.suspended',
        'meta' => ['depth' => 2, 'ratio' => 1 / 3, 'flags' => [true, false, null]],
        'sequence' => 42,
    ];

    $digest = $serializer->digest($value);

    foreach (range(1, 5) as $ignored) {
        expect((new CanonicalSerializer)->digest($value))->toBe($digest);
    }

    expect($digest)->toHaveLength(64);
});

it('treats a list and an equivalent map as different values', function (): void {
    $serializer = new CanonicalSerializer;

    expect($serializer->encode(['a', 'b']))->not->toBe($serializer->encode([0 => 'a', 1 => 'b', 'x' => null]))
        ->and($serializer->encode([1 => 'a', 2 => 'b']))->not->toBe($serializer->encode(['a', 'b']));
});

it('refuses values that have no deterministic form', function (): void {
    $serializer = new CanonicalSerializer;

    expect(fn () => $serializer->encode(['at' => new stdClass]))
        ->toThrow(AuditPayloadException::class, 'no deterministic canonical form')
        ->and(fn () => $serializer->encode(NAN))
        ->toThrow(AuditPayloadException::class, 'NAN or INF')
        ->and(fn () => $serializer->encode(['inf' => INF]))
        ->toThrow(AuditPayloadException::class, 'NAN or INF');
});

it('names the path of the offending value without disclosing it', function (): void {
    $serializer = new CanonicalSerializer;

    $message = '';

    try {
        $serializer->encode(['outer' => ['secret' => new stdClass]]);
    } catch (AuditPayloadException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('payload.outer.secret')
        ->and($message)->toContain('stdClass');
});

it('refuses pathological nesting rather than recursing without bound', function (): void {
    $serializer = new CanonicalSerializer;
    $deep = 'leaf';

    foreach (range(1, CanonicalSerializer::MAX_DEPTH + 2) as $ignored) {
        $deep = ['down' => $deep];
    }

    expect(fn () => $serializer->encode($deep))->toThrow(AuditPayloadException::class, 'nests deeper');
});
