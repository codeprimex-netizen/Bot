<?php

declare(strict_types=1);

use App\Console\Commands\PruneIdempotencyKeys;
use App\Console\Commands\RelayOutbox;
use App\Console\Commands\ResumeQuotaPausedWork;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
| The platform runs a **single** cron entry — `* * * * * php artisan schedule:run`
| (task 39.2) — so everything periodic is declared here. Every entry must be safe to
| run every minute, safe to run concurrently with itself, and cheap when there is
| nothing to do.
*/

/*
| Quota-paused work is handed back once its plan allowance returns (Req 3.4 / A3;
| Req 20.3 / C3; Req 31.1 / NFR2).
|
| Every minute, because a period rolls at each tenant's *own* local midnight (and at
| their own month boundary): a coarser cadence would either delay some tenants by
| hours or need a second copy of the bucketing rules to decide when to run. A tick
| with nothing due is one indexed query and no writes.
|
| The safety of running it often is in the command, not here: each hold is claimed
| with a conditional UPDATE, so two workers can never hand the same work back twice
| (`QuotaParkingLot::claim()`). The two guards below are therefore optimisations —
| they stop redundant work, they are not what makes it correct:
|
|   - `withoutOverlapping()` — a sweep that outlives its minute is not joined by a
|     second one; the lock expires after 10 minutes so a killed worker cannot wedge
|     the schedule for ever.
|   - `onOneServer()` — one app server per tick, once the platform is more than one
|     box (both guards need a lock-capable cache store; the default `database` store
|     provides one via `cache_locks`).
*/
Schedule::command(ResumeQuotaPausedWork::class)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->description('Resume QUOTA_PAUSED work whose plan allowance has returned');

/*
| Expired side-effect dedup keys are pruned hourly (Req 31.2 / NFR2).
|
| Hourly rather than every minute because retention is measured in *days*
| (`wa.reliability.idempotency.retention_days`): an entry that becomes prunable at
| 09:00 is no cheaper to delete at 09:01 than at 10:00, and the table is one of the
| busiest on the platform — sweeping it 1 440 times a day would spend more on the
| indexed scan than the deletes save.
|
| Retention correctness is not this schedule's business, and cannot be: the command
| only ever deletes rows whose `expires_at` has passed, and **never** a row without
| one, because deleting a completed key silently re-arms the side effect it recorded.
| The horizon is chosen by whoever wrote the key.
|
| `withoutOverlapping()` and `onOneServer()` are optimisations, not the guarantee:
| deletes are batched and keyed by primary key, so two concurrent runs delete disjoint
| (or already-gone) rows regardless.
*/
Schedule::command(PruneIdempotencyKeys::class)
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->description('Prune expired idempotency keys (never a key with no expiry)');

/*
| The transactional outbox is relayed every minute (Req 31.4 / NFR2, Algorithm 6).
|
| Every minute because latency matters here: an outbox row *is* a webhook the receiver is
| waiting for, and the enqueuer has already committed the state change it describes. A tick
| with nothing due costs one indexed query against `outbox_claim_index` and writes nothing,
| so the cheap case is the common case.
|
| Concurrency safety is in the relay, not here, and both halves are needed: a claim takes
| its batch with `FOR UPDATE SKIP LOCKED` **and** leases each row by pushing
| `next_attempt_at` past the moment any other claimer would look, so two workers cannot
| deliver one row at the same time on either engine. If a lease ever did expire while its
| attempt was still running, the `X-Dedup-Key` header every delivery carries means the
| consumer applies the effect once anyway (Correctness Property 16) — the guarantee does not
| rest on the schedule.
|
| `withoutOverlapping()` and `onOneServer()` are therefore optimisations: they stop a slow
| pass from being joined by a second one and keep a multi-server install from claiming in
| lockstep. The 10-minute lock expiry means a killed worker cannot wedge the relay, and a
| backlog is drained on purpose with `--passes` rather than by a tick that runs unbounded.
*/
Schedule::command(RelayOutbox::class)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->description('Relay due transactional-outbox rows with their dedup key');
