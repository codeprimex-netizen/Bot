<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Exceptions\Audit\AuditPayloadException;
use App\Support\Audit\AuditPayloadNormalizer;
use App\Support\Audit\AuditPayloadRedactor;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Payload shaping and one-way redaction (Req 24.2, 24.5 / D1)
|--------------------------------------------------------------------------
| The end-to-end behaviour is asserted against stored rows in
| `AuditPayloadPrivacyTest`; these are the unit-level rules those rows depend on.
*/

it('flattens the shapes callers actually pass', function (): void {
    $normalized = (new AuditPayloadNormalizer)->normalize([
        'status' => TenantStatus::Suspended,
        'at' => Carbon::parse('2025-06-14 10:30:00', 'UTC'),
        'count' => 3,
        'ratio' => 0.5,
        'flag' => true,
        'none' => null,
    ]);

    expect($normalized['status'])->toBe('SUSPENDED')
        ->and($normalized['at'])->toBe('2025-06-14T10:30:00+00:00')
        ->and($normalized['count'])->toBe(3)
        ->and($normalized['ratio'])->toBe(0.5)
        ->and($normalized['flag'])->toBeTrue()
        ->and($normalized['none'])->toBeNull();
});

it('records a scalar or list payload under a stable key rather than rejecting it', function (): void {
    $normalizer = new AuditPayloadNormalizer;

    expect($normalizer->normalize('just a note'))->toBe(['value' => 'just a note'])
        ->and($normalizer->normalize([1, 2]))->toBe(['items' => [1, 2]]);
});

it('refuses values it cannot represent at all', function (): void {
    expect(fn () => (new AuditPayloadNormalizer)->normalize(['handle' => fopen('php://memory', 'r')]))
        ->toThrow(AuditPayloadException::class, 'no deterministic canonical form');
});

it('caps depth, width, and length, and says so in the value', function (): void {
    $normalizer = new AuditPayloadNormalizer(maxDepth: 2, maxStringLength: 5, maxArrayItems: 3);

    $normalized = $normalizer->normalize([
        'long' => 'abcdefghij',
        'wide' => ['a', 'b', 'c', 'd', 'e'],
        'deep' => ['one' => ['two' => ['three' => 'far']]],
    ]);

    expect($normalized['long'])->toBe('abcde…[audit:truncated from 10 chars]')
        ->and($normalized['wide'])->toHaveKey('[audit:capped]')
        ->and($normalized['wide']['[audit:capped]'])->toBe('2 more items')
        ->and(json_encode($normalized['deep']))->toContain('depth limit');
});

it('masks a phone number in place and is idempotent', function (): void {
    $redactor = new AuditPayloadRedactor;

    $once = $redactor->maskNumbers('called 919876543210 twice');

    expect($once)->toBe('called 91********10 twice')
        // Re-redacting an already redacted value must not corrupt it: audit payloads can
        // pass through more than one layer before they are stored.
        ->and($redactor->maskNumbers($once))->toBe($once);
});

it('leaves values with no phone-shaped number untouched', function (): void {
    $redactor = new AuditPayloadRedactor;

    foreach (['2025-06-14', '1.2.3', 'order #1234', '01HZY8Q0J9', 'plan pro'] as $value) {
        expect($redactor->maskNumbers($value))->toBe($value);
    }
});

it('redacts by key at every depth', function (): void {
    $redacted = (new AuditPayloadRedactor)->redact([
        'outer' => ['inner' => ['api_key' => 'secret-value', 'keep' => 'visible']],
    ]);

    expect($redacted['outer']['inner']['api_key'])->toBe(AuditPayloadRedactor::REDACTED)
        ->and($redacted['outer']['inner']['keep'])->toBe('visible');
});
