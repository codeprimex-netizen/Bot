<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Tenant;

/**
 * What one call to the fair scheduler actually did (Req 1.7 / A1; Req 30.2, 30.6 / NFR1).
 *
 * Returned rather than logged, because the interesting numbers are the ones a caller
 * (or a test, or task 33.x's metrics) wants to assert on: how many units each tenant
 * got, who was skipped because it could not be dispatched right now, whose backlog ran
 * dry, and how many crediting rounds it took. Correctness Property 19 is stated
 * directly in terms of `dispatched`.
 */
final readonly class DispatchRoundResult
{
    /**
     * @param  array<string, int>  $dispatched  tenant id → units dispatched, in claim order
     * @param  list<string>  $skipped  tenant ids the eligibility gate refused this window
     * @param  list<string>  $drained  tenant ids whose dispatch closure reported no work left
     * @param  int  $rounds  crediting rounds performed
     * @param  int  $budget  units the caller allowed
     */
    public function __construct(
        public array $dispatched,
        public array $skipped,
        public array $drained,
        public int $rounds,
        public int $budget,
    ) {}

    public static function nothing(int $budget = 0): self
    {
        return new self([], [], [], 0, $budget);
    }

    /**
     * Units dispatched across every tenant.
     */
    public function total(): int
    {
        return array_sum($this->dispatched);
    }

    public function unitsFor(Tenant|string $tenant): int
    {
        return $this->dispatched[self::idOf($tenant)] ?? 0;
    }

    /**
     * This tenant's fraction of the window, `0.0` when nothing was dispatched at all.
     *
     * The left-hand side of Property 19: compared against `lane_weight / Σ lane_weight`
     * it is the fairness measurement itself.
     */
    public function shareOf(Tenant|string $tenant): float
    {
        $total = $this->total();

        return $total === 0 ? 0.0 : $this->unitsFor($tenant) / $total;
    }

    public function wasSkipped(Tenant|string $tenant): bool
    {
        return in_array(self::idOf($tenant), $this->skipped, true);
    }

    public function wasDrained(Tenant|string $tenant): bool
    {
        return in_array(self::idOf($tenant), $this->drained, true);
    }

    /**
     * Tenants that got at least one unit.
     *
     * @return list<string>
     */
    public function servedTenantIds(): array
    {
        return array_keys(array_filter($this->dispatched, static fn (int $units): bool => $units > 0));
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    private static function idOf(Tenant|string $tenant): string
    {
        return $tenant instanceof Tenant ? $tenant->id : $tenant;
    }
}
