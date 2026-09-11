<?php

declare(strict_types=1);

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| outbox schema and model invariants (Req 31.4 / NFR2)
|--------------------------------------------------------------------------
| The persisted half of Algorithm 6: the dedup key that carries Correctness
| Property 16, the claim predicate the relay batches on, and the two clocks
| (`available_at` intent vs `next_attempt_at` retry gate). Delivery itself is
| task 3.3.
*/

it('stores an enqueued effect with its payload and dedup key', function (): void {
    $tenant = Tenant::factory()->create();

    $message = OutboxMessage::create([
        'tenant_id' => $tenant->id,
        'aggregate_type' => 'order',
        'aggregate_id' => 'ord-1',
        'event_type' => 'order.paid',
        'destination' => 'https://shop.test/hooks',
        'payload' => ['order_id' => 'ord-1', 'lines' => [['sku' => 'A', 'qty' => 2]]],
        'dedup_key' => 'order.paid:ord-1',
    ]);

    $fresh = OutboxMessage::query()->findOrFail($message->id);

    expect($fresh->payload)->toBe(['order_id' => 'ord-1', 'lines' => [['sku' => 'A', 'qty' => 2]]])
        ->and($fresh->status)->toBe(OutboxStatus::Pending)
        ->and($fresh->attempts)->toBe(0)
        ->and($fresh->sent_at)->toBeNull()
        ->and($fresh->tenant?->is($tenant))->toBeTrue()
        ->and($fresh->deliveryHeaders())->toBe([
            'X-Dedup-Key' => 'order.paid:ord-1',
            'X-Event-Type' => 'order.paid',
        ]);
});

it('enforces a unique dedup_key', function (): void {
    OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-1']);

    expect(fn () => OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-1']))
        ->toThrow(QueryException::class);
});

it('round-trips every OutboxStatus value through the database', function (): void {
    foreach (OutboxStatus::cases() as $status) {
        $message = OutboxMessage::factory()->create(['status' => $status]);

        expect(DB::table('outbox')->where('id', $message->id)->value('status'))->toBe($status->value)
            ->and(OutboxMessage::query()->findOrFail($message->id)->status)->toBe($status);
    }
});

it('defaults both clocks to now so a plain insert is immediately claimable', function (): void {
    // A null retry gate would fall outside the claim's range predicate and strand the
    // row for ever, so the column defaults rather than allowing null.
    DB::table('outbox')->insert([
        'aggregate_type' => 'order',
        'aggregate_id' => 'ord-2',
        'event_type' => 'order.created',
        'payload' => json_encode(['id' => 'ord-2']),
        'dedup_key' => 'order.created:ord-2',
    ]);

    $message = OutboxMessage::query()->forDedupKey('order.created:ord-2')->sole();

    expect($message->available_at)->not->toBeNull()
        ->and($message->next_attempt_at)->not->toBeNull()
        ->and($message->isClaimable())->toBeTrue();
});

it('claims only pending and failed rows whose retry gate has opened, oldest first', function (): void {
    $pending = OutboxMessage::factory()->create();
    $retryable = OutboxMessage::factory()->failed(attempts: 2)->create();
    $sent = OutboxMessage::factory()->sent()->create();
    $backingOff = OutboxMessage::factory()->failed(attempts: 3, backoffSeconds: 120)->create();
    $scheduled = OutboxMessage::factory()->deferred()->create();

    $claimed = OutboxMessage::query()->claimable()->pluck('id')->all();

    expect($claimed)->toBe([$pending->id, $retryable->id])
        ->and($claimed)->not->toContain($sent->id, $backingOff->id, $scheduled->id)
        // The in-memory mirror of the same predicate agrees row by row.
        ->and($pending->isClaimable())->toBeTrue()
        ->and($retryable->isClaimable())->toBeTrue()
        ->and($sent->isClaimable())->toBeFalse()
        ->and($backingOff->isClaimable())->toBeFalse()
        ->and($scheduled->isClaimable())->toBeFalse();
});

it('tells a deferred row apart from a delivered one', function (): void {
    $scheduled = OutboxMessage::factory()->deferred(600)->create();
    $backingOff = OutboxMessage::factory()->failed(attempts: 4, backoffSeconds: 90)->create();
    $sent = OutboxMessage::factory()->sent()->create();

    expect($scheduled->isDeferred())->toBeTrue()
        ->and($scheduled->secondsUntilAvailable())->toBeGreaterThan(0)
        ->and($backingOff->isDeferred())->toBeTrue()
        ->and($backingOff->hasBeenRetried())->toBeTrue()
        ->and($sent->isDeferred())->toBeFalse()
        ->and($sent->isDelivered())->toBeTrue()
        ->and($sent->secondsUntilAvailable())->toBe(0);
});

it('compiles the claim lock away on sqlite while keeping the MySQL clause', function (): void {
    // `FOR UPDATE SKIP LOCKED` is MySQL-only. The builder call is portable because
    // SQLite's grammar compiles every lock to the empty string — which also means
    // concurrent-claim behaviour cannot be asserted from this suite and must be
    // verified against MySQL in task 3.3.
    $sql = OutboxMessage::query()->claimable()->lockForClaim()->limit(10)->toSql();

    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and($sql)->not->toContain('skip locked')
        ->and(OutboxMessage::CLAIM_LOCK)->toBe('for update skip locked')
        ->and(
            DB::connection('mysql')->table('outbox')->lock(OutboxMessage::CLAIM_LOCK)->toSql()
        )->toContain('for update skip locked');
});

it('scopes to one tenant, to platform rows, and to an aggregate', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    OutboxMessage::factory()->count(2)->forTenant($acme)->create();
    OutboxMessage::factory()->forTenant($globex)->create();
    OutboxMessage::factory()->create();                       // platform-level: tenant_id null
    OutboxMessage::factory()->create(['aggregate_type' => 'invoice', 'aggregate_id' => 'inv-9']);

    expect(OutboxMessage::query()->forTenant($acme)->count())->toBe(2)
        ->and(OutboxMessage::query()->forTenant($acme->id)->count())->toBe(2)
        ->and(OutboxMessage::query()->forTenant($globex)->count())->toBe(1)
        ->and(OutboxMessage::query()->forTenant(null)->count())->toBe(2)
        ->and(OutboxMessage::query()->forAggregate('invoice', 'inv-9')->count())->toBe(1);
});

it('accepts a platform-level effect with no tenant at all', function (): void {
    // The case a tenant-scoped model could not express: BelongsToTenant's creating
    // hook would refuse this row rather than write a null tenant_id.
    app(TenantContext::class)->forget();

    $message = OutboxMessage::factory()->create(['event_type' => 'gateway.event.acked']);

    expect($message->tenant_id)->toBeNull()
        ->and($message->tenant)->toBeNull()
        ->and(OutboxMessage::query()->claimable()->count())->toBe(1);
});

it('lets the relay claim across tenants with no tenant bound', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    OutboxMessage::factory()->forTenant($acme)->create();
    OutboxMessage::factory()->forTenant($globex)->create();
    OutboxMessage::factory()->create();

    app(TenantContext::class)->forget();

    // One worker, one ordered queue, no MissingTenantContextException — the whole
    // reason this table is exempt from the tenant global scope.
    expect(OutboxMessage::query()->claimable()->count())->toBe(3);
});

it('removes a tenant undelivered effects when the tenant is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    OutboxMessage::factory()->count(3)->forTenant($tenant)->create();
    OutboxMessage::factory()->create();

    $tenant->delete();

    expect(OutboxMessage::query()->forTenant($tenant)->count())->toBe(0)
        ->and(OutboxMessage::query()->count())->toBe(1);
});

it('truncates stored error text', function (): void {
    $long = str_repeat('x', OutboxMessage::MAX_ERROR_LENGTH + 500);

    $message = OutboxMessage::factory()->create([
        'last_error' => OutboxMessage::truncateError($long),
    ]);

    expect(mb_strlen((string) $message->fresh()?->last_error))->toBe(OutboxMessage::MAX_ERROR_LENGTH);
});
