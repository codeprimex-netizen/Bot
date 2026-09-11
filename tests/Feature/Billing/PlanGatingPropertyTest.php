<?php

declare(strict_types=1);

use App\Enums\PlanFeature;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Billing\PlanGatedPipeline;
use Tests\Fixtures\Billing\PlanGatedStage;
use Tests\Fixtures\Billing\PlanGateProbe;
use Tests\Fixtures\Billing\StageLedger;

/*
|--------------------------------------------------------------------------
| Correctness Property 7 — plan gating
|--------------------------------------------------------------------------
| design.md: *"∀ tenant t, ∀ feature f ∉ t.plan.features → f is inaccessible and its
| pipeline stage is skipped."*
|
| **Validates: Requirements 11.3 / B2, 22.2 / C5**
|
| ## What this adds over the scripted tests
|
| `tests/Feature/Billing/PlanGateTest` pins every fail-closed edge of the gate (no plan,
| unknown key, corrupt JSON, platform mode, a plan edit taking effect) and
| `PlanFeatureGateTest` pins the two declarative surfaces (the `plan.feature` middleware
| and the Gate ability) through real requests. Both are necessary. Neither is generative:
| every plan in them is `['ai' => true]` or `['flows' => true, 'integrations' => false]`,
| chosen by hand, so
|
| 1. they answer for the five or six features somebody remembered, not for the catalogue.
|    A `PlanFeature` case added in Phase B or C is gated by *no* assertion until somebody
|    edits a test — and the failure mode of a missed gate is silence, in both directions;
| 2. they exercise one plan shape at a time: explicit `false` everywhere, never an absent
|    key, never a stray key from an older deploy, never a plan that is still granting its
|    own tenant features after being retired;
| 3. the 402-vs-403 split is asserted against two hand-built catalogues, so "the status is
|    derived from what is *actually* on sale" is checked at two points rather than as a
|    rule; and
| 4. nothing anywhere asserts the second half of the property at all — that a stage behind
|    an unlicensed feature is **never invoked**. That half is the expensive one: a stage
|    that runs and discards its answer has already called the provider.
|
| This file draws the feature set from `PlanFeature::cases()`, the plan, the surrounding
| catalogue, and the pipeline, and asserts both halves for **every** case on every
| iteration. `PlanGateProbe::grantSchedule()` guarantees each case is both granted and
| refused across a run, and `catalogueModes()` guarantees both refusal arms occur; the
| tests assert that coverage rather than hoping for it.
|
| ## Anti-vacuity
|
| A gate that refused everything would satisfy "absent features are refused", and a
| pipeline that ran nothing would satisfy "unlicensed stages do not run". So every
| iteration asserts the positive direction too: granted features are permitted, their
| stages **do** run, the ungated fallback always answers, and the spend ledger matches the
| licensed stages exactly. The mutants used to prove these assertions bite are recorded in
| the task report.
|
| ## Reproducibility
|
| One seed drives every draw and is printed in every failure message:
| `PLAN_GATING_SEED=<seed> vendor/bin/pest --filter='<test name>'`.
|
| Substring assertions are written as `expect(str_contains(...))` rather than with
| `toContain()`, which is variadic — a failure message passed to it silently becomes a
| second needle.
*/

beforeEach(function (): void {
    Cache::flush();
});

function gatingGate(): PlanGate
{
    return app(PlanGate::class);
}

/**
 * `allows()` behind a catch-all, because Property 7 needs it to be **total**.
 *
 * Req 22.2 says a feature not in the plan is *hidden or disabled*, which means a panel
 * asks this question for every feature on the page before rendering anything. If it can
 * throw for a catalogue feature — a tenant with no plan being the obvious way — the
 * screen that was supposed to show a disabled control 500s instead. `authorize()` is the
 * form that throws; this one may not.
 */
function gatingAllows(Tenant $tenant, PlanFeature $feature): bool
{
    try {
        return gatingGate()->allows($tenant, $feature);
    } catch (Throwable $thrown) {
        throw new RuntimeException(sprintf(
            'allows(%s) threw %s ("%s"), but it is the form a panel renders with and must be total.',
            $feature->value,
            $thrown::class,
            $thrown->getMessage(),
        ), 0, $thrown);
    }
}

/**
 * A plan granting exactly $granted, written in one of the shapes the probe draws.
 *
 * @param  list<PlanFeature>  $granted
 */
function gatingPlan(PlanGateProbe $probe, string $slug, array $granted, bool $active, int $sort): Plan
{
    return Plan::factory()->create([
        'slug' => $slug,
        'active' => $active,
        'sort' => $sort,
        'features' => $probe->featureMap($granted),
    ]);
}

/**
 * Clear the catalogue between iterations.
 *
 * The catalogue is platform-wide, so an active plan left behind by iteration 3 would sell
 * features in iteration 4 and turn its 403s into 402s. Deleted through the model so
 * `PlanObserver` bumps the plan cache version; `tenants.plan_id` is `nullOnDelete`, so
 * earlier iterations' tenants are simply left planless.
 */
function gatingResetCatalogue(): void
{
    foreach (Plan::query()->get() as $plan) {
        $plan->delete();
    }

    Cache::flush();
}

/**
 * The stages of a drawn pipeline, wired to one ledger.
 *
 * @param  non-empty-list<array{name: string, feature: PlanFeature|null, terminal: bool, cost: int}>  $specs
 * @return list<PlanGatedStage>
 */
function gatingStages(array $specs, StageLedger $ledger): array
{
    $stages = [];

    foreach ($specs as $spec) {
        $stages[] = new PlanGatedStage($spec['name'], $spec['feature'], $spec['terminal'], $spec['cost'], $ledger);
    }

    return $stages;
}

/**
 * The stages a tenant granted $granted is licensed for, in order, up to and including the
 * one that answers — the expectation the ledger is compared against, computed from the
 * draw and not from anything the pipeline reports.
 *
 * @param  non-empty-list<array{name: string, feature: PlanFeature|null, terminal: bool, cost: int}>  $specs
 * @param  list<PlanFeature>  $granted
 * @return array{invocations: list<string>, spend: int, reply: string|null}
 */
function gatingExpectedRun(array $specs, array $granted): array
{
    $invocations = [];
    $spend = 0;
    $reply = null;

    foreach ($specs as $spec) {
        $feature = $spec['feature'];

        if ($feature !== null && ! in_array($feature, $granted, true)) {
            continue;
        }

        $invocations[] = $spec['name'];
        $spend += $spec['cost'];

        if ($spec['terminal']) {
            $reply = $spec['name'];

            break;
        }
    }

    return ['invocations' => $invocations, 'spend' => $spend, 'reply' => $reply];
}

/**
 * @param  list<PlanFeature>  $features
 */
function gatingKeys(array $features): string
{
    return $features === []
        ? 'nothing'
        : implode(', ', array_map(static fn (PlanFeature $feature): string => $feature->value, $features));
}

/*
|--------------------------------------------------------------------------
| Half one: the feature is inaccessible
|--------------------------------------------------------------------------
*/

it('permits exactly what a plan sells and refuses everything else with the actionable status', function (): void {
    $probe = PlanGateProbe::seeded();
    $iterations = 14;
    $schedule = $probe->grantSchedule($iterations);
    $modes = $probe->catalogueModes($iterations);

    /** @var array<string, int> $permitted */
    $permitted = [];
    /** @var array<string, int> $refused */
    $refused = [];
    $arms = [402 => 0, 403 => 0];
    $platformRefusals = 0;
    $platformGrants = 0;

    foreach (range(1, $iterations) as $iteration) {
        gatingResetCatalogue();

        $granted = $schedule[$iteration - 1];
        $mode = $modes[$iteration - 1];

        // The tenant's own plan is sometimes *inactive*: a plan retired from the pricing
        // page still grants the tenants already on it (`forTenant` looks it up by id),
        // and it must not appear as its own upgrade target.
        $ownPlan = gatingPlan($probe, 'own-'.$iteration.'-'.$probe->letters(5), $granted, $probe->chance(80), 0);
        $tenant = Tenant::factory()->create(['plan_id' => $ownPlan->id]);

        /** @var array<string, list<string>> $sells slugs of active plans granting a feature, in catalogue order */
        $sells = [];

        if ($ownPlan->active) {
            foreach ($granted as $feature) {
                $sells[$feature->value][] = $ownPlan->slug;
            }
        }

        $sort = 10;

        foreach ($probe->upgradeCatalogue($mode, $granted) as $offset => $row) {
            $plan = gatingPlan(
                $probe,
                'catalogue-'.$iteration.'-'.$offset.'-'.$probe->letters(4),
                $row['granted'],
                $row['active'],
                $sort,
            );
            $sort += 10;

            if (! $row['active']) {
                continue;
            }

            foreach ($row['granted'] as $feature) {
                $sells[$feature->value][] = $plan->slug;
            }
        }

        $where = sprintf('seed %d, iteration %d, %s catalogue', $probe->seed, $iteration, $mode);
        $shown = sprintf(
            '%splan (%s, %s) sells: %s%son sale: %s',
            PHP_EOL,
            $ownPlan->slug,
            $ownPlan->active ? 'active' : 'retired',
            gatingKeys($granted),
            PHP_EOL,
            $sells === [] ? 'nothing' : json_encode($sells, JSON_UNESCAPED_SLASHES),
        );

        // Every case, every iteration — never a hardcoded subset, so a feature added in a
        // later phase is gated by this assertion the moment its case exists.
        foreach ($probe->shuffleFeatures(PlanFeature::cases()) as $feature) {
            $isGranted = in_array($feature, $granted, true);

            expect(gatingAllows($tenant, $feature))->toBe($isGranted, sprintf(
                '%s: allows(%s) answered %s.%s',
                $where,
                $feature->value,
                $isGranted ? 'false for a feature the plan grants' : 'true for a feature the plan does not grant',
                $shown,
            ));

            if ($isGranted) {
                $permitted[$feature->value] = ($permitted[$feature->value] ?? 0) + 1;

                // The positive direction, asserted every iteration: without it a gate
                // that refused everything would satisfy this property.
                expect(gatingGate()->denies($tenant, $feature))->toBeFalse(sprintf(
                    '%s: denies(%s) disagreed with allows().%s', $where, $feature->value, $shown,
                ))
                    ->and(gatingGate()->denial($tenant, $feature))->toBeNull(sprintf(
                        '%s: a purchased feature (%s) produced a refusal.%s', $where, $feature->value, $shown,
                    ));

                gatingGate()->authorize($tenant, $feature);

                continue;
            }

            $refused[$feature->value] = ($refused[$feature->value] ?? 0) + 1;

            $upgrades = $sells[$feature->value] ?? [];
            $purchasable = $upgrades !== [];
            $expectedStatus = $purchasable
                ? FeatureNotInPlanException::STATUS_UPGRADE_AVAILABLE
                : FeatureNotInPlanException::STATUS_NOT_AVAILABLE;
            $arms[$expectedStatus]++;

            $denial = gatingGate()->denial($tenant, $feature);

            expect($denial)->toBeInstanceOf(FeatureNotInPlanException::class, sprintf(
                '%s: %s is not in the plan but produced no refusal.%s', $where, $feature->value, $shown,
            ))
                // 402 promises a remedy and 403 promises none, so getting this wrong is
                // either an upgrade CTA that cannot be honoured or a sale not made.
                ->and($denial?->getStatusCode())->toBe($expectedStatus, sprintf(
                    '%s: %s was refused with %d, expected %d — %s.%s',
                    $where,
                    $feature->value,
                    (int) $denial?->getStatusCode(),
                    $expectedStatus,
                    $purchasable ? 'an active plan sells it, so an upgrade is possible' : 'no active plan sells it',
                    $shown,
                ))
                ->and($denial?->upgradeable)->toBe($purchasable, sprintf(
                    '%s: upgradeable disagreed with the catalogue for %s.%s', $where, $feature->value, $shown,
                ))
                // Derived from the live catalogue, in catalogue order: the CTA's targets.
                ->and($denial?->upgradePlans)->toBe($upgrades, sprintf(
                    '%s: the upgrade targets for %s were wrong.%s', $where, $feature->value, $shown,
                ))
                ->and($denial?->errorCode())->toBe(
                    $purchasable ? FeatureNotInPlanException::ERROR_CODE : FeatureNotInPlanException::ERROR_CODE_UNAVAILABLE,
                    sprintf('%s: the machine-readable code for %s did not match the status.%s', $where, $feature->value, $shown),
                )
                ->and(gatingGate()->isPurchasable($feature))->toBe($purchasable, sprintf(
                    '%s: isPurchasable(%s) disagreed with the refusal it drives.%s', $where, $feature->value, $shown,
                ))
                // Naming the feature is what makes the refusal actionable; naming the
                // tenant would leak an internal id into a client-visible sentence.
                ->and(str_contains($denial?->publicMessage() ?? '', $feature->label()))->toBeTrue(sprintf(
                    '%s: the public message for %s did not name the feature: %s',
                    $where,
                    $feature->value,
                    $denial?->publicMessage() ?? '',
                ))
                ->and(str_contains($denial?->publicMessage() ?? '', $tenant->id))->toBeFalse(sprintf(
                    '%s: the public message for %s leaked the tenant id.', $where, $feature->value,
                ))
                // The three shapes must agree: a bool for the panel, a value for the
                // response builder, a throw for the action behind the control.
                ->and(function () use ($tenant, $feature): void {
                    gatingGate()->authorize($tenant, $feature);
                })->toThrow(FeatureNotInPlanException::class);
        }

        // The panel's one-call question has to agree with the per-feature answers, in
        // catalogue order — a menu built from a different list than the actions enforce
        // is either a dead control or an undisclosed feature.
        expect(gatingGate()->granted($tenant))->toBe($granted, sprintf(
            '%s: granted() disagreed with the plan.%s', $where, $shown,
        ));

        /*
        | Platform mode is *data isolation only* (Req 1.5). It must not hand a tenant
        | entitlements: support would otherwise build flows the tenant cannot run, and
        | Property 7's "∀ tenant" claim would hold only outside impersonation.
        */
        $context = app(TenantContext::class);
        $absent = array_values(array_filter(
            PlanFeature::cases(),
            static fn (PlanFeature $feature): bool => ! in_array($feature, $granted, true),
        ));

        if ($absent !== []) {
            $feature = $probe->pick($absent);

            $asPlatform = $context->asPlatform('property review', fn (): bool => gatingAllows($tenant, $feature));
            $impersonating = $context->runFor($tenant, fn (): bool => gatingAllows($tenant, $feature));

            expect($asPlatform)->toBeFalse(sprintf(
                '%s: platform mode granted %s, which the tenant has not bought.%s', $where, $feature->value, $shown,
            ))
                ->and($impersonating)->toBeFalse(sprintf(
                    '%s: impersonation granted %s.%s', $where, $feature->value, $shown,
                ))
                ->and(function () use ($context, $tenant, $feature): void {
                    $context->asPlatform('property review', function () use ($tenant, $feature): void {
                        gatingGate()->authorize($tenant, $feature);
                    });
                })->toThrow(FeatureNotInPlanException::class);

            $platformRefusals++;
        }

        if ($granted !== []) {
            $feature = $probe->pick($granted);

            // ...and it must not take entitlements away either, or an admin's session
            // would show a tenant a smaller product than it pays for.
            expect($context->asPlatform('property review', fn (): bool => gatingAllows($tenant, $feature)))
                ->toBeTrue(sprintf('%s: platform mode refused %s, which the plan sells.%s', $where, $feature->value, $shown));

            $platformGrants++;
        }

        $context->forget();
    }

    /*
    | Coverage, asserted rather than assumed: the schedule is drawn, so these are the
    | clauses that make "every case" true of the run that actually happened.
    */
    foreach (PlanFeature::cases() as $feature) {
        expect($permitted[$feature->value] ?? 0)->toBeGreaterThan(0, sprintf(
            'seed %d: %s was never granted in any iteration, so nothing asserted that it can be bought.',
            $probe->seed,
            $feature->value,
        ))
            ->and($refused[$feature->value] ?? 0)->toBeGreaterThan(0, sprintf(
                'seed %d: %s was never absent in any iteration, so nothing asserted that it is gated.',
                $probe->seed,
                $feature->value,
            ));
    }

    expect($arms[402])->toBeGreaterThan(0, sprintf('seed %d: the 402 (upgrade possible) arm was never exercised.', $probe->seed))
        ->and($arms[403])->toBeGreaterThan(0, sprintf('seed %d: the 403 (not for sale) arm was never exercised.', $probe->seed))
        ->and($platformRefusals)->toBeGreaterThan(0, sprintf('seed %d: platform mode was never asked about an absent feature.', $probe->seed))
        ->and($platformGrants)->toBeGreaterThan(0, sprintf('seed %d: platform mode was never asked about a granted feature.', $probe->seed));
});

/*
|--------------------------------------------------------------------------
| Half two: the pipeline stage is skipped — meaning never invoked
|--------------------------------------------------------------------------
*/

it('never enters a pipeline stage whose feature the plan does not sell, and always enters the ones it does', function (): void {
    $probe = PlanGateProbe::seeded();
    $iterations = 12;
    $schedule = $probe->grantSchedule($iterations);

    /*
    | Two of the iterations have their shape forced, and which two is derived from the
    | draw: the richest plan of the run gets the pipeline where a licensed stage answers
    | (it certainly has one to license), and the poorest gets the one where a gated-out
    | stage falls through to the fallback. Everything else is drawn. Without this the two
    | coverage clauses at the end are a ~7%-per-run flake rather than a guarantee.
    */
    $sizes = array_map(static fn (array $granted): int => count($granted), $schedule);
    $shortCircuitAt = (int) array_search(max($sizes), $sizes, true);
    $fallbackAt = (int) array_search(min($sizes), $sizes, true);

    if ($fallbackAt === $shortCircuitAt) {
        $fallbackAt = $shortCircuitAt === 0 ? 1 : 0;
    }

    $skippedStages = 0;
    $ranStages = 0;
    $fallbackAnswers = 0;
    $shortCircuits = 0;

    foreach (range(1, $iterations) as $iteration) {
        gatingResetCatalogue();

        $granted = $schedule[$iteration - 1];
        $plan = gatingPlan($probe, 'pipeline-'.$iteration.'-'.$probe->letters(5), $granted, true, 0);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        $shape = match ($iteration - 1) {
            $shortCircuitAt => PlanGateProbe::SHAPE_SHORT_CIRCUIT,
            $fallbackAt => PlanGateProbe::SHAPE_FALLBACK,
            default => PlanGateProbe::SHAPE_RANDOM,
        };

        $specs = $probe->stagePlan($granted, $shape);
        $ledger = new StageLedger;
        $pipeline = new PlanGatedPipeline(gatingGate(), gatingStages($specs, $ledger));
        $expected = gatingExpectedRun($specs, $granted);

        $reply = $pipeline->run($tenant);

        $where = sprintf('seed %d, iteration %d', $probe->seed, $iteration);
        $shown = sprintf(
            '%splan sells: %s%spipeline:   %s%sinvoked:    %s',
            PHP_EOL,
            gatingKeys($granted),
            PHP_EOL,
            implode(' → ', array_map(
                static fn (array $spec): string => $spec['name'].($spec['terminal'] ? ' (answers)' : ''),
                $specs,
            )),
            PHP_EOL,
            $ledger->invocations() === [] ? 'nothing' : implode(' → ', $ledger->invocations()),
        );

        // The headline clause, named stage by stage so a failure says which one ran.
        foreach ($specs as $spec) {
            $feature = $spec['feature'];

            if ($feature === null || in_array($feature, $granted, true)) {
                continue;
            }

            $skippedStages++;

            expect(in_array($spec['name'], $ledger->invocations(), true))->toBeFalse(sprintf(
                '%s: %s was entered although the plan does not include %s. A stage that runs and '
                .'discards its answer has already called the provider and spent the money, so '
                .'"skipped" can only mean never invoked.%s',
                $where,
                $spec['name'],
                $feature->value,
                $shown,
            ));
        }

        // ...and the positive direction, which is what stops the whole property from
        // being satisfied by a pipeline that runs nothing at all.
        expect($ledger->invocations())->toBe($expected['invocations'], sprintf(
            '%s: the licensed stages did not run, in order.%s', $where, $shown,
        ))
            // Asserted on spend, not on a returned flag: this is the number a discarded
            // result has already cost.
            ->and($ledger->spendCents())->toBe($expected['spend'], sprintf(
                '%s: the run spent %d, expected %d.%s', $where, $ledger->spendCents(), $expected['spend'], $shown,
            ))
            ->and($reply)->toBe($expected['reply'], sprintf(
                '%s: the wrong stage answered.%s', $where, $shown,
            ));

        $ranStages += count($expected['invocations']);

        if ($expected['reply'] === PlanGatedPipeline::FALLBACK) {
            // Gating decides which stages a tenant gets, never whether it gets an answer.
            $fallbackAnswers++;
        } else {
            $shortCircuits++;
        }
    }

    expect($skippedStages)->toBeGreaterThan(0, sprintf('seed %d: no stage was ever gated out.', $probe->seed))
        ->and($ranStages)->toBeGreaterThan(0, sprintf('seed %d: no stage ever ran, so nothing was proven about skipping.', $probe->seed))
        ->and($fallbackAnswers)->toBeGreaterThan(0, sprintf('seed %d: the ungated fallback never answered.', $probe->seed))
        ->and($shortCircuits)->toBeGreaterThan(0, sprintf('seed %d: a licensed stage never answered before the fallback.', $probe->seed));
});

/*
|--------------------------------------------------------------------------
| A tenant with no plan is gated to nothing — not to everything
|--------------------------------------------------------------------------
*/

it('gates a tenant with no plan to no features at all, and never throws answering', function (): void {
    $probe = PlanGateProbe::seeded();

    foreach (range(1, 8) as $iteration) {
        gatingResetCatalogue();

        // A live catalogue around a tenant that is on none of it: a fresh trial, or a plan
        // retired under it (`tenants.plan_id` is nullOnDelete). "No plan" must read as
        // *bought nothing*, never as *unknown, allow it*.
        $onSale = $probe->subsetOf(PlanFeature::cases());

        if ($onSale === []) {
            // The catalogue has to sell *something*, or the control at the end of the
            // iteration proves nothing.
            $onSale = [$probe->pick(PlanFeature::cases())];
        }

        $seller = gatingPlan($probe, 'seller-'.$iteration.'-'.$probe->letters(5), $onSale, true, 10);
        $planless = Tenant::factory()->create(['plan_id' => null]);
        $subscriber = Tenant::factory()->create(['plan_id' => $seller->id]);

        $where = sprintf('seed %d, iteration %d', $probe->seed, $iteration);
        $shown = sprintf('%son sale: %s', PHP_EOL, gatingKeys($onSale));

        foreach ($probe->shuffleFeatures(PlanFeature::cases()) as $feature) {
            $purchasable = in_array($feature, $onSale, true);

            expect(gatingAllows($planless, $feature))->toBeFalse(sprintf(
                '%s: a tenant with no plan was granted %s.%s', $where, $feature->value, $shown,
            ))
                ->and(gatingGate()->denial($planless, $feature)?->getStatusCode())->toBe(
                    $purchasable
                        ? FeatureNotInPlanException::STATUS_UPGRADE_AVAILABLE
                        : FeatureNotInPlanException::STATUS_NOT_AVAILABLE,
                    sprintf('%s: the refusal of %s for a planless tenant had the wrong status.%s', $where, $feature->value, $shown),
                );
        }

        expect(gatingGate()->granted($planless))->toBe([], sprintf(
            '%s: granted() offered a planless tenant a menu.%s', $where, $shown,
        ));

        // The pipeline: every gated stage skipped, and the ungated fallback still answers,
        // because a tenant with no plan must still get a reply rather than silence.
        $specs = $probe->stagePlan([]);
        $ledger = new StageLedger;
        $reply = (new PlanGatedPipeline(gatingGate(), gatingStages($specs, $ledger)))->run($planless);

        expect($ledger->invocations())->toBe([PlanGatedPipeline::FALLBACK], sprintf(
            '%s: a planless tenant ran %s.%s',
            $where,
            $ledger->invocations() === [] ? 'nothing at all' : implode(' → ', $ledger->invocations()),
            $shown,
        ))
            ->and($reply)->toBe(PlanGatedPipeline::FALLBACK, sprintf(
                '%s: a planless tenant got no answer at all.%s', $where, $shown,
            ));

        // The control: the same pipeline, for a tenant that *is* on the plan. Without it
        // the clause above is satisfied by a pipeline that can only ever run the fallback.
        $subscriberLedger = new StageLedger;
        $subscriberSpecs = $probe->stagePlan($onSale, PlanGateProbe::SHAPE_SHORT_CIRCUIT);
        $subscriberReply = (new PlanGatedPipeline(gatingGate(), gatingStages($subscriberSpecs, $subscriberLedger)))
            ->run($subscriber);

        expect($subscriberLedger->invocations())->toBe(
            gatingExpectedRun($subscriberSpecs, $onSale)['invocations'],
            sprintf('%s: the subscriber’s licensed stages did not run.%s', $where, $shown),
        )
            ->and($subscriberReply)->not->toBe(PlanGatedPipeline::FALLBACK, sprintf(
                '%s: a licensed stage never answered for the subscriber, so nothing here proves the '
                .'planless tenant’s empty run was caused by the plan.%s',
                $where,
                $shown,
            ));
    }
});
