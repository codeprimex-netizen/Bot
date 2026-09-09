<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use LogicException;

/**
 * The input generator for the Correctness Property 18 property test: random saga
 * *shapes*, and a reproducible schedule of which fault is injected at which step index
 * (Req 15.5 / B6, Req 31.5 / NFR2).
 *
 * ## Why not `fake()` / `random_int()`
 *
 * The same reason `Tests\Fixtures\AuditChainProbe` gives. A property test is only useful
 * if a failure can be replayed, and `random_int()` is an unseedable CSPRNG while seeding
 * `mt_srand()` for `fake()` would also seed the model factories — where
 * `fake()->unique()` starts colliding across iterations and throws before the property is
 * ever evaluated. So every value here is derived from `sha256(seed:draw)`.
 *
 * One seed therefore fixes the whole test: how many sagas, how long each is, which of
 * their steps leave an effect behind, what each contributes to `sagas.state`, which fault
 * kind lands at which index, and which subset of compensations refuses. The seed is
 * printed in every failure message, and setting `SAGA_ATOMICITY_SEED` replays it:
 *
 * ```
 * SAGA_ATOMICITY_SEED=140737488355328 vendor/bin/pest --filter='leaves no orphaned side effect'
 * ```
 *
 * ## The step index is enumerated, the fault kind is drawn
 *
 * Every index of every shape is faulted — a sampled index leaves the interesting one
 * (the first, the last) unexercised on most runs, and a coverage assertion over sampled
 * indices is a flake waiting for a slow afternoon. The *kind* is drawn instead, from a
 * queue that is refilled with a fresh shuffle only once every kind in it has been used,
 * so a run covers every kind while the pairing of kind to index keeps moving.
 */
final class SagaProbe
{
    /**
     * Set this to replay a failed run.
     */
    public const string SEED_ENV = 'SAGA_ATOMICITY_SEED';

    /**
     * Every way a saga run can be interrupted, as the platform can actually be
     * interrupted. All of them except `orphaned_effect` (asserted separately, as the
     * documented boundary of the step contract) must leave Property 18 holding.
     *
     * - `forward_throws` — a dependency refuses; the step leaves nothing behind.
     * - `unrecordable` — the effect lands and its result cannot be recorded, so the step
     *   is settled `DONE` with no handle and must still be undone.
     * - `compensation_some_throw` — the unwind itself partly fails: a drawn proper subset
     *   of the compensations refuses.
     * - `compensation_all_throw` — every compensation refuses; the whole unwind is owed.
     * - `in_flight` — another worker holds the step's key. Not a step failure, and a run
     *   that unwound here would compensate a saga whose next effect is in flight.
     * - `crash_between_steps` — the worker died between two steps; the saga is resumed
     *   from its rows alone and then fails.
     * - `ledger_replay` — the worker died *after* the effect landed and the ledger
     *   recorded it, but before the step row was settled. The forward closure is never
     *   entered again, so the handle the compensation needs can only come from the ledger.
     *
     * @var non-empty-list<string>
     */
    public const array FAULT_KINDS = [
        'forward_throws',
        'unrecordable',
        'compensation_some_throw',
        'compensation_all_throw',
        'in_flight',
        'crash_between_steps',
        'ledger_replay',
    ];

    /**
     * Plausible step names. Only their distinctness matters — a name is half of both
     * idempotency keys and is unique per saga in the database — but real-looking names
     * make a failure message readable.
     *
     * @var non-empty-list<string>
     */
    private const array STEP_NAMES = [
        'reserve_items',
        'create_payment_link',
        'await_payment',
        'charge_wallet',
        'apply_coupon',
        'verify_address',
        'allocate_courier',
        'register_shipment',
        'issue_invoice',
        'notify_customer',
        'close_ticket',
        'release_hold',
    ];

    private int $draws = 0;

    public function __construct(public readonly int $seed) {}

    /**
     * A fresh seed, or the one named by `SAGA_ATOMICITY_SEED` for a replay.
     */
    public static function seeded(): self
    {
        $override = getenv(self::SEED_ENV);

        if (is_string($override) && ctype_digit($override)) {
            return new self((int) $override);
        }

        return new self(random_int(1, 2 ** 48));
    }

    /*
    |--------------------------------------------------------------------------
    | Draws
    |--------------------------------------------------------------------------
    */

    /**
     * An integer in `[$min, $max]`.
     */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + $this->draw() % (($max - $min) + 1);
    }

    public function bool(int $percent = 50): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $values
     * @return TValue
     */
    public function pick(array $values): mixed
    {
        return $values[$this->int(0, count($values) - 1)];
    }

    /**
     * A Fisher-Yates shuffle from the seeded stream.
     *
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return list<TValue>
     */
    public function shuffled(array $values): array
    {
        for ($index = count($values) - 1; $index > 0; $index--) {
            $swap = $this->int(0, $index);
            [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
        }

        return $values;
    }

    /**
     * A non-empty subset of $values, at most `count - $spare` of them — how "one,
     * several, or all of the compensations refuse" is drawn.
     *
     * @param  list<string>  $values
     * @param  int  $spare  how many members must be left out (1 keeps the subset proper)
     * @return list<string>
     */
    public function subsetOf(array $values, int $spare = 0): array
    {
        if ($values === []) {
            return [];
        }

        $limit = max(1, count($values) - max(0, $spare));
        $chosen = array_slice($this->shuffled($values), 0, $this->int(1, $limit));

        // Back into the caller's order: the unwind order is a claim about positions, and a
        // shuffled expectation would make that assertion meaningless.
        return array_values(array_filter($values, static fn (string $value): bool => in_array($value, $chosen, true)));
    }

    /*
    |--------------------------------------------------------------------------
    | Shapes
    |--------------------------------------------------------------------------
    */

    /**
     * A saga of $count steps: distinct names, a drawn mix of compensating and read-only
     * steps, and a drawn state contribution each.
     *
     * At least one step always leaves an effect. A saga of purely read-only steps would
     * satisfy "no orphaned side effect" with nothing to orphan, and an iteration that
     * cannot fail is not evidence.
     *
     * @return list<array{name: string, compensates: bool, contributes: array<string, mixed>}>
     */
    public function shape(int $count): array
    {
        $names = array_slice($this->shuffled(self::STEP_NAMES), 0, max(1, $count));
        $plan = [];

        foreach ($names as $name) {
            $plan[] = [
                'name' => $name,
                'compensates' => $this->bool(70),
                'contributes' => $this->contribution($name),
            ];
        }

        if (array_filter($plan, static fn (array $step): bool => $step['compensates']) === []) {
            $plan[$this->int(0, count($plan) - 1)]['compensates'] = true;
        }

        return $plan;
    }

    /**
     * What one step contributes to `sagas.state`. JSON-safe by construction: the ledger
     * carries it, and an unstorable value is a fault kind of its own rather than an
     * accident of the generator.
     *
     * @return array<string, mixed>
     */
    public function contribution(string $name): array
    {
        $contribution = [
            $name.'_done' => true,
            $name.'_nonce' => $this->int(1, 1_000_000),
        ];

        if ($this->bool(40)) {
            $contribution[$name.'_detail'] = [
                'attempted_at' => $this->int(1_600_000_000, 1_900_000_000),
                'items' => [$this->int(1, 9), 'sku-'.$this->int(100, 999)],
            ];
        }

        return $contribution;
    }

    /*
    |--------------------------------------------------------------------------
    | The fault schedule
    |--------------------------------------------------------------------------
    */

    /**
     * Which fault kind to inject at which index, for each shape: `[$shape][$index]`.
     *
     * Indices are enumerated, not sampled. Kinds come from a queue drained before it is
     * refilled, so every kind is exercised on every run — the test asserts that, and the
     * assertion is a fact about this method rather than a hope about the seed.
     *
     * @param  list<int>  $counts  the step count of each shape
     * @return list<list<string>>
     */
    public function faultSchedule(array $counts): array
    {
        $queue = [];
        $schedule = [];

        foreach ($counts as $count) {
            $kinds = [];

            for ($index = 0; $index < $count; $index++) {
                [$kind, $queue] = $this->nextApplicable($queue, $index);
                $kinds[] = $kind;
            }

            $schedule[] = $kinds;
        }

        return $schedule;
    }

    /**
     * Whether a fault kind can be injected at a step index at all.
     *
     * A compensation cannot refuse where there is nothing yet to compensate, and a saga
     * cannot be resumed mid-flight at its first step. Rather than silently degrading such
     * a pairing into a different fault — which would quietly stop exercising the kind the
     * schedule claims — the schedule skips it and takes the next kind from the queue.
     */
    public static function appliesAt(string $kind, int $index): bool
    {
        return match ($kind) {
            // Needs at least two completed steps, so the refusing subset can be proper.
            'compensation_some_throw' => $index >= 2,
            'compensation_all_throw', 'crash_between_steps' => $index >= 1,
            default => true,
        };
    }

    /**
     * The first kind in the queue that applies at $index, refilling the queue when none
     * of what is left does.
     *
     * @param  list<string>  $queue
     * @return array{0: string, 1: list<string>}
     */
    private function nextApplicable(array $queue, int $index): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            foreach ($queue as $position => $kind) {
                if (self::appliesAt($kind, $index)) {
                    unset($queue[$position]);

                    return [$kind, array_values($queue)];
                }
            }

            $queue = [...$queue, ...$this->shuffled(self::FAULT_KINDS)];
        }

        throw new LogicException('No fault kind applies at step index '.$index.'.');
    }

    /**
     * A 48-bit draw derived from the seed and the draw index.
     */
    private function draw(): int
    {
        $this->draws++;

        return (int) hexdec(substr(hash('sha256', $this->seed.':'.$this->draws), 0, 12));
    }
}
