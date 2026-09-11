<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

/**
 * The grant sequence claimed from the deficit ledger for one dispatch window: the
 * tenant ids to serve, in order, one entry per unit of work (Req 1.7 / A1).
 *
 * The plan exists because claiming and dispatching are deliberately separated. The
 * claim is a short, locked read-modify-write of the shared deficit counters — the part
 * that must be atomic across workers. Dispatching is the slow part (a database write,
 * a bridge call, an LLM round trip) and happens **after** the lock is released, one
 * plan entry at a time. Holding the fairness lock across a bridge call would turn the
 * scheduler into the platform's bottleneck and, worse, make one slow tenant delay
 * everybody's *scheduling* as well as their sending.
 *
 * A tenant appears in `sequence` once per unit it was granted, interleaved with the
 * others — `[a, b, c, a, b, a]` for weights 3 / 2 / 1 — so a caller that walks the
 * sequence naturally alternates tenants instead of emptying one lane before starting
 * the next.
 */
final readonly class DispatchPlan
{
    /**
     * @param  list<string>  $sequence  tenant ids, one entry per granted unit
     * @param  int  $rounds  crediting rounds it took to fill the plan
     */
    public function __construct(
        public array $sequence,
        public int $rounds,
    ) {}

    public static function empty(): self
    {
        return new self([], 0);
    }

    public function isEmpty(): bool
    {
        return $this->sequence === [];
    }

    /**
     * Units granted in total.
     */
    public function size(): int
    {
        return count($this->sequence);
    }

    /**
     * The tenant at the head of the plan, or `null` for an empty plan.
     */
    public function first(): ?string
    {
        return $this->sequence[0] ?? null;
    }
}
