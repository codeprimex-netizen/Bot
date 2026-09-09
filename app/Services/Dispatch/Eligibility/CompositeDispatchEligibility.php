<?php

declare(strict_types=1);

namespace App\Services\Dispatch\Eligibility;

use App\Models\Tenant;
use App\Services\Dispatch\DispatchEligibility;

/**
 * Every dispatch gate, ANDed, in order (Req 30.6 / NFR1).
 *
 * This is what the scheduler is actually given. It exists so that a later phase can add
 * a reason to skip a tenant — a quota cap (task 2.3), anti-ban pacing (task 9.6), an
 * in-flight AI concurrency cap (task 36.5) — by appending a class to
 * `wa.dispatch.eligibility.gates`, without the scheduler or any dispatch call site
 * changing.
 *
 * ## Order and short-circuiting
 *
 * Gates are asked in order and the first `false` wins, so the cheapest and most
 * decisive checks belong first. `LifecycleDispatchEligibility` is always first and is
 * **not** read from config: a suspended tenant must never be dispatched, so that gate is
 * mandatory in code where it cannot be configured away, and the config array is purely
 * additive on top of it (`DispatchServiceProvider` assembles the two).
 *
 * ## An empty extras list is the normal state
 *
 * With no configured gates this collapses to "is the tenant suspended?", which is
 * exactly right for the platform as it stands: there is no quota guard and no anti-ban
 * engine to consult yet. That is a complete implementation of a smaller world, not a
 * stub — nothing here pretends to check a cap that does not exist, and nothing needs to
 * be rewritten when one does.
 */
final readonly class CompositeDispatchEligibility implements DispatchEligibility
{
    /**
     * @var list<DispatchEligibility>
     */
    private array $gates;

    /**
     * @param  iterable<array-key, DispatchEligibility>  $gates  applied in order
     */
    public function __construct(iterable $gates)
    {
        $ordered = [];

        foreach ($gates as $gate) {
            $ordered[] = $gate;
        }

        $this->gates = $ordered;
    }

    public function canDispatch(Tenant $tenant): bool
    {
        foreach ($this->gates as $gate) {
            if (! $gate->canDispatch($tenant)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many gates are in play — for the deployment assertions in
     * `DispatchEligibilityTest`, which pin the mandatory suspension gate so it cannot
     * be dropped by a config edit.
     */
    public function count(): int
    {
        return count($this->gates);
    }

    /**
     * The configured chain, in the order it is asked.
     *
     * @return list<class-string<DispatchEligibility>>
     */
    public function gateClasses(): array
    {
        return array_map(static fn (DispatchEligibility $gate): string => $gate::class, $this->gates);
    }
}
