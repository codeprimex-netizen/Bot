<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning;

use App\Exceptions\Tenancy\InvalidProvisioningStepException;
use Illuminate\Contracts\Container\Container;

/**
 * The ordered provisioning pipeline, resolved from `wa.tenancy.provisioning.steps`
 * (Req 1.8 / A1).
 *
 * This class is the extension mechanism Req 1.8 needs. `TenantLifecycle::provision()`
 * asks it for a list and runs the list; it does not know what a wallet is, and it will
 * not have to when task 10.1 creates one. **Appending a class to that config array is
 * the entire cost of adding a provisioning concern** — see `TenantProvisioningStep`
 * for the two steps Req 1.8 still owes and which task owes each.
 *
 * ## Order is the design, not a detail
 *
 * The list is read in order and applied in order, and two properties depend on that:
 * the tenant record must exist before anything can hang off it, and every step with
 * effects outside the database sits *after* the purely transactional ones, so the
 * likely failures (a duplicate slug, a missing plan) happen before a directory has
 * been created or a key sealed. Compensations run in exact reverse.
 *
 * ## Steps are resolved from the container, freshly, on every run
 *
 * Two reasons, both about safety rather than convenience. Container resolution means a
 * step declares its collaborators in its constructor (`PlanRepository`, `FieldCipher`,
 * `TenantStorage`) instead of reaching for facades, so a step is testable in isolation
 * and a fake can be bound under `tests/`. Fresh instances mean a step cannot carry
 * state — a remembered tenant, a half-built path — from one tenant's provisioning into
 * the next one's, which on a long-lived queue worker would be a cross-tenant leak.
 *
 * ## Every misconfiguration is fatal
 *
 * Unlike `wa.tenancy.resolvers`, which skips an entry it cannot resolve, an
 * unresolvable step here stops provisioning entirely
 * (`InvalidProvisioningStepException`). A skipped step means a tenant created without
 * its plan, its key, or its storage — indistinguishable afterwards from a tenant that
 * legitimately has none. See that exception for the full argument.
 */
final class TenantProvisioningStepRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * The pipeline, in application order.
     *
     * @return list<TenantProvisioningStep>
     *
     * @throws InvalidProvisioningStepException when the configured list is not runnable
     */
    public function steps(): array
    {
        $steps = [];
        $names = [];

        foreach ($this->configuredClasses() as $class) {
            $step = $this->container->make($class);

            if (! $step instanceof TenantProvisioningStep) {
                // Reachable despite the class-level check: a container binding may resolve
                // the name to something else entirely.
                throw InvalidProvisioningStepException::notAStep($class);
            }

            $name = $step->name();

            if (in_array($name, $names, true)) {
                throw InvalidProvisioningStepException::duplicated($name);
            }

            $names[] = $name;
            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * The step names, in order — what the `tenant.provisioned` audit entry records, and
     * what `TenantProvisioningStepRegistryTest` holds to an exact list so a phase that
     * forgets to register its step fails a test rather than shipping a half-provisioned
     * tenant.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn (TenantProvisioningStep $step): string => $step->name(),
            $this->steps(),
        );
    }

    /**
     * The configured class names, validated but not instantiated.
     *
     * @return list<class-string<TenantProvisioningStep>>
     *
     * @throws InvalidProvisioningStepException
     */
    public function classes(): array
    {
        return $this->configuredClasses();
    }

    /**
     * @return list<class-string<TenantProvisioningStep>>
     */
    private function configuredClasses(): array
    {
        $configured = config('wa.tenancy.provisioning.steps');
        $classes = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw InvalidProvisioningStepException::notAClass($entry);
            }

            $class = trim($entry);

            if (! class_exists($class)) {
                throw InvalidProvisioningStepException::missingClass($class);
            }

            if (! is_subclass_of($class, TenantProvisioningStep::class)) {
                throw InvalidProvisioningStepException::notAStep($class);
            }

            /** @var class-string<TenantProvisioningStep> $class */
            $classes[] = $class;
        }

        if ($classes === []) {
            throw InvalidProvisioningStepException::emptyPipeline();
        }

        return $classes;
    }
}
