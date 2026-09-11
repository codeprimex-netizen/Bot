<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\Security\PermissionDeniedException;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\ApiTokenIdentity;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The `RbacService` implementation: roles from `tenant_users`, permissions from the
 * `TenantPermission` matrix, scopes from the presented API key (Req 32.1 / NFR3).
 *
 * Read `RbacService` for the contract. This class is where the four decisions that make
 * it fail closed live.
 *
 * ## 1. Membership is read by (user, tenant), explicitly
 *
 * `tenant_users` is the one tenant-carrying table without `BelongsToTenant` — it is what
 * *answers* "which tenant is acting?", so it cannot be filtered by the answer (see the
 * `TenantUser` docblock). That means the tenant is not applied for us here, so every
 * query below names **both** columns. A query that named only `user_id` would return the
 * user's role in whichever tenant sorted first, which is a cross-tenant privilege
 * escalation in the shape of a missing `where`.
 *
 * ## 2. `joined_at` is part of the predicate, not a display detail
 *
 * An invitation creates the row before the person accepts. Treating a pending row as a
 * membership would mean inviting somebody grants them the role immediately — and an
 * invitation is exactly the thing an attacker can cause to exist. `SessionTenantResolver`
 * already takes this position for resolution; this is the same rule for authorization.
 *
 * ## 3. Memoisation lives for one unit of work
 *
 * A panel screen asks about several permissions per render, so `(userId, tenantId) → role`
 * is cached on this instance — and `RbacServiceProvider` binds it `scoped()`, so the cache
 * dies with the request or the job. A role revoked mid-request therefore takes effect on
 * the next request rather than whenever a worker happens to restart. Misses are cached
 * too: "not a member" is the answer an attacker probes for, and re-querying it on every
 * check would make refusal the expensive path.
 *
 * ## 4. Nothing here can widen the matrix
 *
 * `roleAllows()` delegates to `TenantPermission::allowedRoles()` and adds no special
 * cases: no super-role, no config override, no "owner may do anything" shortcut. Owner
 * holds everything because the matrix says so in one readable place, which is also the
 * place a reviewer can check.
 */
final class DatabaseRbacService implements RbacService
{
    /**
     * `"{userId}|{tenantId}" => TenantRole|null` for this unit of work.
     *
     * @var array<string, TenantRole|null>
     */
    private array $roles = [];

    public function __construct(private readonly TenantContext $context) {}

    public function roleFor(User|int|string $user, Tenant|string|null $tenant = null): ?TenantRole
    {
        $userId = $this->userId($user);
        $tenantId = $this->tenantId($tenant);

        if ($userId === null || $tenantId === null) {
            return null;
        }

        $cacheKey = $userId.'|'.$tenantId;

        if (array_key_exists($cacheKey, $this->roles)) {
            return $this->roles[$cacheKey];
        }

        $membership = TenantUser::query()
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            // An unaccepted invitation is not a membership (see the class docblock).
            ->whereNotNull('joined_at')
            ->first();

        return $this->roles[$cacheKey] = $membership?->role;
    }

    public function roleAllows(?TenantRole $role, TenantPermission $permission): bool
    {
        return $role !== null && $permission->grantedTo($role);
    }

    public function allows(User|int|string $user, TenantPermission $permission, Tenant|string|null $tenant = null): bool
    {
        if ($this->context->actingAsPlatform()) {
            return false;
        }

        return $this->roleAllows($this->roleFor($user, $tenant), $permission);
    }

    public function authorize(User|int|string $user, TenantPermission $permission, Tenant|string|null $tenant = null): void
    {
        if ($this->context->actingAsPlatform()) {
            throw PermissionDeniedException::inPlatformMode($permission);
        }

        $tenantId = $this->tenantId($tenant);

        if ($tenantId === null) {
            throw PermissionDeniedException::withoutTenant($permission);
        }

        $role = $this->roleFor($user, $tenantId);

        if ($role === null) {
            throw PermissionDeniedException::withoutMembership($permission, $tenantId);
        }

        if (! $this->roleAllows($role, $permission)) {
            throw PermissionDeniedException::forRole($permission, $role, $tenantId);
        }
    }

    public function tokenAllows(?ApiTokenIdentity $token, TenantPermission $permission, Tenant|string|null $tenant = null): bool
    {
        if ($token === null || $this->context->actingAsPlatform()) {
            return false;
        }

        $tenantId = $this->tenantId($tenant);

        return $tenantId !== null && $token->actsAs($tenantId) && $token->allows($permission);
    }

    public function authorizeToken(?ApiTokenIdentity $token, TenantPermission $permission, Tenant|string|null $tenant = null): void
    {
        if ($this->context->actingAsPlatform()) {
            throw PermissionDeniedException::inPlatformMode($permission);
        }

        if ($token === null) {
            throw PermissionDeniedException::withoutIdentity($permission);
        }

        $tenantId = $this->tenantId($tenant);

        if ($tenantId === null) {
            throw PermissionDeniedException::withoutTenant($permission);
        }

        if (! $token->actsAs($tenantId)) {
            throw PermissionDeniedException::forForeignToken($permission, $token->tokenId, $tenantId);
        }

        if (! $token->allows($permission)) {
            throw PermissionDeniedException::forToken($permission, $token->tokenId);
        }
    }

    /**
     * @return list<TenantPermission>
     */
    public function permissionsFor(TenantRole $role): array
    {
        return TenantPermission::forRole($role);
    }

    public function forgetRoles(): void
    {
        $this->roles = [];
    }

    /**
     * The tenant a check is about: the argument, or the bound tenant.
     *
     * `null` means "no tenant", which every caller above turns into a refusal.
     */
    private function tenantId(Tenant|string|null $tenant): ?string
    {
        if ($tenant instanceof Tenant) {
            $id = $tenant->getKey();

            return is_string($id) && $id !== '' ? $id : null;
        }

        if (is_string($tenant)) {
            return $tenant === '' ? null : $tenant;
        }

        return $this->context->currentId();
    }

    /**
     * The identifier of the user a check is about.
     *
     * `Authenticatable` is accepted as well as `User` so a check works against whatever
     * guard produced the caller, and a value that is not a usable identifier is `null`
     * rather than cast — casting would make an unexpected type resolve to *some* user.
     */
    private function userId(User|int|string $user): ?string
    {
        if ($user instanceof Authenticatable) {
            $id = $user->getAuthIdentifier();

            return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : null;
        }

        return is_int($user) || $user !== '' ? (string) $user : null;
    }
}
