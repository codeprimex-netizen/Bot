<?php

declare(strict_types=1);

namespace App\Services\Dispatch\Eligibility;

use App\Enums\QuotaKind;
use App\Models\Tenant;
use App\Services\Dispatch\DispatchEligibility;
use App\Services\Tenancy\QuotaGuard;
use Throwable;

/**
 * The quota gate: a tenant with no allowance left is stepped over in the dispatch loop
 * rather than granted a share it would only waste (Req 3.4 / A3; Req 30.6 / NFR1;
 * design.md §"Noisy-neighbor prevention" → quota-aware dispatch).
 *
 * This is the gate `DispatchEligibility` reserved for task 2.3, and it is configured in
 * `wa.dispatch.eligibility.gates` like every other one — the scheduler does not know it
 * exists.
 *
 * ## Why the send gate is not enough
 *
 * Algorithm 3 refuses an over-quota send anyway, so nothing gets out either way. The
 * difference is what the platform spends finding that out: without this gate, a tenant
 * that exhausted its monthly allowance on the 3rd still wins its full weighted share of
 * every window for the rest of the month, and every granted unit travels through a
 * worker, a job, a plan lookup and a quota check only to be released again. That is
 * precisely the share of the platform Req 30.6 exists to keep for the tenants that can
 * use it. Skipping here hands those units to them.
 *
 * ## Reads, never spends
 *
 * The scheduler asks **speculatively** — about tenants it may not dispatch, several times
 * a second — so this gate may only ever call `QuotaGuard::verdict()`, which writes
 * nothing and reserves nothing. Calling `consume()` here would bill a tenant for work
 * that was merely *considered*, and would make the counter depend on how often the
 * scheduler happened to run. `QuotaGuardTest` pins that: the gate leaves `tenant_usage`
 * untouched.
 *
 * ## What counts as "capped"
 *
 * Every kind in `wa.dispatch.eligibility.quota.kinds` must allow one more unit. Both
 * non-allowing outcomes skip, for different reasons that are both correct here:
 *
 * - **defer** (the period is spent) clears by itself when the period rolls, which is the
 *   textbook transient skip;
 * - **block** (nothing priced, no plan, a gauge at capacity) clears by an operator or
 *   tenant action — an upgrade, a deletion. `DispatchEligibility` explicitly admits that
 *   class of reason, and the alternative is worse: dispatching a tenant whose every send
 *   the gate will refuse.
 *
 * The list is config, not code, because which quotas *pace dispatch* is an operational
 * question: message counters do, `AI_CREDITS` deliberately does not (an exhausted AI
 * allowance must not stop a tenant's plain outbound messages — task 36.5 adds the
 * AI-specific gate), and the gauges do not either, since they cap *creation* rather than
 * throughput.
 *
 * Fails **closed**: an unreadable plan or an unavailable database means "skip this
 * window", never "send anyway".
 */
final readonly class QuotaDispatchEligibility implements DispatchEligibility
{
    /**
     * Quotas that pace dispatch when the config key is absent altogether.
     *
     * An explicit empty list is honoured as "do not pace dispatch by quota" — the send
     * gate still enforces every kind, so that is a throughput decision, not a hole.
     *
     * @var list<QuotaKind>
     */
    private const array DEFAULT_KINDS = [QuotaKind::MessagesMonthly, QuotaKind::MessagesDaily];

    public function __construct(private QuotaGuard $quota) {}

    public function canDispatch(Tenant $tenant): bool
    {
        foreach ($this->kinds() as $kind) {
            try {
                if (! $this->quota->verdict($tenant, $kind)->isAllowed()) {
                    return false;
                }
            } catch (Throwable) {
                // Fail closed, exactly like the lifecycle gate: a cap that could not be
                // checked is treated as reached. The work stays queued and the next window
                // asks again.
                return false;
            }
        }

        return true;
    }

    /**
     * The quota kinds this gate consults, in order.
     *
     * An entry that is not a `QuotaKind` value is ignored rather than fatal — unlike a
     * missing *gate* class, which `DispatchServiceProvider` refuses. The distinction is
     * deliberate: a typo'd gate class silently removes a whole cap, while a typo'd kind
     * here can only ever make this gate check *less* than intended, and the send gate
     * still enforces every kind. Enumerating a bad name would otherwise stall dispatch
     * platform-wide on a config typo.
     *
     * @return list<QuotaKind>
     */
    public function kinds(): array
    {
        $configured = config('wa.dispatch.eligibility.quota.kinds');

        if (! is_array($configured)) {
            return self::DEFAULT_KINDS;
        }

        $kinds = [];

        foreach ($configured as $entry) {
            if ($entry instanceof QuotaKind) {
                $kinds[$entry->value] = $entry;

                continue;
            }

            $kind = is_string($entry) ? QuotaKind::tryFrom(trim($entry)) : null;

            if ($kind !== null) {
                $kinds[$kind->value] = $kind;
            }
        }

        return array_values($kinds);
    }
}
