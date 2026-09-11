<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Tenant;
use InvalidArgumentException;

/**
 * One tenant's share of a queue lane: the tenant, and how much work of its own is
 * waiting (Req 1.7 / A1; Req 30.2 / NFR1).
 *
 * A *queue lane* is a worker pool (`campaign`, `ai-reply`, `transactional`). A
 * **dispatch lane** — this object — is one tenant's slice of such a pool, which is
 * the unit the deficit-round-robin scheduler shares out. The scheduler is handed the
 * set of lanes with pending work and answers "whose turn is it?"; it deliberately
 * does not know how to *find* pending work, because the tables that hold it arrive
 * later (campaign recipients in task 13.x, the outbound queue in task 9.3, the
 * per-lane workers in task 36.4). Each of those callers already has the query it
 * needs and passes the result in.
 *
 * ## Backlog, and why it may be unknown
 *
 * `backlog` is a **count of dispatchable units**, not a byte size or a priority: one
 * unit is one call of the caller's dispatch closure (one message, one AI reply, one
 * campaign recipient). It is used for two things only — a lane with none is not in
 * the rotation, and a lane whose backlog runs out mid-window releases the rest of the
 * window to the others.
 *
 * A caller that knows the count passes it (`DispatchLane::for($tenant, 4_213)`). A
 * caller that only knows *that* work exists — the common case for a `SELECT EXISTS`
 * or a Redis lane depth it does not want to count — uses `pending()`, which marks the
 * backlog `UNBOUNDED`. Unbounded is not "infinite priority": the lane still gets only
 * its weighted quantum per round, so an unbounded lane and a lane with a known
 * 10-million backlog are dispatched identically. The count is an optimisation for
 * *ending* a window early, never a claim on a larger share.
 *
 * The weight is deliberately **absent** from this object. It comes from
 * `TierResolver::laneWeight()` at claim time, so a caller cannot inflate its own
 * share, and a weight changed in `tenant_tiers` (or in `wa.tenancy.tiers`) applies to
 * the very next round without the caller rebuilding anything.
 */
final readonly class DispatchLane
{
    /**
     * Backlog for "this lane has work, and counting it is not worth a query".
     */
    public const int UNBOUNDED = PHP_INT_MAX;

    private function __construct(
        public Tenant $tenant,
        public int $backlog,
    ) {}

    /**
     * A lane with a known number of dispatchable units.
     *
     * A zero or negative backlog is accepted and means "nothing pending": the
     * scheduler drops such a lane from the rotation rather than treating it as an
     * error, so a caller can pass the raw result of a `GROUP BY tenant_id` count
     * without filtering it first.
     */
    public static function for(Tenant $tenant, int $backlog): self
    {
        return new self($tenant, max(0, $backlog));
    }

    /**
     * A lane known to have work, with no count.
     */
    public static function pending(Tenant $tenant): self
    {
        return new self($tenant, self::UNBOUNDED);
    }

    /**
     * Whatever the caller handed over, as a lane.
     *
     * A bare `Tenant` in a candidate set means "this tenant has pending work" —
     * which is exactly `pending()`.
     */
    public static function from(DispatchLane|Tenant $candidate): self
    {
        return $candidate instanceof self ? $candidate : self::pending($candidate);
    }

    public function tenantId(): string
    {
        return $this->tenant->id;
    }

    public function isEmpty(): bool
    {
        return $this->backlog <= 0;
    }

    public function isUnbounded(): bool
    {
        return $this->backlog === self::UNBOUNDED;
    }

    /**
     * One lane covering the work of both, for a caller that listed the same tenant
     * twice (two campaigns, two sessions).
     *
     * Saturating rather than overflowing: two large backlogs add up to `UNBOUNDED`
     * instead of wrapping negative, which would silently remove the busiest tenant on
     * the platform from the rotation.
     */
    public function merged(self $other): self
    {
        if ($other->tenantId() !== $this->tenantId()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot merge dispatch lanes of two different tenants ([%s] and [%s]).',
                $this->tenantId(),
                $other->tenantId(),
            ));
        }

        if ($this->isUnbounded() || $other->isUnbounded()) {
            return new self($this->tenant, self::UNBOUNDED);
        }

        return new self($this->tenant, min(self::UNBOUNDED, $this->backlog + $other->backlog));
    }
}
