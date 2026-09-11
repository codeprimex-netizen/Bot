<?php

declare(strict_types=1);

use App\Enums\IdempotencyState;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| idempotency_keys schema and model invariants (Req 31.2 / NFR2)
|--------------------------------------------------------------------------
| `uniq(scope, key)` is the dedup mechanism itself — the insert is the lock — so it
| is the first thing asserted here. `IdempotencyStore::once()` is task 3.4.
*/

it('stores a key with its replay material', function (): void {
    $result = ['status' => 'captured', 'amount_micros' => 990_000];

    $entry = IdempotencyKey::create([
        'scope' => 'gateway:razorpay',
        'key' => 'evt_123',
        'state' => IdempotencyState::Completed,
        'result' => $result,
        'response_hash' => IdempotencyKey::fingerprint($result),
        'request_fingerprint' => IdempotencyKey::fingerprint(['event' => 'payment.captured']),
        'locked_at' => now()->subSecond(),
        'completed_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    $fresh = IdempotencyKey::query()->findOrFail($entry->id);

    expect($fresh->state)->toBe(IdempotencyState::Completed)
        ->and($fresh->result)->toBe($result)
        ->and($fresh->response_hash)->toBe(IdempotencyKey::fingerprint($result))
        ->and($fresh->isReplayable())->toBeTrue()
        ->and($fresh->matchesRequest(['event' => 'payment.captured']))->toBeTrue()
        ->and($fresh->matchesRequest(['event' => 'payment.failed']))->toBeFalse()
        ->and($fresh->locked_at)->not->toBeNull()
        ->and($fresh->expires_at)->not->toBeNull();
});

it('enforces uniq(scope, key)', function (): void {
    IdempotencyKey::factory()->create(['scope' => 'gateway:razorpay', 'key' => 'evt_1']);

    expect(fn () => IdempotencyKey::factory()->create(['scope' => 'gateway:razorpay', 'key' => 'evt_1']))
        ->toThrow(QueryException::class);
});

it('lets the same key live in different scopes', function (): void {
    IdempotencyKey::factory()->create(['scope' => 'gateway:razorpay', 'key' => 'evt_1']);
    IdempotencyKey::factory()->create(['scope' => 'wa:inbound', 'key' => 'evt_1']);

    expect(IdempotencyKey::query()->where('key', 'evt_1')->count())->toBe(2)
        ->and(IdempotencyKey::query()->forKey('wa:inbound', 'evt_1')->count())->toBe(1)
        ->and(IdempotencyKey::query()->inScope('gateway:razorpay')->count())->toBe(1);
});

it('round-trips every IdempotencyState value through the database', function (): void {
    foreach (IdempotencyState::cases() as $state) {
        $entry = IdempotencyKey::factory()->create(['state' => $state, 'key' => 'k-'.$state->value]);

        expect(DB::table('idempotency_keys')->where('id', $entry->id)->value('state'))->toBe($state->value)
            ->and(IdempotencyKey::query()->findOrFail($entry->id)->state)->toBe($state);
    }
});

it('defaults a new key to IN_FLIGHT', function (): void {
    DB::table('idempotency_keys')->insert(['scope' => 'saga', 'key' => 'k-default']);

    expect(IdempotencyKey::query()->forKey('saga', 'k-default')->sole()->state)
        ->toBe(IdempotencyState::InFlight);
});

it('fingerprints payloads independently of key order but not of content', function (): void {
    // A retried webhook whose JSON was re-serialised in a different key order is the
    // *same* request, and must not be mistaken for a key reuse.
    expect(IdempotencyKey::fingerprint(['a' => 1, 'b' => ['y' => 2, 'x' => 3]]))
        ->toBe(IdempotencyKey::fingerprint(['b' => ['x' => 3, 'y' => 2], 'a' => 1]))
        ->and(IdempotencyKey::fingerprint(['a' => 1]))
        ->not->toBe(IdempotencyKey::fingerprint(['a' => 2]))
        ->and(IdempotencyKey::fingerprint('raw-body'))
        ->toBe(IdempotencyKey::fingerprint('raw-body'))
        ->and(mb_strlen(IdempotencyKey::fingerprint(['a' => 1])))->toBe(64);
});

it('distinguishes a live lease from an abandoned one', function (): void {
    $live = IdempotencyKey::factory()->create();
    $stale = IdempotencyKey::factory()->stale()->create(['key' => 'k-stale']);
    $done = IdempotencyKey::factory()->completed()->create(['key' => 'k-done']);
    $failed = IdempotencyKey::factory()->failed()->create(['key' => 'k-failed']);

    expect($live->isHeld())->toBeTrue()
        ->and($live->allowsExecution())->toBeFalse()
        ->and($live->lockHasExpired())->toBeFalse()
        // Presumed dead: the operation may be retaken, otherwise one crash blocks the
        // key for ever.
        ->and($stale->isHeld())->toBeFalse()
        ->and($stale->lockHasExpired())->toBeTrue()
        ->and($stale->allowsExecution())->toBeTrue()
        ->and($done->allowsExecution())->toBeFalse()
        ->and($done->isReplayable())->toBeTrue()
        ->and($failed->allowsExecution())->toBeTrue()
        ->and($failed->isReplayable())->toBeFalse();
});

it('finds stale leases and prunable rows', function (): void {
    IdempotencyKey::factory()->create(['key' => 'k-live']);
    IdempotencyKey::factory()->stale()->create(['key' => 'k-stale']);
    IdempotencyKey::factory()->completed()->expired()->create(['key' => 'k-expired']);
    IdempotencyKey::factory()->completed()->create(['key' => 'k-keep', 'expires_at' => null]);

    expect(IdempotencyKey::query()->stale()->pluck('key')->all())->toBe(['k-stale'])
        ->and(IdempotencyKey::query()->prunable()->pluck('key')->all())->toBe(['k-expired'])
        // No horizon means never pruned: deleting the entry would silently re-arm a
        // side effect that already happened.
        ->and(IdempotencyKey::query()->prunable()->pluck('key')->all())->not->toContain('k-keep');
});

it('treats a key with no recorded request fingerprint as always matching', function (): void {
    $entry = IdempotencyKey::factory()->completed()->create(['request_fingerprint' => null]);

    expect($entry->matchesRequest(['anything' => true]))->toBeTrue();
});

it('dedups a gateway event before any tenant is resolved', function (): void {
    // The reason this table keeps a nullable tenant_id and skips BelongsToTenant: the
    // webhook dedup that Req 31.2 mandates runs *before* the lookup that decides which
    // tenant the event belongs to.
    app(TenantContext::class)->forget();

    $entry = IdempotencyKey::factory()->create(['scope' => 'gateway:razorpay', 'key' => 'evt_pre']);

    expect($entry->tenant_id)->toBeNull()
        ->and($entry->tenant)->toBeNull()
        ->and(IdempotencyKey::query()->forKey('gateway:razorpay', 'evt_pre')->exists())->toBeTrue();
});

it('attributes a key to its tenant and cascades on tenant deletion', function (): void {
    $tenant = Tenant::factory()->create();
    IdempotencyKey::factory()->forTenant($tenant)->count(2)->create();
    IdempotencyKey::factory()->create(['key' => 'k-platform']);

    expect(IdempotencyKey::query()->where('tenant_id', $tenant->id)->count())->toBe(2);

    $tenant->delete();

    expect(IdempotencyKey::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->sole()->key)->toBe('k-platform');
});
