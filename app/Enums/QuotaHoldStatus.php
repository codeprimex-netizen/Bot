<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of one parked unit of work — the `QUOTA_PAUSED` state Req 20.3 names,
 * plus the three states that make resuming it safe with several workers.
 *
 * | Case | Meaning | Who moves it on |
 * |---|---|---|
 * | `QUOTA_PAUSED` | the allowance ran out mid-run; the work is parked, not dropped | the resume sweep, once the allowance returns |
 * | `RESUMING` | one worker has claimed this hold and is handing the work back | that worker (or the lease expiry, if it died) |
 * | `RESUMED` | the work was handed back to its owner | nobody — terminal |
 * | `CANCELLED` | the parked work itself is gone (campaign deleted, tenant offboarded) | nobody — terminal |
 *
 * ## Why `RESUMING` exists
 *
 * A resume has two halves that cannot be one statement: flipping the hold, and telling
 * the owner (a campaign, a queued batch) to carry on. Marking `RESUMED` *first* would
 * lose the work if the second half failed — the hold is gone, so nothing would ever come
 * back for it, which is exactly the drop Req 20.3 and Req 31.1 forbid. Doing the second
 * half first would let two workers resume the same campaign twice.
 *
 * So a worker claims the hold by moving `QUOTA_PAUSED → RESUMING` with a
 * compare-and-set (`where status = QUOTA_PAUSED ... update`), and only the worker whose
 * update affected a row proceeds. If the hand-back fails, the hold goes **back** to
 * `QUOTA_PAUSED` with the error recorded and a backoff; if the worker dies mid-claim,
 * the claim is a lease (`QuotaHold::CLAIM_LEASE_SECONDS`) and the next sweep retakes it.
 * Work is therefore never lost, only ever retried.
 */
enum QuotaHoldStatus: string
{
    case QuotaPaused = 'QUOTA_PAUSED';
    case Resuming = 'RESUMING';
    case Resumed = 'RESUMED';
    case Cancelled = 'CANCELLED';

    /**
     * Whether the hold still owes its owner a resume — the set the sweep looks at, and
     * the set a panel renders as "paused".
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::QuotaPaused, self::Resuming => true,
            self::Resumed, self::Cancelled => false,
        };
    }

    /**
     * Whether the hold is parked and unclaimed — the only state a sweep may claim from.
     */
    public function isPaused(): bool
    {
        return $this === self::QuotaPaused;
    }

    public function isClaimed(): bool
    {
        return $this === self::Resuming;
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * Human-readable label for panels, logs, and operator views.
     */
    public function label(): string
    {
        return match ($this) {
            self::QuotaPaused => 'Paused — plan allowance spent',
            self::Resuming => 'Resuming',
            self::Resumed => 'Resumed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The states a sweep may still have work to do for.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isOpen()),
        ));
    }
}
