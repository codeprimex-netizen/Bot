<?php

declare(strict_types=1);

namespace Tests\Fixtures\Billing;

use App\Enums\PlanFeature;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The input generator for Correctness Property 7 — plan gating (Req 11.3 / B2,
 * Req 22.2 / C5).
 *
 * `tests/Feature/Billing/PlanGateTest` and `PlanFeatureGateTest` already pin the gate
 * against *chosen* plans: `['ai' => true]`, a `growth` plan that sells `ai`, a route
 * that names `flows,integrations`. Both are necessary and neither is generative — every
 * plan in them is a plan somebody wrote, so they can only fail for a reason somebody
 * already thought of, and a feature added in Phase B or C is covered by exactly none of
 * them until somebody remembers to add a case.
 *
 * So this class draws the *whole shape of the question* instead:
 *
 * - **The feature set**, from `PlanFeature::cases()` — never a hardcoded subset. A case
 *   added later is drawn automatically, and `grantSchedule()` guarantees every case is
 *   both granted and refused at least once across a run, so a new feature cannot slip
 *   through untested.
 * - **The plan**, as a random subset of the catalogue, written sometimes as an explicit
 *   `false` and sometimes as an *absent* key — the two shapes `PlanFeatures` documents
 *   as identical, and a difference between them would be a silent grant.
 * - **The catalogue around the tenant**, because it is the catalogue that decides
 *   whether a refusal is a **402** ("upgrade is possible") or a **403** ("not for
 *   sale"). `catalogueModes()` guarantees a run contains at least one iteration where
 *   nothing on sale grants the missing feature and at least one where something does,
 *   so both arms of that deliberate design split are genuinely exercised rather than
 *   left to chance.
 * - **The pipeline**, as three to six feature-gated stages in a drawn order plus the
 *   ungated fallback (`stagePlan()`), so "its pipeline stage is skipped" is asserted
 *   against a shape nobody hand-picked.
 *
 * ## Reproducibility
 *
 * Every draw comes from one seeded engine, and the seed is printed in every failure
 * message, so a failure replays exactly:
 *
 * ```
 * PLAN_GATING_SEED=<seed> vendor/bin/pest --filter='<test name>'
 * ```
 *
 * Plan names and tenant slugs still come from the unseeded factory faker: nothing here
 * depends on them, so a replay is identical in every respect the property asserts.
 */
final class PlanGateProbe
{
    /**
     * Set this to replay a failed run.
     */
    public const string SEED_ENV = 'PLAN_GATING_SEED';

    /**
     * No **active** plan grants anything the tenant lacks — every refusal must be a
     * 403, because an upgrade CTA would be a lie. A retired plan that *did* sell the
     * feature is the realistic shape of this arm, so one is drawn.
     */
    public const string SELLS_NOTHING = 'sells-nothing';

    /**
     * At least one active plan grants every catalogue feature — every refusal must be a
     * 402, because paying really does fix it.
     */
    public const string SELLS_EVERYTHING = 'sells-everything';

    /**
     * A catalogue of random subsets: both arms, decided feature by feature.
     */
    public const string MIXED = 'mixed';

    private readonly Randomizer $rng;

    private function __construct(public readonly int $seed)
    {
        $this->rng = new Randomizer(new Mt19937($seed));
    }

    /**
     * A probe seeded from the environment when replaying, or freshly at random.
     */
    public static function seeded(): self
    {
        $configured = getenv(self::SEED_ENV);

        return new self(
            is_string($configured) && $configured !== '' && ctype_digit($configured)
                ? (int) $configured
                : random_int(1, PHP_INT_MAX),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Draws
    |--------------------------------------------------------------------------
    */

    public function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->rng->getInt(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $items
     * @return TValue
     */
    public function pick(array $items): mixed
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    public function letters(int $count): string
    {
        $letters = '';

        for ($index = 0; $index < $count; $index++) {
            $letters .= chr($this->int(97, 122));
        }

        return $letters;
    }

    /*
    |--------------------------------------------------------------------------
    | The feature set
    |--------------------------------------------------------------------------
    */

    /**
     * What the tenant's plan grants in each iteration, in catalogue order.
     *
     * Each feature's row is drawn independently at ~50%, and then **repaired** so that
     * it holds at least one grant and at least one refusal: a property test whose
     * coverage is left to chance either misses a feature on an unlucky seed or grows a
     * coverage assertion that flakes on a slow afternoon. Repairing the schedule keeps
     * both the randomness and the guarantee, and the repair is itself seeded.
     *
     * @return list<list<PlanFeature>>
     */
    public function grantSchedule(int $iterations): array
    {
        if ($iterations < 2) {
            throw new \InvalidArgumentException('A schedule that grants and refuses every feature needs at least two iterations.');
        }

        /** @var list<list<PlanFeature>> $granted */
        $granted = array_fill(0, $iterations, []);

        foreach (PlanFeature::cases() as $feature) {
            $row = [];

            for ($index = 0; $index < $iterations; $index++) {
                $row[$index] = $this->chance(50);
            }

            if (! in_array(true, $row, true)) {
                $row[$this->int(0, $iterations - 1)] = true;
            }

            if (! in_array(false, $row, true)) {
                $row[$this->int(0, $iterations - 1)] = false;
            }

            foreach ($row as $index => $isGranted) {
                if ($isGranted) {
                    $granted[$index][] = $feature;
                }
            }
        }

        return $this->withSomethingAlwaysMissing($granted);
    }

    /**
     * The same schedule, guaranteed to leave **at least one feature absent** in every
     * iteration.
     *
     * Every clause about a refusal — the 402/403 split, the platform-mode bypass, a
     * skipped stage — needs a feature the tenant does not have. An iteration that drew
     * the entire catalogue would exercise none of them, and a coverage assertion that
     * merely *hoped* no iteration did that is a flake with a 1-in-131,072 fuse.
     *
     * The grant that is dropped is only ever taken from a feature that is granted in
     * another iteration too, so the "every case is granted somewhere" half of the
     * guarantee survives the repair.
     *
     * @param  list<list<PlanFeature>>  $granted
     * @return list<list<PlanFeature>>
     */
    private function withSomethingAlwaysMissing(array $granted): array
    {
        $total = count(PlanFeature::cases());
        $grantsPerFeature = [];

        foreach ($granted as $row) {
            foreach ($row as $feature) {
                $grantsPerFeature[$feature->value] = ($grantsPerFeature[$feature->value] ?? 0) + 1;
            }
        }

        foreach ($granted as $index => $row) {
            if (count($row) < $total) {
                continue;
            }

            $droppable = array_values(array_filter(
                $row,
                static fn (PlanFeature $feature): bool => ($grantsPerFeature[$feature->value] ?? 0) > 1,
            ));

            if ($droppable === []) {
                continue;
            }

            $dropped = $this->pick($droppable);
            $grantsPerFeature[$dropped->value]--;
            $granted[$index] = array_values(array_filter(
                $row,
                static fn (PlanFeature $feature): bool => $feature !== $dropped,
            ));
        }

        return $granted;
    }

    /**
     * The catalogue shape for each iteration, guaranteeing that both the 402 and the
     * 403 arm occur, in a seed-shuffled order so neither is pinned to iteration 1.
     *
     * @return list<string>
     */
    public function catalogueModes(int $iterations): array
    {
        if ($iterations < 2) {
            throw new \InvalidArgumentException('Both refusal arms need at least two iterations.');
        }

        $modes = [self::SELLS_NOTHING, self::SELLS_EVERYTHING];

        while (count($modes) < $iterations) {
            $modes[] = $this->pick([self::MIXED, self::MIXED, self::SELLS_NOTHING, self::SELLS_EVERYTHING]);
        }

        /** @var list<string> $shuffled */
        $shuffled = $this->rng->shuffleArray($modes);

        return $shuffled;
    }

    /**
     * A `plans.features` map granting exactly $granted.
     *
     * Everything else is written as an explicit `false` or left out altogether, drawn
     * per feature: `PlanFeatures` documents absence and `false` as the same answer, and
     * a gate that treated a missing key as "unknown, allow it" would pass a test that
     * only ever wrote explicit flags. A stray key from an older deploy is planted
     * sometimes too — storage is permissive, but a key that is not in the catalogue
     * must gate nothing.
     *
     * @param  list<PlanFeature>  $granted
     * @return array<string, bool>
     */
    public function featureMap(array $granted): array
    {
        $map = [];

        foreach (PlanFeature::cases() as $feature) {
            if (in_array($feature, $granted, true)) {
                $map[$feature->value] = true;

                continue;
            }

            if ($this->chance(50)) {
                $map[$feature->value] = false;
            }
        }

        if ($this->chance(20)) {
            $map['legacy_'.$this->letters(6)] = true;
        }

        return $map;
    }

    /**
     * The rest of the catalogue for an iteration: what else is on sale, and whether it
     * is still sellable.
     *
     * In `SELLS_NOTHING` mode every **active** row is restricted to features the tenant
     * already has, so no refusal can be an upgrade — that is the invariant the 403 arm
     * needs, and it is enforced here rather than asserted in the test.
     *
     * @param  list<PlanFeature>  $tenantGrants
     * @return non-empty-list<array{granted: list<PlanFeature>, active: bool}>
     */
    public function upgradeCatalogue(string $mode, array $tenantGrants): array
    {
        $rows = [];
        $count = $this->int(1, 3);

        for ($index = 0; $index < $count; $index++) {
            // The 402 arm needs one guaranteed active seller; every other row is drawn.
            $active = $mode === self::SELLS_EVERYTHING && $index === 0 ? true : $this->chance(70);

            $rows[] = [
                'granted' => match ($mode) {
                    self::SELLS_NOTHING => $active ? $this->subsetOf($tenantGrants) : PlanFeature::cases(),
                    self::SELLS_EVERYTHING => $active ? PlanFeature::cases() : $this->subsetOf(PlanFeature::cases()),
                    default => $this->subsetOf(PlanFeature::cases()),
                },
                'active' => $active,
            ];
        }

        return $rows;
    }

    /**
     * A random subset of $features, in the order given.
     *
     * @param  list<PlanFeature>  $features
     * @return list<PlanFeature>
     */
    public function subsetOf(array $features): array
    {
        $subset = [];

        foreach ($features as $feature) {
            if ($this->chance(50)) {
                $subset[] = $feature;
            }
        }

        return $subset;
    }

    /**
     * @param  list<PlanFeature>  $features
     * @return list<PlanFeature>
     */
    public function shuffleFeatures(array $features): array
    {
        /** @var list<PlanFeature> $shuffled */
        $shuffled = $this->rng->shuffleArray($features);

        return $shuffled;
    }

    /*
    |--------------------------------------------------------------------------
    | The pipeline
    |--------------------------------------------------------------------------
    */

    /**
     * A licensed stage answers the message, so the stages behind it are skipped for a
     * reason that is *not* plan gating — the other way "skipped" has to mean "never
     * invoked".
     */
    public const string SHAPE_SHORT_CIRCUIT = 'short-circuit';

    /**
     * No gated stage answers and at least one is gated out, so the ungated fallback is
     * the stage that replies: gating decides which stages a tenant gets, never whether
     * it gets an answer.
     */
    public const string SHAPE_FALLBACK = 'fallback';

    /**
     * Whatever the draw produces.
     */
    public const string SHAPE_RANDOM = 'random';

    /**
     * A resolution pipeline: three to six feature-gated stages over drawn features,
     * plus the **ungated fallback** that must run whatever the plan says (Algorithm 1's
     * `LlmReplyStage` → `FallbackStage` tail).
     *
     * At most one gated stage answers the message. `$shape` forces the two outcomes a
     * run has to contain — a licensed stage answering, and a gated-out stage falling
     * through to the fallback — because leaving both to the draw makes the coverage
     * assertions that guard them flaky rather than true.
     *
     * Each stage carries a `cost`, which the ledger accumulates: the point of the
     * property is that an unlicensed stage never spends it.
     *
     * @param  list<PlanFeature>  $granted  what the tenant's plan sells this iteration
     * @return non-empty-list<array{name: string, feature: PlanFeature|null, terminal: bool, cost: int}>
     */
    public function stagePlan(array $granted, string $shape = self::SHAPE_RANDOM): array
    {
        $features = $this->shuffleFeatures(PlanFeature::cases());
        $count = $this->int(3, 6);
        /** @var list<PlanFeature> $stageFeatures */
        $stageFeatures = array_slice($features, 0, $count);
        $terminalAt = $shape === self::SHAPE_RANDOM && $this->chance(40) ? $this->int(0, $count - 1) : null;

        $absent = array_values(array_filter(
            PlanFeature::cases(),
            static fn (PlanFeature $feature): bool => ! in_array($feature, $granted, true),
        ));

        if ($shape === self::SHAPE_SHORT_CIRCUIT && $granted !== []) {
            $terminalAt = $this->int(0, $count - 1);
            $stageFeatures[$terminalAt] = $this->pick($granted);
        }

        if ($shape === self::SHAPE_FALLBACK && $absent !== []) {
            $stageFeatures[$this->int(0, $count - 1)] = $this->pick($absent);
        }

        $stages = [];

        for ($index = 0; $index < $count; $index++) {
            $feature = $stageFeatures[$index];

            $stages[] = [
                'name' => 'stage-'.$index.'-'.$feature->value,
                'feature' => $feature,
                'terminal' => $index === $terminalAt,
                'cost' => $this->int(1, 99),
            ];
        }

        $stages[] = [
            'name' => PlanGatedPipeline::FALLBACK,
            'feature' => null,
            'terminal' => true,
            'cost' => $this->int(1, 9),
        ];

        return $stages;
    }
}
