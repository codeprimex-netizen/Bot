<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Tenant;
use App\Services\Dispatch\DispatchEligibility;

/**
 * A stand-in for the dispatch gates that do not exist yet — task 9.6's anti-ban pacing,
 * task 36.5's in-flight AI concurrency cap.
 *
 * It exists so the scheduler's *"skip a rate-capped tenant in the loop"* behaviour
 * (Req 30.6 / NFR1) can be tested against a cap that can be raised and lifted at will,
 * from a test that is about scheduling rather than about any particular cap. Bound only
 * under `tests/`, through `wa.dispatch.eligibility.gates` — production ships the real
 * chain (`Eligibility\CompositeDispatchEligibility`): the mandatory suspension gate plus
 * the quota gate task 2.3 added.
 *
 * The capped set is static because the container resolves this gate itself: a test
 * changes the cap without holding a reference to the instance the scheduler was given.
 */
final class FakeRateCapGate implements DispatchEligibility
{
    /**
     * @var array<string, true>
     */
    private static array $capped = [];

    public static function cap(Tenant|string $tenant): void
    {
        self::$capped[self::idOf($tenant)] = true;
    }

    public static function release(Tenant|string $tenant): void
    {
        unset(self::$capped[self::idOf($tenant)]);
    }

    public static function reset(): void
    {
        self::$capped = [];
    }

    public function canDispatch(Tenant $tenant): bool
    {
        return ! array_key_exists($tenant->id, self::$capped);
    }

    private static function idOf(Tenant|string $tenant): string
    {
        return $tenant instanceof Tenant ? $tenant->id : $tenant;
    }
}
