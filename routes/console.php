<?php

declare(strict_types=1);

use App\Console\Commands\PruneIdempotencyKeys;
use App\Console\Commands\RecheckTenantDomains;
use App\Console\Commands\RelayOutbox;
use App\Console\Commands\ResumeQuotaPausedWork;
use App\Console\Commands\RotateMasterKey;
use App\Console\Commands\RotateSigningSecrets;
use App\Console\Commands\RotateTenantKeys;
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

/*
| Per-tenant DEKs are rotated daily (Req 32.6 / NFR3, task 4.2).
|
| Daily rather than every minute because the interval is measured in *months*
| (`wa.security.encryption.rotation.dek_after_days`, 90 by default): a lineage that becomes
| due at 03:00 is no more overdue at 03:01 than at the next night's run, and every rotation
| is a key-store round trip. A tick with nothing due is one indexed query
| (`encryption_keys.status` + `created_at`) and no writes.
|
| The safety of running it unattended is in the rotation, not here, and it is structural: a
| rotation *adds* a version and demotes the previous one to `RETIRING`, which still
| decrypts. Nothing stored becomes unreadable, re-encryption stays lazy, and **no version is
| ever retired** by any scheduled job — `RETIRED` refuses to decrypt, so retiring one that is
| still referenced would be silent data loss (see `App\Services\Security\DekRotator`).
|
| A tenant whose key store refuses a seal keeps its previous version `ACTIVE` and is retried
| tomorrow, so one failure cannot stop the sweep. `withoutOverlapping()` and `onOneServer()`
| are optimisations: two concurrent sweeps would collide on
| `uniq(tenant_id, purpose, active_flag)` and one would simply lose its insert.
*/
Schedule::command(RotateTenantKeys::class)
    ->dailyAt('03:10')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Rotate per-tenant DEKs older than the configured interval (old versions stay readable)');

/*
| The KMS master key is rotated monthly, and outstanding re-wraps are swept up hourly
| (Req 32.6 / NFR3, task 4.2).
|
| Two entries for one job, because the two halves have different costs. Minting a new master
| key version is one call and belongs on a slow, predictable cadence. Re-sealing every stored
| DEK and signing secret under it is two key-store calls *per row*, so it is batched — and an
| estate that does not finish in one pass has to be picked up again without waiting a month,
| or the previous master key can never be retired. Hence `--rewrap-only` hourly: it mints
| nothing, and when there is nothing outstanding it is one indexed query per store.
|
| Re-wrapping cannot orphan data, which is what makes this safe to schedule at all: only the
| *seal* changes, never the DEK, so every stored ciphertext keeps naming the same key version
| and keeps decrypting. Rows that cannot be opened are left byte-for-byte untouched and
| reported — the command exits non-zero for exactly that case, because removing the old
| master key while any remain would make the loss permanent.
*/
Schedule::command(RotateMasterKey::class)
    ->monthlyOn(1, '02:30')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->description('Rotate the KMS master key and re-wrap material sealed under the previous one');

Schedule::command(RotateMasterKey::class, ['--rewrap-only'])
    ->hourly()
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Finish outstanding master-key re-wraps so the previous key can be retired');

/*
| HMAC webhook secrets are rotated hourly (Req 32.6 / NFR3, task 4.2).
|
| Hourly, not daily, because this sweep does two things and the second is time-based: it
| rotates scopes past `wa.security.hmac.rotate_after_days`, and it purges secrets whose
| overlap window has closed. Neither is urgent, but both are cheap — one indexed query each
| when there is nothing to do — and an hourly cadence keeps closed windows from lingering as
| rows for most of a day.
|
| Rotation is deliberately invisible to the peer: the previous secret keeps **verifying** for
| `wa.security.hmac.overlap_hours` while only the new one **signs**, so requests already in
| flight still authenticate. The window is enforced by the clock in
| `SigningSecretStore::verify()`, not by this job — a purge that has not run therefore cannot
| widen a window, and a secret past its deadline is refused whether or not its row survives.
*/
Schedule::command(RotateSigningSecrets::class)
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->description('Rotate HMAC signing secrets with an overlap window and purge closed ones');

/*
| Verified tenant custom domains are re-validated hourly (Req 9.7 / A9, task 5.5).
|
| Hourly is the *tick*, not the cadence: each run takes only the verified domains whose
| evidence is older than `wa.tenancy.domains.recheck.interval_hours` (default 24), stalest
| first, capped at `recheck.batch` (default 25). So a platform with a handful of domains
| does almost nothing 23 hours out of 24, and a platform with thousands works through them
| across ticks without ever probing them all in one process. A tick with nothing due is one
| indexed query against `idx(verified_at, last_checked_at)` and no writes.
|
| Why re-check at all: a verification is a statement about one instant. The cheap failures
| are a lapsed certificate or a tidied-away DNS record; the dangerous one is a domain that
| changes hands and is pointed back at the platform, where a TLS-only check would still pass
| while the name is no longer the tenant's. `DomainVerifier::verify()` re-runs the ownership
| challenge as well, so that case is caught and the domain is revoked — and revocation lands
| on the next request, because both host caches hang off the model's save hook.
|
| A run that cannot reach the network changes **nothing**: an inconclusive probe leaves every
| domain exactly as it was (see `DomainVerificationFailure::isConclusive()`). That is what
| makes this safe to schedule — the failure mode of the sweep itself is "no effect", never
| "unverify the platform".
*/
Schedule::command(RecheckTenantDomains::class)
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->description('Re-validate verified custom domains and revoke those whose proof no longer holds');
