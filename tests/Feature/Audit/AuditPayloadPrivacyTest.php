<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Audit;

/*
|--------------------------------------------------------------------------
| Audit payloads carry no phone numbers and no message bodies
|--------------------------------------------------------------------------
| The platform's standing rule (design § Observability, Req 32 / NFR3) is that phone
| numbers are redacted and message bodies are never logged — only content hashes. An
| audit row is the strictest place that rule has to hold: it is written on the paths
| that touch the most sensitive data, it is retained as long as the trail is, and it is
| immutable, so a phone number written here cannot be removed later without breaking
| the hash chain.
|
| These assertions are made against the **stored row**, not the value handed to the
| service, because that is the artefact that outlives the request. Task 4.4's
| `PiiRedactor` will consolidate the patterns; the one-way behaviour must stay.
*/

/**
 * The raw JSON of the last stored audit row.
 */
function lastStoredPayload(): string
{
    $value = DB::table('audit_logs')->orderByDesc('sequence')->value('payload');

    return is_string($value) ? $value : '';
}

it('masks phone numbers wherever they appear in a payload', function (): void {
    $tenant = Tenant::factory()->create();

    $entry = Audit::service()->write('contact.blocked', [
        'phone' => '919876543210',
        'jid' => '919876543210@s.whatsapp.net',
        'reason' => 'user asked to stop, called from +91 98765 43210',
        'nested' => ['msisdn' => '+14155552671'],
    ], tenant: $tenant);

    $stored = lastStoredPayload();

    expect($stored)->not->toContain('919876543210')
        ->and($stored)->not->toContain('98765 43210')
        ->and($stored)->not->toContain('4155552671')
        ->and($entry->payload['phone'])->toBe('91********10')
        ->and($entry->payload['jid'])->toBe('91********10@s.whatsapp.net')
        ->and($entry->payload['reason'])->toContain('+91********10')
        ->and($entry->payload['nested']['msisdn'])->toBe('+14*******71')
        // Masked, not removed: an operator can still correlate an entry with a ticket.
        ->and($entry->payload['reason'])->toContain('user asked to stop');
});

it('stores message content as a digest and never as text', function (): void {
    $tenant = Tenant::factory()->create();

    $entry = Audit::service()->write('template.sent', [
        'body' => 'Hi Ravi, your order 1234 is out for delivery',
        'caption' => 'invoice attached',
        'template_key' => 'order_dispatched',
    ], tenant: $tenant);

    $stored = lastStoredPayload();

    expect($stored)->not->toContain('out for delivery')
        ->and($stored)->not->toContain('invoice attached')
        ->and($entry->payload['body'])->toStartWith('sha256:')
        ->and($entry->payload['body'])->toContain('chars:44')
        ->and($entry->payload['caption'])->toStartWith('sha256:')
        // Non-content fields are untouched: the entry still says what was sent.
        ->and($entry->payload['template_key'])->toBe('order_dispatched');
});

it('gives the same digest for the same content, so entries can be correlated', function (): void {
    $tenant = Tenant::factory()->create();

    $first = Audit::service()->write('a', ['body' => 'identical text'], tenant: $tenant);
    $second = Audit::service()->write('b', ['body' => 'identical text'], tenant: $tenant);
    $third = Audit::service()->write('c', ['body' => 'different text'], tenant: $tenant);

    expect($first->payload['body'])->toBe($second->payload['body'])
        ->and($third->payload['body'])->not->toBe($first->payload['body']);
});

it('drops secret-looking values entirely', function (): void {
    $entry = Audit::service()->writeForPlatform('gateway.configured', [
        'gateway' => 'razorpay',
        'api_key' => 'rzp_live_51H8xVerySecret',
        'webhook_secret' => 'whsec_abc123',
        'access_token' => 'ya29.a0AfH6SMB',
        'authorization' => 'Bearer eyJhbGciOi',
        'signature' => 'sha256=deadbeef',
    ]);

    $stored = lastStoredPayload();

    foreach (['rzp_live_51H8xVerySecret', 'whsec_abc123', 'ya29.a0AfH6SMB', 'eyJhbGciOi', 'deadbeef'] as $secret) {
        expect($stored)->not->toContain($secret);
    }

    expect($entry->payload['api_key'])->toBe('[redacted]')
        ->and($entry->payload['webhook_secret'])->toBe('[redacted]')
        ->and($entry->payload['gateway'])->toBe('razorpay');
});

it('does not mask identifiers that only look numeric, so entries stay readable', function (): void {
    $tenant = Tenant::factory()->create();

    $entry = Audit::service()->write('plan.changed', [
        'from' => 'starter',
        'to' => 'pro',
        'effective_on' => '2025-06-14',
        'version' => '1.2.3',
        'seats' => 12,
        'price_micros' => 4990000,
    ], tenant: $tenant);

    // Dates and dotted versions are left alone (they carry no `+` and no long digit run),
    // while a genuinely long digit run in a numeric *value* is not a string and is not
    // touched at all — the redactor works on strings, so counters stay countable.
    expect($entry->payload['effective_on'])->toBe('2025-06-14')
        ->and($entry->payload['version'])->toBe('1.2.3')
        ->and($entry->payload['seats'])->toBe(12)
        ->and($entry->payload['price_micros'])->toBe(4990000);
});

it('keeps a redacted payload verifiable — redaction happens before hashing', function (): void {
    $tenant = Tenant::factory()->create();

    Audit::service()->write('contact.blocked', ['phone' => '919876543210', 'body' => 'stop'], tenant: $tenant);
    Audit::service()->write('contact.blocked', ['phone' => '919876543299', 'body' => 'stop'], tenant: $tenant);

    expect(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});

it('truncates an oversized payload instead of refusing to record the action', function (): void {
    $tenant = Tenant::factory()->create();

    // A write that could be made to fail by its own input would be a way to suppress an
    // audit entry, so oversized input is truncated (and marked as truncated).
    $entry = Audit::service()->write('import.ran', [
        'note' => str_repeat('x', 5000),
        'rows' => range(1, 500),
    ], tenant: $tenant);

    expect(mb_strlen((string) $entry->payload['note']))->toBeLessThan(2200)
        ->and($entry->payload['note'])->toContain('truncated from 5000 chars')
        ->and($entry->payload['rows'])->toHaveCount(101)
        ->and($entry->payload['rows'])->toHaveKey('[audit:capped]')
        ->and(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});

it('records binary and non-finite values as markers rather than aborting', function (): void {
    $tenant = Tenant::factory()->create();

    $entry = Audit::service()->write('media.rejected', [
        'head' => "\xC3\x28\xFF\xFE binary",
        'score' => INF,
    ], tenant: $tenant);

    expect((string) $entry->payload['head'])->toStartWith('[audit:binary sha256:')
        ->and($entry->payload['score'])->toBe('[audit:inf]')
        ->and(Audit::service()->verify($tenant)->isIntact())->toBeTrue();
});
