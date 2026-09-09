<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

/**
 * The deficit counters of one queue lane, plus the rotation cursor — the whole memory
 * of the weighted-fair scheduler (Req 1.7 / A1; Req 30.2 / NFR1).
 *
 * Mutable by design: a claim is a read-modify-write, and this is the "modify" half.
 * `DeficitLedger::mutate()` hands one of these to the scheduler inside the lane's lock
 * and persists it afterwards, so nothing outside that closure ever mutates live credits.
 *
 * ## What a credit is
 *
 * One credit buys one unit of dispatched work. A tenant is credited
 * `lane_weight × quantum` at the start of every round it is active in, spends one credit
 * per unit, and carries what it does not spend into the next round (capped — see
 * `DeficitRoundRobinScheduler`). Credits are therefore always ≥ 0 and a tenant with no
 * entry simply has zero, which is why `set()` deletes rather than stores a zero: the map
 * stays proportional to the number of *backlogged* tenants, not to the number of tenants
 * that have ever been dispatched.
 *
 * ## The cursor
 *
 * The tenant served last. The next claim starts at the tenant *after* it, so when a
 * window is too small to reach every lane the advantage of being early in the order
 * rotates instead of always falling to the same tenant. Shared through the ledger, so it
 * rotates across workers too.
 *
 * ## Soft state
 *
 * These numbers are an optimisation of fairness, never a record of work: no message, no
 * quota, and no billing figure is derived from them. Losing them (a cache flush, a TTL
 * expiry, a new Redis) restarts every lane from zero credit, which is a *fair* starting
 * point — it costs at most one round of proportionality, and no work is lost or
 * duplicated, because the work itself lives in the queue tables.
 */
final class LaneCredits
{
    /**
     * @param  array<string, int>  $credits  tenant id → unspent credits
     */
    private function __construct(
        private array $credits,
        private ?string $cursor,
    ) {}

    public static function empty(): self
    {
        return new self([], null);
    }

    /**
     * Re-type whatever came back out of the cache store.
     *
     * Anything unrecognisable is treated as an empty ledger rather than an error: the
     * state is soft, and a scheduler that refuses to dispatch because it cannot parse
     * its own counters would be a far worse failure than one that starts a fresh round.
     */
    public static function fromArray(mixed $state): self
    {
        if (! is_array($state)) {
            return self::empty();
        }

        $credits = [];
        $stored = $state['credits'] ?? null;

        if (is_array($stored)) {
            foreach ($stored as $tenantId => $credit) {
                if (is_string($tenantId) && $tenantId !== '' && is_numeric($credit) && (int) $credit > 0) {
                    $credits[$tenantId] = (int) $credit;
                }
            }
        }

        $cursor = $state['cursor'] ?? null;

        return new self($credits, is_string($cursor) && $cursor !== '' ? $cursor : null);
    }

    /**
     * @return array{credits: array<string, int>, cursor: string|null}
     */
    public function toArray(): array
    {
        return ['credits' => $this->credits, 'cursor' => $this->cursor];
    }

    public function get(string $tenantId): int
    {
        return $this->credits[$tenantId] ?? 0;
    }

    /**
     * Set a tenant's credit, clamped at zero.
     */
    public function set(string $tenantId, int $credit): void
    {
        if ($credit <= 0) {
            unset($this->credits[$tenantId]);

            return;
        }

        $this->credits[$tenantId] = $credit;
    }

    /**
     * Give a tenant this round's quantum, without letting it exceed `$ceiling`.
     *
     * The ceiling is the noisy-neighbour bound in its most literal form: it is what
     * stops a tenant that has been idle (or rate-capped) for a hundred rounds from
     * arriving with a hundred quanta of credit and taking the next window whole.
     */
    public function credit(string $tenantId, int $quantum, int $ceiling): void
    {
        $this->set($tenantId, min($this->get($tenantId) + $quantum, $ceiling));
    }

    /**
     * Spend one or more credits.
     */
    public function spend(string $tenantId, int $credits): void
    {
        $this->set($tenantId, $this->get($tenantId) - $credits);
    }

    /**
     * Drop a tenant's credit to zero — classic deficit round robin's rule for a lane
     * that turns out to be empty. Without it, a tenant that goes quiet accumulates
     * credit and gets a burst when it returns.
     */
    public function forfeit(string $tenantId): void
    {
        unset($this->credits[$tenantId]);
    }

    /**
     * Forget every tenant not in `$tenantIds` — the same rule as `forfeit()`, applied to
     * the tenants a claim did not even mention, which by definition have no pending work
     * in this lane. Also what keeps the stored ledger bounded by the number of currently
     * backlogged tenants rather than by the size of the platform.
     *
     * @param  list<string>  $tenantIds
     */
    public function retain(array $tenantIds): void
    {
        $keep = array_fill_keys($tenantIds, true);

        foreach (array_keys($this->credits) as $tenantId) {
            if (! array_key_exists($tenantId, $keep)) {
                unset($this->credits[$tenantId]);
            }
        }

        if ($this->cursor !== null && ! array_key_exists($this->cursor, $keep) && $tenantIds !== []) {
            // The cursor points at a tenant that is no longer in the rotation; leaving it
            // would silently disable rotation (nothing matches, so every claim starts at
            // the same lane again).
            $this->cursor = null;
        }
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    /**
     * Remember that this tenant was served last.
     */
    public function advanceCursor(string $tenantId): void
    {
        $this->cursor = $tenantId;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->credits;
    }

    public function isEmpty(): bool
    {
        return $this->credits === [] && $this->cursor === null;
    }

    /**
     * An independent copy, so a read-only peek cannot mutate the ledger.
     */
    public function copy(): self
    {
        return new self($this->credits, $this->cursor);
    }
}
