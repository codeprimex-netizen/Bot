<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Security\Pii\PiiRedactor;
use App\Services\Tenancy\TenantContext;

/*
|--------------------------------------------------------------------------
| A token map lives for one unit of work, and cannot be made to live longer
|--------------------------------------------------------------------------
| The reversible half of Property 15 needs a map from token back to plaintext.
| That map is a PII store, so the design's phrase "kept in-process for the single
| request" is not a stylistic preference — a map that survives the request is a
| plaintext PII database with extra steps, and one that crosses tenants rehydrates
| one customer's reply with another customer's data.
|
| Three boundaries are asserted here, in the wiring rather than in a comment:
| the request/job boundary, the tenant boundary, and the absence of any route to
| persistence.
*/

it('cannot rehydrate an earlier unit of work’s tokens', function (): void {
    $redactor = app(PiiRedactor::class);
    $masked = $redactor->redact('call +14155552671 back')->masked;

    expect($masked)->not->toContain('+14155552671');

    // Exactly what the framework does between two requests and between two queued
    // jobs: scoped instances — this redactor and its map among them — are discarded.
    app()->forgetScopedInstances();

    $next = app(PiiRedactor::class);

    expect($next)->not->toBe($redactor)
        ->and($next->currentMap())->toHaveCount(0)
        // The masked text from the previous request is inert in this one.
        ->and($next->rehydrate($masked, $next->currentMap()))->toBe($masked);
});

it('re-keys its tokens when the bound tenant changes', function (): void {
    $tenants = app(TenantContext::class);
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();

    $redactor = app(PiiRedactor::class);

    $tenants->set($first);
    $forFirst = $redactor->redact('call +14155552671')->masked;

    expect($redactor->currentMap())->toHaveCount(1);

    $tenants->forget();
    $tenants->set($second);

    // The first thing done for the new tenant drops the previous tenant's map.
    $forSecond = $redactor->redact('mail jane@example.com')->masked;

    expect($redactor->currentMap())->toHaveCount(1)
        ->and($forSecond)->not->toContain('jane@example.com')
        // The other tenant's token is not resolvable here, and is left untouched.
        ->and($redactor->rehydrate($forFirst, $redactor->currentMap()))->toBe($forFirst);
});

it('applies the patterns of the tenant that is actually bound', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme-pii']);
    $other = Tenant::factory()->create(['slug' => 'other-pii']);

    config()->set('wa.security.pii.tenant_patterns', [
        'acme-pii' => ['\bACME-\d{5}\b'],
    ]);

    $tenants = app(TenantContext::class);

    $tenants->set($acme);
    $forAcme = app(PiiRedactor::class)->redact('ref ACME-12345 attached')->masked;

    $tenants->forget();
    app()->forgetScopedInstances();

    $tenants->set($other);
    $forOther = app(PiiRedactor::class)->redact('ref ACME-12345 attached')->masked;

    expect($forAcme)->not->toContain('ACME-12345')
        // Another tenant's identifier shape is none of this tenant's business.
        ->and($forOther)->toBe('ref ACME-12345 attached');
});

it('keeps the map out of the queue, the cache, and the session', function (): void {
    $result = app(PiiRedactor::class)->redact('call +14155552671');

    // A queued job payload, a cache entry that leaves the process, and a session are
    // all `serialize()` underneath, so one guard closes all three doors.
    expect(fn (): string => serialize($result->map))
        ->toThrow(App\Exceptions\Security\PiiRedactionException::class)
        ->and(fn (): mixed => Illuminate\Support\Facades\Cache::store('file')->put('pii-map-probe', $result->map, 60))
        ->toThrow(App\Exceptions\Security\PiiRedactionException::class);

    expect(Illuminate\Support\Facades\Cache::store('file')->has('pii-map-probe'))->toBeFalse();
});
