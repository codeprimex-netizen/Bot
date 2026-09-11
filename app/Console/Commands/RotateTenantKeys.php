<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\KeyPurpose;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\Tenant;
use App\Services\Security\DekRotationReport;
use App\Services\Security\DekRotator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rotate per-tenant Data Encryption Keys on schedule (Req 32.6 / NFR3; design § Key
 * rotation).
 *
 * ```
 * php artisan wa:security:rotate-deks                        # what the scheduler runs, daily
 * php artisan wa:security:rotate-deks --dry-run              # how many lineages are due
 * php artisan wa:security:rotate-deks --tenant=01H… --force  # rotate one tenant now (incident response)
 * php artisan wa:security:rotate-deks --purpose=EXPORT       # one purpose only
 * php artisan wa:security:rotate-deks --limit=25             # a smaller bite
 * ```
 *
 * Registered on the scheduler in `routes/console.php` (the platform runs a single cron →
 * `schedule:run`, task 39.2), so this file is *not* where the cadence lives.
 *
 * ## Why running it daily is safe, and why it is not destructive
 *
 * A lineage is due when its `ACTIVE` version is older than
 * `wa.security.encryption.rotation.dek_after_days`, so a tenant rotated yesterday is not
 * due today and a tick with nothing due is one indexed query and no writes.
 *
 * Rotation *adds* a version and demotes the previous one to `RETIRING`:
 *
 * ```
 *   before:  v3 ACTIVE                    every stored value decrypts
 *   after:   v4 ACTIVE, v3 RETIRING       every stored value still decrypts
 * ```
 *
 * New writes use v4; values written under v1–v3 keep naming their own version, and a
 * `RETIRING` version still decrypts. Nothing is re-encrypted eagerly and **nothing is ever
 * retired here** — a `RETIRED` version refuses to decrypt, so retiring one that is still
 * referenced would be silent data loss (see `DekRotator`).
 *
 * ## Failures are per tenant
 *
 * A tenant whose key store refuses a seal is counted and skipped, with its previous version
 * left `ACTIVE` — so that tenant keeps encrypting and decrypting exactly as before, and the
 * rest of the platform still rotates. The exit code stays 0 for the sweep (the rotation is
 * retried on the next tick and nothing is at risk); an explicit single-tenant rotation that
 * fails exits non-zero, because somebody is watching that one.
 */
final class RotateTenantKeys extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wa:security:rotate-deks
                            {--tenant= : Rotate this tenant only (id or slug)}
                            {--purpose= : Key purpose to rotate (FIELD, EXPORT, BACKUP; default FIELD for --tenant, all when sweeping)}
                            {--force : With --tenant, rotate even when the lineage is not due yet}
                            {--limit= : Lineages to rotate in this run (default wa.security.encryption.rotation.batch)}
                            {--dry-run : Report how many lineages are due without rotating}';

    /**
     * @var string
     */
    protected $description = 'Rotate per-tenant DEKs whose active version is older than the configured interval';

    public function handle(DekRotator $rotator): int
    {
        $limit = $this->positiveOption('limit');

        if ($limit === false) {
            $this->components->error('--limit must be a positive integer.');

            return self::INVALID;
        }

        $purpose = $this->purposeOption();

        if ($purpose === false) {
            return self::INVALID;
        }

        $tenant = $this->tenantOption();

        if ($tenant === false) {
            return self::INVALID;
        }

        if ($this->option('dry-run') === true) {
            $this->line(sprintf('  %-12s %d', 'due', $rotator->dueCount()));

            return self::SUCCESS;
        }

        return $tenant instanceof Tenant
            ? $this->rotateOne($rotator, $tenant, $purpose ?? KeyPurpose::Field)
            : $this->sweep($rotator, $limit);
    }

    /**
     * One named tenant — incident response, or an operator finishing a migration.
     */
    private function rotateOne(DekRotator $rotator, Tenant $tenant, KeyPurpose $purpose): int
    {
        if ($this->option('force') !== true && ! $rotator->isDue($tenant, $purpose)) {
            $this->components->warn(sprintf(
                'Tenant %s is not due for %s rotation yet. Pass --force to rotate anyway.',
                $tenant->id,
                $purpose->value,
            ));

            return self::SUCCESS;
        }

        try {
            $key = $rotator->rotate($tenant, $purpose);
        } catch (KeyUnavailableException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Tenant %s now encrypts %s values under version %d; earlier versions stay readable.',
            $tenant->id,
            $purpose->value,
            $key->version,
        ));

        return self::SUCCESS;
    }

    /**
     * The scheduled sweep over every due lineage.
     */
    private function sweep(DekRotator $rotator, ?int $limit): int
    {
        return $this->report($rotator->rotateDue($limit));
    }

    private function report(DekRotationReport $report): int
    {
        if ($report->isEmpty()) {
            $this->info('No data keys are due for rotation.');

            return self::SUCCESS;
        }

        $this->line(sprintf('  %-12s %d', 'rotated', $report->rotated()));

        if ($report->failed() > 0) {
            // Exit 0 on purpose: the lineage is untouched, the tenant is unaffected, and the
            // next tick retries. A non-zero exit here would make the scheduler shout about a
            // self-healing condition.
            $this->components->warn(sprintf(
                '%d lineage(s) could not be rotated and still use their previous version; the next run retries.',
                $report->failed(),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return KeyPurpose|null|false the purpose, null when absent, false when invalid
     */
    private function purposeOption(): KeyPurpose|null|false
    {
        $option = $this->option('purpose');

        if ($option === null) {
            return null;
        }

        $purpose = is_string($option) ? KeyPurpose::tryFrom(strtoupper(trim($option))) : null;

        if (! $purpose instanceof KeyPurpose) {
            $this->components->error(sprintf(
                '--purpose must be one of %s.',
                implode(', ', KeyPurpose::values()),
            ));

            return false;
        }

        return $purpose;
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

    /**
     * @return int|null|false the parsed option, null when absent, false when invalid
     */
    private function positiveOption(string $name): int|null|false
    {
        $option = $this->option($name);

        if ($option === null) {
            return null;
        }

        if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
            return false;
        }

        return (int) $option;
    }
}
