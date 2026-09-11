<?php

declare(strict_types=1);

namespace App\Events\Tenancy;

use App\Enums\QuotaKind;

/**
 * A tenant's allowance came back and its parked work is being handed back
 * (Req 20.3 / C3: *"auto-resume at the next period reset or after top-up/upgrade"*).
 *
 * Dispatched by `QuotaNotifier` **once per tenant per quota per period**, on the same
 * deduplication as `TenantQuotaExhausted` — a tenant with 40 parked campaigns is told once
 * that its month rolled, not 40 times.
 *
 * Two audiences:
 *
 * - the notifications inbox (task 29.2), which turns it into the "your campaigns have
 *   resumed" notice that closes the loop opened by `TenantQuotaExhausted`;
 * - any owner that prefers to re-read its own state rather than register a
 *   `QuotaResumer` — which is exactly what a hold with no `resumer` relies on.
 *
 * `$trigger` says *what* returned the allowance, because the three cases read very
 * differently to a tenant: `period_reset` (the month rolled), `plan_change` (an upgrade,
 * or an admin raising a limit), `top_up` (wallet credit — task 10.4).
 */
final readonly class TenantQuotaRestored
{
    /**
     * The period rolled over and the counter started fresh.
     */
    public const string TRIGGER_PERIOD_RESET = 'period_reset';

    /**
     * The plan changed under the tenant — an upgrade, or an admin edit to plan limits.
     */
    public const string TRIGGER_PLAN_CHANGE = 'plan_change';

    /**
     * The tenant bought more allowance (task 10.4's wallet top-up / quota grant).
     */
    public const string TRIGGER_TOP_UP = 'top_up';

    /**
     * @param  string  $tenantId  whose allowance returned
     * @param  QuotaKind  $kind  which allowance
     * @param  string  $periodKey  the bucket the resumed work now counts against
     * @param  string  $message  the tenant-safe sentence to show
     * @param  string  $trigger  one of the `TRIGGER_*` constants
     * @param  int  $resumedHolds  how many parked units of work were handed back
     */
    public function __construct(
        public string $tenantId,
        public QuotaKind $kind,
        public string $periodKey,
        public string $message,
        public string $trigger = self::TRIGGER_PERIOD_RESET,
        public int $resumedHolds = 0,
    ) {}
}
