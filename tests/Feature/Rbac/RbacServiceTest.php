<?php

declare(strict_types=1);

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\Security\PermissionDeniedException;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Rbac\RbacService;
use App\Services\Tenancy\TenantContext;

/*
|--------------------------------------------------------------------------
| RBAC: deny by default, and every way of not being allowed (Req 32.1 / NFR3)
|--------------------------------------------------------------------------
| `RbacService` is the primitive task 30.4's role-management screen calls and
| `EnsurePermission` enforces with. The interesting cases are all refusals, because
| a permission system is only as good as the states in which it says no: no
| membership, an unaccepted invitation, a membership in *another* tenant, no tenant
| bound at all, and the audited platform bypass.
*/

/**
 * A user with a role in a tenant, and that tenant bound to the context.
 *
 * @return array{0: User, 1: Tenant}
 */
function rbacMember(TenantRole $role = TenantRole::Operator, bool $accepted = true): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $factory = TenantUser::factory();

    if (! $accepted) {
        $factory = $factory->pendingInvite();
    }

    $factory->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    app(TenantContext::class)->set($tenant);

    return [$user, $tenant];
}

function rbac(): RbacService
{
    // Resolved fresh: the service memoises roles for its unit of work, and a test that
    // changes a membership must not be answered from a cache built before the change.
    app()->forgetInstance(RbacService::class);

    return app(RbacService::class);
}

it('permits what the role holds and refuses what it does not', function (): void {
    [$user] = rbacMember(TenantRole::Operator);

    expect(rbac()->allows($user, TenantPermission::CampaignsManage))->toBeTrue()
        ->and(rbac()->allows($user, TenantPermission::TenantBillingManage))->toBeFalse();

    rbac()->authorize($user, TenantPermission::CampaignsManage);

    expect(fn () => rbac()->authorize($user, TenantPermission::TenantBillingManage))
        ->toThrow(PermissionDeniedException::class);
});

it('refuses a stranger to the tenant', function (): void {
    [, $tenant] = rbacMember(TenantRole::Owner);
    $stranger = User::factory()->create();

    expect(rbac()->roleFor($stranger, $tenant))->toBeNull()
        ->and(rbac()->allows($stranger, TenantPermission::MessagesRead))->toBeFalse()
        ->and(fn () => rbac()->authorize($stranger, TenantPermission::MessagesRead))
        ->toThrow(PermissionDeniedException::class, 'No accepted membership');
});

it('refuses an invitation that has not been accepted', function (): void {
    // The row exists before the person accepts, and an invitation is exactly the thing
    // an attacker can cause to exist. Inviting somebody must not grant them the role.
    [$invited] = rbacMember(TenantRole::Admin, accepted: false);

    expect(rbac()->roleFor($invited))->toBeNull()
        ->and(rbac()->allows($invited, TenantPermission::TenantSettingsManage))->toBeFalse();
});

it('never answers with a role held in another tenant', function (): void {
    $owned = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = User::factory()->create();

    TenantUser::factory()->create([
        'tenant_id' => $owned->id,
        'user_id' => $user->id,
        'role' => TenantRole::Owner,
    ]);

    app(TenantContext::class)->set($other);

    // `tenant_users` carries no global tenant scope (it is what *answers* "which tenant
    // is acting?"), so a query that named only user_id would return this owner row and
    // escalate them into a tenant they have never joined.
    expect(rbac()->roleFor($user))->toBeNull()
        ->and(rbac()->allows($user, TenantPermission::TenantSettingsManage))->toBeFalse()
        ->and(rbac()->roleFor($user, $owned))->toBe(TenantRole::Owner);
});

it('refuses everything when no tenant is bound', function (): void {
    [$user] = rbacMember(TenantRole::Owner);
    app(TenantContext::class)->forget();

    expect(rbac()->roleFor($user))->toBeNull()
        ->and(rbac()->allows($user, TenantPermission::MessagesRead))->toBeFalse()
        ->and(fn () => rbac()->authorize($user, TenantPermission::MessagesRead))
        ->toThrow(PermissionDeniedException::class, 'No tenant is bound');
});

it('refuses in platform mode, because the platform bypass is not a tenant role', function (): void {
    [$user, $tenant] = rbacMember(TenantRole::Owner);
    app(TenantContext::class)->forget();

    app(TenantContext::class)->asPlatform('rbac test', function () use ($user, $tenant): void {
        // Permitting here would turn the audited cross-tenant read of Req 1.5 into a way
        // past the tenant matrix. Platform administration has its own boundary (task 30.1).
        expect(rbac()->allows($user, TenantPermission::MessagesRead, $tenant))->toBeFalse()
            ->and(fn () => rbac()->authorize($user, TenantPermission::MessagesRead, $tenant))
            ->toThrow(PermissionDeniedException::class, 'Platform mode is not a tenant role');
    });
});

it('treats a null role as holding nothing', function (): void {
    foreach (TenantPermission::cases() as $permission) {
        expect(rbac()->roleAllows(null, $permission))->toBeFalse();
    }
});

it('reports the permissions of a role for the screens that render them', function (): void {
    rbacMember();

    expect(rbac()->permissionsFor(TenantRole::Viewer))->toBe(TenantPermission::forRole(TenantRole::Viewer))
        ->and(rbac()->permissionsFor(TenantRole::Owner))->toBe(TenantPermission::cases());
});

it('answers repeated checks from one membership query, and forgets on demand', function (): void {
    [$user, $tenant] = rbacMember(TenantRole::Viewer);
    $service = rbac();

    expect($service->allows($user, TenantPermission::MessagesRead))->toBeTrue();

    // Promote the row underneath the memoised answer.
    TenantUser::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->update(['role' => TenantRole::Owner->value]);

    // Still the cached role: the cache's lifetime is one request/job, so a change takes
    // effect on the next unit of work rather than halfway through this one.
    expect($service->allows($user, TenantPermission::TenantBillingManage))->toBeFalse();

    $service->forgetRoles();

    expect($service->allows($user, TenantPermission::TenantBillingManage))->toBeTrue();
});

it('carries no denial reason into the response, only into the message', function (): void {
    [, $tenant] = rbacMember(TenantRole::Owner);
    $stranger = User::factory()->create();

    try {
        rbac()->authorize($stranger, TenantPermission::TenantBillingManage);
    } catch (PermissionDeniedException $e) {
        expect($e->getStatusCode())->toBe(403)
            ->and($e->publicMessage())->toBe(PermissionDeniedException::PUBLIC_MESSAGE)
            ->and($e->permission)->toBe(TenantPermission::TenantBillingManage)
            // Tenant ids are fingerprinted, never printed — the SecurityException rule.
            ->and($e->getMessage())->not->toContain($tenant->id);
    }
});
