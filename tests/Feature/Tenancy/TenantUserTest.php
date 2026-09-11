<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\QueryException;

it('links a user to a tenant with a role', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $membership = TenantUser::create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role' => TenantRole::Owner,
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    $fresh = TenantUser::query()->findOrFail($membership->id);

    expect($fresh->role)->toBe(TenantRole::Owner)
        ->and($fresh->tenant->is($tenant))->toBeTrue()
        ->and($fresh->user->is($user))->toBeTrue()
        ->and($fresh->isPending())->toBeFalse();
});

it('round-trips every TenantRole value through the database', function (): void {
    $tenant = Tenant::factory()->create();

    foreach (TenantRole::cases() as $case) {
        $membership = TenantUser::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => $case,
        ]);

        $stored = DB::table('tenant_users')->where('id', $membership->id)->value('role');

        expect($stored)->toBe($case->value)
            ->and(TenantUser::query()->findOrFail($membership->id)->role)->toBe($case);
    }
});

it('enforces uniq(tenant_id, user_id)', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

    expect(fn () => TenantUser::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role' => TenantRole::Admin,
    ]))->toThrow(QueryException::class);

    expect(TenantUser::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('lets one user belong to several tenants with different roles', function (): void {
    $user = User::factory()->create();
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();

    TenantUser::factory()->create([
        'tenant_id' => $acme->id,
        'user_id' => $user->id,
        'role' => TenantRole::Owner,
    ]);
    TenantUser::factory()->create([
        'tenant_id' => $globex->id,
        'user_id' => $user->id,
        'role' => TenantRole::Agent,
    ]);

    /** @var TenantUser $acmePivot */
    $acmePivot = $acme->users()->firstOrFail()->getRelationValue('pivot');
    /** @var TenantUser $globexPivot */
    $globexPivot = $globex->users()->firstOrFail()->getRelationValue('pivot');

    expect(TenantUser::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($acmePivot)->toBeInstanceOf(TenantUser::class)
        ->and($acmePivot->role)->toBe(TenantRole::Owner)
        ->and($globexPivot->role)->toBe(TenantRole::Agent);
});

it('exposes tenant members through the tenant relationships', function (): void {
    $tenant = Tenant::factory()->create();
    $members = User::factory()->count(3)->create();

    foreach ($members as $member) {
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $member->id]);
    }

    expect($tenant->tenantUsers)->toHaveCount(3)
        ->and($tenant->users)->toHaveCount(3)
        ->and($tenant->users->pluck('id')->sort()->values()->all())
        ->toBe($members->pluck('id')->sort()->values()->all());
});

it('treats a membership without joined_at as a pending invitation', function (): void {
    $membership = TenantUser::factory()->pendingInvite()->create();

    expect($membership->isPending())->toBeTrue()
        ->and($membership->invited_at)->not->toBeNull();
});

it('leaves platform super-admins without any tenant membership row', function (): void {
    $superAdmin = User::factory()->create();

    expect(TenantUser::query()->where('user_id', $superAdmin->id)->exists())->toBeFalse();
});

it('removes memberships when the tenant is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    TenantUser::factory()->count(2)->create(['tenant_id' => $tenant->id]);

    $tenant->delete();

    expect(TenantUser::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});
