<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\Dispatch\InvalidDispatchGateException;
use App\Services\Dispatch\CacheDeficitLedger;
use App\Services\Dispatch\DeficitLedger;
use App\Services\Dispatch\DeficitRoundRobinScheduler;
use App\Services\Dispatch\DispatchEligibility;
use App\Services\Dispatch\Eligibility\CompositeDispatchEligibility;
use App\Services\Dispatch\Eligibility\LifecycleDispatchEligibility;
use App\Services\Dispatch\FairScheduler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires weighted-fair dispatch: the scheduler, its deficit ledger, and the eligibility
 * chain that decides which tenants are in the rotation (Req 1.7 / A1;
 * Req 30.2, 30.6 / NFR1).
 */
class DispatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons because all three are stateless: the scheduler holds nothing between
        // calls (its memory is the ledger), the ledger holds nothing but the cache factory,
        // and a gate is a pure function of the tenant. One instance per worker process is
        // therefore safe and saves rebuilding the gate chain on every dispatch tick.
        $this->app->singleton(DeficitLedger::class, CacheDeficitLedger::class);

        $this->app->singleton(
            DispatchEligibility::class,
            fn (Application $app): DispatchEligibility => new CompositeDispatchEligibility($this->gates($app)),
        );

        $this->app->singleton(FairScheduler::class, DeficitRoundRobinScheduler::class);
    }

    /**
     * The eligibility chain: the mandatory suspension gate, then whatever
     * `wa.dispatch.eligibility.gates` adds.
     *
     * The lifecycle gate is prepended **in code, not config**, so no deployment can
     * configure away "a suspended tenant is not dispatched" (Req 1.1 / A1). Listing it in
     * config as well is harmless — it is de-duplicated rather than asked twice.
     *
     * @return list<DispatchEligibility>
     *
     * @throws InvalidDispatchGateException when a configured gate is not usable
     */
    private function gates(Application $app): array
    {
        $gates = [$app->make(LifecycleDispatchEligibility::class)];
        $seen = [LifecycleDispatchEligibility::class => true];

        $configured = config('wa.dispatch.eligibility.gates');

        foreach (is_array($configured) ? $configured : [] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw InvalidDispatchGateException::notAClass($entry);
            }

            $class = trim($entry);

            if (! class_exists($class)) {
                throw InvalidDispatchGateException::missingClass($class);
            }

            if (! is_subclass_of($class, DispatchEligibility::class)) {
                throw InvalidDispatchGateException::notAGate($class);
            }

            if (array_key_exists($class, $seen)) {
                continue;
            }

            $seen[$class] = true;
            $gate = $app->make($class);

            if ($gate instanceof DispatchEligibility) {
                $gates[] = $gate;
            }
        }

        return $gates;
    }
}
