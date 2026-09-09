<?php

declare(strict_types=1);

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
