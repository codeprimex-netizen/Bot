<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantDomain;
use App\Services\Domains\DomainVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Re-validates verified custom domains, revoking the ones whose proof has stopped
 * holding (Req 9.7 / A9).
 *
 * ```
 * php artisan wa:domains:recheck                 # what the scheduler runs, hourly
 * php artisan wa:domains:recheck --batch=5       # a smaller bite
 * php artisan wa:domains:recheck --host=chat.acme.example   # one domain, now
 * ```
 *
 * Registered on the scheduler in `routes/console.php` (the platform runs a single cron →
 * `schedule:run`, task 39.2), so this file is not where the cadence lives.
 *
 * ## Why re-checking is not optional
 *
 * A verification is a statement about the world at one instant, and the world moves: a
 * certificate expires, a DNS record is tidied away, a domain is sold. The dangerous case
 * is the last one — a name that leaves the tenant and is pointed back at the platform
 * would keep routing as that tenant, so its new owner would receive somebody else's
 * traffic. `DomainVerifier::verify()` re-runs the *ownership challenge* as well as the TLS
 * check (the challenge is standing rather than consumed, precisely so this is possible),
 * so a domain that is no longer the tenant's fails and is revoked — and revocation lands
 * on the next request, because both host caches hang off the model's `saved` hook.
 *
 * ## Why it is cheap enough to run hourly
 *
 * Three bounds, and it does nothing at all most of the time:
 *
 *  - **stale-only** — `TenantDomain::scopeDueForRecheck()` selects verified rows whose
 *    `last_checked_at` is null or older than `wa.tenancy.domains.recheck.interval_hours`
 *    (default 24), served by `idx(verified_at, last_checked_at)`. With a daily interval and
 *    an hourly tick, ~1/24 of domains are due per run;
 *  - **batched** — at most `recheck.batch` (default 25) per run, stalest first. A platform
 *    with 10 000 domains does not try to probe them in one process; it works through them
 *    across ticks, and the ordering guarantees no domain is starved;
 *  - **guarded** — every probe goes through `GuardedDomainProbe`, so a broken resolver
 *    trips the breaker after the first couple of failures and the rest of the batch fails
 *    fast instead of paying a timeout each.
 *
 * ## Fail-closed, but not on our own outage
 *
 * A probe that cannot run (`DomainVerificationFailure::ProbeUnavailable`) leaves the
 * domain exactly as it was and is reported as `skipped`. Only *conclusive* evidence
 * against a domain revokes it — otherwise a resolver outage would unverify every custom
 * domain on the platform at once, and there is no worse moment to do that than while the
 * network is broken. The bound is the same either way: a re-check can never *grant*
 * verification to a domain that did not already have it... except in the one honest case
 * where it can — a domain that was verified, and still satisfies both halves, stays
 * verified. Nothing here can verify an unverified claim, because nothing here selects one.
 */
final class RecheckTenantDomains extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wa:domains:recheck
                            {--batch= : Domains per run (default wa.tenancy.domains.recheck.batch)}
                            {--host= : Re-check exactly this host, ignoring staleness}
                            {--all : Re-check every verified domain, ignoring staleness (bounded by --batch)}';

    /**
     * @var string
     */
    protected $description = 'Re-validate verified custom domains and revoke those whose proof no longer holds';

    public function handle(DomainVerifier $verifier): int
    {
        $batch = $this->batch();

        if ($batch === false) {
            $this->components->error('--batch must be a positive integer.');

            return self::INVALID;
        }

        $domains = $this->due($batch);

        if ($domains === []) {
            return self::SUCCESS;
        }

        $checked = 0;
        $revoked = 0;
        $skipped = 0;

        foreach ($domains as $domain) {
            $result = $verifier->verify($domain);
            $checked++;

            if ($result->isInconclusive()) {
                $skipped++;

                continue;
            }

            if ($result->revoked) {
                $revoked++;

                $this->components->warn(sprintf(
                    'Revoked %s — %s. It is no longer used for that workspace\'s links or accepted as a host.',
                    $domain->host,
                    $result->failure->value,
                ));
            }
        }

        $this->line(sprintf('  %-16s %d', 'checked', $checked));

        if ($revoked > 0) {
            $this->line(sprintf('  %-16s %d', 'revoked', $revoked));
        }

        if ($skipped > 0) {
            // Worth saying out loud: a run that could not reach the network has changed
            // nothing, and a persistently rising count means the platform's egress or
            // resolver is the problem, not the tenants' domains.
            $this->components->warn(sprintf(
                '%d domain(s) could not be checked (the lookup itself failed). Their verification is '
                .'unchanged — that is deliberate, our outage is not evidence about their domains.',
                $skipped,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The domains this run will check.
     *
     * @return list<TenantDomain>
     */
    private function due(?int $batch): array
    {
        $limit = $batch ?? $this->configuredBatch();
        $host = $this->option('host');

        if (is_string($host) && trim($host) !== '') {
            /** @var list<TenantDomain> $named */
            $named = TenantDomain::query()
                ->verified()
                ->where('host', '=', strtolower(trim($host, ". \t")))
                ->get()
                ->all();

            return $named;
        }

        $query = $this->option('all') === true
            ? TenantDomain::query()->verified()->orderBy('host')
            : TenantDomain::query()->dueForRecheck(
                Carbon::now()->subHours($this->intervalHours()),
            );

        /** @var list<TenantDomain> $domains */
        $domains = $query->limit($limit)->get()->all();

        return $domains;
    }

    /**
     * @return int|null|false the parsed batch size, null for the configured default, false when invalid
     */
    private function batch(): int|null|false
    {
        $option = $this->option('batch');

        if ($option === null) {
            return null;
        }

        if (is_bool($option)) {
            // `--batch` with no value. It takes one, so this is a caller mistake, not a flag.
            return false;
        }

        // A string from the command line, an int from `Artisan::call(..., ['--batch' => 5])`
        // — both are accepted, and anything that is not a positive whole number is refused
        // rather than coerced: reading `--batch=abc` as 1 would make a typo look like a
        // working run.
        $value = (string) $option;

        if (! ctype_digit($value) || (int) $value < 1) {
            return false;
        }

        return (int) $value;
    }

    private function configuredBatch(): int
    {
        $batch = config('wa.tenancy.domains.recheck.batch');
        $batch = is_numeric($batch) ? (int) $batch : 25;

        return $batch > 0 ? $batch : 25;
    }

    private function intervalHours(): int
    {
        $hours = config('wa.tenancy.domains.recheck.interval_hours');
        $hours = is_numeric($hours) ? (int) $hours : 24;

        // A non-positive interval would make every verified domain due on every tick,
        // which is a self-inflicted probe storm rather than a configuration.
        return $hours > 0 ? $hours : 24;
    }
}
