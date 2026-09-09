<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Models\Plan;
use App\Services\Billing\PlanRepository;
use Database\Seeders\PlanSeeder;

/*
|--------------------------------------------------------------------------
| The starter catalogue (Req 25.1 / D2)
|--------------------------------------------------------------------------
| Every environment needs a plan before a tenant can be provisioned onto one, so
| the seeder is part of the contract task 1.2 relies on — including its being safe
| to re-run on every deploy.
*/

it('seeds a sellable catalogue', function (): void {
    thisTest()->seed(PlanSeeder::class);

    expect(Plan::query()->active()->ordered()->pluck('slug')->all())
        ->toBe(['starter', 'growth', 'scale']);
});

it('is idempotent, so re-running it on deploy updates rather than duplicates', function (): void {
    thisTest()->seed(PlanSeeder::class);
    $before = Plan::query()->orderBy('slug')->pluck('id', 'slug')->all();

    thisTest()->seed(PlanSeeder::class);

    expect(Plan::query()->count())->toBe(3)
        ->and(Plan::query()->orderBy('slug')->pluck('id', 'slug')->all())->toBe($before);
});

it('seeds the plan new tenants are provisioned onto', function (): void {
    thisTest()->seed(PlanSeeder::class);

    $default = app(PlanRepository::class)->defaultPlan();

    expect($default?->slug)->toBe((string) config('wa.tenancy.default_plan_slug'))
        ->and($default?->slug)->toBe('starter')
        ->and($default?->price_cents)->toBe(0);
});

it('prices every quota kind on every seeded plan', function (): void {
    thisTest()->seed(PlanSeeder::class);

    foreach (Plan::query()->get() as $plan) {
        expect($plan->quotaLimits()->undeclared())->toBe([], sprintf(
            'Plan "%s" leaves a quota kind unpriced, which grants nothing for it.',
            $plan->slug,
        ));
    }
});

it('grades features and limits up the tiers, including an unlimited one', function (): void {
    thisTest()->seed(PlanSeeder::class);

    $starter = Plan::query()->where('slug', 'starter')->firstOrFail();
    $scale = Plan::query()->where('slug', 'scale')->firstOrFail();

    expect($starter->allows('ai'))->toBeFalse()
        ->and($starter->limitFor(QuotaKind::AiCredits))->toBe(0)
        ->and($starter->limitFor(QuotaKind::Sessions))->toBe(1)
        ->and($scale->allows('ai'))->toBeTrue()
        ->and($scale->allows('white_label'))->toBeTrue()
        ->and($scale->isUnlimited(QuotaKind::MessagesMonthly))->toBeTrue()
        // Unlimited monthly, but the daily ceiling stays finite: it is the anti-ban
        // bound, not a price lever.
        ->and($scale->limitFor(QuotaKind::MessagesDaily))->toBe(20_000);
});
