<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Security\SigningSecretRotation;
use App\Services\Security\SigningSecretStore;
use Illuminate\Console\Command;

/**
 * Dual-secret rotation of webhook/HMAC secrets (Req 32.6 / NFR3; design § Key rotation —
 * "webhook/HMAC secrets support dual-secret rotation (accept old+new during overlap)").
 *
 * ```
 * php artisan wa:security:rotate-hmac                          # what the scheduler runs, hourly
 * php artisan wa:security:rotate-hmac --dry-run                # which scopes are due
 * php artisan wa:security:rotate-hmac --scope=gateway:razorpay  # rotate one scope now (suspected leak)
 * php artisan wa:security:rotate-hmac --overlap=3600           # a shorter window, for an incident
 * php artisan wa:security:rotate-hmac --no-purge               # rotate only, keep closed windows
 * ```
 *
 * Registered on the scheduler in `routes/console.php` (the platform runs a single cron →
 * `schedule:run`, task 39.2), so this file is *not* where the cadence lives.
 *
 * ## What a rotation does to a peer
 *
 * Nothing, for the length of the overlap window. The old secret keeps verifying while only
 * the new one signs, so requests already in flight — and everything the peer sends until it
 * is reconfigured — still authenticate:
 *
 * ```
 *   v1 signs ──┬── v1 verifies only ──────────┤ v1 refused (window closed)
 *              └── v2 signs and verifies ─────┴──▶
 * ```
 *
 * Which is the entire reason the window exists: without it, rotating a webhook secret
 * rejects every signature the peer has in flight, and the pressure that creates ("just skip
 * verification for a minute") is exactly the spoofing the signature is there to stop.
 *
 * Handing the new secret to the peer is a **separate, deliberate act** —
 * `SigningSecretStore::currentSecret()`, at registration time — and never something a
 * scheduled command prints. Nothing in this command's output or audit trail contains secret
 * material.
 *
 * ## Purging
 *
 * Secrets past their window are refused by `verify()` on the clock, not by this sweep; the
 * purge only reclaims the rows. That ordering is deliberate: a purge that has not run must
 * never be able to widen a window.
 */
final class RotateSigningSecrets extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wa:security:rotate-hmac
                            {--scope= : Rotate this scope only (e.g. gateway:razorpay, bridge:session:01H…)}
                            {--overlap= : Seconds the previous secret keeps verifying (default wa.security.hmac.overlap_hours)}
                            {--limit= : Scopes to rotate in this run (default wa.security.hmac.batch)}
                            {--no-purge : Skip deleting secrets whose overlap window has closed}
                            {--dry-run : Report which scopes are due without rotating}';

    /**
     * @var string
     */
    protected $description = 'Rotate HMAC signing secrets with an overlap window (old secret still verifies, only the new one signs)';

    public function handle(SigningSecretStore $secrets): int
    {
        $limit = $this->positiveOption('limit');
        $overlap = $this->overlapOption();

        if ($limit === false || $overlap === false) {
            $this->components->error('--limit must be a positive integer and --overlap a non-negative integer.');

            return self::INVALID;
        }

        $scope = $this->scopeOption();

        if ($scope === false) {
            return self::INVALID;
        }

        $batch = $limit ?? $this->configuredBatch();
        $due = $scope === null ? $secrets->dueForRotation($batch) : [$scope];

        if ($this->option('dry-run') === true) {
            $this->line(sprintf('  %-10s %d', 'due', count($due)));

            return self::SUCCESS;
        }

        $rotated = 0;
        $failed = 0;

        foreach ($due as $target) {
            try {
                $this->describe($secrets->rotate($target, $overlap));
                $rotated++;
            } catch (KeyUnavailableException $e) {
                // The scope keeps its current secret, so the peer is unaffected; the next run
                // retries. Reported per scope so an operator can see which one is stuck.
                $this->components->warn($e->getMessage());
                $failed++;
            }
        }

        $purged = $this->option('no-purge') === true ? 0 : $secrets->purgeExpired();

        return $this->report($rotated, $failed, $purged, $scope !== null);
    }

    /**
     * One line per rotation: versions and a deadline, never a secret.
     */
    private function describe(SigningSecretRotation $rotation): void
    {
        $this->line($rotation->hasOverlap()
            ? sprintf(
                '  %s: v%d signs; v%d still verifies until %s',
                $rotation->scope,
                $rotation->version,
                (int) $rotation->previousVersion,
                (string) $rotation->acceptedUntil?->toDateTimeString(),
            )
            : sprintf('  %s: v%d issued (first secret for this scope)', $rotation->scope, $rotation->version));
    }

    private function report(int $rotated, int $failed, int $purged, bool $explicit): int
    {
        if ($rotated === 0 && $failed === 0 && $purged === 0) {
            $this->info('No signing secrets are due for rotation.');

            return self::SUCCESS;
        }

        if ($purged > 0) {
            $this->line(sprintf('  %-10s %d', 'purged', $purged));
        }

        // An explicit single-scope rotation that failed exits non-zero — somebody is watching
        // that one. A failed sweep entry does not: the scope is untouched and retried.
        return $failed > 0 && $explicit ? self::FAILURE : self::SUCCESS;
    }

    private function configuredBatch(): int
    {
        $batch = config('wa.security.hmac.batch', 100);

        return is_numeric($batch) && (int) $batch > 0 ? (int) $batch : 100;
    }

    /**
     * @return string|null|false the scope, null when absent, false when unusable
     */
    private function scopeOption(): string|null|false
    {
        $option = $this->option('scope');

        if ($option === null) {
            return null;
        }

        if (! is_string($option) || trim($option) === '') {
            $this->components->error('--scope needs a non-empty scope name.');

            return false;
        }

        return trim($option);
    }

    /**
     * @return int|null|false seconds, null for the configured default, false when invalid
     */
    private function overlapOption(): int|null|false
    {
        $option = $this->option('overlap');

        if ($option === null) {
            return null;
        }

        if (! is_string($option) || ! ctype_digit($option)) {
            return false;
        }

        return (int) $option;
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
