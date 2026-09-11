<?php

declare(strict_types=1);

use App\Enums\IdempotencyMode;
use App\Enums\IdempotencyState;
use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Exceptions\Reliability\UnrecordableResultException;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use App\Services\Reliability\DatabaseIdempotencyStore;
use App\Services\Reliability\IdempotencyOptions;
use App\Services\Reliability\IdempotencyStore;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| IdempotencyStore::once() — generic side-effect dedup (Req 31.2 / NFR2)
|--------------------------------------------------------------------------
| Req 31.2: *"process every payment and bridge/provider webhook idempotently"*;
| Req 25.2 / D2: a gateway webhook is *"processed exactly once"*.
|
| Every test here is about one of two failures, because they are the only two that
| matter: the operation running **twice** (a payment captured twice), or a key being
| left in a state where the operation can **never** run again (a poisoned key that
| silently drops work). The schema-level guarantees they build on — `uniq(scope, key)`,
| the lease windows, `scopePrunable()` — are asserted in `IdempotencyKeyTest`.
*/

/**
 * A store, an operation factory, and a counter of how many times an operation actually ran.
 *
 * The counter is an object rather than an `int` so every closure below shares the same
 * one — which is the whole assertion in most of these tests.
 *
 * @return array{0: IdempotencyStore, 1: Closure, 2: object{runs: int}}
 */
function guardedOperation(): array
{
    $counter = new class
    {
        public int $runs = 0;
    };

    $op = fn (mixed $value = ['status' => 'captured']): Closure => function () use ($value, $counter): mixed {
        $counter->runs++;

        return $value;
    };

    return [app(IdempotencyStore::class), $op, $counter];
}

function ledgerRow(string $scope, string $key): IdempotencyKey
{
    return IdempotencyKey::query()->forKey($scope, $key)->sole();
}

/*
|--------------------------------------------------------------------------
| Exactly once, and the replay is the original answer
|--------------------------------------------------------------------------
*/

it('runs the operation once across many callers with the same key and replays the result', function (): void {
    [$store, $op, $runs] = guardedOperation();

    $first = $store->once('gateway:razorpay', 'evt_1', $op(['status' => 'captured', 'amount_micros' => 990_000]));

    $replays = [];

    foreach (range(1, 5) as $ignored) {
        $replays[] = $store->once('gateway:razorpay', 'evt_1', $op(['status' => 'captured', 'amount_micros' => 990_000]));
    }

    expect($runs->runs)->toBe(1)
        ->and($first->wasExecuted())->toBeTrue()
        ->and($first->isReplay())->toBeFalse()
        ->and($first->value)->toBe(['status' => 'captured', 'amount_micros' => 990_000]);

    foreach ($replays as $replay) {
        // Round-tripped through `idempotency_keys.result`, so this is the *recorded*
        // answer rather than a second run that happened to agree.
        expect($replay->isReplay())->toBeTrue()
            ->and($replay->wasExecuted())->toBeFalse()
            ->and($replay->value)->toBe($first->value)
            ->and($replay->payload())->toBe(['status' => 'captured', 'amount_micros' => 990_000]);
    }

    expect(IdempotencyKey::query()->count())->toBe(1)
        ->and(ledgerRow('gateway:razorpay', 'evt_1')->state)->toBe(IdempotencyState::Completed);
});

it('records the completed key with its replay material and retention horizon', function (): void {
    [$store, $op] = guardedOperation();

    $store->once('gateway:razorpay', 'evt_2', $op(['status' => 'captured']), IdempotencyOptions::default()->keptFor(10));

    $row = ledgerRow('gateway:razorpay', 'evt_2');

    expect($row->state)->toBe(IdempotencyState::Completed)
        ->and($row->result)->toBe(['status' => 'captured'])
        ->and($row->response_hash)->toBe(IdempotencyKey::fingerprint(['status' => 'captured']))
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->expires_at?->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($row->isReplayable())->toBeTrue();
});

it('replays scalar and null results without confusing them with a fresh run', function (): void {
    [$store, $op, $runs] = guardedOperation();

    foreach ([['k' => 'a-string', 'v' => 'order_42'], ['k' => 'an-int', 'v' => 7], ['k' => 'a-bool', 'v' => false], ['k' => 'null', 'v' => null]] as $case) {
        $fresh = $store->once('tool:invoke', (string) $case['k'], $op($case['v']));
        $replay = $store->once('tool:invoke', (string) $case['k'], $op('SHOULD NOT RUN'));

        expect($fresh->value)->toBe($case['v'])
            ->and($fresh->wasExecuted())->toBeTrue()
            ->and($replay->isReplay())->toBeTrue()
            ->and($replay->value)->toBe($case['v']);
    }

    // Four keys, four runs — the replays ran nothing.
    expect($runs->runs)->toBe(4);
});

it('keeps distinct scopes and distinct keys independent', function (): void {
    [$store, $op, $runs] = guardedOperation();

    $store->once('gateway:razorpay', 'evt_1', $op(['from' => 'razorpay']));
    $store->once('gateway:stripe', 'evt_1', $op(['from' => 'stripe']));
    $store->once('gateway:razorpay', 'evt_2', $op(['from' => 'razorpay-2']));

    expect($runs->runs)->toBe(3)
        ->and($store->once('gateway:stripe', 'evt_1', $op('SHOULD NOT RUN'))->value)->toBe(['from' => 'stripe'])
        ->and($store->once('gateway:razorpay', 'evt_1', $op('SHOULD NOT RUN'))->value)->toBe(['from' => 'razorpay'])
        ->and($runs->runs)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Tenancy: attribution is the column, isolation is the scope
|--------------------------------------------------------------------------
*/

it('keeps two tenants sharing one natural key independent through the scope', function (): void {
    [$store, $op, $runs] = guardedOperation();

    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();

    // The convention every caller follows (`QuotaGuard::idempotencyScope()`,
    // `QuotaNotifier::noticeScope()`): the tenant id lives in the *scope*, because
    // `idempotency_keys` is deliberately not tenant-scoped.
    $scopeFor = static fn (Tenant $tenant): string => sprintf('order:%s', $tenant->id);

    $mine = $store->once($scopeFor($first), 'ORDER-1', $op(['tenant' => 'first']), IdempotencyOptions::default()->forTenant($first));
    $theirs = $store->once($scopeFor($second), 'ORDER-1', $op(['tenant' => 'second']), IdempotencyOptions::default()->forTenant($second));

    expect($runs->runs)->toBe(2)
        ->and($mine->wasExecuted())->toBeTrue()
        ->and($theirs->wasExecuted())->toBeTrue()
        ->and($theirs->value)->toBe(['tenant' => 'second'])
        // Neither tenant can replay — or block — the other's key.
        ->and($store->once($scopeFor($second), 'ORDER-1', $op('SHOULD NOT RUN'))->value)->toBe(['tenant' => 'second'])
        ->and(ledgerRow($scopeFor($first), 'ORDER-1')->tenant_id)->toBe($first->id)
        ->and(ledgerRow($scopeFor($second), 'ORDER-1')->tenant_id)->toBe($second->id);
});

it('attributes a key to the bound tenant and allows none before the tenant is known', function (): void {
    [$store, $op] = guardedOperation();
    $context = app(TenantContext::class);
    $tenant = Tenant::factory()->create();

    $context->set($tenant);
    $store->once('wa:inbound', 'msg_1', $op(['ok' => true]));

    // Pre-resolution webhook intake: the dedup that Req 31.2 mandates happens *before*
    // the lookup that decides whose event this is.
    $context->forget();
    $store->once('gateway:razorpay', 'evt_pre', $op(['ok' => true]));

    expect(ledgerRow('wa:inbound', 'msg_1')->tenant_id)->toBe($tenant->id)
        ->and(ledgerRow('gateway:razorpay', 'evt_pre')->tenant_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A failing operation must leave the key retryable
|--------------------------------------------------------------------------
*/

it('leaves the key retryable when the operation throws, and records nothing completed', function (): void {
    [$store, $op, $runs] = guardedOperation();

    $boom = function (): never {
        throw new RuntimeException('gateway timed out');
    };

    expect(fn () => $store->once('gateway:razorpay', 'evt_boom', $boom))
        ->toThrow(RuntimeException::class, 'gateway timed out');

    $failed = ledgerRow('gateway:razorpay', 'evt_boom');

    expect($failed->state)->toBe(IdempotencyState::Failed)
        ->and($failed->result)->toBeNull()
        ->and($failed->response_hash)->toBeNull()
        ->and($failed->isReplayable())->toBeFalse()
        ->and($failed->allowsExecution())->toBeTrue();

    // The retry runs for real — the difference between a transient failure and a
    // permanently poisoned key.
    $retry = $store->once('gateway:razorpay', 'evt_boom', $op(['status' => 'captured']));

    expect($runs->runs)->toBe(1)
        ->and($retry->wasExecuted())->toBeTrue()
        ->and($retry->value)->toBe(['status' => 'captured'])
        ->and(ledgerRow('gateway:razorpay', 'evt_boom')->state)->toBe(IdempotencyState::Completed)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('rolls the claim back entirely when a transactional operation throws', function (): void {
    $store = app(IdempotencyStore::class);

    $sideEffect = function (): never {
        // A database write followed by a failure: the whole point of transactional mode is
        // that neither survives.
        IdempotencyKey::factory()->create(['scope' => 'side-effect', 'key' => 'written']);

        throw new RuntimeException('constraint blew up');
    };

    expect(fn () => $store->once('quota:t1:MESSAGES_MONTHLY', 'msg_1', $sideEffect, IdempotencyOptions::transactional()))
        ->toThrow(RuntimeException::class, 'constraint blew up');

    // No claim, no FAILED row, no side effect: nothing happened at all.
    expect(IdempotencyKey::query()->count())->toBe(0);

    $retry = $store->once(
        'quota:t1:MESSAGES_MONTHLY',
        'msg_1',
        fn (): array => ['used' => 1],
        IdempotencyOptions::transactional(),
    );

    expect($retry->wasExecuted())->toBeTrue()
        ->and(ledgerRow('quota:t1:MESSAGES_MONTHLY', 'msg_1')->state)->toBe(IdempotencyState::Completed);
});

it('commits a transactional operation and its ledger row together', function (): void {
    $store = app(IdempotencyStore::class);

    $outcome = $store->once(
        'quota:t1:MESSAGES_MONTHLY',
        'msg_2',
        function (): array {
            IdempotencyKey::factory()->completed(['counted' => true])->create(['scope' => 'side-effect', 'key' => 'counted']);

            return ['period_key' => '2025-09', 'applied' => 1, 'used' => 1, 'limit' => 100];
        },
        IdempotencyOptions::transactional(),
    );

    expect($outcome->wasExecuted())->toBeTrue()
        ->and(IdempotencyKey::query()->forKey('side-effect', 'counted')->exists())->toBeTrue()
        // The `QuotaGuard::consume()` shape: the recorded payload is replayed verbatim, so
        // `QuotaConsumption::fromLedger()` can rebuild the original receipt.
        ->and(ledgerRow('quota:t1:MESSAGES_MONTHLY', 'msg_2')->result)
        ->toBe(['period_key' => '2025-09', 'applied' => 1, 'used' => 1, 'limit' => 100])
        ->and($store->once('quota:t1:MESSAGES_MONTHLY', 'msg_2', fn (): array => ['SHOULD' => 'NOT RUN'], IdempotencyOptions::transactional())->payload())
        ->toBe(['period_key' => '2025-09', 'applied' => 1, 'used' => 1, 'limit' => 100]);
});

/*
|--------------------------------------------------------------------------
| In flight: wait a bounded budget, then refuse — never run it twice
|--------------------------------------------------------------------------
*/

it('refuses a duplicate while the first caller is still running the operation', function (): void {
    $store = app(IdempotencyStore::class);
    $inner = null;

    // A genuine mid-flight duplicate: the second call happens *while* the first one's
    // operation is on the stack, so the claim it sees is live.
    $outcome = $store->once('saga:s1', 'reserve', function () use ($store, &$inner): array {
        try {
            $store->once('saga:s1', 'reserve', fn (): array => ['ran' => 'twice'], IdempotencyOptions::default()->failingFast());
        } catch (OperationInFlightException $exception) {
            $inner = $exception;
        }

        return ['ran' => 'once'];
    });

    expect($inner)->toBeInstanceOf(OperationInFlightException::class)
        ->and($inner?->getStatusCode())->toBe(409)
        ->and($inner?->getHeaders())->toBe(['Retry-After' => '1'])
        ->and($inner?->publicMessage())->toBe(OperationInFlightException::PUBLIC_MESSAGE)
        // …and the first caller still completed normally.
        ->and($outcome->value)->toBe(['ran' => 'once'])
        ->and(ledgerRow('saga:s1', 'reserve')->result)->toBe(['ran' => 'once']);
});

it('polls for a live holder until its wait budget is spent, then refuses', function (): void {
    Sleep::fake();

    $store = app(IdempotencyStore::class);
    IdempotencyKey::factory()->create(['scope' => 'gateway:razorpay', 'key' => 'evt_held', 'locked_at' => now()]);

    expect(fn () => $store->once(
        'gateway:razorpay',
        'evt_held',
        fn (): array => ['ran' => 'twice'],
        IdempotencyOptions::default()->waitingFor(100),
    ))->toThrow(OperationInFlightException::class);

    // Four 25ms polls inside a 100ms budget — bounded, so one dead holder cannot pin a
    // worker for the length of its lease.
    Sleep::assertSleptTimes(4);
    expect(ledgerRow('gateway:razorpay', 'evt_held')->state)->toBe(IdempotencyState::InFlight);
});

it('replays the holder result when it settles inside the wait budget', function (): void {
    Sleep::fake();

    $store = app(IdempotencyStore::class);
    $held = IdempotencyKey::factory()->create(['scope' => 'gateway:razorpay', 'key' => 'evt_settling', 'locked_at' => now()]);

    // Stand in for the holder finishing while we are asleep.
    Sleep::whenFakingSleep(function () use ($held): void {
        $held->update([
            'state' => IdempotencyState::Completed,
            'result' => ['status' => 'captured'],
            'completed_at' => now(),
        ]);
    });

    $outcome = $store->once(
        'gateway:razorpay',
        'evt_settling',
        fn (): array => ['ran' => 'twice'],
        IdempotencyOptions::default()->waitingFor(100),
    );

    expect($outcome->isReplay())->toBeTrue()
        ->and($outcome->value)->toBe(['status' => 'captured']);
});

it('retakes a key whose holder is presumed dead', function (): void {
    [$store, $op, $runs] = guardedOperation();

    // A worker that was SIGKILLed mid-operation: without a stale lease, one crash would
    // block this key for ever.
    IdempotencyKey::factory()->stale()->create(['scope' => 'gateway:razorpay', 'key' => 'evt_abandoned']);

    $outcome = $store->once('gateway:razorpay', 'evt_abandoned', $op(['status' => 'captured']));

    expect($runs->runs)->toBe(1)
        ->and($outcome->wasExecuted())->toBeTrue()
        ->and(IdempotencyKey::query()->count())->toBe(1)
        ->and(ledgerRow('gateway:razorpay', 'evt_abandoned')->state)->toBe(IdempotencyState::Completed)
        ->and(ledgerRow('gateway:razorpay', 'evt_abandoned')->result)->toBe(['status' => 'captured']);
});

it('honours a shorter lease window when the caller asks for one', function (): void {
    [$store, $op, $runs] = guardedOperation();

    IdempotencyKey::factory()->create([
        'scope' => 'gateway:razorpay',
        'key' => 'evt_short_lease',
        'locked_at' => now()->subSeconds(30),
    ]);

    // 30s old: still live under the 300s default, abandoned under a 10s lease.
    expect(fn () => $store->once('gateway:razorpay', 'evt_short_lease', $op(), IdempotencyOptions::default()->failingFast()))
        ->toThrow(OperationInFlightException::class);

    $outcome = $store->once(
        'gateway:razorpay',
        'evt_short_lease',
        $op(['status' => 'captured']),
        IdempotencyOptions::default()->leasedFor(10)->failingFast(),
    );

    expect($runs->runs)->toBe(1)->and($outcome->wasExecuted())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Same key, different request
|--------------------------------------------------------------------------
*/

it('refuses a key reused with a different request instead of replaying the old answer', function (): void {
    [$store, $op, $runs] = guardedOperation();

    $request = ['event' => 'payment.captured', 'amount' => 990];
    $options = IdempotencyOptions::default()->matching($request);

    $store->once('gateway:razorpay', 'evt_3', $op(['status' => 'captured']), $options);

    // Same key, different body: a client bug, and replaying "captured" would be an answer
    // to a question nobody asked.
    $mismatch = IdempotencyOptions::default()->matching(['event' => 'payment.refunded', 'amount' => 990]);

    try {
        $store->once('gateway:razorpay', 'evt_3', $op(['status' => 'refunded']), $mismatch);
        expect(false)->toBeTrue('the reused key should have been refused');
    } catch (IdempotencyKeyReuseException $exception) {
        expect($exception->getStatusCode())->toBe(422)
            // No Retry-After: retrying this call unchanged will be refused again.
            ->and($exception->getHeaders())->toBe([])
            ->and($exception->publicMessage())->toBe(IdempotencyKeyReuseException::PUBLIC_MESSAGE)
            ->and($exception->getMessage())->not->toContain('evt_3');
    }

    // The same request re-serialised in a different key order is *not* a reuse.
    $reordered = IdempotencyOptions::default()->matching(['amount' => 990, 'event' => 'payment.captured']);

    expect($store->once('gateway:razorpay', 'evt_3', $op('SHOULD NOT RUN'), $reordered)->isReplay())->toBeTrue()
        ->and($runs->runs)->toBe(1)
        ->and(ledgerRow('gateway:razorpay', 'evt_3')->request_fingerprint)->toBe(IdempotencyKey::fingerprint($request));
});

it('does not hold a key to a fingerprint it was never given', function (): void {
    [$store, $op] = guardedOperation();

    $store->once('gateway:razorpay', 'evt_4', $op(['status' => 'captured']));

    expect($store->once('gateway:razorpay', 'evt_4', $op('SHOULD NOT RUN'), IdempotencyOptions::default()->matching(['anything' => true]))->isReplay())
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| At-most-once mode (the QuotaNotifier shape)
|--------------------------------------------------------------------------
*/

it('claims once per key with no lease at all in at-most-once mode', function (): void {
    [$store, $op, $runs] = guardedOperation();

    $notice = ['event' => 'exhausted', 'quota' => 'MESSAGES_MONTHLY'];

    $first = $store->once('quota-notice:t1:MESSAGES_MONTHLY', 'exhausted:2025-09', $op($notice), IdempotencyOptions::atMostOnce());
    $second = $store->once('quota-notice:t1:MESSAGES_MONTHLY', 'exhausted:2025-09', $op($notice), IdempotencyOptions::atMostOnce());

    $row = ledgerRow('quota-notice:t1:MESSAGES_MONTHLY', 'exhausted:2025-09');

    expect($runs->runs)->toBe(1)
        ->and($first->wasExecuted())->toBeTrue()
        ->and($second->isReplay())->toBeTrue()
        ->and($second->value)->toBe($notice)
        ->and($row->state)->toBe(IdempotencyState::Completed)
        ->and($row->result)->toBe($notice)
        // No lease was ever taken, so there is nothing a crashed worker could hold.
        ->and($row->locked_at)->toBeNull()
        ->and($row->isHeld())->toBeFalse();
});

it('never retries an at-most-once operation that threw', function (): void {
    [$store, $op, $runs] = guardedOperation();

    $boom = function (): never {
        throw new RuntimeException('mailer down');
    };

    expect(fn () => $store->once('quota-notice:t1:AI_CREDITS', 'exhausted:2025-09', $boom, IdempotencyOptions::atMostOnce()))
        ->toThrow(RuntimeException::class, 'mailer down');

    // The documented bargain: the key is burned, so the notice is skipped rather than
    // risked twice. A retry replays instead of re-running.
    $retry = $store->once('quota-notice:t1:AI_CREDITS', 'exhausted:2025-09', $op(['event' => 'exhausted']), IdempotencyOptions::atMostOnce());

    expect($runs->runs)->toBe(0)
        ->and($retry->isReplay())->toBeTrue()
        ->and($retry->value)->toBeNull()
        ->and(ledgerRow('quota-notice:t1:AI_CREDITS', 'exhausted:2025-09')->state)->toBe(IdempotencyState::Completed);
});

/*
|--------------------------------------------------------------------------
| Results that cannot be replayed, and keys that cannot be stored
|--------------------------------------------------------------------------
*/

it('refuses a result it cannot replay, but still records that the effect happened', function (): void {
    $store = app(IdempotencyStore::class);
    $ran = 0;

    $op = function () use (&$ran): object {
        $ran++;

        return new stdClass;
    };

    expect(fn () => $store->once('tool:invoke', 'obj', $op))->toThrow(UnrecordableResultException::class);

    // COMPLETED, not FAILED: the side effect already landed, and re-running it would be
    // strictly worse than replaying a null.
    $row = ledgerRow('tool:invoke', 'obj');

    expect($row->state)->toBe(IdempotencyState::Completed)
        ->and($row->result)->toBeNull()
        ->and($store->once('tool:invoke', 'obj', $op)->isReplay())->toBeTrue()
        ->and($ran)->toBe(1);
});

it('rejects a blank or over-long scope or key', function (): void {
    $store = app(IdempotencyStore::class);
    $op = fn (): array => ['ok' => true];

    expect(fn () => $store->once('  ', 'k', $op))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->once('scope', '', $op))->toThrow(InvalidArgumentException::class)
        // SQLite would store an over-long value happily and MySQL would reject or (in a
        // non-strict deployment) *truncate* it — merging two units of work into one.
        ->and(fn () => $store->once(str_repeat('s', DatabaseIdempotencyStore::MAX_SCOPE_LENGTH + 1), 'k', $op))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->once('scope', str_repeat('k', DatabaseIdempotencyStore::MAX_KEY_LENGTH + 1), $op))
        ->toThrow(InvalidArgumentException::class)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Pruning
|--------------------------------------------------------------------------
*/

it('prunes only expired rows and never a row without an expiry', function (): void {
    $store = app(IdempotencyStore::class);

    IdempotencyKey::factory()->completed()->expired()->create(['key' => 'k-expired']);
    IdempotencyKey::factory()->failed()->expired()->create(['key' => 'k-expired-failed']);
    IdempotencyKey::factory()->completed()->create(['key' => 'k-live', 'expires_at' => now()->addDay()]);
    // Never deleted at any age: dropping it would silently re-arm a side effect that has
    // already been applied.
    IdempotencyKey::factory()->completed()->create(['key' => 'k-forever', 'expires_at' => null]);

    expect($store->prune())->toBe(2)
        ->and(IdempotencyKey::query()->pluck('key')->sort()->values()->all())->toBe(['k-forever', 'k-live']);
});

it('drains the ledger across batches', function (): void {
    $store = app(IdempotencyStore::class);

    foreach (range(1, 7) as $index) {
        IdempotencyKey::factory()->completed()->expired()->create(['key' => 'k-'.$index]);
    }

    expect($store->prune(2))->toBe(7)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});

it('keeps a key written with no horizon out of reach of the pruner', function (): void {
    [$store, $op] = guardedOperation();

    $store->once('offboarding', 'tenant-purge', $op(['purged' => true]), IdempotencyOptions::default()->keptForever());

    expect(ledgerRow('offboarding', 'tenant-purge')->expires_at)->toBeNull()
        ->and($store->prune())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Property-ish: whatever the retry pattern, one run per key
|--------------------------------------------------------------------------
*/

it('runs each key exactly once however the duplicates interleave', function (): void {
    foreach (range(1, 15) as $iteration) {
        IdempotencyKey::query()->delete();

        $store = app(IdempotencyStore::class);
        $mode = [IdempotencyMode::Lease, IdempotencyMode::Transactional, IdempotencyMode::AtMostOnce][random_int(0, 2)];
        $options = IdempotencyOptions::default()->using($mode)->failingFast();

        $keys = array_map(static fn (int $index): string => 'work-'.$index, range(1, random_int(1, 5)));
        $runs = [];

        // A random interleaving of first attempts and retries, as a queue would produce.
        $attempts = [];

        foreach ($keys as $key) {
            foreach (range(1, random_int(1, 4)) as $ignored) {
                $attempts[] = $key;
            }
        }

        shuffle($attempts);

        foreach ($attempts as $key) {
            $outcome = $store->once('work:'.$iteration, $key, function () use ($key, &$runs): array {
                $runs[$key] = ($runs[$key] ?? 0) + 1;

                return ['key' => $key];
            }, $options);

            // Whichever attempt won, every attempt gets the same answer.
            expect($outcome->payload())->toBe(['key' => $key]);
        }

        foreach ($keys as $key) {
            expect($runs[$key] ?? 0)->toBe(1, sprintf('iteration %d (%s): key %s ran %d times', $iteration, $mode->value, $key, $runs[$key] ?? 0));
        }

        expect(IdempotencyKey::query()->count())->toBe(count($keys));
    }
})->group('property');
