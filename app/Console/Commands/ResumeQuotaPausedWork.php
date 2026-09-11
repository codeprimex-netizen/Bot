<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\QuotaParkingLot;
use App\Services\Tenancy\QuotaResumeReport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * The period-reset sweep: hand `QUOTA_PAUSED` work back once its plan allowance returns
 * (Req 3.4 / A3; Req 20.3 / C3; Req 31.1 / NFR2).
 *
 * ```
 * php artisan wa:quota:resume-paused              # what the scheduler runs, every minute
 * php artisan wa:quota:resume-paused --tenant=01H…  # one tenant, after a manual top-up
 * php artisan wa:quota:resume-paused --limit=25    # a smaller bite, for a busy box
 * php artisan wa:quota:resume-paused --no-prune    # sweep only, keep finished holds
 * ```
 *
 * Registered on the scheduler in `routes/console.php` (the platform runs a single cron →
 * `schedule:run`, task 39.2), so this file is *not* where the cadence lives.
 *
 * ## Why running it every minute is safe
 *
 * Nothing here re-derives when a period rolls; the timing seams already exist and this
 * command only *uses* them:
 *
 * - `QuotaGuard::secondsUntilPeriodReset()` set each hold's `resume_at` when it was parked,
 *   in the **tenant's** timezone — so a daily allowance rolls at the tenant's local
 *   midnight, not at UTC midnight;
 * - `QuotaGuard::verdict()` is asked afresh for every claimed hold, so the decision is
 *   about the allowance, not about the clock;
 * - `QuotaReason::isTransient()` keeps non-transient refusals parked: a downgrade is never
 *   auto-resumed.
 *
 * A tick with nothing due therefore costs one indexed query
 * (`idx(quota_holds.status, resume_at)`) and writes nothing. A tick with work due claims
 * each hold with a conditional `UPDATE`, so two workers — or an overlapping run, or two
 * app servers — cannot resume the same work twice; see `QuotaParkingLot::claim()`. The
 * schedule adds `withoutOverlapping()` and `onOneServer()` on top, which are an
 * optimisation rather than the guarantee.
 *
 * Every run also prunes finished holds past `wa.tenancy.quota.holds.retention_days`, which
 * keeps the table proportional to *paused* work rather than to all work ever paused. Open
 * holds are never pruned — that would be the drop Req 31.1 forbids.
 */
final class ResumeQuotaPausedWork extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wa:quota:resume-paused
                            {--tenant= : Only sweep this tenant (id or slug) — e.g. after a manual top-up}
                            {--limit= : Holds to consider in this run (default wa.tenancy.quota.holds.batch)}
                            {--no-prune : Skip the retention pass over finished holds}';

    /**
     * @var string
     */
    protected $description = 'Resume QUOTA_PAUSED work whose plan allowance has returned (period reset, upgrade, or top-up)';

    public function handle(QuotaParkingLot $parkingLot): int
    {
        $limit = $this->limit();

        if ($limit === false) {
            $this->components->error('--limit must be a positive integer.');

            return self::INVALID;
        }

        $tenant = $this->tenantOption();

        if ($tenant === false) {
            return self::INVALID;
        }

        $report = $tenant === null
            ? $parkingLot->resumeDue($limit)
            : $parkingLot->allowanceChanged($tenant, limit: $limit);

        if ($this->option('no-prune') !== true) {
            $report->recordPruned($parkingLot->prune());
        }

        $this->report($report);

        return self::SUCCESS;
    }

    /**
     * Print only when something happened: this runs 1 440 times a day, and a scheduler log
     * full of "nothing to do" is a log nobody reads.
     */
    private function report(QuotaResumeReport $report): void
    {
        if ($report->isEmpty()) {
            $this->info('No quota-paused work is due.');

            return;
        }

        foreach ($report->toArray() as $label => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %-15s %d', str_replace('_', ' ', $label), $count));
            }
        }

        if ($report->failed() > 0) {
            // Still exit 0: a failed hand-back is retried, the work is not lost, and a
            // non-zero exit would make the scheduler shout about a self-healing condition.
            $this->components->warn(sprintf(
                '%d hold(s) could not be handed back and stay paused — see quota_holds.last_error.',
                $report->failed(),
            ));
        }
    }

    /**
     * @return int|null|false the parsed limit, null for the configured default, false when invalid
     */
    private function limit(): int|null|false
    {
        $option = $this->option('limit');

        if ($option === null) {
            return null;
        }

        if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
            return false;
        }

        return (int) $option;
    }

    /**
     * @return Tenant|null|false the tenant, null when the option is absent, false when it
     *                           names nothing
     */
    private function tenantOption(): Tenant|null|false
    {
        $option = $this->option('tenant');

        if ($option === null) {
            return null;
        }

        if (! is_string($option) || trim($option) === '') {
            $this->components->error('--tenant needs a tenant id or slug.');

            return false;
        }

        $needle = trim($option);

        // `tenants` is the root of the ownership tree and is not itself tenant-scoped, so a
        // plain query is correct here: a console operator is not acting as a tenant.
        $tenant = Tenant::query()
            ->where(function (Builder $match) use ($needle): void {
                $match->where('id', $needle)->orWhere('slug', $needle);
            })
            ->first();

        if (! $tenant instanceof Tenant) {
            $this->components->error(sprintf('No tenant matches "%s".', $needle));

            return false;
        }

        return $tenant;
    }
}
