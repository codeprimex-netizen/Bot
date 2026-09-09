<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\Security\PermissionDeniedException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ApiTokenIdentity;

/**
 * The platform's authorization primitive: *may this caller do this thing inside this
 * tenant?* (Req 32.1 / NFR3; design.md § STRIDE rows "Panels (User/Admin)" and
 * "Public API"; named by task 30.4, which builds the screen that manages roles).
 *
 * ## What this is, and what it is not
 *
 * It answers **authorization** only. Three neighbouring questions belong to other
 * layers, and mixing any of them in here would make each one weaker:
 *
 * | Question | Owner |
 * |---|---|
 * | *which* tenant is acting? | `TenantContext` + `ResolveTenant` |
 * | may this row be touched by the acting tenant? | `TenantScope` + `TenantOwnershipGuard` |
 * | does the tenant's **plan** include this feature? | `PlanGate` + `plan.feature` |
 *
 * RBAC composes with all three rather than replacing them: a role that permits
 * `contacts.manage` still cannot reach another tenant's contacts, and still cannot use a
 * feature the plan omits.
 *
 * ## Two identities, one vocabulary
 *
 * A panel user's authority comes from their `TenantRole` in `tenant_users`; a machine
 * caller's comes from the scopes on its `TenantApiToken`. Both are expressed in
 * `TenantPermission`, so there is no role→scope mapping to drift (see that enum).
 *
 * ## Deny by default — the whole contract in five rules
 *
 * 1. **No membership, no permission.** A user with no *accepted* `tenant_users` row for
 *    the tenant holds nothing. A pending invitation is not a membership.
 * 2. **No tenant, no permission.** With nothing bound there is no membership to read, so
 *    the answer is "no" rather than "probably".
 * 3. **No scope, no permission.** A key does what it was issued for. An empty scope list
 *    — which is what every key issued before task 4.6 has — permits nothing, and there is
 *    no wildcard.
 * 4. **A key acts as exactly one tenant.** A verified key whose tenant is not the bound
 *    one is refused, not reconciled.
 * 5. **Platform mode is not a role.** `actingAsPlatform()` is refused here; platform
 *    administration has its own boundary (task 30.1's guard, IP allowlist, and throttle).
 *
 * The `allows*` methods are **total**: they return a boolean and never throw, so a caller
 * rendering a menu cannot accidentally 500 on a missing membership. The `authorize*`
 * methods are the enforcing form and throw `PermissionDeniedException` (403).
 */
interface RbacService
{
    /**
     * The role `$user` holds in `$tenant`, or `null` when there is no accepted
     * membership.
     *
     * `$tenant` defaults to the bound tenant; with none bound the answer is `null`.
     */
    public function roleFor(User|int|string $user, Tenant|string|null $tenant = null): ?TenantRole;

    /**
     * Whether a role carries a permission. `null` — "no role" — carries nothing.
     *
     * The pure part of the decision: no database, no context, no side effects.
     */
    public function roleAllows(?TenantRole $role, TenantPermission $permission): bool;

    /**
     * Whether `$user` may exercise `$permission` in `$tenant` (default: the bound
     * tenant).
     */
    public function allows(User|int|string $user, TenantPermission $permission, Tenant|string|null $tenant = null): bool;

    /**
     * Enforce `allows()`.
     *
     * @throws PermissionDeniedException
     */
    public function authorize(User|int|string $user, TenantPermission $permission, Tenant|string|null $tenant = null): void;

    /**
     * Whether a verified API key may exercise `$permission` in `$tenant` (default: the
     * bound tenant).
     *
     * `null` — no key was identified — is `false`, so a missing credential cannot be
     * mistaken for an unrestricted one.
     */
    public function tokenAllows(?ApiTokenIdentity $token, TenantPermission $permission, Tenant|string|null $tenant = null): bool;

    /**
     * Enforce `tokenAllows()`.
     *
     * @throws PermissionDeniedException
     */
    public function authorizeToken(?ApiTokenIdentity $token, TenantPermission $permission, Tenant|string|null $tenant = null): void;

    /**
     * Every permission a role holds — what a role-management screen renders and what a
     * token-issuing screen offers as the upper bound of a key's scopes.
     *
     * @return list<TenantPermission>
     */
    public function permissionsFor(TenantRole $role): array;

    /**
     * Drop memoised membership lookups.
     *
     * Implementations cache "this user's role in this tenant" for the unit of work, so a
     * screen that checks a dozen permissions costs one query. This shortens that window
     * explicitly — after a role change, or in a long-running command.
     */
    public function forgetRoles(): void;
}
