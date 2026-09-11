<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\ErrorClass;
use App\Enums\OutboxStatus;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Exceptions\Reliability\OutboxDeliveryException;
use App\Models\OutboxMessage;
use App\Models\Tenant;
use App\Services\Reliability\CircuitBreakerKey;
use App\Services\Reliability\Outbox;
use App\Services\Reliability\OutboxDelivery;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Reliability\Outboxes;
use Tests\Fixtures\Reliability\OutboxProbe;
use Tests\Fixtures\Reliability\RecordingOutboxTransport;

/*
|--------------------------------------------------------------------------
| Correctness Property 16 — exactly-once outbox effect
|--------------------------------------------------------------------------
| design.md: *"∀ outbox row → the downstream effect is applied exactly once (delivery is
| at-least-once, deduped by `dedup_key`), and no row is lost in any crash/retry
| interleaving."*
|
| **Validates: Requirements 31.4 / NFR2, 25.2 / D2**
|
| ## What this adds over `OutboxTest`
|
| `OutboxTest` proves the property by example, including a six-message crash sweep with a
| **scripted** `[true, false, true, false, false, true]` crash list, one batch size, one
| clock schedule and one fault kind (crash-after-ack). Every path it walks is a path
| somebody chose, so it can only fail for a reason somebody already thought of. This file
| states the property instead, and the difference is four claims the scripted sweep cannot
| make:
|
|  1. **The interleavings are generated.** Random workloads across random tenants (and the
|     tenant-less platform), random `available_at` schedules, random batch sizes, a random
|     number of passes with random clock advances, and a fault drawn per *attempt* from the
|     whole realistic menu — an ack, a crash after the ack, a crash before it, a network
|     failure, a 5xx, a 429 with a `Retry-After`, a breaker shedding the call, and a worker
|     that dies between the claim and the delivery.
|  2. **The oracle is per attempt, not just per run.** After every pass, each row is
|     compared against what the fault drawn for it licensed: an ack is `SENT`, a shed leaves
|     the budget *intact*, a failure with budget left is `FAILED` on the policy's gate, a
|     failure without it is parked with its budget burnt, an unclaimed row is untouched to
|     the microsecond unless its budget is spent and its lease expired — in which case it
|     must be parked — and a leased row is invisible. A relay that reached the same
|     end-state by a different route fails here.
|  3. **Two invariants after every single pass**, which is where Req 31.4 lives: for every
|     dedup key `applied() === 1` if the effect was ever acked and `0` if it never was —
|     never 2 — and `OutboxMessage::count()` is exactly what was enqueued. Plus
|     `OutboxRelayReport::isBalanced()`, so no claimed row leaves a pass unaccounted for.
|  4. **Exactly-once survives the park/requeue boundary.** Every parked row is requeued and
|     drained to `SENT` at the end, and the effect it may already have applied before parking
|     is still applied exactly once afterwards.
|
| ## What the generated interleavings turned up
|
| A row can have its **last** attempt counted by a claim whose worker then died. Its budget
| is spent, so it is held out of the claim exactly as a parked row is — but nothing inside
| the relay failed, so nothing wrote `FAILED` or a reason. That is the consequence of
| counting the attempt at claim time (a row that kills its worker must exhaust its budget
| rather than poison the queue for ever), and it has two shapes:
|
|  - the row had already failed at least once, so it is `FAILED` with the previous failure in
|    `last_error` — visibly out of the queue with its budget spent, which is the parked
|    signature an operator reads, and a diagnosis the relay is right not to overwrite;
|  - the row had **never** failed, so it was still `PENDING`. Which is a row nothing will
|    ever claim again (the claim's `attempts < max_attempts` predicate) that nonetheless
|    reads as "enqueued, never attempted, due now": absent from every parked-row query, with
|    no reason recorded, and never delivered. That is silent non-delivery, and this file
|    caught it at seed 7517875994292234202 — three claims killed mid-flight spent one row's
|    whole budget of 3 without a single failure inside the relay.
|
| The relay now repairs the second shape: `DatabaseOutbox::reapAbandoned()` runs before every
| claim and parks the rows whose budget is spent, whose lease has expired and which are still
| `PENDING`, which is a state nothing but a run of unreported claims can produce. So the
| oracle below requires of *every* undelivered row that it end `FAILED`, with its budget
| spent, with a reason, and — with the single exception of the first shape above, which is
| both `heldToDeath` and `everFailed` — with the "Parked" wording. Parking a row it did not
| claim is also the only change a pass is allowed to make to a row it did not attempt.
|
| ## Anti-vacuity
|
| A test asserting only `applied() <= 1` passes against a transport that never delivers, so
| the acked half is asserted positively everywhere: an acked key's count is `1` and not `0`,
| a `SENT` row's effect *must* have been applied (which is what catches a relay that marks
| the row before delivering it), a fault-free workload reaches `SENT` for every row and is
| delivered exactly once, and the run ends with every row `SENT` and every effect applied.
| The menu's coverage is asserted too, so a future edit cannot quietly drop a fault kind.
|
| Two mutants were run against this file, and each is caught within one iteration: marking a
| row `SENT` before calling the transport — caught by the per-attempt oracle, which finds a
| row `SENT` whose attempt the receiver had refused, and by the `SENT` ⇒ applied clause when
| the receiver never acked at all — and claiming without leasing the row, caught by the
| same-pass duplicate-delivery clause and by the dead worker's rows being claimed out from
| under it.
|
| ## Reproducibility
|
| One seed fixes every draw — workload size, tenants, schedules, batch sizes, pass count,
| clock advances, every fault, every `Retry-After`, and the dead worker's batch. It is
| printed in every failure message, together with the pass and the drawn fault, and
| `OUTBOX_EXACTLY_ONCE_SEED=<seed> vendor/bin/pest --filter='<test name>'` replays it. See
| `Tests\Fixtures\Reliability\OutboxProbe`.
|
| ## Exclusivity, and what SQLite can prove
|
| `FOR UPDATE SKIP LOCKED` compiles away on SQLite, so every exclusivity assertion here is
| made against the mechanism that works on both engines — the claim **lease**: no row is
| delivered twice inside one pass even while a re-entrant relay runs from inside a delivery,
| and a row a dead worker is holding is claimed by nobody. The lock clause itself is
| asserted only where the engine has it, gated on the driver, with the reason stated at the
| assertion.
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Every outbox row as it stands now, keyed by id — the snapshot the per-pass oracle
 * compares against, and the query that proves nothing was deleted.
 *
 * @return array<int, OutboxMessage>
 */
function outboxRowsById(): array
{
    $rows = [];

    foreach (OutboxMessage::query()->orderBy('id')->get() as $row) {
        $rows[(int) $row->getKey()] = $row;
    }

    return $rows;
}

/**
 * The state-machine invariants every row must satisfy at every instant, whatever the
 * interleaving — asserted after every pass, for every row, delivered or not.
 *
 * @param  array<int, OutboxMessage>  $rows
 */
function assertOutboxRowsCoherent(
    array $rows,
    RecordingOutboxTransport $transport,
    int $maxAttempts,
    string $where,
): void {
    foreach ($rows as $id => $row) {
        $rowWhere = sprintf('%s, row %d [%s]', $where, $id, $row->dedup_key);

        expect($row->attempts)->toBeGreaterThanOrEqual(0, $rowWhere.': a negative attempt count.')
            // The claim predicate is `attempts < max_attempts`, so no row can ever be
            // attempted past its budget — parking sets it *to* the budget, never over it.
            ->and($row->attempts)->toBeLessThanOrEqual($maxAttempts, $rowWhere.': attempted past its budget.')
            // A null gate would fall outside the claim's range predicate and strand the row
            // for ever, which is a lost effect by another name.
            ->and($row->next_attempt_at->greaterThanOrEqualTo($row->available_at))->toBeTrue(
                $rowWhere.': the retry gate opened before the delivery intent.',
            );

        if ($row->status === OutboxStatus::Sent) {
            expect($row->sent_at)->not->toBeNull($rowWhere.': SENT with no delivery instant.')
                ->and($row->attempts)->toBeGreaterThanOrEqual(1, $rowWhere.': SENT without an attempt.')
                ->and($row->last_error)->toBeNull($rowWhere.': SENT with a failure still recorded.')
                // The clause that makes the whole property non-vacuous: a row is `SENT`
                // only because a receiver acked it, so a `SENT` row whose effect was never
                // applied means the relay recorded delivery it had not achieved.
                ->and($transport->applied($row->dedup_key))->toBe(
                    1,
                    $rowWhere.': marked SENT, but the consumer never applied the effect.',
                );

            continue;
        }

        expect($row->sent_at)->toBeNull($rowWhere.': not SENT, yet carrying a delivery instant.')
            ->and($row->status->isClaimable())->toBeTrue(
                $rowWhere.': neither delivered nor claimable — the row has left the queue.',
            );

        if ($row->status === OutboxStatus::Failed) {
            expect($row->attempts)->toBeGreaterThanOrEqual(1, $rowWhere.': FAILED without an attempt.')
                ->and($row->last_error)->not->toBeNull($rowWhere.': FAILED with no reason recorded.');
        }
    }
}

it('applies an acked effect exactly once and loses no row across random crash and retry interleavings', function (): void {
    $probe = OutboxProbe::seeded();

    // Frozen and advanced by hand: full jitter draws from the whole window *including 0*,
    // so on a live clock "the gate is where the policy put it" is a race against the
    // microseconds the assertions themselves take.
    Carbon::setTestNow('2025-06-14 12:00:00');

    // Platform mode: a tenant-less effect is a legal row on this table, and the workload
    // deliberately contains some.
    app(TenantContext::class)->forget();

    $maxAttempts = $probe->int(3, 6);
    $lease = $probe->int(30, 120);

    config()->set('wa.reliability.outbox.max_attempts', $maxAttempts);
    config()->set('wa.reliability.outbox.lease_seconds', $lease);

    /*
    | The oracle's budgets, read from **config** rather than from `RetryPolicy` — an oracle
    | that asked the implementation what it was going to do would only prove it is
    | self-consistent. The classification each fault gets is
    | `PlatformErrorClassifier`'s documented mapping: `ConnectionException` and a `5xx` are
    | `NETWORK`, a `429` is `RATE_LIMIT`, and an unrecognised `RuntimeException` (both
    | crashes) falls through to `wa.reliability.retry.default_class`.
    */
    $attemptsFor = static fn (ErrorClass $class): int => (int) config(
        'wa.reliability.retry.classes.'.$class->value.'.attempts',
    );
    $crashBudget = $attemptsFor(ErrorClass::from((string) config('wa.reliability.retry.default_class')));

    $budgets = [
        OutboxProbe::CRASH_AFTER_ACK => $crashBudget,
        OutboxProbe::CRASH_BEFORE_ACK => $crashBudget,
        OutboxProbe::NETWORK => $attemptsFor(ErrorClass::Network),
        OutboxProbe::SERVER_ERROR => $attemptsFor(ErrorClass::Network),
        OutboxProbe::RATE_LIMITED => $attemptsFor(ErrorClass::RateLimit),
    ];

    // The claim queries the run really issued, for the driver-gated lock assertion at the end.
    $claimQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$claimQueries): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'select') && str_contains($sql, 'outbox') && str_contains($sql, 'next_attempt_at')) {
            $claimQueries[] = $sql;
        }
    });

    /*
    | The receiver, and the fault it suffers on each attempt.
    |
    | `RecordingOutboxTransport` is both the transport and the consumer on the other end of
    | it, which is what makes exactly-once observable: `applied($key)` is the *effect*, and
    | it dedups on `X-Dedup-Key` exactly as a real consumer must.
    */
    $transport = RecordingOutboxTransport::behaving(
        function (RecordingOutboxTransport $transport, OutboxDelivery $delivery) use ($probe): void {
            // A second worker, running *while* this row is still in flight — the window a
            // concurrent claimer would claim in.
            if ($probe->takeConcurrentWorker()) {
                $probe->recordConcurrentPass(app(Outbox::class)->relayBatch($probe->int(1, 4)));
            }

            $drawn = $probe->faultFor($delivery);
            $fault = $drawn['fault'];

            // The receiver applies the effect first, and only then does the worker die: a
            // crash *after* the ack is the interleaving Property 16 exists for.
            if ($fault === OutboxProbe::ACK || $fault === OutboxProbe::CRASH_AFTER_ACK) {
                $transport->accept($delivery);
            }

            match ($fault) {
                OutboxProbe::ACK => null,
                OutboxProbe::CRASH_AFTER_ACK => throw new RuntimeException('worker killed after the ack'),
                OutboxProbe::CRASH_BEFORE_ACK => throw new RuntimeException('worker killed before the ack'),
                OutboxProbe::NETWORK => throw new ConnectionException('connection reset by peer'),
                OutboxProbe::SERVER_ERROR => throw OutboxDeliveryException::rejected($delivery, 503),
                OutboxProbe::RATE_LIMITED => throw OutboxDeliveryException::rejected($delivery, 429, $drawn['wait']),
                OutboxProbe::CIRCUIT_OPEN => throw CircuitOpenException::open(
                    CircuitBreakerKey::for(CircuitScope::Provider, 'receiver'),
                    $drawn['wait'],
                ),
                default => throw new LogicException('Unhandled fault ['.$fault.'].'),
            };
        }
    );

    $outbox = Outboxes::relayingWith($transport);

    /** @var non-empty-list<string|null> $tenantIds */
    $tenantIds = [null];

    foreach (range(1, $probe->int(1, 3)) as $ignored) {
        $tenantIds[] = Tenant::factory()->create()->id;
    }

    $enqueued = $probe->workload($outbox, $probe->int(6, 14), $tenantIds);
    $total = count($enqueued);

    expect(OutboxMessage::query()->count())->toBe($total);

    $passes = $probe->int(4, 9);
    $faultFreeFrom = $probe->int(2, $passes);
    $ghostRows = 0;

    /*
    | Rows whose most recent event is a claim by a worker that then died, as id => true.
    |
    | The distinction matters at the end: a row whose last *attempt* failed inside the relay
    | is parked by `park()` and says so, whereas a row whose last attempt was counted by a
    | claim that never came back has the same spent budget with the previous failure still in
    | `last_error`. Both are held out of the claim, both are kept, both requeue — but only the
    | first can be required to say "Parked", and asserting otherwise would be asserting
    | something the relay never promised.
    |
    | @var array<int, true>
    */
    $heldToDeath = [];

    /*
    | Rows that have failed an attempt *inside* the relay, as id => true — i.e. the rows
    | that own a `last_error` the relay is not entitled to overwrite. Together with
    | `$heldToDeath` this is exactly the set the end-state oracle excuses from the "Parked"
    | wording, and nothing wider: a row that never failed in the relay has no diagnosis to
    | preserve, so it must say why it is parked whatever spent its budget.
    |
    | @var array<int, true>
    */
    $everFailed = [];

    foreach (range(1, $passes) as $pass) {
        if ($pass >= $faultFreeFrom) {
            // Faults off from a drawn pass onwards — the opening schedule still finishes, so
            // switching them off early cannot cost the menu's coverage.
            $probe->stopFaults();
        }

        Carbon::setTestNow(Carbon::now()->addSeconds($probe->pick([1, 5, 31, 90, 301, 600])));

        $before = outboxRowsById();
        $appliedBefore = [];

        foreach ($enqueued as $seeded) {
            $appliedBefore[$seeded['key']] = $transport->applied($seeded['key']);
        }

        /*
        | A worker that dies between the claim and the delivery: it spends the attempt, takes
        | the lease, and delivers nothing. Pass one always has one, by construction rather
        | than by draw, so this interleaving is covered on every run.
        */
        $ghost = $pass === 1 || $probe->chance(30)
            ? $probe->ghostClaim($probe->int(1, 3), $lease, $maxAttempts)
            : [];
        $ghostRows += count($ghost);
        $ghostLease = Carbon::now()->addSeconds($lease);

        if ($pass === 1 || $probe->chance(35)) {
            $probe->armConcurrentWorker();
        }

        $batch = $probe->int(1, $total);
        $probe->openPass();
        $report = $outbox->relayBatch($batch);

        $journal = $probe->passJournal();

        foreach ($ghost as $held) {
            $heldToDeath[$held] = true;
        }

        foreach ($journal as $entry) {
            unset($heldToDeath[$entry['id']]);
        }

        $where = sprintf(
            'seed %d, pass %d/%d (batch %d, max_attempts %d, lease %ds, %d row(s) held by a dead worker)',
            $probe->seed,
            $pass,
            $passes,
            $batch,
            $maxAttempts,
            $lease,
            count($ghost),
        );

        /*
        |----------------------------------------------------------------------
        | 1. Bookkeeping: every claimed row is accounted for, exactly once
        |----------------------------------------------------------------------
        */
        expect($report->isBalanced())->toBeTrue(sprintf(
            '%s: a claimed row left the pass unaccounted for — %s.',
            $where,
            (string) json_encode($report->toArray()),
        ));

        $innerClaimed = 0;
        $innerAbandoned = 0;

        foreach ($probe->passConcurrentReports() as $inner) {
            expect($inner->isBalanced())->toBeTrue($where.': the concurrent pass lost track of a claimed row.');
            $innerClaimed += $inner->claimed();
            $innerAbandoned += $inner->abandoned();
        }

        // One attempt per claimed row, and no more: nothing was claimed and then skipped.
        expect(count($journal))->toBe(
            $report->claimed() + $innerClaimed,
            $where.': claimed rows and delivery attempts disagree.',
        );

        $attempted = array_map(static fn (array $entry): int => $entry['id'], $journal);

        // The lease, asserted on the mechanism that also holds on SQLite: even with a
        // re-entrant relay running from inside a delivery, no row is attempted twice in one
        // pass — and a row the dead worker is holding is claimed by nobody.
        expect(count(array_unique($attempted)))->toBe(
            count($attempted),
            $where.': a row was delivered twice inside one pass — the claim lease did not hold.',
        );

        foreach ($ghost as $held) {
            expect(in_array($held, $attempted, true))->toBeFalse(sprintf(
                '%s: row %d is leased by a dead worker and was claimed anyway.',
                $where,
                $held,
            ));
        }

        /*
        |----------------------------------------------------------------------
        | 2. Req 31.4: the row set is conserved, whatever happened
        |----------------------------------------------------------------------
        */
        $after = outboxRowsById();

        expect(array_keys($after))->toBe(array_keys($before), $where.': the outbox lost or grew a row.')
            ->and(count($after))->toBe($total, $where.': the outbox no longer holds every enqueued effect.');

        /*
        |----------------------------------------------------------------------
        | 3. Per row: exactly what the drawn fault licensed, and nothing else
        |----------------------------------------------------------------------
        */
        $entries = [];

        foreach ($journal as $entry) {
            $entries[$entry['id']] = $entry;
        }

        // Rows this pass parked without claiming them, for the bookkeeping check below.
        $reaped = [];

        foreach ($before as $id => $was) {
            $row = $after[$id];
            $entry = $entries[$id] ?? null;
            $held = in_array($id, $ghost, true);
            $rowWhere = sprintf(
                '%s, row %d [%s] was %s/%d attempt(s)',
                $where,
                $id,
                $was->dedup_key,
                $was->status->value,
                $was->attempts,
            );

            if ($entry === null && ! $held) {
                /*
                | Abandoned, not merely unclaimed: `PENDING` with its whole budget spent and
                | its lease expired, which only a run of claims that reported nothing can
                | produce. No claim will ever take it again, so the relay parks it on sight
                | (`DatabaseOutbox::reapAbandoned()`) — and parking a row it did not attempt
                | is the *only* change a pass may make to one.
                */
                $abandoned = $was->status === OutboxStatus::Pending
                    && $was->attempts >= $maxAttempts
                    && ! $was->next_attempt_at->isFuture();

                if ($abandoned && $row->status === OutboxStatus::Failed) {
                    expect($row->attempts)->toBe(
                        $was->attempts,
                        $rowWhere.': parking an abandoned row spent an attempt it never made.',
                    )
                        ->and($row->sent_at)->toBeNull($rowWhere.': a parked row recorded a delivery instant.')
                        ->and($row->last_error)->toContain('Parked')
                        ->and($row->isClaimable())->toBeFalse($rowWhere.': a parked row is still claimable.')
                        ->and($transport->applied($was->dedup_key))->toBe(
                            $appliedBefore[$was->dedup_key],
                            $rowWhere.': parking a row applied its effect.',
                        );

                    $reaped[] = $id;

                    continue;
                }

                // Never claimed: untouched. A relay that quietly re-gated or re-statused a
                // row it did not attempt would be losing track of somebody's effect. An
                // abandoned row the batch limit did not reach lands here too — leaving it for
                // the next pass is the one other thing the relay may do with it.
                expect($row->status)->toBe($was->status, $rowWhere.': an unclaimed row changed status.')
                    ->and($row->attempts)->toBe($was->attempts, $rowWhere.': an unclaimed row spent an attempt.')
                    ->and($row->next_attempt_at->equalTo($was->next_attempt_at))->toBeTrue(
                        $rowWhere.': an unclaimed row moved its retry gate.',
                    );

                continue;
            }

            if ($entry === null) {
                // The dead worker's rows. The attempt is counted *before* it is made, which
                // is what stops a row that kills its worker from being retried for ever; the
                // status is untouched, because nothing was attempted; and the lease is what
                // hides it until it is safe to try again.
                expect($row->attempts)->toBe(
                    $was->attempts + 1,
                    $rowWhere.': a claim that died mid-batch did not spend its attempt.',
                )
                    ->and($row->status)->toBe($was->status, $rowWhere.': an undelivered claim changed status.')
                    ->and($row->next_attempt_at->equalTo($ghostLease))->toBeTrue(
                        $rowWhere.': a dead worker left no lease on the row.',
                    )
                    ->and($transport->applied($was->dedup_key))->toBe(
                        $appliedBefore[$was->dedup_key],
                        $rowWhere.': an effect was applied by a worker that never delivered it.',
                    );

                continue;
            }

            $fault = $entry['fault'];
            $attempt = $was->attempts + 1;
            $rowWhere .= sprintf(', fault [%s] on attempt %d', $fault, $attempt);

            // The attempt number the consumer is told is the attempt actually being made —
            // `attempts` is incremented at claim time precisely so this cannot drift.
            expect($entry['attempt'])->toBe($attempt, $rowWhere.': the delivery announced the wrong attempt.');

            if ($fault === OutboxProbe::CIRCUIT_OPEN) {
                // A call that was never made is not a failed attempt: the budget comes back,
                // the status is left exactly as it was, and the row waits out the breaker's
                // own cool-down.
                expect($row->attempts)->toBe($was->attempts, $rowWhere.': a shed call consumed the attempt budget.')
                    ->and($row->status)->toBe($was->status, $rowWhere.': a shed call changed the row status.')
                    ->and($row->next_attempt_at->equalTo(Carbon::now()->addSeconds($entry['wait'])))->toBeTrue(
                        $rowWhere.': a shed row is not waiting out the cool-down the breaker named.',
                    )
                    ->and($transport->applied($was->dedup_key))->toBe(
                        $appliedBefore[$was->dedup_key],
                        $rowWhere.': an effect was applied by a call that was never made.',
                    );

                continue;
            }

            if ($fault === OutboxProbe::ACK) {
                expect($row->status)->toBe(OutboxStatus::Sent, $rowWhere.': an acked effect was not recorded SENT.')
                    ->and($row->attempts)->toBe($attempt, $rowWhere.': the delivered row miscounted its attempts.')
                    ->and($row->sent_at)->not->toBeNull($rowWhere.': SENT with no delivery instant.')
                    ->and($row->last_error)->toBeNull($rowWhere.': a delivered row kept a stale failure.')
                    ->and($transport->applied($was->dedup_key))->toBe(
                        1,
                        $rowWhere.': the receiver acked and the effect was not applied exactly once.',
                    );

                continue;
            }

            /*
            | A failure. The budget was already spent at claim time, so the decision is
            | about the attempt that just failed: rescheduled while both budgets allow
            | another one, parked when either is gone — and parked means *kept*, since
            | `OutboxStatus` has no DEAD state to lose it into.
            */
            expect($transport->applied($was->dedup_key))->toBe(
                $fault === OutboxProbe::CRASH_AFTER_ACK ? 1 : $appliedBefore[$was->dedup_key],
                $rowWhere.': the applied-effect count is wrong for this failure.',
            );

            $keepsGoing = $attempt < $budgets[$fault] && $attempt < $maxAttempts;

            // This row has now failed *inside* the relay, so it has a diagnosis of its own
            // that the relay must never overwrite — which is what the end-state oracle's one
            // exemption is about.
            $everFailed[$id] = true;

            expect($row->status)->toBe(OutboxStatus::Failed, $rowWhere.': a failed attempt left the row unmarked.')
                ->and($row->sent_at)->toBeNull($rowWhere.': a failed attempt recorded a delivery instant.')
                ->and($row->last_error)->not->toBeNull($rowWhere.': a failed attempt recorded no reason.');

            if (! $keepsGoing) {
                // Parking burns whatever is left of the budget: that spent budget, not a
                // status, is what holds the row out of the claim — so an operator who resets
                // one column by hand cannot re-arm the effect by accident.
                expect($row->attempts)->toBe($maxAttempts, $rowWhere.': a parked row kept an unspent budget.')
                    ->and($row->last_error)->toContain('Parked')
                    ->and($row->isClaimable())->toBeFalse($rowWhere.': a parked row is still claimable.');

                continue;
            }

            expect($row->attempts)->toBe($attempt, $rowWhere.': the retried row miscounted its attempts.');

            $fault === OutboxProbe::RATE_LIMITED
                // A wait the receiver named is honoured verbatim: jittering a 429's
                // `Retry-After` down only guarantees the next attempt is refused too.
                ? expect($row->next_attempt_at->equalTo(Carbon::now()->addSeconds($entry['wait'])))->toBeTrue(
                    $rowWhere.': the 429 Retry-After was not honoured verbatim.',
                )
                // Everything else waits on `RetryPolicy`'s full-jitter window, drawn from
                // [0, cap] — so the claim is that the gate is inside it, never that it is in
                // the future.
                : expect($row->next_attempt_at->betweenIncluded(Carbon::now(), Carbon::now()->addSeconds(31)))
                    ->toBeTrue($rowWhere.': the backoff gate is outside the policy window.');
        }

        // The other half of the bookkeeping: a pass reports every row it parked without
        // claiming, and parks none it did not report. `abandoned` is deliberately outside
        // `isBalanced()` — those rows were never claimed — so this is what keeps it honest.
        expect(count($reaped))->toBe(
            $report->abandoned() + $innerAbandoned,
            sprintf(
                '%s: the pass parked %d abandoned row(s) and reported %d — %s.',
                $where,
                count($reaped),
                $report->abandoned() + $innerAbandoned,
                (string) json_encode($report->toArray()),
            ),
        );

        /*
        |----------------------------------------------------------------------
        | 4. Property 16 itself, after every pass
        |----------------------------------------------------------------------
        */
        $acked = $probe->ackedKeys();

        foreach ($enqueued as $seeded) {
            expect($transport->applied($seeded['key']))->toBe(
                isset($acked[$seeded['key']]) ? 1 : 0,
                sprintf(
                    '%s: effect [%s] was applied %d time(s); the receiver %s acked it.',
                    $where,
                    $seeded['key'],
                    $transport->applied($seeded['key']),
                    isset($acked[$seeded['key']]) ? 'has' : 'has never',
                ),
            );

            expect($after[$seeded['id']]->tenant_id)->toBe(
                $seeded['tenant'],
                $where.': the effect was re-attributed to another tenant.',
            );
        }

        expect($transport->appliedCount())->toBe(count($acked), $where.': an effect was applied out of nowhere.');

        assertOutboxRowsCoherent($after, $transport, $maxAttempts, $where);
    }

    /*
    |--------------------------------------------------------------------------
    | Eventual progress: with faults off, the queue converges
    |--------------------------------------------------------------------------
    | Each drain pass advances past the lease and past the widest backoff any fault could
    | have set, so when a pass finds nothing to claim it is because every row is either
    | delivered or parked — not because something is still waiting on a clock.
    */
    $probe->stopFaults();
    $drained = 0;

    foreach (range(1, 40) as $drain) {
        Carbon::setTestNow(Carbon::now()->addSeconds($lease + 180));

        $probe->openPass();
        $report = $outbox->relayBatch();
        $drained++;

        foreach ($probe->passJournal() as $entry) {
            unset($heldToDeath[$entry['id']]);
        }

        $where = sprintf('seed %d, drain pass %d', $probe->seed, $drain);
        $rows = outboxRowsById();
        $acked = $probe->ackedKeys();

        expect($report->isBalanced())->toBeTrue($where.': a claimed row left the pass unaccounted for.')
            ->and(count($rows))->toBe($total, $where.': the outbox lost a row while draining.');

        foreach ($enqueued as $seeded) {
            expect($transport->applied($seeded['key']))->toBe(
                isset($acked[$seeded['key']]) ? 1 : 0,
                $where.': effect ['.$seeded['key'].'] is no longer applied exactly once.',
            );
        }

        assertOutboxRowsCoherent($rows, $transport, $maxAttempts, $where);

        if ($report->isEmpty()) {
            break;
        }
    }

    $where = sprintf('seed %d, after %d drain pass(es)', $probe->seed, $drained);

    // Quiescent: faults are off and every gate a fault could have set has long expired, so a
    // further pass finding nothing is the statement that each remaining row is held out of
    // the claim by its **spent budget** — the parking mechanism, since `OutboxStatus` has no
    // DEAD state for the relay to drop work into.
    Carbon::setTestNow(Carbon::now()->addSeconds($lease + 180));

    expect($outbox->relayBatch()->isEmpty())->toBeTrue($where.': the queue never settled.');

    $parked = [];

    foreach (outboxRowsById() as $id => $row) {
        if ($row->status === OutboxStatus::Sent) {
            continue;
        }

        // Not delivered, so: parked. Kept, `FAILED`, budget spent, a reason on the row, and
        // waiting for an operator — never gone, and never still-quietly-waiting.
        expect($row->status)->toBe(OutboxStatus::Failed, $where.': a row is neither delivered nor parked.')
            ->and($row->attempts)->toBe($maxAttempts, $where.': an undelivered row kept an unspent budget.')
            ->and($row->last_error)->not->toBeNull($where.': an undelivered row records no reason at all.');

        /*
        | And it says so in the operator's words. Two routes reach that: a last attempt that
        | failed inside the relay is parked by `park()`, and a budget spent entirely by
        | claims that reported nothing is parked by `reapAbandoned()` on the next pass —
        | which is the row this file used to find still `PENDING`.
        |
        | Exactly one row cannot: one that *had* failed inside the relay and then had the
        | rest of its budget spent by claims that never came back. The attempt is counted
        | before it is made (which is what stops a row that kills its worker from being
        | retried for ever), so its `last_error` is the failure before them — and the relay
        | deliberately keeps that diagnosis rather than overwriting it with a generic
        | parking note. So the exemption needs *both* conditions, and a row that satisfies
        | only one of them is held to the full claim.
        */
        if (! isset($heldToDeath[$id]) || ! isset($everFailed[$id])) {
            expect($row->last_error)->toContain('Parked');
        }

        $parked[] = $id;
    }

    /*
    |--------------------------------------------------------------------------
    | Coverage of the menu, then the park/requeue boundary
    |--------------------------------------------------------------------------
    */
    foreach (OutboxProbe::FAULTS as $kind) {
        expect($probe->drew($kind))->toBeGreaterThanOrEqual(
            1,
            sprintf('seed %d: fault kind [%s] was never exercised.', $probe->seed, $kind),
        );
    }

    expect($ghostRows)->toBeGreaterThanOrEqual(1, sprintf('seed %d: no worker ever died mid-batch.', $probe->seed))
        ->and($probe->concurrentPasses())->toBeGreaterThanOrEqual(
            1,
            sprintf('seed %d: no concurrent relay pass ever ran.', $probe->seed),
        )
        ->and(count($probe->journal()))->toBeGreaterThanOrEqual(
            $total,
            sprintf('seed %d: fewer attempts than rows — the workload was never really relayed.', $probe->seed),
        );

    // From here the receiver acks everything: the operator half of "never lose the row" is
    // a fresh budget and the *same* dedup key, so a consumer that already applied the effect
    // before the row parked must not apply it again.
    $probe->ackEverything();

    foreach ($parked as $id) {
        expect($outbox->requeue($id))->toBeTrue($where.': a parked row refused to be requeued.');
    }

    foreach (range(1, 20) as $drain) {
        Carbon::setTestNow(Carbon::now()->addSeconds($lease + 180));

        $report = $outbox->relayBatch();
        $rows = outboxRowsById();

        expect($report->isBalanced())->toBeTrue($where.': a requeued row left the pass unaccounted for.')
            ->and(count($rows))->toBe($total, $where.': the outbox lost a requeued row.');

        assertOutboxRowsCoherent($rows, $transport, $maxAttempts, $where.', requeue drain '.$drain);

        if ($report->isEmpty()) {
            break;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The end state — asserted positively, so none of the above can be vacuous
    |--------------------------------------------------------------------------
    */
    $rows = outboxRowsById();

    expect(count($rows))->toBe($total, $where.': the run ended with a different number of rows than it enqueued.')
        ->and($transport->appliedCount())->toBe($total, $where.': not every effect was applied exactly once.')
        ->and($transport->deliveryCount())->toBeGreaterThanOrEqual(
            $total,
            $where.': fewer deliveries than rows — delivery is at least once.',
        );

    foreach ($enqueued as $seeded) {
        $row = $rows[$seeded['id']];

        expect($row->status)->toBe(
            OutboxStatus::Sent,
            sprintf('%s: row %d never reached SENT even with the receiver acking.', $where, $seeded['id']),
        )
            ->and($transport->applied($seeded['key']))->toBe(
                1,
                sprintf(
                    '%s: effect [%s] was applied %d time(s) across %d deliveries.',
                    $where,
                    $seeded['key'],
                    $transport->applied($seeded['key']),
                    $transport->deliveryCount($seeded['key']),
                ),
            );
    }

    /*
    |--------------------------------------------------------------------------
    | The row lock, where the engine has one
    |--------------------------------------------------------------------------
    | Exclusivity is asserted above against the lease, which holds on both engines. The
    | lock is what MySQL adds on top: it makes a competing worker *skip* a held row rather
    | than wait for it. SQLite's grammar compiles every lock clause to the empty string and
    | serializes writers instead, so asserting its presence there would be asserting
    | nothing — the driver gate says which claim is being made rather than pretending this
    | run proved the other one.
    */
    expect($claimQueries)->not->toBeEmpty(sprintf('seed %d: no claim query was observed at all.', $probe->seed));

    foreach ($claimQueries as $claim) {
        DB::connection()->getDriverName() === 'mysql'
            ? expect($claim)->toContain(OutboxMessage::CLAIM_LOCK)
            : expect($claim)->not->toContain('for update');
    }
});

it('delivers every row once and applies every effect when nothing fails', function (): void {
    /*
    | The control for the property above, and the reason it cannot pass vacuously: an
    | assertion that only ever said "at most one effect" would hold for a transport that
    | delivered nothing at all. So a fault-free workload has to reach `SENT` for every row,
    | apply every effect exactly once, and — because nothing failed — be delivered exactly
    | once, with no redelivery anywhere.
    */
    $probe = OutboxProbe::seeded();

    Carbon::setTestNow('2025-06-14 12:00:00');
    app(TenantContext::class)->forget();

    $transport = RecordingOutboxTransport::acking();
    $outbox = Outboxes::relayingWith($transport);

    /** @var non-empty-list<string|null> $tenantIds */
    $tenantIds = [null, Tenant::factory()->create()->id];

    $enqueued = $probe->workload($outbox, $probe->int(6, 14), $tenantIds);
    $total = count($enqueued);
    $where = sprintf('seed %d, fault-free workload of %d effect(s)', $probe->seed, $total);

    foreach (range(1, 6) as $pass) {
        Carbon::setTestNow(Carbon::now()->addSeconds(120));

        $report = $outbox->relayBatch($probe->int(1, $total));

        expect($report->isBalanced())->toBeTrue($where.': a claimed row left the pass unaccounted for.')
            ->and($report->retrying())->toBe(0, $where.': nothing failed, yet a row was rescheduled.')
            ->and($report->parked())->toBe(0, $where.': nothing failed, yet a row was parked.')
            ->and($report->shed())->toBe(0, $where.': nothing failed, yet a call was shed.');

        if ($report->isEmpty()) {
            break;
        }
    }

    $rows = outboxRowsById();

    expect(count($rows))->toBe($total, $where.': the outbox lost a row.')
        // Exactly one delivery per row: at-least-once only ever becomes more-than-once
        // because something failed.
        ->and($transport->deliveryCount())->toBe($total, $where.': a row was delivered more than once.')
        ->and($transport->appliedCount())->toBe($total, $where.': not every effect was applied.');

    foreach ($enqueued as $seeded) {
        $row = $rows[$seeded['id']];

        expect($row->status)->toBe(OutboxStatus::Sent, $where.': row '.$seeded['id'].' was never delivered.')
            ->and($row->attempts)->toBe(1, $where.': row '.$seeded['id'].' took more than one attempt.')
            ->and($row->tenant_id)->toBe($seeded['tenant'], $where.': the effect changed tenant.')
            ->and($transport->applied($seeded['key']))->toBe(1, $where.': effect ['.$seeded['key'].'] was not applied once.');
    }

    assertOutboxRowsCoherent($rows, $transport, (int) config('wa.reliability.outbox.max_attempts'), $where);
});
