<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Models\Tenant;
use App\Services\Tenancy\QuotaGuard;
use Tests\Fixtures\Quota;

/*
|--------------------------------------------------------------------------
| Correctness Property 4 — quota never negative, never double-counted
|--------------------------------------------------------------------------
| design.md: *"∀ tenant t, ∀ retry of a send job → `tenant_usage.used` increases by
| exactly the number of messages the bridge confirmed, and never exceeds `limit`
| (except explicit plan overage)."*
|
| The generator is the retry itself. A send pipeline does not fail cleanly: it confirms
| a send and then dies before acknowledging, is retried, confirms nothing new, and calls
| `consume()` again with the same message key. So each scenario below builds a random
| set of confirmed sends, then replays a random interleaving of them — the same key
| appearing anywhere from once to several times, in any order — and asserts the counter
| against a straight-line simulation of what *should* have been counted.
|
| These properties are the reason `consume()` takes the idempotency key as a required
| argument: with an optional one, every assertion here would still pass for the caller
| that remembered it and silently fail for the one that did not.
*/

it('increases used by exactly the confirmed units, however the retries interleave', function (): void {
    foreach (range(1, 25) as $iteration) {
        $sends = Quota::sends(random_int(1, 8));

        // A ceiling generous enough that nothing is capped: this iteration is about the
        // *exactness* half of the property.
        $limit = array_sum($sends) + random_int(0, 20);
        $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => $limit]);
        $guard = Quota::guard();

        foreach (Quota::attempts($sends) as $key) {
            $guard->consume($tenant, QuotaKind::MessagesMonthly, $key, $sends[$key]);
        }

        expect(Quota::used($tenant, QuotaKind::MessagesMonthly))->toBe(
            array_sum($sends),
            sprintf('iteration %d: counted %d units for %d sends', $iteration, Quota::used($tenant, QuotaKind::MessagesMonthly), count($sends)),
        )
            // One ledger entry per unit of work, not per attempt.
            ->and(Quota::ledgerCount($tenant, QuotaKind::MessagesMonthly))->toBe(count($sends));
    }
})->group('property');

it('never pushes a counter past the limit and never below zero', function (): void {
    foreach (range(1, 25) as $iteration) {
        $sends = Quota::sends(random_int(2, 10));

        // A ceiling deliberately below the total, so the cap binds part-way through.
        $limit = max(1, (int) floor(array_sum($sends) * (random_int(1, 7) / 10)));
        $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => $limit]);
        $guard = Quota::guard();

        // What the counter should be: each *distinct* key applies what still fits.
        $expected = 0;
        $counted = [];

        foreach (Quota::attempts($sends) as $key) {
            $guard->consume($tenant, QuotaKind::MessagesMonthly, $key, $sends[$key]);

            if (! array_key_exists($key, $counted)) {
                $counted[$key] = true;
                $expected = min($limit, $expected + $sends[$key]);
            }
        }

        $used = Quota::used($tenant, QuotaKind::MessagesMonthly);

        expect($used)->toBe($expected, sprintf('iteration %d: limit %d', $iteration, $limit))
            ->and($used)->toBeLessThanOrEqual($limit)
            ->and($used)->toBeGreaterThanOrEqual(0);
    }
})->group('property');

it('counts every confirmed unit exactly once when the plan sells overage', function (): void {
    foreach (range(1, 15) as $iteration) {
        $sends = Quota::sends(random_int(2, 8));

        // Overage is the documented exception to "never exceeds limit": the units are all
        // recorded, so the tenant is billed for exactly what it sent — and still never
        // for a retry.
        $tenant = Quota::tenant(
            [QuotaKind::MessagesMonthly->value => 1],
            [QuotaGuard::OVERAGE_FEATURE => true],
        );
        $guard = Quota::guard();

        foreach (Quota::attempts($sends) as $key) {
            $guard->consume($tenant, QuotaKind::MessagesMonthly, $key, $sends[$key]);
        }

        expect(Quota::used($tenant, QuotaKind::MessagesMonthly))
            ->toBe(array_sum($sends), sprintf('iteration %d', $iteration));
    }
})->group('property');

it('counts every confirmed unit exactly once when the plan is unlimited', function (): void {
    foreach (range(1, 15) as $iteration) {
        $sends = Quota::sends(random_int(2, 8));
        $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => null]);
        $guard = Quota::guard();

        foreach (Quota::attempts($sends) as $key) {
            $guard->consume($tenant, QuotaKind::MessagesMonthly, $key, $sends[$key]);
        }

        expect(Quota::used($tenant, QuotaKind::MessagesMonthly))
            ->toBe(array_sum($sends), sprintf('iteration %d', $iteration));
    }
})->group('property');

it('keeps every tenant\'s counter independent under interleaved retries', function (): void {
    foreach (range(1, 10) as $iteration) {
        $tenants = [];
        $sends = [];

        foreach (range(1, random_int(2, 4)) as $index) {
            $tenant = Quota::tenant([QuotaKind::MessagesMonthly->value => null]);
            $tenants[$tenant->id] = $tenant;
            // The *same* message keys on every tenant: dedup must be per tenant.
            $sends[$tenant->id] = Quota::sends(random_int(1, 5));
        }

        $guard = Quota::guard();
        $attempts = [];

        foreach ($sends as $tenantId => $tenantSends) {
            foreach (Quota::attempts($tenantSends) as $key) {
                $attempts[] = [$tenantId, $key];
            }
        }

        shuffle($attempts);

        foreach ($attempts as [$tenantId, $key]) {
            /** @var Tenant $tenant */
            $tenant = $tenants[$tenantId];
            $guard->consume($tenant, QuotaKind::MessagesMonthly, $key, $sends[$tenantId][$key]);
        }

        foreach ($tenants as $tenantId => $tenant) {
            expect(Quota::used($tenant, QuotaKind::MessagesMonthly))
                ->toBe(array_sum($sends[$tenantId]), sprintf('iteration %d: tenant %s', $iteration, $tenantId));
        }
    }
})->group('property');

it('never lets a gauge recount go negative, whatever sequence it is given', function (): void {
    foreach (range(1, 20) as $iteration) {
        $tenant = Quota::tenant([QuotaKind::Sessions->value => random_int(1, 10)]);
        $guard = Quota::guard();
        $last = 0;

        foreach (range(1, random_int(1, 12)) as $ignored) {
            $last = random_int(0, 15);
            $guard->observe($tenant, QuotaKind::Sessions, $last);
        }

        // A gauge is *set*, so the counter is the last measurement — never a sum, never
        // negative, and never spread over more than the one standing bucket.
        expect(Quota::used($tenant, QuotaKind::Sessions))->toBe($last, sprintf('iteration %d', $iteration))
            ->and(Quota::used($tenant, QuotaKind::Sessions))->toBeGreaterThanOrEqual(0)
            ->and(Quota::bucket($tenant, QuotaKind::Sessions)?->period_key)->toBe(QuotaKind::GAUGE_PERIOD_KEY);
    }
})->group('property');
