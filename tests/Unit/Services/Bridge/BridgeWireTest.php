<?php

declare(strict_types=1);

use App\Services\Bridge\BridgeWire;

/*
|--------------------------------------------------------------------------
| Reading a response body from another process, defensively
|--------------------------------------------------------------------------
| The sidecar is a separate deployable in another language, so its bodies are untrusted
| input in the same sense a request body is: a field this platform expects to be a string
| can arrive as a number, an object, or absent. Every accessor here answers "is this
| readable?" and never supplies a default — because a default is how a failed operation
| comes to look like a successful one.
*/

it('reads a string field and trims it', function (): void {
    expect(BridgeWire::stringOrNull(' CONNECTED '))->toBe('CONNECTED')
        ->and(BridgeWire::stringOrNull('   '))->toBeNull()
        ->and(BridgeWire::stringOrNull(null))->toBeNull()
        // Unquoted JSON numbers are legitimate for ids and timestamps.
        ->and(BridgeWire::stringOrNull(42))->toBe('42');
});

it('refuses a boolean where a string is expected', function (): void {
    // `(string) true` is "1", which would silently become a plausible-looking id or status.
    expect(BridgeWire::stringOrNull(true))->toBeNull()
        ->and(BridgeWire::stringOrNull(['nested']))->toBeNull();
});

it('reads every spelling of a boolean a loosely typed sidecar may send', function (): void {
    expect(BridgeWire::boolOrNull(true))->toBeTrue()
        ->and(BridgeWire::boolOrNull(false))->toBeFalse()
        ->and(BridgeWire::boolOrNull(1))->toBeTrue()
        ->and(BridgeWire::boolOrNull(0))->toBeFalse()
        ->and(BridgeWire::boolOrNull('TRUE'))->toBeTrue()
        ->and(BridgeWire::boolOrNull('no'))->toBeFalse();
});

it('reports an unstated boolean as null rather than as false', function (): void {
    // A caller has to be able to tell a real `false` from a missing field — that difference
    // is what keeps "not on WhatsApp" apart from "the protocol declined to answer".
    expect(BridgeWire::boolOrNull(null))->toBeNull()
        ->and(BridgeWire::boolOrNull('maybe'))->toBeNull()
        ->and(BridgeWire::boolOrNull(7))->toBeNull();
});

it('reads a timestamp from an iso string and from epoch seconds', function (): void {
    expect(BridgeWire::timestampOrNull('2024-06-20T10:00:00+00:00')?->toIso8601String())
        ->toBe('2024-06-20T10:00:00+00:00')
        ->and(BridgeWire::timestampOrNull(1718877600)?->timestamp)->toBe(1718877600);
});

it('reads epoch milliseconds, because the sidecar is javascript', function (): void {
    expect(BridgeWire::timestampOrNull(1718877600123)?->timestamp)->toBe(1718877600);
});

it('never fabricates a timestamp for an unreadable one', function (): void {
    // A fabricated "last seen just now" would make a dead session look alive.
    expect(BridgeWire::timestampOrNull('not a date'))->toBeNull()
        ->and(BridgeWire::timestampOrNull(0))->toBeNull()
        ->and(BridgeWire::timestampOrNull(-5))->toBeNull()
        ->and(BridgeWire::timestampOrNull(null))->toBeNull()
        ->and(BridgeWire::timestampOrNull(['when' => 'later']))->toBeNull();
});

it('normalises a nested object to string keys and a missing one to an empty array', function (): void {
    expect(BridgeWire::arrayOrEmpty(['a' => 1, 2 => 'b']))->toBe(['a' => 1, '2' => 'b'])
        // JSON's `{}` and a missing key mean the same thing to every caller, all of which
        // iterate the result.
        ->and(BridgeWire::arrayOrEmpty(null))->toBe([])
        ->and(BridgeWire::arrayOrEmpty('nope'))->toBe([]);
});

it('normalises a json list to a plain list', function (): void {
    expect(BridgeWire::listOrEmpty([3 => 'a', 7 => 'b']))->toBe(['a', 'b'])
        ->and(BridgeWire::listOrEmpty(null))->toBe([]);
});
