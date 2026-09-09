<?php

declare(strict_types=1);

namespace App\Exceptions\Dispatch;

use App\Services\Dispatch\DispatchEligibility;
use LogicException;

/**
 * `wa.dispatch.eligibility.gates` does not describe a usable set of dispatch gates
 * (Req 30.6 / NFR1).
 *
 * A deployment error, raised when the scheduler's eligibility chain is assembled — at
 * container-resolution time, before any work is dispatched.
 *
 * ## Why an unresolvable gate is fatal
 *
 * `wa.tenancy.resolvers` skips an entry it cannot resolve, because a missing resolver
 * means one fewer door and fails closed. A dispatch gate is the opposite shape: every
 * gate in this list exists to *withhold* work from a tenant — it is at its quota, at its
 * rate cap, at its in-flight AI limit — so skipping one silently removes a cap. The
 * platform would keep running, keep dispatching, and keep looking healthy while doing
 * exactly what Req 30.6 forbids: letting one tenant consume a whole lane. A typo here
 * must stop the worker, not quietly widen a limit.
 *
 * The suspension gate is not in this list at all (see
 * `App\Services\Dispatch\Eligibility\CompositeDispatchEligibility`) — it is applied in
 * code, so no config edit can drop it.
 */
final class InvalidDispatchGateException extends LogicException
{
    public static function notAClass(mixed $entry): self
    {
        return new self(sprintf(
            'wa.dispatch.eligibility.gates contains [%s], which is not a class name. Every '
            .'entry must be a class implementing %s.',
            get_debug_type($entry),
            DispatchEligibility::class,
        ));
    }

    public static function missingClass(string $class): self
    {
        return new self(sprintf(
            'wa.dispatch.eligibility.gates names class [%s], which does not exist.',
            $class,
        ));
    }

    public static function notAGate(string $class): self
    {
        return new self(sprintf(
            'wa.dispatch.eligibility.gates names [%s], which does not implement %s.',
            $class,
            DispatchEligibility::class,
        ));
    }
}
