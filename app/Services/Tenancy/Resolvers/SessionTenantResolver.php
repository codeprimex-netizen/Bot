<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Resolvers;

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\Tenancy\TenantResolution;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Door 1 — the panel session: the authenticated user's active tenant.
 *
 * Two ways in, both requiring an *accepted* membership row (`tenant_users` with
 * `joined_at` set — a pending invitation is not a tenant you can act in):
 *
 * 1. the active tenant id stored in the session (written on login and on the
 *    panel's tenant switcher), validated against the user's memberships on
 *    every request so a revoked member stops resolving immediately;
 * 2. otherwise the user's *sole* membership. Deliberately only when there is
 *    exactly one: picking one of several would make the acting tenant depend on
 *    row order, which is precisely the kind of ambiguity Req 1 forbids.
 */
final class SessionTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?TenantResolution
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $userId = $user->getAuthIdentifier();

        if (! is_int($userId) && ! is_string($userId)) {
            return null;
        }

        $tenant = $this->activeTenant($request, $userId) ?? $this->soleTenant($userId);

        return $tenant instanceof Tenant
            ? new TenantResolution($tenant, TenantResolutionSource::Session)
            : null;
    }

    /**
     * The tenant the panel has marked active, if the user is still a member.
     */
    private function activeTenant(Request $request, int|string $userId): ?Tenant
    {
        if (! $request->hasSession()) {
            return null;
        }

        $tenantId = $request->session()->get($this->sessionKey());

        if (! is_string($tenantId) || $tenantId === '') {
            return null;
        }

        return $this->memberships($userId)
            ->where('tenant_id', $tenantId)
            ->first()?->tenant;
    }

    /**
     * The user's only tenant — unambiguous, so safe to bind without a session.
     */
    private function soleTenant(int|string $userId): ?Tenant
    {
        $memberships = $this->memberships($userId)->limit(2)->get();

        return $memberships->count() === 1
            ? $memberships->first()?->tenant
            : null;
    }

    /**
     * Accepted memberships for a user, with the tenant eager-loaded.
     *
     * @return Builder<TenantUser>
     */
    private function memberships(int|string $userId): Builder
    {
        return TenantUser::query()
            ->with('tenant')
            ->where('user_id', $userId)
            ->whereNotNull('joined_at');
    }

    private function sessionKey(): string
    {
        $key = config('wa.tenancy.session_key', 'active_tenant_id');

        return is_string($key) && $key !== '' ? $key : 'active_tenant_id';
    }
}
