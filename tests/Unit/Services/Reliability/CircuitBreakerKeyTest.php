<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use App\Services\Reliability\CircuitBreakerKey;

/*
|--------------------------------------------------------------------------
| CircuitBreakerKey — the validated (scope, name) identity
|--------------------------------------------------------------------------
| The service accepts a raw string scope as well as the enum (design.md § 5), so loose
| input is turned into a checked key in exactly one place — and that place also decides
| what a key may look like in a log line (Req 31.3 / NFR2, Req 32.2 / NFR3).
*/

it('accepts a scope as an enum or as its raw value', function (): void {
    expect(CircuitBreakerKey::for(CircuitScope::Provider, 'openai')->scope)->toBe(CircuitScope::Provider)
        ->and(CircuitBreakerKey::for('gateway', 'razorpay')->scope)->toBe(CircuitScope::Gateway)
        ->and(CircuitBreakerKey::for(CircuitScope::Provider, 'openai')->toString())->toBe('provider:openai');
});

it('trims the name and rejects one it could not store faithfully', function (): void {
    expect(CircuitBreakerKey::for(CircuitScope::Provider, '  openai  ')->name)->toBe('openai')
        ->and(fn () => CircuitBreakerKey::for(CircuitScope::Provider, ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => CircuitBreakerKey::for(CircuitScope::Provider, "\t \n"))
        ->toThrow(InvalidArgumentException::class)
        // At the column's limit, so two long names cannot silently collapse into one row.
        ->and(CircuitBreakerKey::for(CircuitScope::Provider, str_repeat('a', 191))->name)
        ->toHaveLength(191)
        ->and(fn () => CircuitBreakerKey::for(CircuitScope::Provider, str_repeat('a', 192)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a family it does not know', function (): void {
    expect(fn () => CircuitBreakerKey::for('vector-store', 'qdrant'))
        ->toThrow(InvalidArgumentException::class);
});

it('fingerprints the tenant segment of a composite name and keeps the dependency', function (): void {
    $tenantId = '01JABCDEFGHJKMNPQRSTVWXYZ';
    $key = CircuitBreakerKey::for(
        CircuitScope::Provider,
        CircuitBreakerRecord::compositeName('openai', $tenantId),
    );

    $redacted = $key->redacted();

    expect($redacted)->toStartWith('provider:openai:')
        // An operator needs to know *what* is down…
        ->and($redacted)->toContain('openai')
        // …but a tenant id must not travel into a log line or an HTTP error.
        ->and($redacted)->not->toContain($tenantId)
        ->and($redacted)->toContain('#')
        // Stable, so two lines about the same tenant's breaker still correlate.
        ->and($redacted)->toBe($key->redacted())
        ->and($key->toString())->toContain($tenantId);
});

it('leaves a single-segment name alone when redacting', function (): void {
    expect(CircuitBreakerKey::for(CircuitScope::Gateway, 'razorpay')->redacted())
        ->toBe('gateway:razorpay');
});

it('escapes control characters out of a redacted name', function (): void {
    expect(CircuitBreakerKey::for(CircuitScope::Bridge, "session\n1")->redacted())
        ->not->toContain("\n");
});

it('compares keys by scope and name', function (): void {
    $one = CircuitBreakerKey::for(CircuitScope::Provider, 'openai');

    expect($one->equals(CircuitBreakerKey::for(CircuitScope::Provider, 'openai')))->toBeTrue()
        ->and($one->equals(CircuitBreakerKey::for(CircuitScope::Gateway, 'openai')))->toBeFalse()
        ->and($one->equals(CircuitBreakerKey::for(CircuitScope::Provider, 'gemini')))->toBeFalse()
        ->and($one->cacheKey())->toBe($one->toString());
});
