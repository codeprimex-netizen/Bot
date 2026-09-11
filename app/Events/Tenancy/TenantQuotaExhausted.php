<?php

declare(strict_types=1);

namespace App\Events\Tenancy;

use App\Enums\QuotaKind;
use App\Enums\QuotaReason;

/**
 * A tenant's allowance stopped work, and the tenant is being told about it — Req 3.4's
 * *"defer or block the send (never drop it) **and notify the tenant**"*.
 *
 * Dispatched **once per tenant per quota per period** by `QuotaNotifier`, not once per
 * refused job: a campaign with 900 remaining sends produces one of these, not 900. See
 * that class for how the deduplication survives restarts and several workers.
 *
 * ## This is the seam, not the inbox
 *
 * The tenant-facing notifications inbox is task 29.2. It subscribes to this event and
 * writes its own row; nothing here knows a mailbox exists. That keeps two things separate
 * that would otherwise be welded together: *deciding* that a tenant must be told (a quota
 * concern, owned here) and *how* they are told — inbox, email, webhook, panel banner (a
 * notification concern, owned there). Any other subscriber — a metrics counter, an
 * account-manager alert on a repeat offender — plugs in the same way.
 *
 * `$message` is the tenant-safe sentence from `QuotaVerdict::explanation()`
 * (= `QuotaExceededException::publicMessage()`): the tenant's own plan, its own usage, and
 * what happens next. Subscribers should display it rather than compose their own, so the
 * panel, the API 429 body, and the inbox all say the same thing.
 */
final readonly class TenantQuotaExhausted
{
    /**
     * @param  string  $tenantId  whose allowance ran out
     * @param  QuotaKind  $kind  which allowance
     * @param  string  $periodKey  the `tenant_usage` bucket that was exhausted
     * @param  QuotaReason  $reason  why (`PERIOD_EXHAUSTED` for parked work; a blocking reason otherwise)
     * @param  string  $message  the tenant-safe sentence to show
     * @param  int  $resumeSeconds  seconds until the allowance returns on its own; 0 when it will not
     * @param  string|null  $holdId  the `quota_holds` row, when work was parked rather than merely refused
     */
    public function __construct(
        public string $tenantId,
        public QuotaKind $kind,
        public string $periodKey,
        public QuotaReason $reason,
        public string $message,
        public int $resumeSeconds = 0,
        public ?string $holdId = null,
    ) {}

    /**
     * Whether the allowance comes back on its own — the difference between "your campaign
     * continues on 1 July" and "upgrade to continue".
     */
    public function isTransient(): bool
    {
        return $this->reason->isTransient();
    }
}
