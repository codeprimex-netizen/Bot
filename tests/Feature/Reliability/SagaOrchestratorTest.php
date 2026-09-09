<?php

declare(strict_types=1);

use App\Enums\IdempotencyState;
use App\Enums\SagaStatus;
use App\Enums\SagaStepStatus;
use App\Exceptions\Reliability\InvalidSagaDefinitionException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Exceptions\Reliability\SagaCompensatedException;
use App\Exceptions\Reliability\UnrecordableResultException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\IdempotencyKey;
use App\Models\Saga;
use App\Models\SagaStep;
use App\Models\Tenant;
use App\Services\Reliability\SagaDefinitionRegistry;
use App\Services\Reliability\SagaOrchestrator;
use App\Services\Tenancy\TenantContext;
use Tests\Fixtures\Reliability\SagaWorld;
use Tests\Fixtures\Reliability\SpySagaDefinition;
use Tests\Fixtures\Reliability\UnrecordableSagaDefinition;

/*
|--------------------------------------------------------------------------
| SagaOrchestrator::run — Algorithm 8 (Req 15.5 / B6, Req 31.5 / NFR2)
|--------------------------------------------------------------------------
| Correctness Property 18: every run ends with either all forward steps complete
| or every completed step compensated in reverse order, leaving no partial side
| effect.
|
| "No partial side effect" is asserted against `SagaWorld::liveEffects()` — real
| reservations that a forward action created and a compensation has to remove —
| not against the `saga_steps.status` column. Statuses are bookkeeping, and the
| bug this suite exists to catch (see the distinct-key test) is precisely one
| where the bookkeeping is perfect and nothing was actually undone.
*/

beforeEach(function (): void {
    SagaWorld::reset();
    SpySagaDefinition::reset();
    SpySagaDefinition::register();
});

/**
 * A saga with **no** step rows — the orchestrator lays them out from the definition.
 */
function bareSaga(Tenant $tenant, string $type = SpySagaDefinition::TYPE): Saga
{
    return Saga::factory()->create(['tenant_id' => $tenant->id, 'type' => $type]);
}

/**
 * A saga whose steps are already persisted, the first $doneCount of them `DONE` with the
 * handle a real forward action would have recorded — and with those effects standing in
 * `SagaWorld`, so "was it undone?" is a question about the world and not about a column.
 */
function resumableSaga(Tenant $tenant, int $doneCount, SagaStatus $status = SagaStatus::Running): Saga
{
    $saga = Saga::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);

    foreach (SpySagaDefinition::STEPS as $position => $name) {
        $done = $position < $doneCount;

        if ($done) {
            // Seeded, not "reserved": the effect exists because an earlier process created
            // it, so it must not count as a forward action of *this* run.
            SagaWorld::seed($name, ['reservation' => $name.'-handle']);
        }

        SagaStep::query()->create([
            'saga_id' => $saga->id,
            'position' => $position,
            'name' => $name,
            'status' => $done ? SagaStepStatus::Done : SagaStepStatus::Pending,
            'compensation_payload' => $done ? ['reservation' => $name.'-handle'] : null,
            'compensation_ref' => $done ? 'release:'.$name : null,
            'attempts' => $done ? 1 : 0,
            'completed_at' => $done ? now() : null,
        ]);
    }

    return $saga->refresh();
}

/**
 * Bind the tenant and hand back the orchestrator.
 */
function orchestrator(Tenant $tenant): SagaOrchestrator
{
    app(TenantContext::class)->set($tenant);

    return app(SagaOrchestrator::class);
}

/**
 * @return array<string, SagaStepStatus>
 */
function stepStatuses(Saga $saga): array
{
    return $saga->refresh()->steps
        ->mapWithKeys(static fn (SagaStep $step): array => [$step->name => $step->status])
        ->all();
}

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('runs every forward step in order and completes the saga', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);

    $outcome = orchestrator($tenant)->run($saga);

    expect($outcome->isCompleted())->toBeTrue()
        ->and($outcome->isReplay())->toBeFalse()
        ->and($outcome->status)->toBe(SagaStatus::Completed)
        ->and(SagaWorld::forwards())->toBe(SpySagaDefinition::STEPS)
        ->and(SagaWorld::compensations())->toBe([])
        ->and(SagaWorld::liveEffects())->toBe(SpySagaDefinition::STEPS);

    $saga->refresh();

    expect($saga->status)->toBe(SagaStatus::Completed)
        ->and($saga->completed_at)->not->toBeNull()
        ->and($saga->current_step)->toBe(count(SpySagaDefinition::STEPS))
        ->and($saga->allStepsDone())->toBeTrue()
        // Each step's contribution reached the shared state, so a later step can read
        // what an earlier one produced.
        ->and($saga->state)->toHaveKeys(['reserve_items_done', 'create_payment_link_done', 'fulfil_order_done']);
});

it('persists every step from the definition before executing anything', function (): void {
    // The first step fails, so steps 2 and 3 never run — and must still exist on disk.
    // That is the crash-recovery precondition: the step list is recoverable from the
    // database alone, not from the memory of the worker that laid it out.
    SagaWorld::failForwardAt(SpySagaDefinition::STEPS[0]);

    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);

    orchestrator($tenant)->run($saga);

    expect(stepStatuses($saga))->toBe([
        'reserve_items' => SagaStepStatus::Failed,
        'create_payment_link' => SagaStepStatus::Pending,
        'fulfil_order' => SagaStepStatus::Pending,
    ])
        ->and($saga->refresh()->steps->pluck('position')->all())->toBe([0, 1, 2]);
});

it('records the forward attempt and the compensation handle on the step row', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);

    orchestrator($tenant)->run($saga);

    $step = $saga->refresh()->steps->firstOrFail();

    expect($step->status)->toBe(SagaStepStatus::Done)
        ->and($step->attempts)->toBe(1)
        ->and($step->compensation_attempts)->toBe(0)
        ->and($step->compensation_ref)->toBe('release:reserve_items')
        ->and($step->compensation_payload)->toBe(['reservation' => 'reserve_items-handle'])
        ->and($step->completed_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Property 18: fault injection at every step index
|--------------------------------------------------------------------------
*/

it('leaves no orphaned side effect when any single step fails', function (int $index): void {
    $failing = SpySagaDefinition::STEPS[$index];
    SagaWorld::failForwardAt($failing);

    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);

    $outcome = orchestrator($tenant)->run($saga);

    $before = array_slice(SpySagaDefinition::STEPS, 0, $index);

    expect($outcome->isCompleted())->toBeFalse()
        ->and($outcome->isCompensated())->toBeTrue()
        ->and($outcome->needsAttention())->toBeFalse()
        ->and($outcome->failedStep)->toBe($failing)
        // The property itself: nothing the saga did is still standing.
        ->and(SagaWorld::liveEffects())->toBe([])
        // …and it was undone in reverse execution order.
        ->and(SagaWorld::compensations())->toBe(array_reverse($before))
        ->and($outcome->compensated)->toBe(array_reverse($before));

    $saga->refresh();

    expect($saga->status)->toBe(SagaStatus::Failed)
        ->and($saga->failed_at)->not->toBeNull()
        ->and($saga->isFullyCompensated())->toBeTrue()
        ->and($saga->last_error)->toContain('failed on purpose');

    foreach (SpySagaDefinition::STEPS as $position => $name) {
        expect(stepStatuses($saga)[$name])->toBe(match (true) {
            $position < $index => SagaStepStatus::Compensated,
            $position === $index => SagaStepStatus::Failed,
            default => SagaStepStatus::Pending,
        });
    }
})->with([[0], [1], [2]]);

/*
|--------------------------------------------------------------------------
| The distinct-key deviation from Algorithm 8
|--------------------------------------------------------------------------
*/

it('actually executes compensations rather than replaying the forward action key', function (): void {
    /*
     * This is the test that pins the deviation from design.md's Algorithm 8.
     *
     * The pseudocode passes the SAME key to `executeIdempotent(step.forward, key :=
     * saga.id + ':' + step.name)` and to `compensateIdempotent(s.compensation, key :=
     * saga.id + ':' + s.name)`. Implemented literally against `IdempotencyStore`, the
     * forward action has already recorded that key `COMPLETED`, so `once()` would replay
     * its result and never call the compensation: every unwind a silent no-op that
     * reports success, with every step dutifully marked COMPENSATED.
     *
     * So the assertions below are deliberately about the *world*, not the statuses:
     *   - `compensations()` proves the closures were entered;
     *   - `liveEffects() === []` proves the effects are gone.
     * Both fail if the two directions share a key. The statuses would pass either way,
     * which is exactly why they are not the evidence here.
     */
    SagaWorld::failForwardAt('fulfil_order');

    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);

    orchestrator($tenant)->run($saga);

    expect(SagaWorld::compensations())->toBe(['create_payment_link', 'reserve_items'])
        ->and(SagaWorld::liveEffects())->toBe([])
        ->and(SagaWorld::handle('reserve_items'))->toBeNull();

    // And at the ledger level: two distinct keys per step, both recorded COMPLETED.
    $forwardKey = $saga->stepKey('reserve_items');
    $compensationKey = $saga->compensationKey('reserve_items');

    expect($compensationKey)->not->toBe($forwardKey)
        ->and($compensationKey)->toBe($forwardKey.':compensate');

    foreach ([$forwardKey, $compensationKey] as $key) {
        $row = IdempotencyKey::query()
            ->where('scope', Saga::IDEMPOTENCY_SCOPE)
            ->where('key', $key)
            ->firstOrFail();

        expect($row->state)->toBe(IdempotencyState::Completed)
            ->and($row->tenant_id)->toBe($tenant->id);
    }
});

it('does not re-run a compensation that already succeeded', function (): void {
    SagaWorld::failForwardAt('create_payment_link');

    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);
    $orchestrator = orchestrator($tenant);

    $orchestrator->run($saga);

    expect(SagaWorld::compensations())->toBe(['reserve_items']);

    // A duplicate delivery of the same job. The saga is terminal, so nothing runs; and
    // even if it were re-entered, the compensation key is recorded COMPLETED.
    $outcome = $orchestrator->run($saga->refresh());

    expect($outcome->isCompensated())->toBeTrue()
        ->and($outcome->isReplay())->toBeTrue()
        ->and(SagaWorld::compensations())->toBe(['reserve_items'])
        ->and(SagaWorld::liveEffects())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Re-running is idempotent and total
|--------------------------------------------------------------------------
*/

it('is a no-op on a saga that already completed', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);
    $orchestrator = orchestrator($tenant);

    $orchestrator->run($saga);
    SagaWorld::reset();

    $outcome = $orchestrator->run($saga->refresh());

    expect($outcome->isCompleted())->toBeTrue()
        ->and($outcome->isReplay())->toBeTrue()
        ->and(SagaWorld::forwards())->toBe([])
        ->and(SagaWorld::compensations())->toBe([]);
});

it('resumes the forward pass at the first pending step without re-executing completed ones', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = resumableSaga($tenant, doneCount: 2);

    $outcome = orchestrator($tenant)->run($saga);

    expect($outcome->isCompleted())->toBeTrue()
        // Only the third step ran: the first two were already DONE on disk, and a saga
        // that re-executed them would double every effect on every recovery sweep.
        ->and(SagaWorld::forwards())->toBe(['fulfil_order'])
        ->and(SagaWorld::liveEffects())->toBe(SpySagaDefinition::STEPS)
        ->and($saga->refresh()->steps->pluck('attempts')->all())->toBe([1, 1, 1]);
});

it('resumes an interrupted unwind instead of restarting the saga forward', function (): void {
    $tenant = Tenant::factory()->create();
    // A worker died mid-unwind: two steps landed, the saga is COMPENSATING.
    $saga = resumableSaga($tenant, doneCount: 2, status: SagaStatus::Compensating);

    expect(SagaWorld::liveEffects())->toBe(['reserve_items', 'create_payment_link']);

    $outcome = orchestrator($tenant)->run($saga);

    expect($outcome->isCompensated())->toBeTrue()
        // No forward action ran: COMPENSATING is never restarted forward.
        ->and(SagaWorld::forwards())->toBe([])
        ->and(SagaWorld::compensations())->toBe(['create_payment_link', 'reserve_items'])
        ->and(SagaWorld::liveEffects())->toBe([])
        ->and($saga->refresh()->status)->toBe(SagaStatus::Failed)
        ->and(stepStatuses($saga)['fulfil_order'])->toBe(SagaStepStatus::Pending);
});

it('unwinds a saga whose row still says RUNNING but whose step is already failed', function (): void {
    // The crash window between marking the step and marking the saga. Continuing forward
    // here would complete a saga that has a failed step in it.
    $tenant = Tenant::factory()->create();
    $saga = resumableSaga($tenant, doneCount: 1);
    $saga->steps()->where('name', 'create_payment_link')->update([
        'status' => SagaStepStatus::Failed->value,
        'failed_at' => now(),
    ]);

    $outcome = orchestrator($tenant)->run($saga->refresh());

    expect($outcome->isCompensated())->toBeTrue()
        ->and($outcome->failedStep)->toBe('create_payment_link')
        // No forward action ran in this pass: the failed step is durable evidence that this
        // saga is unwinding, whatever the saga row still says.
        ->and(SagaWorld::forwards())->toBe([])
        ->and(SagaWorld::compensations())->toBe(['reserve_items'])
        ->and(SagaWorld::liveEffects())->toBe([])
        ->and($saga->refresh()->status)->toBe(SagaStatus::Failed);
});

/*
|--------------------------------------------------------------------------
| When a compensation itself fails (the case Algorithm 8 does not consider)
|--------------------------------------------------------------------------
*/

it('keeps unwinding past a failed compensation and leaves the saga visible to an operator', function (): void {
    SagaWorld::failForwardAt('fulfil_order');
    // The *later* of the two compensations refuses. Abandoning the unwind there would
    // strand `reserve_items` as well, which is a worse partial state than the one the
    // unwind was called to fix.
    SagaWorld::failCompensationAt('create_payment_link');

    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);
    $orchestrator = orchestrator($tenant);

    $outcome = $orchestrator->run($saga);

    expect($outcome->needsAttention())->toBeTrue()
        ->and($outcome->isCompensated())->toBeFalse()
        ->and($outcome->isCompleted())->toBeFalse()
        ->and($outcome->unfinished)->toBe(['create_payment_link'])
        ->and($outcome->compensated)->toBe(['reserve_items'])
        // The earlier step was still undone.
        ->and(SagaWorld::compensations())->toBe(['reserve_items'])
        ->and(SagaWorld::liveEffects())->toBe(['create_payment_link']);

    $saga->refresh();

    // NOT `FAILED`: on this platform that word means "compensated, nothing outstanding".
    // `COMPENSATING` is resumable and shows up in the stalled-saga query.
    expect($saga->status)->toBe(SagaStatus::Compensating)
        ->and($saga->failed_at)->toBeNull()
        ->and($saga->isResumable())->toBeTrue()
        ->and($saga->isFullyCompensated())->toBeFalse()
        ->and($saga->last_error)->toContain('create_payment_link')
        // The recovery sweep finds it, which is what makes "an operator can act on it"
        // more than a sentence in a docblock.
        ->and(Saga::query()->resumable()->pluck('id')->all())->toContain($saga->id);

    // The step that would not undo is still DONE, which is what brings it back.
    $step = $saga->steps->firstWhere('name', 'create_payment_link');

    expect($step?->status)->toBe(SagaStepStatus::Done)
        ->and($step?->compensation_attempts)->toBe(1)
        ->and($step?->last_error)->toContain('compensation of [create_payment_link] failed on purpose');

    // Once the dependency recovers, the retry finishes the unwind — and does not touch
    // the compensation that already succeeded.
    SagaWorld::healCompensationAt('create_payment_link');

    $resumed = $orchestrator->run($saga->refresh());

    expect($resumed->isCompensated())->toBeTrue()
        ->and($resumed->needsAttention())->toBeFalse()
        ->and(SagaWorld::compensations())->toBe(['reserve_items', 'create_payment_link'])
        ->and(SagaWorld::liveEffects())->toBe([])
        ->and($saga->refresh()->status)->toBe(SagaStatus::Failed)
        ->and($saga->steps->firstWhere('name', 'create_payment_link')?->compensation_attempts)->toBe(2);
});

it('offers the loud form of a non-completed outcome', function (): void {
    SagaWorld::failForwardAt('create_payment_link');

    $tenant = Tenant::factory()->create();
    $outcome = orchestrator($tenant)->run(bareSaga($tenant));

    expect(fn () => $outcome->throwUnlessCompleted())
        ->toThrow(SagaCompensatedException::class);

    // The log shape carries step names and ids, never the saga's state (which holds the
    // order, the amounts and the customer).
    expect($outcome->toArray())->toBe([
        'saga_id' => $outcome->sagaId,
        'type' => SpySagaDefinition::TYPE,
        'status' => 'FAILED',
        'failed_step' => 'create_payment_link',
        'compensated' => ['reserve_items'],
        'unfinished' => [],
        'replayed' => false,
    ]);

    $exception = $outcome->toException();

    expect($exception->getStatusCode())->toBe(409)
        ->and($exception->needsAttention())->toBeFalse()
        ->and($exception->isRetryable())->toBeTrue()
        ->and($exception->failedStep())->toBe('create_payment_link')
        ->and($exception->getPrevious()?->getMessage())->toContain('failed on purpose')
        // The public body names nothing about the saga.
        ->and($exception->publicMessage())->not->toContain('create_payment_link');
});

/*
|--------------------------------------------------------------------------
| Concurrency and bookkeeping failures must not be mistaken for step failures
|--------------------------------------------------------------------------
*/

it('propagates an in-flight refusal instead of unwinding a saga another worker is running', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = resumableSaga($tenant, doneCount: 1);

    // Another worker is mid-flight on step 2's forward action.
    IdempotencyKey::factory()->create([
        'scope' => Saga::IDEMPOTENCY_SCOPE,
        'key' => $saga->stepKey('create_payment_link'),
    ]);

    expect(fn () => orchestrator($tenant)->run($saga))
        ->toThrow(OperationInFlightException::class);

    $saga->refresh();

    // Nothing was undone and nothing was declared failed: the other worker owns this.
    expect($saga->status)->toBe(SagaStatus::Running)
        ->and(SagaWorld::compensations())->toBe([])
        ->and(SagaWorld::liveEffects())->toBe(['reserve_items'])
        ->and(stepStatuses($saga)['create_payment_link'])->toBe(SagaStepStatus::Pending);
});

it('compensates a step whose effect landed but whose result could not be recorded', function (): void {
    // A definition bug rather than a dependency failure: the second step's effect happened
    // but its result cannot go in the ledger. The step must therefore end up DONE and be
    // *compensated* — marking it FAILED, a state that is never compensated, would orphan
    // the effect it just caused.
    UnrecordableSagaDefinition::register();

    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);

    $outcome = orchestrator($tenant)->run($saga);

    expect($outcome->isCompensated())->toBeTrue()
        ->and($outcome->failedStep)->toBe('create_payment_link')
        // Both effects were undone in the same run — including the one whose bookkeeping
        // broke, which is the whole point.
        ->and($outcome->compensated)->toBe(['create_payment_link', 'reserve_items'])
        ->and(SagaWorld::liveEffects())->toBe([]);

    $saga->refresh();

    expect(stepStatuses($saga))->toBe([
        'reserve_items' => SagaStepStatus::Compensated,
        'create_payment_link' => SagaStepStatus::Compensated,
    ])
        ->and($saga->status)->toBe(SagaStatus::Failed)
        // The definition bug is still on the record, unswallowed.
        ->and($saga->last_error)->toContain(UnrecordableResultException::class);
});

/*
|--------------------------------------------------------------------------
| Tenancy
|--------------------------------------------------------------------------
*/

it('refuses to run another tenant\'s saga', function (): void {
    $owner = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $saga = bareSaga($owner);

    // Checked up front, before the step list is laid out: a cross-tenant refusal
    // surfacing later, from the first write, would be caught by the forward loop and
    // unwind a saga on the strength of a security error.
    expect(fn () => orchestrator($other)->run($saga))
        ->toThrow(CrossTenantAccessException::class);

    app(TenantContext::class)->set($owner);

    expect(SagaWorld::forwards())->toBe([])
        ->and($saga->refresh()->status)->toBe(SagaStatus::Running)
        ->and($saga->steps)->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Definitions must describe the saga that is being run
|--------------------------------------------------------------------------
*/

it('refuses a saga whose type no definition claims and leaves it untouched', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant, type: 'DRIP_SEQUENCE');

    expect(fn () => orchestrator($tenant)->run($saga))
        ->toThrow(InvalidSagaDefinitionException::class, 'DRIP_SEQUENCE');

    expect($saga->refresh()->status)->toBe(SagaStatus::Running)
        ->and($saga->steps)->toHaveCount(0)
        ->and(SagaWorld::forwards())->toBe([]);
});

it('refuses a saga whose persisted steps no longer match its definition', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = resumableSaga($tenant, doneCount: 1);

    // A deploy renamed a step under a saga that is already in flight. Step names are
    // idempotency keys, so running it would re-execute effects and strand compensations.
    $saga->steps()->where('name', 'create_payment_link')->update(['name' => 'authorise_payment']);

    expect(fn () => orchestrator($tenant)->run($saga->refresh()))
        ->toThrow(InvalidSagaDefinitionException::class, 'authorise_payment');

    expect(SagaWorld::forwards())->toBe([])
        ->and(SagaWorld::compensations())->toBe([]);
});

it('rejects a misconfigured definition list before anything runs', function (): void {
    $registry = app(SagaDefinitionRegistry::class);

    config()->set('wa.reliability.saga.definitions', [SpySagaDefinition::class, SpySagaDefinition::class]);
    expect(fn () => $registry->definitions())
        ->toThrow(InvalidSagaDefinitionException::class, 'already owns');

    config()->set('wa.reliability.saga.definitions', ['App\\Nope\\MissingSaga']);
    expect(fn () => $registry->definitions())
        ->toThrow(InvalidSagaDefinitionException::class, 'does not exist');

    config()->set('wa.reliability.saga.definitions', [Saga::class]);
    expect(fn () => $registry->definitions())
        ->toThrow(InvalidSagaDefinitionException::class, 'does not implement');

    config()->set('wa.reliability.saga.definitions', [['not' => 'a class']]);
    expect(fn () => $registry->definitions())
        ->toThrow(InvalidSagaDefinitionException::class, 'not a class name');

    SpySagaDefinition::withSteps([]);
    expect(fn () => $registry->definitions())
        ->toThrow(InvalidSagaDefinitionException::class, 'declares no steps');

    SpySagaDefinition::withSteps(['reserve_items', 'reserve_items']);
    expect(fn () => $registry->definitions())
        ->toThrow(InvalidSagaDefinitionException::class, 'two steps named');
});

it('treats an empty definition list as legal but refuses to run an unclaimed type', function (): void {
    // Phase 5 owns the concrete order -> payment -> fulfilment saga, so the shipped list
    // is empty — and that is legal here, unlike `wa.tenancy.provisioning.steps`. An
    // orchestrator that refused to boot without a definition would be a stub; instead the
    // failure lands at the moment it means something.
    config()->set('wa.reliability.saga.definitions', []);

    $registry = app(SagaDefinitionRegistry::class);
    $tenant = Tenant::factory()->create();
    $saga = bareSaga($tenant);

    expect($registry->types())->toBe([])
        ->and($registry->has(SpySagaDefinition::TYPE))->toBeFalse()
        ->and(fn () => orchestrator($tenant)->run($saga))
        ->toThrow(InvalidSagaDefinitionException::class, 'no definition');
});
