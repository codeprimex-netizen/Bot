<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantPermission;
use App\Models\Tenant;
use App\Models\TenantApiToken;

/**
 * A verified API credential: which tenant it acts as, which row it was, and **what it
 * was issued to do** (Req 32.1 / NFR3; design.md § STRIDE row "Public API").
 *
 * `TenantTokenRepository` returns this instead of a bare `Tenant` for one reason: the
 * scope list has to travel with the identity that was verified. The alternative — look
 * the tenant up during resolution and look the scopes up again in the authorization
 * middleware — means the credential is verified **twice, by two pieces of code**, and
 * two verifiers eventually disagree about what counts as usable. Resolving once and
 * carrying the answer keeps "is this key valid?" in exactly one place.
 *
 * Deliberately absent: the plaintext token and its hash. This object is put on the
 * request (`REQUEST_ATTRIBUTE`) where a later middleware, a logger, or an exception
 * renderer can reach it, so it must carry nothing that would be a credential if it
 * leaked. `tokenId` is a ULID primary key — enough to revoke, audit, or rate-limit by,
 * useless for authenticating.
 *
 * Scopes are a list of `TenantPermission`, the same vocabulary a panel user's role grants
 * (see that enum for why there is one vocabulary and no wildcard). An empty list is
 * meaningful and it means *nothing is permitted*.
 */
final readonly class ApiTokenIdentity
{
    /**
     * Where `ResolveTenant` publishes the identity of an API-authenticated request, and
     * where `EnsurePermission` reads it from.
     *
     * Request attributes rather than a container singleton, because the lifetime that
     * matters is exactly one HTTP request: a scoped binding would have to be cleared,
     * and the request that forgets to clear it is the request that acts on the previous
     * caller's scopes.
     */
    public const string REQUEST_ATTRIBUTE = 'wa.api_token_identity';

    /**
     * @param  list<TenantPermission>  $scopes  what this key may do; empty = nothing
     */
    public function __construct(
        public Tenant $tenant,
        public string $tokenId,
        public string $name,
        public array $scopes,
    ) {}

    /**
     * The identity of a stored row, with unrecognised scope entries dropped.
     */
    public static function fromToken(TenantApiToken $token, Tenant $tenant): self
    {
        return new self($tenant, (string) $token->getKey(), $token->name, $token->grantedScopes());
    }

    /**
     * Whether this key was issued for `$permission`.
     *
     * Total: no exception, no partial match, no wildcard.
     */
    public function allows(TenantPermission $permission): bool
    {
        return in_array($permission, $this->scopes, true);
    }

    /**
     * Whether this key acts as `$tenantId`.
     *
     * Checked by `RbacService` in addition to the scope: a key is bound to exactly one
     * tenant, so a request whose bound tenant is not that one has gone wrong somewhere
     * upstream and must be refused rather than reasoned about.
     */
    public function actsAs(string $tenantId): bool
    {
        return $tenantId !== '' && $this->tenant->getKey() === $tenantId;
    }

    /**
     * @return list<string>
     */
    public function scopeKeys(): array
    {
        return array_map(static fn (TenantPermission $permission): string => $permission->value, $this->scopes);
    }
}
