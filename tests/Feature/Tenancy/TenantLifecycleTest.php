<?php

declare(strict_types=1);

use App\Enums\AuditActorType;
use App\Enums\TenantStatus;
use App\Exceptions\Tenancy\InvalidTenantTransitionException;
use App\Models\Tenant;
use App\Models\User;
use Tests\Fixtures\Lifecycle;

/*
|--------------------------------------------------------------------------
| The tenant state machine (Req 1.1 / A1; design.md §"Tenant lifecycle")
|--------------------------------------------------------------------------
| `TenantStatus` already owns which edges exist and has its own unit test; what is
| asserted here is the service's half — that an edge outside the diagram is refused
| loudly, that a repeat call is a no-op rather than a second event, that `CANCELLED`
| really is the end, and that every transition leaves one audit row on the tenant's own
| chain from each of the three contexts a transition actually happens in.
*/

/*
|--------------------------------------------------------------------------
| Legal edges
|--------------------------------------------------------------------------
*/

it('performs every transition the lifecycle diagram allows', function (TenantStatus $from, TenantStatus $to): void {
    $tenant = Tenant::factory()->create(['status' => $from]);

    $returned = Lifecycle::service()->transitionTo($tenant, $to, 'because the test said so');

    expect($returned->status)->toBe($to)
        ->and($tenant->fresh()?->status)->toBe($to)
        ->and(Lifecycle::trail($tenant))->toHaveCount(1);
})->with([
    'trial subscribes' => [TenantStatus::Trial, TenantStatus::Active],
    'trial suspended for abuse' => [TenantStatus::Trial, TenantStatus::Suspended],
    'trial cancelled' => [TenantStatus::Trial, TenantStatus::Cancelled],
    'active suspended' => [TenantStatus::Active, TenantStatus::Suspended],
    'active cancelled' => [TenantStatus::Active, TenantStatus::Cancelled],
    'suspended reactivated' => [TenantStatus::Suspended, TenantStatus::Active],
    'suspended cancelled' => [TenantStatus::Suspended, TenantStatus::Cancelled],
]);

it('walks the whole TRIAL -> ACTIVE -> SUSPENDED -> CANCELLED path through the named methods', function (): void {
    $tenant = Tenant::factory()->trial()->create();
    $service = Lifecycle::service();

    $service->activate($tenant, 'card added');
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active);

    $service->suspend($tenant, 'invoice 3 unpaid');
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->fresh()?->suspended_at)->not->toBeNull();

    $service->reactivate($tenant, 'invoice 3 settled');
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        // Answers "when did the *current* suspension start?", so it is cleared on the way
        // out; the audit trail keeps the history of past suspensions.
        ->and($tenant->fresh()?->suspended_at)->toBeNull();

    $service->cancel($tenant, 'customer left');
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Cancelled)
        ->and($tenant->fresh()?->cancelled_at)->not->toBeNull();

    expect(Lifecycle::trail($tenant)->pluck('action')->all())->toBe([
        'tenant.activated',
        'tenant.suspended',
        'tenant.reactivated',
        'tenant.cancelled',
    ]);
});

/*
|--------------------------------------------------------------------------
| Illegal edges — loud, never a silent no-op
|--------------------------------------------------------------------------
*/

it('refuses an edge the diagram does not have and leaves the tenant untouched', function (TenantStatus $from, TenantStatus $to): void {
    $tenant = Tenant::factory()->create(['status' => $from]);

    expect(fn (): Tenant => Lifecycle::service()->transitionTo($tenant, $to, 'not allowed'))
        ->toThrow(InvalidTenantTransitionException::class);

    expect($tenant->fresh()?->status)->toBe($from)
        ->and(Lifecycle::trail($tenant))->toHaveCount(0);
})->with([
    'no going back to trial from active' => [TenantStatus::Active, TenantStatus::Trial],
    'no going back to trial from suspended' => [TenantStatus::Suspended, TenantStatus::Trial],
    'cancelled cannot be revived' => [TenantStatus::Cancelled, TenantStatus::Active],
    'cancelled cannot be suspended' => [TenantStatus::Cancelled, TenantStatus::Suspended],
    'cancelled cannot be re-trialled' => [TenantStatus::Cancelled, TenantStatus::Trial],
]);

it('reports what was allowed instead, so a panel can offer the real next action', function (): void {
    $tenant = Tenant::factory()->suspended()->create();

    try {
        Lifecycle::service()->transitionTo($tenant, TenantStatus::Trial, 'nope');
        $this->fail('An illegal transition must throw.');
    } catch (InvalidTenantTransitionException $exception) {
        expect($exception->from)->toBe(TenantStatus::Suspended)
            ->and($exception->to)->toBe(TenantStatus::Trial)
            ->and($exception->tenantId)->toBe($tenant->id)
            ->and($exception->allowedNext())->toBe([TenantStatus::Active, TenantStatus::Cancelled])
            ->and($exception->getStatusCode())->toBe(409)
            ->and($exception->publicMessage())->not->toContain($tenant->id);
    }
});

it('treats CANCELLED as terminal for every other state', function (): void {
    $tenant = Tenant::factory()->cancelled()->create();

    expect($tenant->status->isTerminal())->toBeTrue();

    foreach ([TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Trial] as $target) {
        expect(fn (): Tenant => Lifecycle::service()->transitionTo($tenant, $target, 'attempt'))
            ->toThrow(InvalidTenantTransitionException::class, 'terminal');
    }
});

it('requires a reason, because the reason is the audit evidence', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): Tenant => Lifecycle::service()->suspend($tenant, '   '))
        ->toThrow(InvalidArgumentException::class);

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Active)
        ->and(Lifecycle::trail($tenant))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

it('treats a same-state transition as an idempotent success with no second audit row', function (): void {
    $tenant = Tenant::factory()->create();

    Lifecycle::service()->suspend($tenant, 'first call');
    $suspendedAt = $tenant->fresh()?->suspended_at;

    // A retried job or a double-clicked admin button: succeeds, changes nothing, records
    // nothing — one decision must not read as two in the trail.
    thisTest()->travel(5)->minutes();
    $returned = Lifecycle::service()->suspend($tenant, 'same call again');

    expect($returned->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->fresh()?->suspended_at?->format('Y-m-d H:i:s'))->toBe($suspendedAt?->format('Y-m-d H:i:s'))
        ->and(Lifecycle::trail($tenant))->toHaveCount(1);
});

it('keeps the original cancellation clock when cancel is called twice', function (): void {
    $tenant = Tenant::factory()->create();

    Lifecycle::service()->cancel($tenant, 'customer left');
    $cancelledAt = $tenant->fresh()?->cancelled_at;

    thisTest()->travel(2)->days();
    Lifecycle::service()->cancel($tenant, 'and again');

    // The retention window must not restart on a repeat call.
    expect($tenant->fresh()?->cancelled_at?->format('Y-m-d H:i:s'))->toBe($cancelledAt?->format('Y-m-d H:i:s'))
        ->and(Lifecycle::trail($tenant))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Audit trail (Req 24.2 / D1)
|--------------------------------------------------------------------------
*/

it('audits a transition with the actor, the from/to diff, the reason, and the tenant as subject', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    thisTest()->actingAs($user);

    Lifecycle::service()->suspend($tenant, 'chargeback on invoice 7');

    $entry = Lifecycle::trail($tenant)->sole();

    expect($entry->action)->toBe('tenant.suspended')
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->chain_key)->toBe($tenant->id)
        ->and($entry->subject_type)->toBe(Tenant::class)
        ->and($entry->subject_id)->toBe($tenant->id)
        ->and($entry->actor_type)->toBe(AuditActorType::User)
        ->and($entry->actor_id)->toBe((string) $user->getKey())
        ->and($entry->payload)->toMatchArray([
            'from' => 'ACTIVE',
            'to' => 'SUSPENDED',
            'reason' => 'chargeback on invoice 7',
        ]);
});

it('records what offboarding still owes when a tenant is cancelled', function (): void {
    $tenant = Tenant::factory()->create();

    Lifecycle::service()->cancel($tenant, 'right to delete requested');

    $payload = Lifecycle::trail($tenant)->sole()->payload;
    $offboarding = $payload['offboarding'] ?? null;

    expect($payload['to'] ?? null)->toBe('CANCELLED')
        ->and($offboarding)->toBeArray()
        ->and($offboarding['retention_days'] ?? null)->toBe(Lifecycle::service()->retentionDays())
        ->and($offboarding['owes'] ?? null)->toBe(['oltp', 'object_storage', 'vector_store', 'analytics'])
        ->and($offboarding['residual_objects'] ?? null)->toBeFalse()
        ->and($offboarding['purge_due_at'] ?? null)->toBeString();

    // ...and nothing was deleted: the design purges after the retention window, verified
    // (task 34.3), so cancellation must leave the data in place.
    expect(Tenant::query()->whereKey($tenant->id)->exists())->toBeTrue();
});

it('truncates an over-long reason instead of pasting a support thread into the trail', function (): void {
    $tenant = Tenant::factory()->create();

    Lifecycle::service()->suspend($tenant, str_repeat('a', 900));

    $reason = Lifecycle::trail($tenant)->sole()->payload['reason'] ?? '';

    expect($reason)->toBeString()
        ->and(mb_strlen((string) $reason))->toBeLessThanOrEqual(500);
});

/*
|--------------------------------------------------------------------------
| Every context a transition happens in
|--------------------------------------------------------------------------
*/

it('transitions from platform mode without duplicating the event onto the platform chain', function (): void {
    $tenant = Tenant::factory()->create();

    Lifecycle::tenancy()->asPlatform('admin suspends a tenant', function () use ($tenant): void {
        Lifecycle::service()->suspend($tenant, 'abuse report 42');
    });

    expect(Lifecycle::trail($tenant)->sole()->action)->toBe('tenant.suspended')
        // The scope bypass itself is audited by the platform-mode listener (its own two
        // rows); the lifecycle event is not written a second time alongside them.
        ->and(Lifecycle::platformTrail())->toHaveCount(0);
});

it('transitions with no tenant bound at all — the scheduler and console case', function (): void {
    $tenant = Tenant::factory()->create();

    expect(Lifecycle::tenancy()->current())->toBeNull();

    Lifecycle::service()->suspend($tenant, 'dunning: 3 failed charges');

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Suspended)
        ->and(Lifecycle::trail($tenant)->sole()->actor_type)->toBe(AuditActorType::System);
});

it('transitions in tenant self-service, with that tenant bound', function (): void {
    $tenant = Tenant::factory()->create();
    Lifecycle::tenancy()->set($tenant);

    Lifecycle::service()->cancel($tenant, 'owner closed the account');

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Cancelled)
        ->and(Lifecycle::trail($tenant)->sole()->action)->toBe('tenant.cancelled');
});

it('lands the audit entry on the subject tenant chain even when another tenant is bound', function (): void {
    $acting = Tenant::factory()->create();
    $subject = Tenant::factory()->create();
    Lifecycle::tenancy()->set($acting);

    Lifecycle::service()->suspend($subject, 'platform-wide abuse sweep');

    expect(Lifecycle::trail($subject)->sole()->action)->toBe('tenant.suspended')
        ->and(Lifecycle::trail($acting))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Offboarding seam for task 34.3
|--------------------------------------------------------------------------
*/

it('schedules the purge from cancelled_at plus the configured retention window', function (): void {
    config()->set('wa.tenancy.lifecycle.retention_days', 7);
    $tenant = Tenant::factory()->create();

    expect(Lifecycle::service()->purgeDueAt($tenant))->toBeNull()
        ->and(Lifecycle::service()->isPurgeDue($tenant))->toBeFalse();

    Lifecycle::service()->cancel($tenant, 'offboarding');

    expect(Lifecycle::service()->retentionDays())->toBe(7)
        ->and(Lifecycle::service()->purgeDueAt($tenant)?->format('Y-m-d'))->toBe(now()->addDays(7)->format('Y-m-d'))
        ->and(Lifecycle::service()->isPurgeDue($tenant))->toBeFalse();

    thisTest()->travel(8)->days();

    $reloaded = Tenant::query()->whereKey($tenant->id)->sole();

    expect(Lifecycle::service()->isPurgeDue($reloaded))->toBeTrue();
});

it('never lets a misconfigured retention window make data purgeable immediately', function (): void {
    config()->set('wa.tenancy.lifecycle.retention_days', 0);
    $tenant = Tenant::factory()->cancelled()->create();

    expect(Lifecycle::service()->retentionDays())->toBe(30)
        ->and(Lifecycle::service()->isPurgeDue($tenant))->toBeFalse();
});
