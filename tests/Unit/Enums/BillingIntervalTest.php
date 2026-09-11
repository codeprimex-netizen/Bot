<?php

declare(strict_types=1);

use App\Enums\BillingInterval;

it('exposes the two intervals the plans table accepts', function (): void {
    expect(BillingInterval::values())->toBe(['MONTH', 'YEAR'])
        ->and(BillingInterval::tryFrom('MONTH'))->toBe(BillingInterval::Month)
        ->and(BillingInterval::tryFrom('WEEK'))->toBeNull();
});

it('describes each interval in whole months', function (): void {
    expect(BillingInterval::Month->months())->toBe(1)
        ->and(BillingInterval::Year->months())->toBe(12);
});

it('labels each interval for pricing tables', function (): void {
    expect(BillingInterval::Month->label())->toBe('Monthly')
        ->and(BillingInterval::Month->shortSuffix())->toBe('mo')
        ->and(BillingInterval::Year->label())->toBe('Yearly')
        ->and(BillingInterval::Year->shortSuffix())->toBe('yr');
});
