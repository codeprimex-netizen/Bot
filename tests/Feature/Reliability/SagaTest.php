<?php

declare(strict_types=1);

use App\Enums\SagaStatus;
use App\Enums\SagaStepStatus;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Saga;
use App\Models\SagaStep;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Factories\SagaFactory;
use Database\Factories\SagaStepFactory;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| sagas / saga_steps schema and model invariants (Req 31.5 / NFR2)
|--------------------------------------------------------------------------
| Sagas are the one tenant-owned reliability table, so this file covers both the
| record (casts, uniques, relation, ordering) and the isolation the other three
| tables deliberately do without. Orchestration is task 3.5.
*/

/**
 * A saga with the design's four order-fulfilment steps, the first $doneCount of
 * which have already succeeded.
 */
function seedSaga(Tenant $tenant, int $doneCount = 0, SagaStatus $status = SagaStatus::Running): Saga
{
    $saga = Saga::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);

    foreach (SagaStepFactory::ORDER_FULFILLMENT_STEPS as $position => $name) {
        $factory = SagaStep::factory()->at($position, $name);

        ($position < $doneCount ? $factory->done() : $factory)->create(['saga_id' => $saga->id]);
    }

    return $saga->refresh();
}

it('stores a saga with its json state and step relation', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = seedSaga($tenant, doneCount: 2);

    // Bound as the owner: the inverse `step->saga` relation is tenant-scoped like any
    // other read of a saga, so it needs a context — see the next test.
    app(TenantContext::class)->set($tenant);

    $fresh = Saga::query()->with('steps')->findOrFail($saga->id);

    expect($fresh->status)->toBe(SagaStatus::Running)
        ->and($fresh->type)->toBe(SagaFactory::ORDER_FULFILLMENT)
        ->and($fresh->state)->toHaveKeys(['order_id', 'amount_micros'])
        ->and($fresh->current_step)->toBe(0)
        ->and($fresh->steps)->toHaveCount(4)
        ->and($fresh->steps->pluck('name')->all())->toBe(SagaStepFactory::ORDER_FULFILLMENT_STEPS)
        ->and($fresh->steps->first()?->saga->is($fresh))->toBeTrue()
        ->and($fresh->tenant->is($tenant))->toBeTrue();
});

it('fails closed on the inverse step-to-saga relation with no tenant bound', function (): void {
    // `saga_steps` carries no tenant_id, so a step hydrated on its own is unscoped —
    // but walking back to its saga is a read of tenant data and is scoped like any
    // other. Loud and fail-closed beats a silent cross-tenant parent.
    $tenant = Tenant::factory()->create();
    $saga = seedSaga($tenant, doneCount: 1);
    $step = SagaStep::query()->where('saga_id', $saga->id)->firstOrFail();

    app(TenantContext::class)->forget();

    expect(fn () => $step->saga)->toThrow(MissingTenantContextException::class);

    app(TenantContext::class)->set($tenant);

    expect($step->fresh()?->saga->is($saga))->toBeTrue();
});

it('round-trips every SagaStatus and SagaStepStatus value through the database', function (): void {
    $tenant = Tenant::factory()->create();

    foreach (SagaStatus::cases() as $status) {
        $saga = Saga::factory()->ofStatus($status)->create(['tenant_id' => $tenant->id]);

        expect(DB::table('sagas')->where('id', $saga->id)->value('status'))->toBe($status->value)
            ->and(Saga::forTenant($tenant)->findOrFail($saga->id)->status)->toBe($status);
    }

    $host = Saga::factory()->uncorrelated()->create(['tenant_id' => $tenant->id]);

    foreach (SagaStepStatus::cases() as $position => $status) {
        $step = SagaStep::factory()->at($position, 'step_'.$status->value)->create([
            'saga_id' => $host->id,
            'status' => $status,
        ]);

        expect(DB::table('saga_steps')->where('id', $step->id)->value('status'))->toBe($status->value)
            ->and($host->steps()->findOrFail($step->id)->status)->toBe($status);
    }
});

it('round-trips both step payload directions as json', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = Saga::factory()->create(['tenant_id' => $tenant->id]);

    $step = SagaStep::factory()->create([
        'saga_id' => $saga->id,
        'payload' => ['sku' => 'A', 'qty' => 3, 'nested' => ['warehouse' => 'W1']],
        'compensation_payload' => ['reservation_id' => 'res-1'],
        'compensation_ref' => 'release-reservation',
    ]);

    $fresh = $saga->steps()->findOrFail($step->id);

    expect($fresh->payload)->toBe(['sku' => 'A', 'qty' => 3, 'nested' => ['warehouse' => 'W1']])
        ->and($fresh->compensation_payload)->toBe(['reservation_id' => 'res-1'])
        ->and($fresh->hasCompensation())->toBeTrue()
        ->and(SagaStep::factory()->withoutCompensation()->create(['saga_id' => $saga->id, 'position' => 7, 'name' => 'notify'])->hasCompensation())
        ->toBeFalse();
});

it('enforces one saga per tenant, type and correlation id', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    Saga::factory()->create([
        'tenant_id' => $acme->id,
        'type' => SagaFactory::ORDER_FULFILLMENT,
        'correlation_id' => 'ord-1',
    ]);

    // Same business key under another tenant is a different saga.
    Saga::factory()->create([
        'tenant_id' => $globex->id,
        'type' => SagaFactory::ORDER_FULFILLMENT,
        'correlation_id' => 'ord-1',
    ]);

    expect(fn () => Saga::factory()->create([
        'tenant_id' => $acme->id,
        'type' => SagaFactory::ORDER_FULFILLMENT,
        'correlation_id' => 'ord-1',
    ]))->toThrow(QueryException::class);
});

it('leaves uncorrelated sagas unconstrained', function (): void {
    $tenant = Tenant::factory()->create();

    Saga::factory()->count(3)->uncorrelated()->create(['tenant_id' => $tenant->id]);

    expect(Saga::forTenant($tenant)->count())->toBe(3);
});

it('enforces unique step names and positions within a saga', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = Saga::factory()->create(['tenant_id' => $tenant->id]);
    $other = Saga::factory()->uncorrelated()->create(['tenant_id' => $tenant->id]);

    SagaStep::factory()->at(0, 'reserve_items')->create(['saga_id' => $saga->id]);

    // The same step name in another saga is fine — `{sagaId}:{stepName}` is the key.
    SagaStep::factory()->at(0, 'reserve_items')->create(['saga_id' => $other->id]);

    expect(fn () => SagaStep::factory()->at(1, 'reserve_items')->create(['saga_id' => $saga->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => SagaStep::factory()->at(0, 'other_step')->create(['saga_id' => $saga->id]))
        ->toThrow(QueryException::class);
});

it('keys forward actions and compensations distinctly', function (): void {
    // Algorithm 8's pseudocode reuses one literal key for both directions; against a
    // real store that would replay the forward result and skip the compensation
    // entirely, so the two are keyed apart.
    $tenant = Tenant::factory()->create();
    $saga = seedSaga($tenant, doneCount: 1);
    $step = $saga->steps->first();

    expect($step)->not->toBeNull()
        ->and($saga->stepKey($step))->toBe($saga->id.':reserve_items')
        ->and($saga->stepKey('reserve_items'))->toBe($saga->id.':reserve_items')
        ->and($saga->compensationKey($step))->toBe($saga->id.':reserve_items:compensate')
        ->and($saga->compensationKey($step))->not->toBe($saga->stepKey($step));
});

it('exposes the done set in reverse order for an unwind', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = seedSaga($tenant, doneCount: 3);

    // Algorithm 8's `done[]`, reversed: exactly what compensation must walk.
    expect($saga->compensatableSteps()->pluck('name')->all())
        ->toBe(['await_payment', 'create_payment_link', 'reserve_items'])
        ->and($saga->nextPendingStep()?->name)->toBe('fulfil_order')
        ->and($saga->currentStep()?->position)->toBe(0)
        ->and($saga->isFullyCompensated())->toBeFalse()
        ->and($saga->allStepsDone())->toBeFalse();
});

it('reports a saga fully compensated once nothing is left to undo', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = Saga::factory()->compensating()->create(['tenant_id' => $tenant->id]);

    SagaStep::factory()->at(0)->compensated()->create(['saga_id' => $saga->id]);
    SagaStep::factory()->at(1)->failed()->create(['saga_id' => $saga->id]);

    expect($saga->refresh()->compensatableSteps())->toHaveCount(0)
        ->and($saga->isFullyCompensated())->toBeTrue()
        // The failed step is never compensated: its forward action left nothing to undo.
        ->and($saga->steps->last()?->status)->toBe(SagaStepStatus::Failed);
});

it('reports all steps done and never claims success for an empty saga', function (): void {
    $tenant = Tenant::factory()->create();
    $complete = seedSaga($tenant, doneCount: 4);
    $empty = Saga::factory()->uncorrelated()->create(['tenant_id' => $tenant->id]);

    expect($complete->allStepsDone())->toBeTrue()
        ->and($complete->isFullyCompensated())->toBeFalse()
        ->and($empty->refresh()->allStepsDone())->toBeFalse();
});

it('orders steps in reverse with the inReverseOrder scope', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = seedSaga($tenant, doneCount: 4);

    expect($saga->steps()->inReverseOrder()->pluck('name')->all())
        ->toBe(array_reverse(SagaStepFactory::ORDER_FULFILLMENT_STEPS))
        ->and($saga->steps()->awaitingCompensation()->count())->toBe(4)
        ->and($saga->steps()->pending()->count())->toBe(0);
});

it('finds resumable and stalled sagas, oldest stall first', function (): void {
    $tenant = Tenant::factory()->create();

    $running = Saga::factory()->stalled(900)->create(['tenant_id' => $tenant->id]);
    $compensating = Saga::factory()->compensating()->uncorrelated()->create(['tenant_id' => $tenant->id]);
    Saga::factory()->completed()->uncorrelated()->create(['tenant_id' => $tenant->id]);
    Saga::factory()->failed()->uncorrelated()->create(['tenant_id' => $tenant->id]);

    expect(Saga::forTenant($tenant)->resumable()->pluck('id')->all())
        ->toBe([$running->id, $compensating->id])
        ->and(Saga::forTenant($tenant)->stalled(600)->pluck('id')->all())->toBe([$running->id])
        ->and(Saga::forTenant($tenant)->stalled(3600)->count())->toBe(0);
});

it('narrows to a saga by its business key', function (): void {
    $tenant = Tenant::factory()->create();
    Saga::factory()->create(['tenant_id' => $tenant->id, 'correlation_id' => 'ord-7']);
    Saga::factory()->create(['tenant_id' => $tenant->id, 'correlation_id' => 'ord-8']);

    expect(Saga::forTenant($tenant)->forCorrelation(SagaFactory::ORDER_FULFILLMENT, 'ord-7')->sole()->correlation_id)
        ->toBe('ord-7')
        ->and(Saga::forTenant($tenant)->ofType(SagaFactory::ORDER_FULFILLMENT)->count())->toBe(2)
        ->and(Saga::forTenant($tenant)->ofType('DRIP')->count())->toBe(0);
});

it('isolates sagas between tenants and stamps the acting tenant on create', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    seedSaga($acme, doneCount: 1);
    seedSaga($globex, doneCount: 2);

    $context = app(TenantContext::class);
    $context->set($acme);

    $created = Saga::create(['type' => 'DRIP', 'correlation_id' => 'drip-1']);

    expect($created->tenant_id)->toBe($acme->id)
        ->and(Saga::query()->count())->toBe(2)
        ->and(Saga::query()->pluck('tenant_id')->unique()->values()->all())->toBe([$acme->id]);

    $context->forget();
    $context->set($globex);

    expect(Saga::query()->count())->toBe(1)
        ->and(Saga::query()->sole()->tenant_id)->toBe($globex->id);
});

it('fails closed when sagas are queried with no tenant bound', function (): void {
    Saga::factory()->create();
    app(TenantContext::class)->forget();

    expect(fn () => Saga::query()->count())->toThrow(MissingTenantContextException::class);
});

it('lets the recovery sweep find work across tenants and run it scoped', function (): void {
    // The sanctioned two-step shape: withoutTenantScope() to *find* the work, runFor()
    // so the run itself is scoped to the owner. No platform-mode bypass needed.
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    seedSaga($acme, doneCount: 1);
    seedSaga($globex, doneCount: 2, status: SagaStatus::Compensating);
    Saga::factory()->completed()->uncorrelated()->create(['tenant_id' => $acme->id]);

    $context = app(TenantContext::class);
    $context->forget();

    $swept = [];

    foreach (Saga::withoutTenantScope()->resumable()->get() as $saga) {
        $swept[] = $context->runFor($saga->tenant, function () use ($saga): string {
            // Inside the frame the scope applies normally, so the saga really is
            // readable as its owner — and only as its owner.
            expect(Saga::query()->whereKey($saga->id)->exists())->toBeTrue();

            return (string) $saga->tenant_id;
        });
    }

    expect($swept)->toHaveCount(2)
        ->and(array_unique($swept))->toHaveCount(2);
});

it('removes a saga and its steps when the tenant is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = seedSaga($tenant, doneCount: 2);

    $tenant->delete();

    expect(Saga::forTenant($tenant)->exists())->toBeFalse()
        ->and(DB::table('saga_steps')->where('saga_id', $saga->id)->count())->toBe(0);
});

it('removes steps when their saga is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $saga = seedSaga($tenant, doneCount: 1);

    app(TenantContext::class)->set($tenant);
    $saga->delete();

    expect(DB::table('saga_steps')->where('saga_id', $saga->id)->count())->toBe(0);
});
