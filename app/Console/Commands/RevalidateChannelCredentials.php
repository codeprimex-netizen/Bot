<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Models\ChannelCredential;
use App\Models\Tenant;
use App\Services\Channel\ChannelCredentialValidator;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

/**
 * Re-checks stored per-mode channel credentials against their own driver, and takes the ones
 * the provider now refuses out of service (Req 8.6, 8.13 / A8).
 *
 * ```
 * php artisan wa:channel:revalidate --dry-run                       # what would be checked
 * php artisan wa:channel:revalidate --tenant=01HZ… --mode=CLOUD_API # one tenant's set, now
 * php artisan wa:channel:revalidate --limit=5                       # a bounded sweep, stalest first
 * ```
 *
 * The operator-facing half of `ChannelCredentialValidator`: the validator owns every decision,
 * this owns *which sets to ask about* and how to report the answers. Nothing here can activate
 * a set the driver did not confirm, and nothing here prints or logs a secret — the only
 * credential material it handles is a label.
 *
 * ## Why this is not on the scheduler, and what would have to change first
 *
 * `RecheckTenantDomains` runs hourly because an inconclusive domain probe is *distinguishable*
 * from a failed one (`DomainVerificationFailure::isConclusive()`), so a resolver outage cannot
 * unverify the platform. `ChannelHealth` cannot make that distinction today: it is
 * two-valued, and `CloudApiChannelDriver::healthCheck()` correctly reports a Meta
 * **rate-limit** refusal as `unhealthy` — *"Meta is rate-limiting this account, so the
 * credentials could not be confirmed right now"* — because by then `ProviderCallGuard` has
 * already retried it.
 *
 * An unattended sweep over that would mark perfectly good credentials `INVALID` for every
 * tenant Meta happened to be throttling, and a tenant cannot fix that by re-entering a token
 * that was never wrong. So re-validation stays an **explicitly invoked** act: an operator
 * running this, or a tenant pressing "test connection". Adding a `ChannelHealth` state for
 * *"could not be confirmed"* — the change that would make a sweep safe — is a change to a
 * contract this task does not own, and is reported rather than made.
 *
 * `--dry-run` is therefore the honest default for a first run on a live platform: it lists
 * what is stale without touching a provider or a row.
 *
 * ## What a run can and cannot change
 *
 * | Driver's answer | This run |
 * |---|---|
 * | confirmed | `verified_at` re-stamped; an `INVALID` set that works again returns to `ACTIVE` |
 * | refused | the set is marked `INVALID` and stops being selectable; an older verified set of the same mode takes over if the tenant has one |
 * | could not be asked (transport, open circuit, no driver registered) | **nothing at all**, and the row is reported as skipped |
 *
 * The sweep finds work with `ChannelCredential::withoutTenantScope()` — the sanctioned,
 * greppable bypass a platform maintenance command is allowed, and the same shape `Saga`
 * documents — but every *read of material* and every write goes back through
 * `ChannelCredentialStore` for the tenant that owns the row, so no credential is decrypted
 * outside the tenant scope it belongs to. Suspended tenants are skipped: probing an account
 * that is not allowed to send would spend a provider call to learn nothing.
 */
final class RevalidateChannelCredentials extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wa:channel:revalidate
                            {--tenant= : Only this tenant id}
                            {--mode= : Only this channel mode (BAILEYS, CLOUD_API, ON_PREMISE, BSP_GATEWAY)}
                            {--label= : Only credential sets with this label}
                            {--limit= : Credential sets per run (default wa.channel.revalidate.batch)}
                            {--dry-run : List the sets that would be re-checked, contacting nothing}';

    /**
     * @var string
     */
    protected $description = 'Re-check stored channel credentials with their driver and mark the refused ones invalid';

    public function handle(ChannelCredentialValidator $validator): int
    {
        $limit = $this->limit();

        if ($limit === false) {
            $this->components->error('--limit must be a positive integer.');

            return self::INVALID;
        }

        $mode = $this->mode();

        if ($mode === false) {
            $this->components->error(sprintf(
                '--mode must be one of %s.',
                implode(', ', array_map(static fn (ChannelMode $m): string => $m->value, ChannelMode::cases())),
            ));

            return self::INVALID;
        }

        $rows = $this->due($limit ?? $this->configuredBatch(), $mode);

        if ($rows === []) {
            $this->info('No channel credential sets are due for re-validation.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run') === true) {
            foreach ($rows as $row) {
                $this->line($this->describe($row, 'due'));
            }

            $this->line(sprintf('  %-12s %d', 'due', count($rows)));

            return self::SUCCESS;
        }

        return $this->check($validator, $rows);
    }

    /**
     * Re-validate each set, reporting one line per row and a tally at the end.
     *
     * A rejection is a warning rather than a failure exit code: the run did its job, and the
     * thing that needs attention is a tenant's credential set rather than this command. An exit
     * code would be read by a scheduler as "the sweep is broken".
     *
     * @param  list<ChannelCredential>  $rows
     */
    private function check(ChannelCredentialValidator $validator, array $rows): int
    {
        $verified = 0;
        $rejected = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $tenant = $row->tenant;

            if (! $tenant->isOperational()) {
                $skipped++;
                $this->line($this->describe($row, 'skipped (tenant not operational)'));

                continue;
            }

            try {
                $validation = $validator->revalidate($tenant, $row->mode, $row->provider, $row->label);
                $verified++;
                $this->line($this->describe($row, 'ok').' — '.$validation->health->detail);
            } catch (ChannelCredentialException $refused) {
                // One line per row, like every other outcome, and via `line()` rather than the
                // warning component on purpose: a component's output is not visible to
                // `expectsOutputToContain()`, so a reason printed that way could not be pinned
                // by a test. `detail()` is the driver's own scrubbed words; neither it nor the
                // public message carries a secret.
                $rejected++;
                $this->line(sprintf(
                    '%s — %s',
                    $this->describe($row, $refused->isRejection() ? 'rejected' : 'missing'),
                    $refused->detail() ?? $refused->publicMessage(),
                ));
            } catch (LogicException $undeployed) {
                // No driver is registered for this mode in this build. Not the tenant's
                // problem, and not something a re-run fixes.
                $skipped++;
                $this->line($this->describe($row, 'skipped').' — no driver is registered for this mode');
            } catch (Throwable $unreachable) {
                // The probe could not be performed, so the validator changed nothing. Reported
                // per row so a rising count points at the platform's egress rather than at the
                // tenants' tokens.
                $skipped++;
                $this->line($this->describe($row, 'skipped').' — '.$unreachable::class);
            }
        }

        $this->line(sprintf('  %-12s %d', 'verified', $verified));

        if ($rejected > 0) {
            $this->line(sprintf('  %-12s %d', 'rejected', $rejected));
        }

        if ($skipped > 0) {
            $this->components->warn(sprintf(
                '%d credential set(s) could not be checked. They are unchanged — a probe that '
                .'could not be made is not evidence against a credential.',
                $skipped,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The sets this run will ask about: currently usable ones, least-recently-verified first.
     *
     * Never-verified rows come first — they are the ones a tenant most likely entered by hand
     * and never had confirmed — and the ordering guarantees no set is starved across runs.
     *
     * @return list<ChannelCredential>
     */
    private function due(int $limit, ?ChannelMode $mode): array
    {
        $query = ChannelCredential::withoutTenantScope()
            ->with('tenant')
            ->where('status', ChannelCredentialStatus::Active)
            ->orderByRaw('verified_at is null desc')
            ->orderBy('verified_at')
            ->orderBy('created_at')
            ->limit($limit);

        $tenant = $this->stringOption('tenant');

        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        if ($mode !== null) {
            $query->where('mode', $mode);
        }

        $label = $this->stringOption('label');

        if ($label !== null) {
            $query->where('label', $label);
        }

        /** @var list<ChannelCredential> $rows */
        $rows = $query->get()->all();

        return $rows;
    }

    /**
     * One row, one line: whose set it is, which mode, which label — and never a secret, since
     * the only credential material named here is the label the tenant chose.
     */
    private function describe(ChannelCredential $row, string $outcome): string
    {
        return sprintf(
            '  %-9s %s %s [%s]',
            $outcome,
            $row->tenant_id,
            $row->mode->value.($row->provider === null ? '' : '/'.$row->provider->value),
            $row->label,
        );
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

        if (is_bool($option)) {
            // `--limit` with no value. It takes one, so this is a caller mistake, not a flag.
            return false;
        }

        $value = (string) $option;

        // Refused rather than coerced: reading `--limit=abc` as 1 would make a typo look like a
        // working run — `RecheckTenantDomains`' rule, for the same reason.
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : false;
    }

    /**
     * @return ChannelMode|null|false the parsed mode, null for "every mode", false when invalid
     */
    private function mode(): ChannelMode|null|false
    {
        $option = $this->stringOption('mode');

        if ($option === null) {
            return null;
        }

        // `tryFromKey()` accepts the canonical spelling only, so a stored-value typo is
        // refused rather than guessed at.
        return ChannelMode::tryFromKey(strtoupper($option)) ?? false;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function configuredBatch(): int
    {
        $batch = config('wa.channel.revalidate.batch');
        $batch = is_numeric($batch) ? (int) $batch : 25;

        // A non-positive batch would make a run a no-op that reports success, which is worse
        // than a run that does nothing visible.
        return $batch > 0 ? $batch : 25;
    }
}
