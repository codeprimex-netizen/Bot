<?php

declare(strict_types=1);

use App\Enums\SagaStatus;
use App\Enums\SagaStepStatus;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Models\IdempotencyKey;
use App\Models\Saga;
use App\Models\SagaStep;
use App\Models\Tenant;
use App\Services\Reliability\SagaOrchestrator;
use App\Services\Reliability\SagaStepResult;
use App\Services\Tenancy\TenantContext;
use Tests\Fixtures\Reliability\GeneratedSagaDefinition;
use Tests\Fixtures\Reliability\SagaProbe;
use Tests\Fixtures\Reliability\SagaWorld;

/*
|--------------------------------------------------------------------------
| Correctness Property 18 — saga atomicity
|--------------------------------------------------------------------------
| design.md: *"∀ saga run → either all forward steps complete, or every completed step
| is compensated in reverse order, leaving no partial side effect."*
|
| **Validates: Requirements 15.5 / B6, 31.5 / NFR2**
|
| `SagaOrchestratorTest` proves the property by example: a fixed three-step cast, a
| `[[0],[1],[2]]` dataset over the step index, one scripted fault per case. This file
| states it as a property, and the difference is not "more randomness" — it is five
| claims the scripted cases cannot make:
|
|  1. **Any shape.** A one-step saga, a nine-step chain, and a random mix of steps that
|     leave an effect behind with steps that are read-only (`SagaStepResult::none()`).
|     The scripted suite's three compensating steps cannot show that a read-only step is
|     still *invoked* during an unwind, or that a one-step saga has nothing to undo but
|     still reaches a legal terminal state.
|  2. **Any fault, at every index.** Seven fault kinds — a forward refusal, an unstorable
|     result, a partly-failing unwind, a wholly-failing unwind, a key another worker
|     holds, a crash between two steps, and a crash *after* an effect landed and the
|     ledger recorded it. Every index of every shape is faulted; the *kind* is drawn.
|  3. **The third outcome is resumable, and the resume is not a re-run.** Where a
|     compensation refuses, the saga must be left `COMPENSATING` and turn up in the
|     recovery sweep — and once the dependency heals, the retry must finish the unwind
|     *without* re-entering a compensation that already succeeded. That is asserted
|     against `compensation_attempts` and against the world, not against a status column.
|  4. **A duplicate delivery changes nothing.** Every terminal saga is re-run a drawn
|     number of times and nothing may move: no forward action, no compensation, no
|     effect, no attempt counter. This is the clause that catches a duplicate job
|     delivery double-charging a customer.
|  5. **The bookkeeping and the world agree.** A step whose effect is gone is
|     `COMPENSATED`; a step that never ran is `PENDING` and appears in neither the
|     forward nor the compensation record; a `FAILED` step never had an effect.
|
| **"No orphaned side effect" is a claim about the world.** `SagaWorld` holds live
| effects — a forward action `reserve()`s and a compensation `release()`s — so the
| assertion is `liveEffects() === []` rather than a count of `COMPENSATED` rows. That is
| deliberate: the bug this property exists to exclude (design.md's Algorithm 8 passing
| one key to both directions, so `once()` replays the forward result and every unwind
| becomes a silent no-op) leaves the statuses *perfect* and the reservations standing.
| The mutation was applied to `PersistedSagaOrchestrator` while writing this test to
| confirm it fails here; the statuses would have passed it.
|
| **Anti-vacuity.** A test that only ever asserts `liveEffects() === []` also passes
| against an orchestrator that never runs anything. So every shape is first run with no
| fault at all and required to complete with *every* effect standing, in execution order;
| the `in_flight` and `ledger_replay` kinds end in `COMPLETED` with all effects live too;
| and every shape is guaranteed at least one step that leaves an effect.
|
| **Reproducibility.** One seed fixes every draw — shapes, which steps compensate, state
| contributions, the fault schedule, which compensations refuse, how many times a
| terminal saga is re-run. It is printed in every failure message, and
| `SAGA_ATOMICITY_SEED=<seed> vendor/bin/pest --filter='<test name>'` replays it. See
| `Tests\Fixtures\Reliability\SagaProbe` for why the house `fake()`/`random_int()`
| generators could not be used.
*/

beforeEach(function (): void {
    SagaWorld::reset();
    GeneratedSagaDefinition::reset();
});

/**
 * A saga with no step rows — the orchestrator lays them out from the drawn definition.
 */
function atomicitySaga(Tenant $tenant): Saga
{
    return Saga::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => GeneratedSagaDefinition::TYPE,
    ]);
}

/**
 * A saga a worker died in the middle of: its steps are on disk, the first $doneCount of
 * them `DONE` with the handle a real forward action would have recorded, and those
 * effects are standing in `SagaWorld` — seeded rather than reserved, so they count as an
 * earlier process's work and not as a forward call of the run under test.
 */
function atomicityCrashedSaga(Tenant $tenant, int $doneCount): Saga
{
    $saga = atomicitySaga($tenant);

    foreach (GeneratedSagaDefinition::plan() as $position => $step) {
        $done = $position < $doneCount;
        $handle = $step['compensates'] ? ['reservation' => $step['name'].'-handle'] : null;

        if ($done && $handle !== null) {
            SagaWorld::seed($step['name'], $handle);
        }

        SagaStep::query()->create([
            'saga_id' => $saga->id,
            'position' => $position,
            'name' => $step['name'],
            'status' => $done ? SagaStepStatus::Done : SagaStepStatus::Pending,
            'compensation_payload' => $done ? $handle : null,
            'compensation_ref' => $done && $handle !== null ? 'release:'.$step['name'] : null,
            'attempts' => $done ? 1 : 0,
            'completed_at' => $done ? now() : null,
        ]);
    }

    return $saga->refresh();
}

/**
 * The crash window `SagaStepResult` exists for: the step's effect landed **and** the
 * ledger recorded its result, but the worker died before the step row was settled.
 *
 * A later run therefore *replays* the forward action — the closure is never entered
 * again — so the only place the compensation handle can come from is the ledger. This
 * seeds exactly that state.
 */
function atomicityLedgerReplay(Saga $saga, string $tenantId, int $index): void
{
    $step = GeneratedSagaDefinition::plan()[$index];
    $name = $step['name'];

    $result = $step['compensates']
        ? SagaStepResult::compensateWith(['reservation' => $name.'-handle'], ref: 'release:'.$name)
            ->contributing($step['contributes'])
        : SagaStepResult::none()->contributing($step['contributes']);

    if ($step['compensates']) {
        SagaWorld::seed($name, ['reservation' => $name.'-handle']);
    }

    IdempotencyKey::factory()->completed($result->toArray())->create([
        'tenant_id' => $tenantId,
        'scope' => Saga::IDEMPOTENCY_SCOPE,
        'key' => $saga->stepKey($name),
    ]);
}

/**
 * @return array<string, SagaStepStatus>
 */
function atomicityStatuses(Saga $saga): array
{
    return $saga->refresh()->steps
        ->mapWithKeys(static fn (SagaStep $step): array => [$step->name => $step->status])
        ->all();
}

/**
 * Every step's attempt counters, as `forward/compensation` — the durable record of what
 * was *entered*, which is what a re-run must not move.
 *
 * @return array<string, string>
 */
function atomicityAttempts(Saga $saga): array
{
    return $saga->refresh()->steps
        ->mapWithKeys(static fn (SagaStep $step): array => [
            $step->name => $step->attempts.'/'.$step->compensation_attempts,
        ])
        ->all();
}

/**
 * Sorted copy, for the assertions that are about a *set* rather than an order.
 *
 * Live effects are compared this way because their insertion order is not part of the
 * property — a step whose effect was created by an earlier process is seeded before the
 * run starts. Compensation *order* is a separate claim and is asserted exactly.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function atomicitySorted(array $names): array
{
    sort($names);

    return $names;
}

it('leaves no orphaned side effect however a saga of any shape is interrupted', function (): void {
    $probe = SagaProbe::seeded();

    // One tenant throughout: tenant isolation is Property 1's claim, and a fresh tenant
    // per iteration would only add factory noise to this one. Cross-tenant refusal is
    // covered by `SagaOrchestratorTest`.
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);
    $orchestrator = app(SagaOrchestrator::class);

    // A one-step saga (nothing to compensate at index 0), a long chain (many
    // compensations to get in the right order), and two middling ones.
    /** @var list<int> $counts */
    $counts = [1, $probe->int(7, 9), $probe->int(2, 4), $probe->int(4, 6)];
    $schedule = $probe->faultSchedule($counts);

    /** @var array<string, int> $exercised */
    $exercised = [];
    /** @var array<string, int> $outcomes */
    $outcomes = ['completed' => 0, 'compensated' => 0, 'needs_attention' => 0];
    $iterations = 0;

    foreach ($schedule as $shape => $kinds) {
        GeneratedSagaDefinition::register($probe->shape(count($kinds)));

        $names = GeneratedSagaDefinition::names();
        $effectful = GeneratedSagaDefinition::effectfulNames();

        /*
        |----------------------------------------------------------------------
        | The control: this shape, unfaulted, must actually do its work
        |----------------------------------------------------------------------
        | Without this every assertion below is satisfied by an orchestrator that
        | runs nothing at all.
        */
        SagaWorld::reset();
        $control = atomicitySaga($tenant);
        $clean = $orchestrator->run($control);
        $where = sprintf('seed %d, shape %d (%d steps), unfaulted', $probe->seed, $shape, count($names));

        expect($clean->isCompleted())->toBeTrue($where.': the happy path did not complete — '.$clean->summary())
            ->and($clean->isReplay())->toBeFalse($where)
            ->and(SagaWorld::forwards())->toBe($names, $where.': not every forward action ran, in order.')
            ->and(SagaWorld::liveEffects())->toBe($effectful, $where.': the effects are not all standing.')
            ->and(SagaWorld::compensations())->toBe([], $where.': something was compensated on the happy path.')
            ->and(atomicityStatuses($control))->toBe(array_fill_keys($names, SagaStepStatus::Done), $where)
            ->and($control->refresh()->state)->toMatchArray(
                GeneratedSagaDefinition::contributionsOf(...$names),
                $where.': a step contribution never reached the saga state.'
            );

        foreach ($kinds as $index => $kind) {
            $iterations++;
            $exercised[$kind] = ($exercised[$kind] ?? 0) + 1;

            SagaWorld::reset();

            $failing = $names[$index];
            $before = array_slice($names, 0, $index);
            $where = sprintf(
                'seed %d, shape %d (%d steps), kind [%s] at index %d of [%s]',
                $probe->seed,
                $shape,
                count($names),
                $kind,
                $index,
                implode(' -> ', $names),
            );

            /*
            |------------------------------------------------------------------
            | Draw the fault and the oracle it implies
            |------------------------------------------------------------------
            | `$landed` is the set of steps whose effect is expected to have
            | happened and to have been undone by the end — Algorithm 8's `done[]`,
            | as the drawn fault leaves it. Everything else below is derived from
            | it rather than read off the result.
            */
            $saga = $kind === 'crash_between_steps'
                ? atomicityCrashedSaga($tenant, $index)
                : atomicitySaga($tenant);

            $refusing = [];
            $completes = false;

            switch ($kind) {
                case 'forward_throws':
                case 'crash_between_steps':
                    SagaWorld::failForwardAt($failing);
                    $landed = $before;
                    $failedStep = $failing;

                    break;

                case 'unrecordable':
                    // The effect lands and its result cannot be recorded. The step must end
                    // up DONE — and therefore compensated — because a FAILED step is never
                    // undone, which is how a bookkeeping error becomes an orphaned effect.
                    SagaWorld::failRecordingAt($failing);
                    $landed = [...$before, $failing];
                    $failedStep = $failing;

                    break;

                case 'compensation_some_throw':
                case 'compensation_all_throw':
                    SagaWorld::failForwardAt($failing);
                    $landed = $before;
                    $failedStep = $failing;
                    $refusing = $kind === 'compensation_all_throw'
                        ? $before
                        : $probe->subsetOf($before, spare: 1);

                    foreach ($refusing as $name) {
                        SagaWorld::failCompensationAt($name);
                    }

                    break;

                case 'in_flight':
                    // Another worker holds this step's forward key. Not a step failure: a run
                    // that unwound here would compensate a saga whose next effect is in
                    // flight. The saga must be left exactly as it was found.
                    IdempotencyKey::factory()->create([
                        'scope' => Saga::IDEMPOTENCY_SCOPE,
                        'key' => $saga->stepKey($failing),
                    ]);

                    expect(fn () => $orchestrator->run($saga))
                        ->toThrow(OperationInFlightException::class);

                    expect($saga->refresh()->status)->toBe(SagaStatus::Running, $where.': an in-flight refusal was mistaken for a failure.')
                        ->and(atomicityStatuses($saga)[$failing])->toBe(SagaStepStatus::Pending, $where)
                        ->and(SagaWorld::compensations())->toBe([], $where.': an in-flight refusal started an unwind.')
                        ->and(SagaWorld::forwards())->toBe($before, $where)
                        ->and(atomicitySorted(SagaWorld::liveEffects()))
                        ->toBe(atomicitySorted(array_values(array_intersect($before, $effectful))), $where);

                    // The other worker finished and released the key.
                    IdempotencyKey::query()
                        ->where('scope', Saga::IDEMPOTENCY_SCOPE)
                        ->where('key', $saga->stepKey($failing))
                        ->delete();

                    $landed = [];
                    $failedStep = null;
                    $completes = true;

                    break;

                case 'ledger_replay':
                    atomicityLedgerReplay($saga, $tenant->id, $index);

                    if ($index === count($names) - 1) {
                        // Nothing after it to fail, so the replay carries the saga home.
                        $landed = [];
                        $failedStep = null;
                        $completes = true;

                        break;
                    }

                    // The step *after* the replayed one refuses, so the replayed step has to
                    // be undone using the handle it recovered from the ledger and never saw
                    // created.
                    $failedStep = $names[$index + 1];
                    SagaWorld::failForwardAt($failedStep);
                    $landed = [...$before, $failing];

                    break;

                default:
                    throw new LogicException('Unhandled fault kind ['.$kind.'].');
            }

            /*
            |------------------------------------------------------------------
            | Forward actions the run may enter
            |------------------------------------------------------------------
            | A resumed saga re-executes nothing (`crash_between_steps`), a replayed
            | step is never entered (`ledger_replay`), a step whose forward refuses
            | records nothing, and a step held by another worker is entered on the
            | second pass only.
            */
            $expectedForwards = match ($kind) {
                'crash_between_steps' => [],
                'in_flight' => $names,
                'ledger_replay' => $before,
                'unrecordable' => [...$before, $failing],
                default => $before,
            };

            // ---- the run ------------------------------------------------------
            $outcome = $orchestrator->run($saga->refresh());

            // Exactly one of the three legal endings, every time.
            $legal = [$outcome->isCompleted(), $outcome->isCompensated(), $outcome->needsAttention()];

            expect(count(array_filter($legal)))->toBe(1, $where.': the run ended in no single legal state — '.$outcome->summary());

            if ($refusing !== []) {
                /*
                |--------------------------------------------------------------
                | The third outcome: an unwind that could not finish
                |--------------------------------------------------------------
                | Not `FAILED` — on this platform that word means "compensated,
                | nothing outstanding", and writing it here would tell every
                | dashboard that a still-held reservation had been released.
                */
                $outcomes['needs_attention']++;
                $stranded = array_values(array_intersect($refusing, $effectful));

                expect($outcome->needsAttention())->toBeTrue($where.': a failed compensation was reported as a clean rollback — '.$outcome->summary())
                    ->and($outcome->unfinished)->toBe(array_reverse($refusing), $where.': the outstanding steps are wrong.')
                    ->and($outcome->compensated)->toBe(
                        array_reverse(array_values(array_diff($before, $refusing))),
                        $where.': the compensated steps are wrong.'
                    )
                    ->and(atomicitySorted(SagaWorld::liveEffects()))->toBe(
                        atomicitySorted($stranded),
                        $where.': exactly the effects that refused to be released must still stand.'
                    );

                $saga->refresh();

                expect($saga->status)->toBe(SagaStatus::Compensating, $where)
                    ->and($saga->failed_at)->toBeNull($where.': a saga that still owes work was marked failed.')
                    ->and($saga->isResumable())->toBeTrue($where)
                    ->and($saga->isFullyCompensated())->toBeFalse($where)
                    // The recovery sweep finds it, and an operator can see it.
                    ->and(Saga::query()->resumable()->pluck('id')->all())->toContain($saga->id)
                    ->and(Saga::query()->stalled(0)->pluck('id')->all())->toContain($saga->id);

                $attemptsBeforeHeal = $saga->steps
                    ->mapWithKeys(static fn (SagaStep $step): array => [$step->name => $step->compensation_attempts])
                    ->all();

                foreach ($refusing as $name) {
                    SagaWorld::healCompensationAt($name);
                }

                $outcome = $orchestrator->run($saga->refresh());

                expect($outcome->isCompensated())->toBeTrue($where.': the resumed unwind did not finish — '.$outcome->summary())
                    ->and($outcome->needsAttention())->toBeFalse($where);

                // Nothing that already succeeded was entered again: the second pass touched
                // only the steps that had refused.
                foreach ($saga->refresh()->steps as $step) {
                    $expectedAttempts = in_array($step->name, $refusing, true)
                        ? $attemptsBeforeHeal[$step->name] + 1
                        : $attemptsBeforeHeal[$step->name];

                    expect($step->compensation_attempts)->toBe($expectedAttempts, sprintf(
                        '%s: compensation of [%s] was attempted %d times, expected %d.',
                        $where,
                        $step->name,
                        $step->compensation_attempts,
                        $expectedAttempts,
                    ));
                }
            }

            /*
            |------------------------------------------------------------------
            | The property
            |------------------------------------------------------------------
            */
            $expectedStatuses = [];

            foreach ($names as $name) {
                $expectedStatuses[$name] = match (true) {
                    $completes => SagaStepStatus::Done,
                    in_array($name, $landed, true) => SagaStepStatus::Compensated,
                    $name === $failedStep => SagaStepStatus::Failed,
                    default => SagaStepStatus::Pending,
                };
            }

            $expectedLive = $completes ? $effectful : [];
            $expectedCompensations = $completes
                ? []
                : [
                    // Reverse execution order, and the steps that refused first time round
                    // come last because they were undone on the resumed pass.
                    ...array_reverse(array_values(array_diff($landed, $refusing))),
                    ...array_reverse($refusing),
                ];

            if ($refusing === []) {
                $outcomes[$completes ? 'completed' : 'compensated']++;
            }

            expect($completes ? $outcome->isCompleted() : $outcome->isCompensated())
                ->toBeTrue($where.': wrong ending — '.$outcome->summary())
                // The property itself: nothing this saga did is still standing.
                ->and(atomicitySorted(SagaWorld::liveEffects()))->toBe(
                    atomicitySorted($expectedLive),
                    $where.': orphaned side effect(s) left behind.'
                )
                // …and the unwind ran in exact reverse execution order.
                ->and(SagaWorld::compensations())->toBe(
                    $expectedCompensations,
                    $where.': compensations did not run in reverse execution order.'
                )
                // No effect was undone twice, and no forward action ran twice.
                ->and(SagaWorld::compensations())->toBe(
                    array_values(array_unique(SagaWorld::compensations())),
                    $where.': a compensation ran twice.'
                )
                ->and(SagaWorld::forwards())->toBe($expectedForwards, $where.': the wrong forward actions ran.')
                ->and(atomicityStatuses($saga))->toBe($expectedStatuses, $where);

            $saga->refresh();

            expect($saga->status)->toBe($completes ? SagaStatus::Completed : SagaStatus::Failed, $where)
                ->and($saga->isTerminal())->toBeTrue($where)
                ->and($saga->isFullyCompensated())->toBe(! $completes, $where);

            /*
            |------------------------------------------------------------------
            | Per-step bookkeeping agrees with the world
            |------------------------------------------------------------------
            */
            foreach ($names as $name) {
                $status = $expectedStatuses[$name];
                $live = in_array($name, SagaWorld::liveEffects(), true);
                $ranForward = in_array($name, SagaWorld::forwards(), true);
                $ranCompensation = in_array($name, SagaWorld::compensations(), true);
                $step = sprintf('%s: step [%s] is %s but', $where, $name, $status->value);

                match ($status) {
                    SagaStepStatus::Pending => expect($ranForward)->toBeFalse($step.' its forward action ran.')
                        ->and($live)->toBeFalse($step.' it has a live effect.')
                        ->and($ranCompensation)->toBeFalse($step.' it was compensated.'),
                    SagaStepStatus::Compensated => expect($live)->toBeFalse($step.' its effect is still live.')
                        ->and($ranCompensation)->toBeTrue($step.' its compensation was never entered.'),
                    SagaStepStatus::Failed => expect($live)->toBeFalse($step.' it left a live effect.')
                        ->and($ranCompensation)->toBeFalse($step.' it was compensated — a failed step has nothing to undo.'),
                    SagaStepStatus::Done => expect($live)->toBe(
                        GeneratedSagaDefinition::isEffectful($name),
                        $step.' the world disagrees about its effect.'
                    ),
                };
            }

            /*
            |------------------------------------------------------------------
            | Idempotent re-run: a duplicate delivery changes nothing
            |------------------------------------------------------------------
            */
            $forwardsBefore = SagaWorld::forwards();
            $compensationsBefore = SagaWorld::compensations();
            $liveBefore = SagaWorld::liveEffects();
            $statusesBefore = atomicityStatuses($saga);
            $attemptsBefore = atomicityAttempts($saga);
            $stateBefore = $saga->refresh()->state;

            foreach (range(1, $probe->int(1, 3)) as $pass) {
                $replay = $orchestrator->run($saga->refresh());

                expect($replay->isReplay())->toBeTrue(sprintf('%s: re-run %d was not reported as a replay.', $where, $pass))
                    ->and($replay->status)->toBe($outcome->status, $where)
                    ->and(SagaWorld::forwards())->toBe($forwardsBefore, sprintf('%s: re-run %d re-executed a forward action.', $where, $pass))
                    ->and(SagaWorld::compensations())->toBe($compensationsBefore, sprintf('%s: re-run %d re-executed a compensation.', $where, $pass))
                    ->and(SagaWorld::liveEffects())->toBe($liveBefore, sprintf('%s: re-run %d changed the world.', $where, $pass))
                    ->and(atomicityStatuses($saga))->toBe($statusesBefore, $where)
                    ->and(atomicityAttempts($saga))->toBe($attemptsBefore, sprintf('%s: re-run %d attempted a step again.', $where, $pass))
                    ->and($saga->refresh()->state)->toBe($stateBefore, $where);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Coverage — facts about the schedule, not hopes about the seed
    |--------------------------------------------------------------------------
    */
    expect($iterations)->toBe(array_sum($counts), 'every step index of every shape must be faulted')
        ->and(array_keys($exercised))->toHaveCount(count(SagaProbe::FAULT_KINDS), sprintf(
            'seed %d: only [%s] of the %d fault kinds were exercised.',
            $probe->seed,
            implode(', ', array_keys($exercised)),
            count(SagaProbe::FAULT_KINDS),
        ))
        ->and($outcomes['completed'])->toBeGreaterThan(0, 'no run ended COMPLETED')
        ->and($outcomes['compensated'])->toBeGreaterThan(0, 'no run ended fully compensated')
        ->and($outcomes['needs_attention'])->toBeGreaterThan(0, 'no run exercised the incomplete unwind');
});

it('orphans only the effect of a step that breaks its own forward contract, and nothing else', function (): void {
    /*
    | The honest limit of the construction, asserted rather than glossed over.
    |
    | `SagaStepDefinition::forward()` is all-or-nothing: throwing means "nothing landed",
    | and the orchestrator relies on it — a `FAILED` step is never compensated, because a
    | compensation for work that never happened is itself a side effect. A step that
    | lands its effect and *then* throws therefore orphans that effect, and no amount of
    | correctness in the orchestrator can recover it: nothing in the record says the
    | effect exists.
    |
    | So this asserts the blast radius is exactly one step — every *other* completed step
    | is still undone — and then asserts the supported way to report the same situation:
    | a step whose effect landed and whose result could not be recorded raises
    | `UnrecordableResultException`, is settled `DONE`, and **is** compensated. That pair
    | is the whole reason `UnrecordableResultException` is handled where it is.
    */
    $probe = SagaProbe::seeded();
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);
    $orchestrator = app(SagaOrchestrator::class);

    foreach (range(1, 6) as $iteration) {
        // Every step leaves an effect here: the misbehaving step needs one to orphan, and
        // the steps before it need one each for the "and nothing else" half to say
        // anything.
        $plan = [];

        foreach ($probe->shape($probe->int(3, 6)) as $step) {
            $plan[] = [
                'name' => $step['name'],
                'compensates' => true,
                'contributes' => $step['contributes'],
            ];
        }

        GeneratedSagaDefinition::register($plan);

        $names = GeneratedSagaDefinition::names();
        $index = $probe->int(1, count($names) - 1);
        $culprit = $names[$index];
        $before = array_slice($names, 0, $index);
        $where = sprintf(
            'seed %d, iteration %d: [%s] at index %d of [%s]',
            $probe->seed,
            $iteration,
            $culprit,
            $index,
            implode(' -> ', $names),
        );

        // ---- the boundary: an effect the record cannot know about ------------------
        SagaWorld::reset();
        SagaWorld::orphanAt($culprit);

        $outcome = $orchestrator->run(atomicitySaga($tenant));

        expect($outcome->isCompensated())->toBeTrue($where.': '.$outcome->summary())
            ->and($outcome->failedStep)->toBe($culprit, $where)
            // Exactly one effect stands, and it is the misbehaving step's own.
            ->and(SagaWorld::liveEffects())->toBe([$culprit], $where.': the blast radius is not one step.')
            // Every step that kept its contract was undone, in reverse order.
            ->and(SagaWorld::compensations())->toBe(array_reverse($before), $where);

        // ---- the supported alternative: the same effect, reported ------------------
        SagaWorld::reset();
        SagaWorld::failRecordingAt($culprit);

        $recorded = $orchestrator->run(atomicitySaga($tenant));

        expect($recorded->isCompensated())->toBeTrue($where.': '.$recorded->summary())
            ->and($recorded->failedStep)->toBe($culprit, $where)
            ->and(SagaWorld::liveEffects())->toBe([], $where.': an unrecordable result orphaned an effect.')
            ->and(SagaWorld::compensations())->toBe(array_reverse([...$before, $culprit]), $where);
    }
});
