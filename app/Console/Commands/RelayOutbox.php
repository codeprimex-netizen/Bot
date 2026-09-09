<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use App\Services\Reliability\Outbox;
use App\Services\Reliability\OutboxRelayReport;
use Illuminate\Console\Command;

/**
 * The relay worker: deliver the transactional outbox (Req 31.4 / NFR2, Algorithm 6,
 * Correctness Property 16).
 *
 * ```
 * php artisan wa:outbox:relay                  # what the scheduler runs, every minute
 * php artisan wa:outbox:relay --passes=10      # drain a backlog in one invocation
 * php artisan wa:outbox:relay --batch=25       # smaller claims, for a busy box
 * php artisan wa:outbox:relay --dry-run        # count what is due, deliver nothing
 * php artisan wa:outbox:relay --requeue=1234   # hand one parked row back to the relay
 * ```
 *
 * Registered on the scheduler in `routes/console.php` (the platform runs a single cron →
 * `schedule:run`, task 39.2), so this file is *not* where the cadence lives.
 *
 * ## Why it is safe to run every minute, and concurrently
 *
 * Neither guarantee comes from the schedule. A claim takes each row with
 * `FOR UPDATE SKIP LOCKED` and leases it by pushing `next_attempt_at` past the moment any
 * other claimer would look, so two workers — or two app servers, or an overlapping run —
 * cannot deliver the same row concurrently; and if a lease ever did expire mid-attempt, the
 * `X-Dedup-Key` header means the consumer applies the effect once anyway. See
 * `App\Services\Reliability\DatabaseOutbox`. A tick with nothing due costs one indexed query
 * against `outbox_claim_index` and writes nothing.
 *
 * ## The numbers it prints
 *
 * `delivered` / `retrying` / `parked` / `shed` account for every claimed row. Two of them
 * are worth alerting on: **parked** rows are effects that will not be delivered without a
 * human (`--requeue`), and **raced** deliveries mean a lease expired while its attempt was
 * still running, so the transport is slower than `wa.reliability.outbox.lease_seconds`.
 */
final class RelayOutbox extends Command
{
    /**
     * Passes per invocation when none is asked for. One, because the scheduler calls this
     * every minute: a pass that finds a full batch is followed by another one 60 seconds
     * later, and a backlog is drained on purpose with `--passes` rather than by a command
     * that decides to run for an unbounded time on its own.
     */
    public const int DEFAULT_PASSES = 1;

    /**
     * @var string
     */
    protected $signature = 'wa:outbox:relay
                            {--batch= : Rows to claim per pass (default wa.reliability.outbox.batch)}
                            {--passes= : Passes to run, stopping early when a pass claims nothing (default 1)}
                            {--dry-run : Report what is due without delivering anything}
                            {--requeue=* : Hand these parked outbox row ids back to the relay, then relay as usual}';

    /**
     * @var string
     */
    protected $description = 'Deliver due transactional-outbox rows with their dedup key (Algorithm 6)';

    public function handle(Outbox $outbox): int
    {
        $batch = $this->positiveOption('batch');
        $passes = $this->positiveOption('passes');

        if ($batch === false || $passes === false) {
            $this->components->error('--batch and --passes must be positive integers.');

            return self::INVALID;
        }

        if (! $this->requeue($outbox)) {
            return self::FAILURE;
        }

        if ($this->option('dry-run') === true) {
            $this->line(sprintf('  %-10s %d', 'due', OutboxMessage::query()->claimable()->count()));

            return self::SUCCESS;
        }

        $total = new OutboxRelayReport;

        for ($pass = 1; $pass <= ($passes ?? self::DEFAULT_PASSES); $pass++) {
            $report = $outbox->relayBatch($batch);
            $total->merge($report);

            // Nothing left that is due: stop rather than spending another indexed query per
            // remaining pass.
            if ($report->isEmpty()) {
                break;
            }
        }

        $this->report($total);

        return self::SUCCESS;
    }

    /**
     * Hand the requested rows back to the relay before the pass runs.
     *
     * A row that cannot be requeued is reported rather than skipped silently: it means the
     * id is wrong, the effect was already delivered, or it is past the dedup horizon and a
     * redelivery could double-apply it (`Outbox::requeue()`).
     */
    private function requeue(Outbox $outbox): bool
    {
        $ids = $this->option('requeue');

        if (! is_array($ids) || $ids === []) {
            return true;
        }

        $refused = [];

        foreach ($ids as $id) {
            if (! is_string($id) || ! ctype_digit($id)) {
                $this->components->error(sprintf('--requeue expects outbox row ids; got "%s".', (string) $id));

                return false;
            }

            if (! $outbox->requeue((int) $id)) {
                $refused[] = $id;
            }
        }

        if ($refused !== []) {
            $this->components->warn(sprintf(
                'Could not requeue row(s) %s — unknown, already delivered, or past the dedup horizon, in which case '
                .'a redelivery could apply the effect twice. Enqueue a fresh intent with a new dedup key instead.',
                implode(', ', $refused),
            ));
        }

        return true;
    }

    /**
     * Quiet when there was nothing to do: this runs on a schedule, and a log full of
     * "delivered 0" is a log nobody reads.
     */
    private function report(OutboxRelayReport $report): void
    {
        if ($report->isEmpty()) {
            return;
        }

        foreach ($report->toArray() as $bucket => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %-10s %d', $bucket, $count));
            }
        }

        if ($report->parked() > 0) {
            $this->components->warn(sprintf(
                '%d row(s) parked: kept, never dropped, but nothing will deliver them until an operator requeues '
                .'them (php artisan wa:outbox:relay --requeue=<id>). Read outbox.last_error first.',
                $report->parked(),
            ));
        }

        if ($report->raced() > 0) {
            $this->components->warn(sprintf(
                '%d delivery(ies) found their row already SENT — a claim lease expired while its attempt was still '
                .'running. The consumer dedups on X-Dedup-Key, so nothing is doubly applied, but '
                .'wa.reliability.outbox.lease_seconds is too short for this transport.',
                $report->raced(),
            ));
        }
    }

    /**
     * @return int|null|false the parsed value, null when not given, false when invalid
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
