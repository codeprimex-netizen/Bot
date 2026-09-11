<?php

declare(strict_types=1);

use App\Enums\IdempotencyMode;
use App\Models\IdempotencyKey;
use App\Services\Reliability\IdempotencyOptions;
use App\Services\Reliability\IdempotencyOutcome;

/*
|--------------------------------------------------------------------------
| IdempotencyOptions / IdempotencyOutcome (Req 31.2 / NFR2)
|--------------------------------------------------------------------------
| The withers are worth their own tests because `null` is a *meaningful value* for
| several of these fields — "use the configured default", "no tenant at all" — so a
| naive `?? $this->field` clone would turn `keptFor(null)` and `withoutTenant()` into
| silent no-ops, and the caller would get a retention horizon or a tenant attribution
| it explicitly asked not to have.
*/

it('defaults to the safe mode and to configured defaults', function (): void {
    $options = IdempotencyOptions::default();

    expect($options->mode)->toBe(IdempotencyMode::Lease)
        ->and($options->request)->toBeNull()
        ->and($options->requestFingerprint())->toBeNull()
        ->and($options->tenantId)->toBeNull()
        ->and($options->attributeToContext)->toBeTrue()
        ->and($options->retentionDays)->toBeNull()
        ->and($options->staleSeconds)->toBeNull()
        ->and($options->waitMilliseconds)->toBeNull()
        ->and($options->keepsForever())->toBeFalse();
});

it('names each mode through its own constructor', function (): void {
    expect(IdempotencyOptions::transactional()->mode)->toBe(IdempotencyMode::Transactional)
        ->and(IdempotencyOptions::atMostOnce()->mode)->toBe(IdempotencyMode::AtMostOnce)
        ->and(IdempotencyOptions::default()->using(IdempotencyMode::Transactional)->mode)->toBe(IdempotencyMode::Transactional);
});

it('carries every wither through without losing the others', function (): void {
    $options = IdempotencyOptions::transactional()
        ->matching(['event' => 'payment.captured'])
        ->forTenant('01HTENANT')
        ->keptFor(10)
        ->leasedFor(30)
        ->waitingFor(500);

    expect($options->mode)->toBe(IdempotencyMode::Transactional)
        ->and($options->requestFingerprint())->toBe(IdempotencyKey::fingerprint(['event' => 'payment.captured']))
        ->and($options->tenantId)->toBe('01HTENANT')
        ->and($options->attributeToContext)->toBeFalse()
        ->and($options->retentionDays)->toBe(10)
        ->and($options->staleSeconds)->toBe(30)
        ->and($options->waitMilliseconds)->toBe(500);
});

it('lets a null mean what it says', function (): void {
    $configured = IdempotencyOptions::default()->keptFor(10)->leasedFor(30)->forTenant('01HTENANT');

    expect($configured->keptFor(null)->retentionDays)->toBeNull()
        ->and($configured->leasedFor(null)->staleSeconds)->toBeNull()
        ->and($configured->withoutTenant()->tenantId)->toBeNull()
        ->and($configured->withoutTenant()->attributeToContext)->toBeFalse()
        ->and($configured->failingFast()->waitMilliseconds)->toBe(0)
        // …and none of that disturbed the original: every wither returns a new instance,
        // so a service can hold one configured set and derive per-call variants.
        ->and($configured->retentionDays)->toBe(10)
        ->and($configured->staleSeconds)->toBe(30)
        ->and($configured->tenantId)->toBe('01HTENANT');
});

it('treats zero retention as no horizon at all', function (): void {
    expect(IdempotencyOptions::default()->keptForever()->retentionDays)->toBe(IdempotencyOptions::KEEP_FOREVER)
        ->and(IdempotencyOptions::default()->keptForever()->keepsForever())->toBeTrue()
        ->and(IdempotencyOptions::default()->keptFor(1)->keepsForever())->toBeFalse();
});

it('refuses nonsense windows rather than silently clamping them', function (): void {
    expect(fn () => IdempotencyOptions::default()->keptFor(-1))->toThrow(InvalidArgumentException::class)
        // A zero-second lease would let two callers run the same operation at once, which
        // is the one thing the ledger exists to prevent.
        ->and(fn () => IdempotencyOptions::default()->leasedFor(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => IdempotencyOptions::default()->waitingFor(-5))->toThrow(InvalidArgumentException::class);
});

it('fingerprints a request independently of key order', function (): void {
    $one = IdempotencyOptions::default()->matching(['b' => 2, 'a' => 1]);
    $two = IdempotencyOptions::default()->matching(['a' => 1, 'b' => 2]);

    expect($one->requestFingerprint())->toBe($two->requestFingerprint())
        ->and(IdempotencyOptions::default()->matching('raw-body')->requestFingerprint())
        ->toBe(IdempotencyKey::fingerprint('raw-body'));
});

it('distinguishes a fresh run from a replay', function (): void {
    $fresh = IdempotencyOutcome::executed('gateway:razorpay', 'evt_1', ['status' => 'captured'], IdempotencyMode::Lease);
    $replay = IdempotencyOutcome::replayed('gateway:razorpay', 'evt_1', ['status' => 'captured'], IdempotencyMode::Lease);

    expect($fresh->wasExecuted())->toBeTrue()
        ->and($fresh->isReplay())->toBeFalse()
        ->and($replay->isReplay())->toBeTrue()
        ->and($replay->wasExecuted())->toBeFalse()
        ->and($replay->payload())->toBe(['status' => 'captured'])
        // A scalar result is not a payload, and asking for one must not invent a shape.
        ->and(IdempotencyOutcome::executed('s', 'k', 'order_42', IdempotencyMode::Lease)->payload())->toBe([])
        // The log shape carries no caller data.
        ->and($replay->toArray())->toBe([
            'scope' => 'gateway:razorpay',
            'key' => 'evt_1',
            'mode' => 'LEASE',
            'replayed' => true,
        ]);
});
