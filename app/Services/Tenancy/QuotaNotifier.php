<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\QuotaKind;
use App\Events\Tenancy\TenantQuotaExhausted;
use App\Events\Tenancy\TenantQuotaRestored;
use App\Models\QuotaHold;
use App\Models\Tenant;
use App\Services\Reliability\IdempotencyOptions;
use App\Services\Reliability\IdempotencyStore;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Telling the tenant that a quota stopped their work — and that it started again
 * (Req 3.4 / A3: *"...and notify the tenant"*; Req 20.3 / C3).
 *
 * ```php
 * $notifier->exhausted($tenant, $verdict, $hold);              // "your month is spent"
 * $notifier->restored($tenant, $kind, $periodKey, TenantQuotaRestored::TRIGGER_PERIOD_RESET, 3);
 * ```
 *
 * ## Once per tenant per quota per period — not once per job
 *
 * This is the whole reason the class exists. A campaign with 900 remaining sends refuses
 * 900 times; a tenant that got 900 notices would have been *spammed*, not notified, and
 * would learn to ignore the channel Req 3.4 depends on. So a notice is claimed before it
 * is sent, keyed by `(tenant, quota kind, period, event)`, and only the claim winner
 * dispatches.
 *
 * The claim is `IdempotencyStore::once()` in `IdempotencyMode::AtMostOnce` — a row in
 * `idempotency_keys` written with `INSERT ... IGNORE` — which makes the deduplication:
 *
 * - **durable** — it survives a worker restart and a cache flush, unlike a cache marker.
 *   That matters because the window being deduplicated is up to a month long;
 * - **race-free across processes and hosts** — `uniq(scope, key)` decides, so two workers
 *   refusing the same tenant's sends in the same millisecond produce one notice. No lock,
 *   no read-then-write, and no exception to catch: the ignored insert simply reports zero
 *   rows;
 * - **self-expiring** — each claim carries `expires_at` (`notify.retention_days`, which
 *   must outlive the longest period, a month, or a tenant could be told twice about the
 *   same period).
 *
 * The mode matters, and it is chosen deliberately: **no lease**. A lease exists so that a
 * crashed worker's key can be retried, and retrying is exactly what must not happen here —
 * a duplicate notice is spam that teaches the tenant to ignore the channel Req 3.4 depends
 * on, while a missed one is a nuisance. So the key is burned before the event is
 * dispatched, and a failure is never retried
 * (`IdempotencyMode::AtMostOnce::burnsKeyBeforeExecution()`).
 *
 * ## Events, because the inbox is task 29.2
 *
 * Nothing here sends mail or writes a mailbox row: it dispatches `TenantQuotaExhausted` /
 * `TenantQuotaRestored`, and the notifications inbox (task 29.2) subscribes. See those
 * events for why the split is deliberate. The sentence a subscriber shows is the verdict's
 * own `explanation()` — the same text `QuotaExceededException::publicMessage()` returns —
 * so the 429 body, the panel banner and the inbox cannot drift apart.
 *
 * `wa.tenancy.quota.notify.enabled = false` turns the notices off (a load test, a
 * migration replay) without touching the parking mechanism: work is still parked and still
 * resumed, the tenant simply is not told.
 */
final readonly class QuotaNotifier
{
    /**
     * Fallback `idempotency_keys.scope` prefix for notice claims.
     */
    public const string DEFAULT_SCOPE_PREFIX = 'quota-notice';

    public const string EVENT_EXHAUSTED = 'exhausted';

    public const string EVENT_RESTORED = 'restored';

    public function __construct(
        private Dispatcher $events,
        private IdempotencyStore $store,
    ) {}

    /**
     * Tell $tenant that $verdict stopped their work — at most once for this quota and
     * period.
     *
     * @param  QuotaHold|null  $hold  the parked work, when the refusal parked something
     * @return bool whether this call is the one that notified
     */
    public function exhausted(Tenant $tenant, QuotaVerdict $verdict, ?QuotaHold $hold = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $message = $verdict->explanation();

        if (! $this->claim($tenant, $verdict->kind, $verdict->periodKey, self::EVENT_EXHAUSTED, $message)) {
            return false;
        }

        $this->events->dispatch(new TenantQuotaExhausted(
            $tenant->id,
            $verdict->kind,
            $verdict->periodKey,
            $verdict->reason,
            $message,
            $verdict->secondsUntilPeriodReset(),
            $hold?->id,
        ));

        return true;
    }

    /**
     * Tell $tenant that the allowance is back and their parked work has resumed — at most
     * once for this quota and period.
     *
     * @param  string  $trigger  one of `TenantQuotaRestored::TRIGGER_*`
     * @param  int  $resumedHolds  units of work handed back by the sweep that noticed
     * @return bool whether this call is the one that notified
     */
    public function restored(
        Tenant $tenant,
        QuotaKind $kind,
        string $periodKey,
        string $trigger,
        int $resumedHolds = 0,
    ): bool {
        if (! $this->enabled()) {
            return false;
        }

        $message = $this->restoredMessage($kind, $trigger);

        if (! $this->claim($tenant, $kind, $periodKey, self::EVENT_RESTORED, $message)) {
            return false;
        }

        $this->events->dispatch(new TenantQuotaRestored(
            $tenant->id,
            $kind,
            $periodKey,
            $message,
            $trigger,
            max(0, $resumedHolds),
        ));

        return true;
    }

    /**
     * The `idempotency_keys.scope` one tenant's notices about one quota are claimed in.
     *
     * The tenant id is in the scope because `idempotency_keys` is deliberately not
     * tenant-scoped, so isolation has to be explicit in the key — the same reasoning, and
     * the same shape, as `QuotaGuard::idempotencyScope()`.
     */
    public function noticeScope(Tenant $tenant, QuotaKind $kind): string
    {
        return sprintf('%s:%s:%s', $this->scopePrefix(), $tenant->id, $kind->value);
    }

    /**
     * The key one notice is claimed under: the event, then the period it is about.
     *
     * The period is *in* the key (unlike a consume, where it must not be), because the
     * thing being deduplicated is "have we already told them about **this** period" — and
     * a fresh period is genuinely fresh news.
     */
    public function noticeKey(string $event, string $periodKey): string
    {
        return $event.':'.$periodKey;
    }

    /**
     * Claim the right to send one notice: `INSERT ... IGNORE` on `uniq(scope, key)`.
     *
     * True exactly once per key, across every worker and every restart.
     *
     * The operation handed to the store is pure — it only names the payload that is
     * recorded as the claim's `result` — because the *notice itself* is dispatched by the
     * caller after this returns true. Keeping the dispatch outside is what makes the order
     * "claim, then tell" rather than "tell, then hope the claim sticks", and it means the
     * at-most-once mode's one sharp edge (an operation that throws is never retried) has
     * nothing to bite: there is nothing here that can fail.
     */
    private function claim(Tenant $tenant, QuotaKind $kind, string $periodKey, string $event, string $message): bool
    {
        $notice = ['event' => $event, 'quota' => $kind->value, 'period_key' => $periodKey, 'message' => $message];

        return $this->store->once(
            $this->noticeScope($tenant, $kind),
            $this->noticeKey($event, $periodKey),
            static fn (): array => $notice,
            IdempotencyOptions::atMostOnce()
                ->forTenant($tenant)
                // Must outlive the longest period (a month) or the same period could be
                // announced twice.
                ->keptFor($this->retentionDays()),
        )->wasExecuted();
    }

    /**
     * The tenant-safe sentence for a restored allowance.
     *
     * Composed here rather than taken from a verdict because there is no refusal to
     * explain: the news is that the allowance is back, and *what brought it back* is what
     * makes the sentence useful.
     */
    private function restoredMessage(QuotaKind $kind, string $trigger): string
    {
        return match ($trigger) {
            TenantQuotaRestored::TRIGGER_PLAN_CHANGE => sprintf(
                '%s: your plan allowance changed, so paused work has resumed.',
                $kind->label(),
            ),
            TenantQuotaRestored::TRIGGER_TOP_UP => sprintf(
                '%s: your top-up restored the allowance, so paused work has resumed.',
                $kind->label(),
            ),
            default => sprintf(
                '%s: a new period has started, so paused work has resumed.',
                $kind->label(),
            ),
        };
    }

    private function enabled(): bool
    {
        $configured = config('wa.tenancy.quota.notify.enabled');

        return ! is_bool($configured) || $configured;
    }

    private function scopePrefix(): string
    {
        $configured = config('wa.tenancy.quota.notify.scope_prefix');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : self::DEFAULT_SCOPE_PREFIX;
    }

    private function retentionDays(): int
    {
        $configured = config('wa.tenancy.quota.notify.retention_days');

        // Never below 32 days: a shorter horizon would let a monthly period be announced
        // twice, which is the one thing this class exists to prevent.
        return max(32, is_numeric($configured) ? (int) $configured : 45);
    }
}
