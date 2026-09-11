<?php

declare(strict_types=1);

namespace App\Events\Tenancy;

use App\Enums\QuotaKind;

/**
 * One parked unit of work has its allowance back and is being handed to its owner
 * (Req 20.3 / C3; Req 31.1 / NFR2).
 *
 * Dispatched **per hold**, unlike `TenantQuotaRestored` which is the tenant-facing notice
 * and is deliberately deduplicated to one per tenant per quota per period. The two are not
 * interchangeable: this one is a work hand-back and every hold needs its own, or work
 * would be silently left parked.
 *
 * For a hold that names a `QuotaResumer` this event is an *announcement* — the resumer has
 * already run when it fires. For a hold with no resumer it **is** the hand-back: the
 * owner listens, recognises its own `dedupKey` / `holdable`, and carries on. Either way it
 * is dispatched inside the owning tenant's context, before the hold is closed, so a
 * listener that throws keeps the work parked and retried rather than losing it.
 */
final readonly class QuotaHoldResumed
{
    /**
     * @param  string  $holdId  the `quota_holds` row
     * @param  string  $tenantId  the owning tenant
     * @param  QuotaKind  $kind  the allowance that had run out
     * @param  string  $dedupKey  the unit of work's identity within its tenant
     * @param  string|null  $holdableType  morph type of the parked row, when the work is one
     * @param  string|null  $holdableId  morph key of the parked row
     * @param  array<string, mixed>  $payload  whatever the owner parked alongside the work
     * @param  string  $trigger  one of `TenantQuotaRestored::TRIGGER_*`
     */
    public function __construct(
        public string $holdId,
        public string $tenantId,
        public QuotaKind $kind,
        public string $dedupKey,
        public ?string $holdableType = null,
        public ?string $holdableId = null,
        public array $payload = [],
        public string $trigger = TenantQuotaRestored::TRIGGER_PERIOD_RESET,
    ) {}
}
