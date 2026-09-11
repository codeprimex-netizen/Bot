<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\OutboxStatus;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Exceptions\Reliability\OutboxDeliveryException;
use App\Models\OutboxMessage;
use App\Models\Tenant;
use App\Services\Reliability\CircuitBreakerKey;
use App\Services\Reliability\DatabaseOutbox;
use App\Services\Reliability\Outbox;
use App\Services\Reliability\OutboxDelivery;
use App\Services\Reliability\OutboxEnvelope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Reliability\Outboxes;
use Tests\Fixtures\Reliability\RecordingOutboxTransport;

/*
|--------------------------------------------------------------------------
| Transactional outbox + relay (Req 31.4 / NFR2, Algorithm 6, Property 16)
|--------------------------------------------------------------------------
| Two halves, tested as two halves:
|
|   • enqueue joins the caller's transaction, so the intent and the state change share
|     one commit — there is no instant at which one exists without the other;
|   • the relay claims due rows, delivers each with its `dedup_key` header, and marks a
|     row SENT only after the receiver acks — so a crash redelivers, and the consumer's
|     dedup makes the *effect* exactly once.
|
| `FOR UPDATE SKIP LOCKED` is MySQL-only (it compiles away on SQLite), so the exclusivity
| assertions here are made against the mechanism that works on both: the claim *lease*.
| What the lock adds on MySQL is asserted separately, from the SQL the claim really ran.
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Enqueue — inside the caller's transaction
|--------------------------------------------------------------------------
*/

it('commits the intent with the state change and loses it with a rollback', function (): void {
    $outbox = Outboxes::acking();

    // The dual write the outbox exists to remove: if the state change rolls back, the
    // effect rolls back with it — no webhook for an order that was never paid.
    try {
        DB::transaction(function () use ($outbox): void {
            $outbox->enqueue('order', 'ord-1', 'order.paid', ['order_id' => 'ord-1'], 'order.paid:ord-1');

            throw new RuntimeException('the state change failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxMessage::query()->count())->toBe(0);

    DB::transaction(function () use ($outbox): void {
        $outbox->enqueue('order', 'ord-1', 'order.paid', ['order_id' => 'ord-1'], 'order.paid:ord-1');
    });

    $row = OutboxMessage::query()->forDedupKey('order.paid:ord-1')->sole();

    expect($row->status)->toBe(OutboxStatus::Pending)
        ->and($row->attempts)->toBe(0)
        ->and($row->payload)->toBe(['order_id' => 'ord-1'])
        // Both clocks start open and equal: the gate is initialised from the intent.
        ->and($row->next_attempt_at->equalTo($row->available_at))->toBeTrue()
        ->and($row->isClaimable())->toBeTrue();
});

it('inserts inside the caller transaction rather than one of its own', function (): void {
    $outbox = Outboxes::acking();
    $insertedAtLevel = [];
    $callerLevel = null;

    DB::transaction(function () use ($outbox, &$insertedAtLevel, &$callerLevel): void {
        $callerLevel = DB::transactionLevel();

        DB::listen(function (QueryExecuted $query) use (&$insertedAtLevel): void {
            if (str_contains(strtolower($query->sql), 'insert into "outbox"')
                || str_contains(strtolower($query->sql), 'insert into `outbox`')) {
                $insertedAtLevel[] = DB::transactionLevel();
            }
        });

        $outbox->enqueue('order', 'ord-2', 'order.paid', [], 'order.paid:ord-2');
    });

    // One insert, at the caller's own nesting level: `record()` neither opened a
    // transaction nor committed one, which is what makes the two writes atomic.
    expect($insertedAtLevel)->toBe([$callerLevel]);
});

it('attributes the effect to the ambient tenant and leaves a platform effect tenant-less', function (): void {
    $tenant = Tenant::factory()->create();
    $outbox = Outboxes::acking();
    $context = app(TenantContext::class);

    $context->set($tenant);
    $outbox->enqueue('order', 'ord-3', 'order.paid', [], 'order.paid:ord-3');

    $context->forget();
    $outbox->enqueue('platform', 'gw-1', 'gateway.acked', [], 'gateway.acked:gw-1');

    expect(OutboxMessage::query()->forDedupKey('order.paid:ord-3')->sole()->tenant_id)->toBe($tenant->id)
        ->and(OutboxMessage::query()->forDedupKey('gateway.acked:gw-1')->sole()->tenant_id)->toBeNull();
});

it('records a destination, an explicit tenant and a scheduled delivery intent', function (): void {
    $tenant = Tenant::factory()->create();

    $row = Outboxes::acking()->record(
        OutboxEnvelope::for('order', 'ord-4', 'order.paid', ['total' => 10], 'order.paid:ord-4')
            ->to('https://shop.test/hooks')
            ->forTenant($tenant)
            ->delayedBy(600),
    );

    expect($row->destination)->toBe('https://shop.test/hooks')
        ->and($row->tenant_id)->toBe($tenant->id)
        ->and($row->available_at->isFuture())->toBeTrue()
        ->and($row->next_attempt_at->equalTo($row->available_at))->toBeTrue()
        ->and($row->isDeferred())->toBeTrue();
});

it('refuses an intent it could not store faithfully', function (): void {
    // SQLite would accept an over-long key and a non-strict MySQL would truncate it —
    // merging two distinct effects into one row and skipping one of them for ever.
    expect(fn (): OutboxEnvelope => OutboxEnvelope::for('order', 'ord-5', 'order.paid', [], str_repeat('k', 192)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OutboxEnvelope => OutboxEnvelope::for('order', '  ', 'order.paid', [], 'order.paid:ord-5'))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Relay — the happy path and the two clocks
|--------------------------------------------------------------------------
*/

it('delivers a due row with its dedup key and only then marks it sent', function (): void {
    $transport = RecordingOutboxTransport::acking();
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-6', 'event_type' => 'order.paid']);

    expect($outbox->relay())->toBe(1);

    $delivery = $transport->lastDelivery();
    $row->refresh();

    expect($delivery)->toBeInstanceOf(OutboxDelivery::class)
        ->and($delivery?->headers)->toBe([
            'X-Dedup-Key' => 'order.paid:ord-6',
            'X-Event-Type' => 'order.paid',
            OutboxDelivery::ATTEMPT_HEADER => '1',
        ])
        ->and($delivery?->payload)->toBe($row->payload)
        ->and($delivery?->isRedelivery())->toBeFalse()
        ->and($row->status)->toBe(OutboxStatus::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toBeNull()
        // Delivered rows are never claimed again.
        ->and($outbox->relay())->toBe(0)
        ->and($transport->deliveryCount())->toBe(1);
});

it('honours both clocks: an open retry gate does not deliver a scheduled effect early', function (): void {
    $transport = RecordingOutboxTransport::acking();
    $outbox = Outboxes::relayingWith($transport);

    $scheduled = OutboxMessage::factory()->deferred(600)->create();

    // An operator — or a bug — reopens the retry gate while the *intent* is still in the
    // future. `available_at` is the immutable intent, so the row still must not go out.
    OutboxMessage::query()->whereKey($scheduled->getKey())->update(['next_attempt_at' => now()->subMinute()]);

    expect($outbox->relayBatch()->claimed())->toBe(0)
        ->and($transport->deliveryCount())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(11));

    expect($outbox->relay())->toBe(1);
});

it('accounts for every claimed row and reports nothing when the queue is empty', function (): void {
    $outbox = Outboxes::acking();
    OutboxMessage::factory()->count(3)->create();

    $report = $outbox->relayBatch(2);

    expect($report->claimed())->toBe(2)
        ->and($report->delivered())->toBe(2)
        ->and($report->isBalanced())->toBeTrue()
        ->and($report->isEmpty())->toBeFalse();

    $outbox->relayBatch();

    expect($outbox->relayBatch()->isEmpty())->toBeTrue();
});

it('claims oldest first so effects are delivered in the order they were recorded', function (): void {
    $transport = RecordingOutboxTransport::acking();
    $outbox = Outboxes::relayingWith($transport);

    $keys = ['a', 'b', 'c'];

    foreach ($keys as $key) {
        OutboxMessage::factory()->create(['dedup_key' => $key]);
    }

    $outbox->relay();

    expect(array_map(
        static fn (OutboxDelivery $delivery): string => $delivery->dedupKey,
        $transport->deliveries(),
    ))->toBe($keys);
});

/*
|--------------------------------------------------------------------------
| Failure, backoff, parking
|--------------------------------------------------------------------------
*/

it('retries a transient failure on the retry policy backoff and keeps the row', function (): void {
    // Frozen, because full jitter draws from the *whole* window including 0: an unfrozen
    // clock makes "the gate is not in the past" a race against the microseconds the
    // assertions themselves take.
    Carbon::setTestNow('2025-06-14 12:00:00');

    $transport = RecordingOutboxTransport::failing(new ConnectionException('connection refused'));
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create();

    $report = $outbox->relayBatch();
    $row->refresh();

    expect($report->retrying())->toBe(1)
        ->and($report->delivered())->toBe(0)
        ->and($report->isBalanced())->toBeTrue()
        ->and($row->status)->toBe(OutboxStatus::Failed)
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toContain('connection refused')
        // The policy's jittered window, never a second backoff formula: NETWORK caps at 30s.
        ->and($row->next_attempt_at->greaterThanOrEqualTo(now()))->toBeTrue()
        ->and($row->next_attempt_at->lessThanOrEqualTo(now()->addSeconds(31)))->toBeTrue()
        // Kept, not dropped. The gate is deliberately *not* asserted to be in the future:
        // full jitter draws from the whole window including 0, so an immediate re-attempt on
        // a later pass is the policy working, not the backoff failing.
        ->and(OutboxMessage::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

it('delivers a row that failed once, on a later pass', function (): void {
    $transport = RecordingOutboxTransport::failingTimes(1, new ConnectionException('flaky'));
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-7']);

    $outbox->relayBatch();

    Carbon::setTestNow(now()->addMinute());

    expect($outbox->relay())->toBe(1);

    $row->refresh();

    expect($row->status)->toBe(OutboxStatus::Sent)
        ->and($row->attempts)->toBe(2)
        ->and($row->hasBeenRetried())->toBeTrue()
        ->and($transport->applied('order.paid:ord-7'))->toBe(1)
        ->and($transport->deliveries()[1]->isRedelivery())->toBeTrue();
});

it('parks a failure that waiting cannot fix, on the first attempt', function (): void {
    // 422: our own misconfiguration (no destination). VALIDATION is fail-fast — retrying a
    // missing URL twelve times only delays the moment somebody notices.
    $transport = RecordingOutboxTransport::behaving(
        function (RecordingOutboxTransport $transport, OutboxDelivery $delivery): void {
            throw OutboxDeliveryException::undeliverable($delivery, 'the row names no destination');
        }
    );
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create(['destination' => null]);

    $report = $outbox->relayBatch();
    $row->refresh();

    expect($report->parked())->toBe(1)
        ->and($report->isBalanced())->toBeTrue()
        // Kept, not dropped — and held out of the claim by its spent budget, because
        // `OutboxStatus` has no DEAD state to lose it into.
        ->and(OutboxMessage::query()->whereKey($row->getKey())->exists())->toBeTrue()
        ->and($row->status)->toBe(OutboxStatus::Failed)
        ->and($row->attempts)->toBe((int) config('wa.reliability.outbox.max_attempts'))
        ->and($row->last_error)->toContain('names no destination')
        ->and($outbox->relayBatch()->claimed())->toBe(0);
});

it('parks a row whose attempt budget is spent and hands it back when requeued', function (): void {
    config()->set('wa.reliability.outbox.max_attempts', 2);

    $transport = RecordingOutboxTransport::failing(new ConnectionException('down'));
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-8']);

    foreach ([1, 2] as $pass) {
        Carbon::setTestNow(now()->addMinute());
        $outbox->relayBatch();
    }

    $row->refresh();

    expect($row->attempts)->toBe(2)
        ->and($row->status)->toBe(OutboxStatus::Failed)
        ->and($row->last_error)->toContain('Parked')
        ->and($outbox->relayBatch()->claimed())->toBe(0);

    // The operator half of "never lose the row": a fresh budget, an open gate, the same
    // dedup key — so a consumer that saw an earlier delivery still applies the effect once.
    expect($outbox->requeue($row->id))->toBeTrue();

    $row->refresh();

    expect($row->attempts)->toBe(0)
        ->and($row->status)->toBe(OutboxStatus::Pending)
        ->and($row->isClaimable())->toBeTrue();

    Outboxes::acking()->relay();

    expect($row->refresh()->status)->toBe(OutboxStatus::Sent);
});

it('parks a row whose budget was spent by claims that never reported an outcome', function (): void {
    config()->set('wa.reliability.outbox.max_attempts', 2);
    config()->set('wa.reliability.outbox.lease_seconds', 60);

    $transport = RecordingOutboxTransport::acking();
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-11']);

    /*
    | A worker killed *between* the claim and the delivery — the one interleaving no
    | transport double can produce, because the relay catches everything a transport throws.
    | So it is staged with the relay's own claim statement: the attempt is counted, the lease
    | is taken, and then nothing ever comes back. Twice, so the whole budget goes that way
    | and not one failure is ever recorded inside the relay.
    */
    foreach ([1, 2] as $ignored) {
        OutboxMessage::query()
            ->whereKey($row->getKey())
            ->increment('attempts', 1, ['next_attempt_at' => now()->addSeconds(60)]);

        Carbon::setTestNow(now()->addSeconds(61));
    }

    // Where that leaves the row, and why it is the one state nothing will ever look at:
    // `PENDING`, so absent from every parked-row query an operator would write, with no
    // reason recorded — and with a spent budget, so no claim will ever take it again.
    expect($row->refresh()->status)->toBe(OutboxStatus::Pending)
        ->and($row->attempts)->toBe(2)
        ->and($row->last_error)->toBeNull();

    $report = $outbox->relayBatch();
    $row->refresh();

    expect($report->abandoned())->toBe(1)
        ->and($report->claimed())->toBe(0)
        ->and($report->isBalanced())->toBeTrue()
        // A pass that repaired one of these did something an operator has to hear about, so
        // it is not an empty pass even though it claimed nothing.
        ->and($report->isEmpty())->toBeFalse()
        // Repaired, not delivered: the budget really is spent.
        ->and($transport->deliveryCount())->toBe(0)
        ->and($row->status)->toBe(OutboxStatus::Failed)
        ->and($row->attempts)->toBe(2)
        ->and($row->last_error)->toContain('Parked')
        ->and($row->last_error)->toContain('did not survive')
        ->and($row->isClaimable())->toBeFalse();

    // Once and once only: the row is `FAILED` now, so no later pass rescans it — and none
    // can overwrite the diagnosis of a row that failed for a reason of its own.
    expect($outbox->relayBatch()->abandoned())->toBe(0);

    // And the operator half of "never lose the row" still works on it.
    expect($outbox->requeue($row->id))->toBeTrue()
        ->and($outbox->relay())->toBe(1)
        ->and($transport->applied('order.paid:ord-11'))->toBe(1);
});

it("keeps a failed row's own diagnosis when the rest of its budget went the same way", function (): void {
    config()->set('wa.reliability.outbox.max_attempts', 2);

    $transport = RecordingOutboxTransport::failing(new ConnectionException('connection refused'));
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create();

    // Attempt 1 fails inside the relay, so the row is `FAILED` and says why.
    $outbox->relayBatch();
    Carbon::setTestNow(now()->addMinutes(2));

    // Attempt 2 is spent by a worker killed between the claim and the write. The budget is
    // gone, so no claim will take the row again — but it already reads as the parked
    // signature (`FAILED` with a spent budget), and the reason it carries is a real failure
    // of its own. Overwriting that with a generic parking note would cost an operator the
    // one diagnosis on the row and buy nothing, so the relay leaves it alone.
    OutboxMessage::query()->whereKey($row->getKey())->increment('attempts', 1, ['next_attempt_at' => now()]);

    $before = $row->refresh()->last_error;
    $report = $outbox->relayBatch();

    expect($report->abandoned())->toBe(0)
        ->and($report->claimed())->toBe(0)
        ->and($report->isEmpty())->toBeTrue()
        ->and($row->refresh()->status)->toBe(OutboxStatus::Failed)
        ->and($row->attempts)->toBe(2)
        ->and($row->last_error)->toBe($before)
        ->and($row->last_error)->toContain('connection refused');
});

it('leaves a row whose final attempt is still in flight alone', function (): void {
    config()->set('wa.reliability.outbox.max_attempts', 1);

    /*
    | The row a reap must not touch. Its budget is spent (counted at claim time) and it is
    | still `PENDING` — the same shape as an abandoned row — because its only attempt is in
    | flight *right now*. The lease is the whole distinction between a worker that is still
    | working and one that is never coming back, so the re-entrant pass runs from inside the
    | delivery, which is where a second worker would look.
    */
    $inner = null;

    $transport = RecordingOutboxTransport::behaving(
        function (RecordingOutboxTransport $transport, OutboxDelivery $delivery) use (&$inner): void {
            $inner ??= app(Outbox::class)->relayBatch();

            $transport->accept($delivery);
        }
    );

    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-12']);

    $report = $outbox->relayBatch();

    expect($inner?->abandoned())->toBe(0)
        ->and($report->abandoned())->toBe(0)
        ->and($row->refresh()->status)->toBe(OutboxStatus::Sent)
        ->and($row->last_error)->toBeNull()
        ->and($transport->applied('order.paid:ord-12'))->toBe(1);
});

it('refuses to requeue a delivered or unknown row', function (): void {
    $outbox = Outboxes::acking();
    $sent = OutboxMessage::factory()->sent()->create();

    expect($outbox->requeue($sent))->toBeFalse()
        ->and($outbox->requeue(9_999))->toBeFalse()
        ->and($sent->refresh()->status)->toBe(OutboxStatus::Sent);
});

it('never reopens the gate before the delivery intent when requeuing', function (): void {
    $outbox = Outboxes::acking();
    $row = OutboxMessage::factory()->deferred(900)->failed(attempts: 3)->create();

    expect($outbox->requeue($row))->toBeTrue()
        ->and($row->refresh()->next_attempt_at->equalTo($row->available_at))->toBeTrue()
        ->and($row->isClaimable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Exclusivity: the lease (both engines) and the lock (MySQL)
|--------------------------------------------------------------------------
*/

it('leases a claimed row so a concurrent pass cannot claim or deliver it twice', function (): void {
    $inner = null;

    // The re-entrant pass runs *while* the first row is still being delivered — exactly the
    // window a second worker would claim in. It sees nothing claimable, because claiming
    // pushed the gate `lease_seconds` into the future: the mechanism that also holds on
    // SQLite, where the FOR UPDATE SKIP LOCKED clause compiles away.
    $transport = RecordingOutboxTransport::behaving(
        function (RecordingOutboxTransport $transport, OutboxDelivery $delivery) use (&$inner): void {
            $inner ??= app(Outbox::class)->relayBatch();

            $transport->accept($delivery);
        }
    );

    $outbox = Outboxes::relayingWith($transport);
    OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-9']);

    $report = $outbox->relayBatch();

    expect($report->delivered())->toBe(1)
        ->and($inner?->claimed())->toBe(0)
        ->and($transport->deliveryCount('order.paid:ord-9'))->toBe(1)
        ->and($transport->applied('order.paid:ord-9'))->toBe(1);
});

it('claims with the ordered predicate, and with FOR UPDATE SKIP LOCKED where the engine has it', function (): void {
    $outbox = Outboxes::acking();
    OutboxMessage::factory()->create();

    DB::enableQueryLog();
    $outbox->relayBatch(5);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $claim = collect($queries)
        ->pluck('query')
        ->filter(fn (mixed $sql): bool => is_string($sql))
        ->map(fn (mixed $sql): string => (string) $sql)
        ->first(fn (string $sql): bool => str_contains($sql, 'select * from "outbox"')
            || str_contains($sql, 'select * from `outbox`'));

    expect($claim)->not->toBeNull()
        ->and($claim)->toContain('order by')
        ->and($claim)->toContain('limit 5');

    // The row lock is the concurrency guarantee on MySQL and a no-op on SQLite, so the
    // assertion follows the engine instead of pretending this suite proved exclusivity —
    // which the lease test above does prove, on both.
    DB::connection()->getDriverName() === 'mysql'
        ? expect($claim)->toContain('for update skip locked')
        : expect($claim)->not->toContain('for update');
});

/*
|--------------------------------------------------------------------------
| Property 16: exactly-once effect across a crash
|--------------------------------------------------------------------------
*/

it('applies the effect exactly once when the worker dies between the ack and the SENT write', function (): void {
    $transport = RecordingOutboxTransport::crashingAfterAck(new RuntimeException('worker killed'));
    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create(['dedup_key' => 'order.paid:ord-10']);

    // Attempt 1: the receiver applied the effect, then the worker died before the row could
    // be marked SENT. The row survives — that is the whole point.
    $outbox->relayBatch();
    $row->refresh();

    expect($row->status)->toBe(OutboxStatus::Failed)
        ->and($row->attempts)->toBe(1)
        ->and($transport->applied('order.paid:ord-10'))->toBe(1);

    Carbon::setTestNow(now()->addMinute());

    // Attempt 2 redelivers the same dedup key. Delivery is at-least-once; the consumer's
    // dedup makes the effect exactly-once (Property 16).
    expect($outbox->relay())->toBe(1)
        ->and($transport->deliveryCount('order.paid:ord-10'))->toBe(2)
        ->and($transport->applied('order.paid:ord-10'))->toBe(1)
        ->and($row->refresh()->status)->toBe(OutboxStatus::Sent);
});

it('keeps every row across a run of crash and retry interleavings, applying each effect once', function (): void {
    // Not the dedicated property test (task 3.8) — a small interleaving sweep, so "no row is
    // lost and no effect is applied twice" is checked over more than one scripted path.
    config()->set('wa.reliability.outbox.max_attempts', 6);

    $crashes = [true, false, true, false, false, true];
    $index = 0;

    $transport = RecordingOutboxTransport::behaving(
        function (RecordingOutboxTransport $transport, OutboxDelivery $delivery) use (&$index, $crashes): void {
            $crash = $crashes[$index++ % count($crashes)];

            $transport->accept($delivery);

            if ($crash) {
                throw new RuntimeException('worker died after the ack');
            }
        }
    );

    $outbox = Outboxes::relayingWith($transport);
    $keys = [];

    foreach (range(1, 6) as $n) {
        $keys[] = $key = "order.paid:batch-{$n}";
        OutboxMessage::factory()->create(['dedup_key' => $key]);
    }

    foreach (range(1, 5) as $pass) {
        Carbon::setTestNow(now()->addMinutes(2));
        expect($outbox->relayBatch()->isBalanced())->toBeTrue();
    }

    expect(OutboxMessage::query()->count())->toBe(6);

    foreach ($keys as $key) {
        expect($transport->applied($key))->toBe(1);
    }
});

/*
|--------------------------------------------------------------------------
| Circuit-shed and the dedup horizon
|--------------------------------------------------------------------------
*/

it('hands the attempt back when a breaker sheds the call, and waits out its cool-down', function (): void {
    $breaker = CircuitOpenException::open(CircuitBreakerKey::for(CircuitScope::Provider, 'acme'), 30);
    $transport = RecordingOutboxTransport::behaving(
        function (RecordingOutboxTransport $transport, OutboxDelivery $delivery) use ($breaker): void {
            throw $breaker;
        }
    );

    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create();

    $report = $outbox->relayBatch();
    $row->refresh();

    expect($report->shed())->toBe(1)
        ->and($report->parked())->toBe(0)
        ->and($report->isBalanced())->toBeTrue()
        // A call that was never made is not a failed attempt: the budget is intact and the
        // row is still PENDING, not FAILED.
        ->and($row->attempts)->toBe(0)
        ->and($row->status)->toBe(OutboxStatus::Pending)
        ->and($transport->appliedCount())->toBe(0)
        ->and($row->next_attempt_at->greaterThan(now()->addSeconds(25)))->toBeTrue()
        ->and($row->next_attempt_at->lessThanOrEqualTo(now()->addSeconds(31)))->toBeTrue();
});

it('parks rather than redelivers an effect whose dedup key the consumer may have pruned', function (): void {
    $transport = RecordingOutboxTransport::acking();
    $outbox = Outboxes::relayingWith($transport);

    $horizon = (int) config('wa.reliability.idempotency.retention_days');
    $row = OutboxMessage::factory()->create();

    OutboxMessage::query()->whereKey($row->getKey())->update([
        'created_at' => now()->subDays($horizon + 1),
        'available_at' => now()->subDays($horizon + 1),
    ]);

    $report = $outbox->relayBatch();
    $row->refresh();

    expect($report->parked())->toBe(1)
        // Not delivered at all: past the horizon a redelivery is indistinguishable from a
        // first delivery, so the effect could be applied twice.
        ->and($transport->deliveryCount())->toBe(0)
        ->and($row->status)->toBe(OutboxStatus::Failed)
        ->and($row->last_error)->toContain('dedup key')
        // And the operator path refuses too: the remedy is a fresh intent with a fresh key.
        ->and($outbox->requeue($row))->toBeFalse();
});

it('cannot be configured to redeliver past the dedup ledger it depends on', function (): void {
    // The guard `wa.reliability.idempotency.retention_days` needs: it is 45 days and is
    // documented as having to outlive the longest retry window of anything it guards, so an
    // outbox horizon beyond it would silently re-arm side effects. Config cannot ask for one.
    config()->set('wa.reliability.idempotency.retention_days', 45);
    config()->set('wa.reliability.outbox.dedup_horizon_days', 3_650);

    $outbox = app(DatabaseOutbox::class);

    expect($outbox->dedupHorizonDays())->toBe(45);

    config()->set('wa.reliability.outbox.dedup_horizon_days', 7);

    expect($outbox->dedupHorizonDays())->toBe(7);
});

it('parks a row whose receiver asked to wait past the dedup horizon', function (): void {
    config()->set('wa.reliability.outbox.dedup_horizon_days', 1);

    // A 429 with `Retry-After: 2 days`. RetryPolicy honours a named wait verbatim, so the
    // gate would fall outside the window in which the consumer still remembers the key.
    $transport = RecordingOutboxTransport::behaving(
        function (RecordingOutboxTransport $transport, OutboxDelivery $delivery): void {
            throw OutboxDeliveryException::rejected($delivery, 429, 2 * 24 * 60 * 60);
        }
    );

    $outbox = Outboxes::relayingWith($transport);
    $row = OutboxMessage::factory()->create();

    $report = $outbox->relayBatch();
    $row->refresh();

    expect($report->parked())->toBe(1)
        ->and($report->retrying())->toBe(0)
        ->and($row->last_error)->toContain('dedup horizon')
        ->and($row->next_attempt_at->lessThanOrEqualTo(now()->addDay()))->toBeTrue();
});
