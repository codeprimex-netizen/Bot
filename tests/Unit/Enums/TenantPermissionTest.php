<?php

declare(strict_types=1);

use App\Enums\TenantPermission;
use App\Enums\TenantRole;

/*
|--------------------------------------------------------------------------
| The role → permission matrix (Req 32.1 / NFR3, task 4.6)
|--------------------------------------------------------------------------
| The matrix is the whole of the RBAC decision, so these tests are about its
| *shape* rather than about individual grants: every permission is decided, nobody
| outranks the owner, the seniority chain never inverts, and the two `TenantRole`
| predicates that predate this enum still agree with it.
|
| A permission added without a matrix row cannot reach these tests — an exhaustive
| `match` with no default arm fails static analysis first and raises at runtime
| second. That is the intended order: deny by default is a property of the type,
| and these tests check the things a type cannot.
*/

it('decides every permission, and grants none of them to nobody', function (): void {
    foreach (TenantPermission::cases() as $permission) {
        // No empty row: a permission no role can exercise is dead configuration that
        // reads like a feature.
        expect($permission->allowedRoles())->not->toBeEmpty()
            // Roles, not strings: nothing here can name a role that does not exist.
            ->and($permission->allowedRoles())->each->toBeInstanceOf(TenantRole::class);
    }
});

it('has no wildcard and no permission whose key is empty', function (): void {
    foreach (TenantPermission::keys() as $key) {
        expect($key)->not->toBe('')
            ->and($key)->not->toBe('*')
            // Dotted, lowercase, no spaces: these strings are stored in token scope
            // lists and typed into route declarations.
            ->and($key)->toMatch('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/');
    }

    expect(TenantPermission::tryFromKey('*'))->toBeNull()
        ->and(TenantPermission::tryFromKey('messages.*'))->toBeNull()
        ->and(TenantPermission::tryFromKey(''))->toBeNull()
        // A prefix of a real key is not a key: partial matching would make
        // `messages` a wildcard for `messages.send`.
        ->and(TenantPermission::tryFromKey('messages'))->toBeNull();
});

it('tolerates surrounding whitespace but nothing else, for stored and declared keys', function (): void {
    expect(TenantPermission::tryFromKey(' messages.send '))->toBe(TenantPermission::MessagesSend)
        ->and(TenantPermission::coerce('messages.send'))->toBe(TenantPermission::MessagesSend)
        ->and(TenantPermission::tryFromKey('MESSAGES.SEND'))->toBeNull();
});

it('raises on an unknown key from a route declaration, and reports the known ones', function (): void {
    // A typo in `tenant.permission:...` is a developer mistake: a 500 on the first
    // request is loud, where denying would look exactly like a permissions bug and
    // would lock a working screen for every tenant.
    expect(fn (): TenantPermission => TenantPermission::coerce('campaigns.manages'))
        ->toThrow(InvalidArgumentException::class, 'Unknown tenant permission');

    try {
        TenantPermission::coerce('nope');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('campaigns.manage');
    }
});

it('gives the owner everything, so no role can outrank the tenant owner', function (): void {
    expect(TenantPermission::forRole(TenantRole::Owner))->toBe(TenantPermission::cases());
});

it('keeps the seniority chain monotonic: owner ⊇ admin ⊇ operator ⊇ agent ⊇ viewer', function (): void {
    $chain = [TenantRole::Owner, TenantRole::Admin, TenantRole::Operator, TenantRole::Agent, TenantRole::Viewer];

    for ($i = 0; $i < count($chain) - 1; $i++) {
        $senior = TenantPermission::forRole($chain[$i]);
        $junior = TenantPermission::forRole($chain[$i + 1]);

        $extra = array_values(array_filter(
            $junior,
            static fn (TenantPermission $permission): bool => ! in_array($permission, $senior, true),
        ));

        // A junior role holding something its senior does not would mean demoting
        // somebody could *grant* them a power — the kind of inversion nobody notices
        // until it is exploited.
        expect($extra)->toBe([], sprintf(
            '[%s] holds permissions [%s] does not.',
            $chain[$i + 1]->value,
            $chain[$i]->value,
        ));
    }
});

it('grants administrative permissions only to administrative roles', function (): void {
    $administrative = array_values(array_filter(
        TenantRole::cases(),
        static fn (TenantRole $role): bool => $role->isAdministrative(),
    ));

    foreach (TenantPermission::cases() as $permission) {
        if (! $permission->isAdministrative()) {
            continue;
        }

        $nonAdministrative = array_values(array_filter(
            $permission->allowedRoles(),
            static fn (TenantRole $role): bool => ! in_array($role, $administrative, true),
        ));

        // `TenantRole::isAdministrative()` predates this matrix (task 0.2) and stays
        // authoritative: a role that stops being administrative must not keep
        // administrative permissions by accident.
        expect($nonAdministrative)->toBe([], sprintf(
            '[%s] is administrative but is granted to a non-administrative role.',
            $permission->value,
        ));
    }
});

it('grants conversation handling to exactly the roles that may handle conversations', function (): void {
    $expected = array_values(array_filter(
        TenantRole::cases(),
        static fn (TenantRole $role): bool => $role->canHandleConversations(),
    ));

    expect(TenantPermission::ConversationsHandle->allowedRoles())->toBe($expected);
});

it('reserves ending the tenant for the owner alone', function (): void {
    expect(TenantPermission::TenantLifecycleManage->allowedRoles())->toBe([TenantRole::Owner])
        ->and(TenantPermission::TenantLifecycleManage->grantedTo(TenantRole::Admin))->toBeFalse();
});

it('gives a viewer reads and nothing that mutates', function (): void {
    $viewer = TenantPermission::forRole(TenantRole::Viewer);

    expect($viewer)->toBe([
        TenantPermission::MessagesRead,
        TenantPermission::ContactsRead,
        TenantPermission::ReportsView,
    ]);

    foreach ($viewer as $permission) {
        expect($permission->isAdministrative())->toBeFalse();
    }
});
