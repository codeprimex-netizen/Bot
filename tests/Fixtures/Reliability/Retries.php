<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Enums\QuotaKind;
use App\Enums\QuotaReason;
use App\Exceptions\Tenancy\QuotaExceededException;
use App\Services\Tenancy\QuotaVerdict;

/**
 * Shared entry points for the retry-policy tests — a class rather than global Pest
 * helpers, for the same reason as `Tests\Fixtures\Quota`.
 *
 * Both builders are the *real* exception over a real verdict (no database is involved: a
 * verdict is a value object), because the two halves of Req 31.1 are told apart by the
 * verdict's own reason and a hand-built double could not express that.
 */
final class Retries
{
    /**
     * A quota refusal that a period roll will clear — Req 31.1's **defer**, carrying the
     * exact wait in its `Retry-After`.
     */
    public static function deferrableQuota(int $resetSeconds = 900): QuotaExceededException
    {
        return QuotaExceededException::from('tenant-1', QuotaVerdict::for(
            kind: QuotaKind::MessagesMonthly,
            reason: QuotaReason::PeriodExhausted,
            periodKey: '2025-06',
            units: 1,
            used: 1_000,
            limit: 1_000,
            resetSeconds: $resetSeconds,
        ));
    }

    /**
     * A quota refusal no wait will clear (the plan does not price the kind at all) —
     * Req 31.1's **block**, with no `Retry-After` because no reset is coming.
     */
    public static function blockedQuota(): QuotaExceededException
    {
        return QuotaExceededException::from('tenant-1', QuotaVerdict::for(
            kind: QuotaKind::MessagesMonthly,
            reason: QuotaReason::NotPriced,
            periodKey: '2025-06',
            units: 1,
            used: 0,
            limit: 0,
        ));
    }
}
