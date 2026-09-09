<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Enums\TenantStatus;
use App\Exceptions\Tenancy\TenantNotOperationalException;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\Lifecycle;

/*
|--------------------------------------------------------------------------
| What suspension actually *means* (Req 1.1 / A1; Req 10.3 / B1)
|--------------------------------------------------------------------------
| The design's one-line spec for suspension is "block all outbound, keep inbound
| logging, panels read-only", and a status column enforces none of that by itself. The
| three guards below are the enforcement, and they are the only place that meaning is
| written down — so these tests are the specification of suspension, and the later tasks
| that must call them (9.3 send gate, 11.2/11.3 inbound, Phase C panels) inherit their
| behaviour from here rather than re-deriving it.
*/

/*
|--------------------------------------------------------------------------
| Block outbound
|--------------------------------------------------------------------------
*/

it('blocks outbound for a suspended tenant and allows it for an operational one', function (TenantStatus $status, bool $maySend): void {
    $tenant = Tenant::factory()->create(['status' => $status]);

    expect(Lifecycle::service()->canSendOutbound($tenant))->toBe($maySend)
        // The model's convenience read and the enum's rule must agree with the guard.
        ->and($tenant->isOperational())->toBe($maySend)
        ->and($status->isOperational())->toBe($maySend);
})->with([
    'active sends' => [TenantStatus::Active, true],
    'trial sends' => [TenantStatus::Trial, true],
    'suspended does not' => [TenantStatus::Suspended, false],
    'cancelled does not' => [TenantStatus::Cancelled, false],
]);

it('refuses the send with a 403 that names the status, for the task 9.3 choke point', function (): void {
    $tenant = Tenant::factory()->create();
    Lifecycle::service()->suspend($tenant, 'non-payment');

    try {
        Lifecycle::service()->assertCanSendOutbound($tenant);
        $this->fail('A suspended tenant must not pass the outbound guard.');
    } catch (TenantNotOperationalException $exception) {
        expect($exception->status)->toBe(TenantStatus::Suspended)
            ->and($exception->tenantId)->toBe($tenant->id)
            ->and($exception->getStatusCode())->toBe(403)
            ->and($exception->getMessage())->toContain('SUSPENDED');
    }
});

it('guards a tenant known only by id, as the send path and the webhook intake do', function (): void {
    $tenant = Tenant::factory()->suspended()->create();

    expect(Lifecycle::service()->canSendOutbound($tenant->id))->toBeFalse()
        ->and(Lifecycle::service()->canMutate($tenant->id))->toBeFalse()
        ->and(Lifecycle::service()->canRecordInbound($tenant->id))->toBeTrue();

    expect(fn () => Lifecycle::service()->assertCanSendOutbound($tenant->id))
        ->toThrow(TenantNotOperationalException::class);
});

it('fails closed for a tenant id that resolves to nothing', function (): void {
    // A queued job draining after its tenant is gone: no status to trust, no send.
    expect(Lifecycle::service()->canSendOutbound('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeFalse()
        ->and(Lifecycle::service()->canRecordInbound('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeFalse()
        ->and(Lifecycle::service()->canMutate(''))->toBeFalse();

    expect(fn () => Lifecycle::service()->assertCanSendOutbound('01JZZZZZZZZZZZZZZZZZZZZZZZ'))
        ->toThrow(TenantNotOperationalException::class, 'No tenant');
});

/*
|--------------------------------------------------------------------------
| Keep the inbound log (Req 10.3)
|--------------------------------------------------------------------------
*/

it('keeps inbound recording while suspended but forbids the automatic reply', function (): void {
    $tenant = Tenant::factory()->create();
    Lifecycle::service()->suspend($tenant, 'invoice overdue');

    // Req 10.3, exactly: log the inbound message, send no automatic reply.
    expect(Lifecycle::service()->canRecordInbound($tenant))->toBeTrue()
        ->and(Lifecycle::service()->canAutoReply($tenant))->toBeFalse();
});

it('stops accepting new inbound data once a tenant is cancelled', function (): void {
    $tenant = Tenant::factory()->cancelled()->create();

    // Inside its deletion window a tenant must not accumulate new personal data.
    expect(Lifecycle::service()->canRecordInbound($tenant))->toBeFalse()
        ->and(Lifecycle::service()->canAutoReply($tenant))->toBeFalse();
});

it('lets an operational tenant both record and reply', function (TenantStatus $status): void {
    $tenant = Tenant::factory()->create(['status' => $status]);

    expect(Lifecycle::service()->canRecordInbound($tenant))->toBeTrue()
        ->and(Lifecycle::service()->canAutoReply($tenant))->toBeTrue();
})->with([
    'active' => [TenantStatus::Active],
    'trial' => [TenantStatus::Trial],
]);

/*
|--------------------------------------------------------------------------
| Panels read-only
|--------------------------------------------------------------------------
*/

it('puts the panel in read-only mode while suspended: reads work, writes are refused', function (): void {
    $tenant = Tenant::factory()->create();
    Lifecycle::tenancy()->set($tenant);
    TenantUsage::factory()->create([
        'tenant_id' => $tenant->id,
        'kind' => QuotaKind::MessagesMonthly,
        'used' => 12,
    ]);

    Lifecycle::service()->suspend($tenant, 'abuse investigation');

    expect(Lifecycle::service()->isReadOnly($tenant))->toBeTrue()
        ->and(Lifecycle::service()->canMutate($tenant))->toBeFalse();

    // The read half: the tenant can still see everything it had.
    expect(TenantUsage::query()->count())->toBe(1)
        ->and($tenant->usage()->sum('used'))->toEqual(12)
        ->and(Tenant::query()->whereKey($tenant->id)->sole()->status)->toBe(TenantStatus::Suspended);

    // The write half: refused at the guard, naming what was attempted.
    try {
        Lifecycle::service()->assertCanMutate($tenant, 'update chatbot');
        $this->fail('A suspended tenant must not pass the mutation guard.');
    } catch (TenantNotOperationalException $exception) {
        expect($exception->intent)->toBe('update chatbot')
            ->and($exception->getStatusCode())->toBe(403)
            ->and($exception->getMessage())->toContain('read-only');
    }
});

it('exposes the panel guard as a gate ability, so components authorize instead of branching', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    thisTest()->actingAs($user);

    expect(Gate::allows('tenant.mutate', $tenant))->toBeTrue()
        ->and(Gate::allows('tenant.send', $tenant))->toBeTrue();

    Lifecycle::service()->suspend($tenant, 'non-payment');

    expect(Gate::denies('tenant.mutate', $tenant))->toBeTrue()
        ->and(Gate::denies('tenant.send', $tenant))->toBeTrue()
        ->and(Gate::inspect('tenant.mutate', $tenant)->status())->toBe(403);
});

it('answers the gate for contexts with no authenticated user — console, queue, webhook', function (): void {
    $tenant = Tenant::factory()->suspended()->create();

    expect(auth()->check())->toBeFalse()
        ->and(Gate::denies('tenant.mutate', $tenant))->toBeTrue();

    expect(Gate::allows('tenant.mutate', Tenant::factory()->create()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Reactivation restores everything suspension took away
|--------------------------------------------------------------------------
*/

it('restores sending, replying, and panel writes on reactivation', function (): void {
    $tenant = Tenant::factory()->create();
    $service = Lifecycle::service();

    $service->suspend($tenant, 'non-payment');
    expect($service->canSendOutbound($tenant))->toBeFalse();

    $service->reactivate($tenant, 'paid');

    expect($service->canSendOutbound($tenant))->toBeTrue()
        ->and($service->canAutoReply($tenant))->toBeTrue()
        ->and($service->canMutate($tenant))->toBeTrue()
        ->and($service->isReadOnly($tenant))->toBeFalse()
        ->and(Gate::allows('tenant.send', $tenant))->toBeTrue();

    // Nothing to rebuild: suspension only ever changed the status, so both assertions
    // pass again — they throw if they do not.
    $service->assertCanSendOutbound($tenant);
    $service->assertCanMutate($tenant, 'update chatbot');

    expect($tenant->fresh()?->suspended_at)->toBeNull();
});
