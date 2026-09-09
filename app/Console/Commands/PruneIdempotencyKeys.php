<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use App\Services\Reliability\IdempotencyStore;
use Illuminate\Console\Command;

/**
 * Retention pass over the side-effect dedup ledger (Req 31.2 / NFR2).
 *
 * ```
 * php artisan wa:idempotency:prune            # what the scheduler runs, hourly
 * php artisan wa:idempotency:prune --batch=250 # smaller delete statements
 * php artisan wa:idempotency:prune --dry-run   # count what would go, delete nothing
 * ```
 *
 * Registered on the scheduler in `routes/console.php` (the platform runs a single cron →
 * `schedule:run`, task 39.2), so this file is *not* where the cadence lives.
 *
 * ## What is deleted, and what is never deleted
 *
 * Only `IdempotencyKey::scopePrunable()` rows: `expires_at` set, and already past. A row
 * with **no** `expires_at` is never deleted at any age, and that is the single most
 * important line in this command — deleting a completed key silently re-arms the side
 * effect it was recording, because the next retry of that work becomes indistinguishable
 * from a first attempt. A ledger that is too big is an operational problem; a ledger that
 * forgot a key is a payment captured twice.
 *
 * For the same reason the horizon is set by the *writer*, not here: each caller knows how
 * long its own retries can arrive for (`IdempotencyOptions::keptFor()`, defaulting to
 * `wa.reliability.idempotency.retention_days`). This command has no opinion beyond
 * honouring it.
 *
 * ## Why it is safe to run often, and concurrently
 *
 * Deletes are batched and keyed by primary key, so two overlapping runs simply delete
 * disjoint (or already-gone) rows — the second one's `DELETE … WHERE id IN (…)` reports
 * fewer rows and nothing breaks. A tick with nothing to do costs one indexed query against
 * `idempotency_keys_expires_at_index` and writes nothing.
 *
 * Stale leases are **reported, not repaired**: a lease whose holder died is already
 * reclaimable on demand (`IdempotencyStore::once()` retakes it), so rewriting the row here
 * would add a second writer to the one state transition that must stay conditional. A
 * persistently rising count is, however, worth an operator's attention — it means workers
 * are dying mid-operation.
 */
final class PruneIdempotencyKeys extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wa:idempotency:prune
                            {--batch= : Rows per delete statement (default wa.reliability.idempotency.prune_batch)}
                            {--dry-run : Report what would be deleted without deleting it}';

    /**
     * @var string
     */
    protected $description = 'Delete expired side-effect dedup keys (never a key with no expiry)';

    public function handle(IdempotencyStore $store): int
    {
        $batch = $this->batch();

        if ($batch === false) {
            $this->components->error('--batch must be a positive integer.');

            return self::INVALID;
        }

        $stale = IdempotencyKey::query()->stale()->count();

        if ($this->option('dry-run') === true) {
            $this->line(sprintf('  %-16s %d', 'prunable', IdempotencyKey::query()->prunable()->count()));
            $this->reportStale($stale);

            return self::SUCCESS;
        }

        $deleted = $store->prune($batch);

        // Quiet when there is nothing to say: this runs on a schedule, and a log full of
        // "deleted 0" is a log nobody reads.
        if ($deleted > 0) {
            $this->line(sprintf('  %-16s %d', 'deleted', $deleted));
        }

        $this->reportStale($stale);

        return self::SUCCESS;
    }

    /**
     * Surface abandoned leases without touching them.
     */
    private function reportStale(int $stale): void
    {
        if ($stale === 0) {
            return;
        }

        $this->components->warn(sprintf(
            '%d idempotency lease(s) are past %ds and presumed abandoned — they stay claimable, but workers dying '
            .'mid-operation is worth investigating.',
            $stale,
            IdempotencyKey::STALE_LOCK_SECONDS,
        ));
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

        if (! is_string($option) || ! ctype_digit($option) || (int) $option < 1) {
            return false;
        }

        return (int) $option;
    }
}
