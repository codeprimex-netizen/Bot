<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Security\MasterKeyRewrapper;
use App\Services\Security\RewrapReport;
use Illuminate\Console\Command;

/**
 * Rotate the KMS master key and re-seal everything that was sealed under the old one
 * (Req 32.6 / NFR3; design § Key rotation).
 *
 * ```
 * php artisan wa:security:rotate-master-key                 # what the scheduler runs, monthly
 * php artisan wa:security:rotate-master-key --dry-run       # what is outstanding, change nothing
 * php artisan wa:security:rotate-master-key --rewrap-only   # finish a rotation, mint no new key
 * php artisan wa:security:rotate-master-key --passes=20     # drain a large backlog in one go
 * ```
 *
 * Registered on the scheduler in `routes/console.php` (the platform runs a single cron →
 * `schedule:run`, task 39.2), so this file is *not* where the cadence lives.
 *
 * ## What one run does, and why the order matters
 *
 * 1. **Mint** a new master key version, when the key store can (`KmsKeyWrapper` can;
 *    `ConfigMasterKeyWrapper` cannot — its material is operator-supplied, so the command
 *    says so and sweeps under whatever `master_key_id` names instead of pretending).
 * 2. **Re-wrap** a bounded batch from every store — per-tenant DEKs and HMAC signing
 *    secrets — under the new key.
 *
 * Minting first is what makes the sweep meaningful; the previous version keeps *opening*
 * what it sealed throughout, so at no point is anything undecryptable. Re-wrapping changes
 * only the seal, never the DEK, so no stored ciphertext is affected at all — encrypt,
 * rotate, and the old value still decrypts.
 *
 * ## Exit codes are the alert
 *
 * - `0` — everything is sealed under the active key (`pending = 0`, `failed = 0`). This is
 *   the only state in which it is safe to **remove** the previous master key from the key
 *   store, and the command says so explicitly.
 * - `0` with pending rows — a large estate mid-rotation; the next run continues. Normal.
 * - `1` — rows **failed to open**. The master key they were sealed under is no longer
 *   resolvable, so they cannot be moved; removing anything from the key store now would
 *   make that permanent. Deliberately noisy.
 */
final class RotateMasterKey extends Command
{
    /**
     * Sweep passes per invocation when none is asked for. One, because the scheduler comes
     * back: a run that fills its batch is followed by another, and draining a backlog on
     * purpose is `--passes`, not a command that decides to make thousands of KMS calls.
     */
    public const int DEFAULT_PASSES = 1;

    /**
     * @var string
     */
    protected $signature = 'wa:security:rotate-master-key
                            {--rewrap-only : Re-seal outstanding rows without minting a new master key version}
                            {--limit= : Rows per store per pass (default wa.security.encryption.rotation.batch)}
                            {--passes= : Sweep passes, stopping early when nothing is outstanding (default 1)}
                            {--dry-run : Report what is outstanding without rotating or re-sealing}';

    /**
     * @var string
     */
    protected $description = 'Rotate the KMS master key and re-wrap every DEK and signing secret sealed under the old one';

    public function handle(MasterKeyRewrapper $rewrapper): int
    {
        $limit = $this->positiveOption('limit');
        $passes = $this->positiveOption('passes');

        if ($limit === false || $passes === false) {
            $this->components->error('--limit and --passes must be positive integers.');

            return self::INVALID;
        }

        try {
            if ($this->option('dry-run') === true) {
                return $this->reportOutstanding($rewrapper);
            }

            if ($this->option('rewrap-only') !== true) {
                $this->mint($rewrapper);
            }

            return $this->sweep($rewrapper, $limit, $passes ?? self::DEFAULT_PASSES);
        } catch (KeyUnavailableException $e) {
            // Fail closed and legibly: the message carries no key material, and a stack
            // trace in a scheduler log tells an operator nothing this does not.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Advance the master key, or explain why this key store cannot.
     */
    private function mint(MasterKeyRewrapper $rewrapper): void
    {
        if (! $rewrapper->canRotateMasterKey()) {
            $this->components->warn(
                'The configured key wrapper cannot mint master keys. Rotate by generating a new master key, '
                .'adding it to wa.security.encryption.master_keys, and pointing WA_MASTER_KEY_ID at it — '
                .'keeping the previous id listed until this command reports nothing outstanding. '
                .'Re-sealing under the configured active key continues below.'
            );

            return;
        }

        [$previous, $active] = $rewrapper->rotateMasterKey();

        if ($previous === $active) {
            $this->components->warn(sprintf('The key store did not advance the master key (still %s).', $active));

            return;
        }

        $this->components->info(sprintf('Master key advanced: %s -> %s.', $previous, $active));
    }

    /**
     * Re-seal outstanding rows, pass by pass.
     */
    private function sweep(MasterKeyRewrapper $rewrapper, ?int $limit, int $passes): int
    {
        $last = new RewrapReport;

        for ($pass = 1; $pass <= $passes; $pass++) {
            $last = $rewrapper->rewrap($limit);

            // Nothing outstanding, or nothing movable: another pass would repeat the same
            // queries and the same failures.
            if ($last->pending() === 0 || $last->rewrapped() === 0) {
                break;
            }
        }

        return $this->report($rewrapper, $last);
    }

    /**
     * `--dry-run`: what a real run would have to do.
     */
    private function reportOutstanding(MasterKeyRewrapper $rewrapper): int
    {
        $this->line(sprintf('  %-18s %s', 'active key', $rewrapper->activeKeyId()));
        $this->line(sprintf('  %-18s %d', 'outstanding', $rewrapper->pending()));

        if (! $rewrapper->canRotateMasterKey()) {
            $this->components->warn('The configured key wrapper cannot mint master keys; a real run would only re-seal.');
        }

        return self::SUCCESS;
    }

    private function report(MasterKeyRewrapper $rewrapper, RewrapReport $report): int
    {
        if ($report->isEmpty()) {
            $this->info('Every data key and signing secret is already sealed under the active master key.');

            return self::SUCCESS;
        }

        foreach ($report->perStore() as $label => $counts) {
            $this->line(sprintf(
                '  %-18s rewrapped %d, failed %d, pending %d',
                $label,
                $counts['rewrapped'],
                $counts['failed'],
                $counts['pending'],
            ));
        }

        if ($report->failed() > 0) {
            $this->components->error(sprintf(
                '%d row(s) could not be opened and were left untouched. The master key they were sealed under '
                .'is no longer resolvable — restore it before removing anything from the key store, or that '
                .'material is unrecoverable.',
                $report->failed(),
            ));

            return self::FAILURE;
        }

        if ($report->isComplete()) {
            $this->components->info(sprintf(
                'Rotation complete: everything is sealed under %s. The previous master key may now be retired.',
                $rewrapper->activeKeyId(),
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d row(s) still sealed under an older master key; the next run continues. Keep the previous '
            .'master key available until this reaches zero.',
            $report->pending(),
        ));

        return self::SUCCESS;
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
