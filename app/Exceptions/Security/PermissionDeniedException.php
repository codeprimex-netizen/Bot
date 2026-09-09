<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A caller was refused a permission inside the tenant they are acting as — **403**
 * (Req 32.1 / NFR3; design.md § STRIDE rows "Panels (User/Admin)" and "Public API").
 *
 * ## One exception for every way of not being allowed
 *
 * `RbacService` refuses for six distinct reasons, and they all land here with the same
 * status and the same public sentence:
 *
 * | Reason | Named constructor |
 * |---|---|
 * | the role holds no such permission | `forRole()` |
 * | the user is not an accepted member of this tenant | `withoutMembership()` |
 * | no tenant is bound, so there is no membership to check | `withoutTenant()` |
 * | neither a user nor an API key was identified | `withoutIdentity()` |
 * | the API key was not issued for this permission | `forToken()` |
 * | the API key belongs to a different tenant than the bound one | `forForeignToken()` |
 *
 * Distinguishing them in the *message* is for the operator; the *response* says the same
 * thing every time. "No such member" and "member without the role" have to be
 * indistinguishable from outside, or the 403 becomes a membership oracle.
 *
 * The permission key itself **is** in the envelope. It came from the route the caller
 * already reached, so it reveals nothing they did not just exercise, and without it a
 * panel cannot say *which* power is missing and an API client cannot tell "re-issue this
 * key with `messages.send`" from "ask your owner for a role".
 *
 * ## Why not Laravel's `AuthorizationException`
 *
 * Because this is one of the seven trust boundaries of the STRIDE table and it is
 * asserted as such: a dedicated type means the boundary test can prove that a refusal
 * *was* a permission refusal rather than a 403 from somewhere else, and it keeps the
 * `SecurityException` family's "no secret in a message" rule (a token id is
 * fingerprinted, never printed).
 */
final class PermissionDeniedException extends SecurityException implements HttpExceptionInterface
{
    /**
     * Authenticated but not permitted. Never 404 — Req 1.3's precedent — and never 401,
     * which would tell a caller to re-authenticate for a thing no credential of theirs
     * can do.
     */
    public const int STATUS = 403;

    /**
     * The only sentence a client is ever shown.
     */
    public const string PUBLIC_MESSAGE = 'You do not have permission to perform this action.';

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'permission_denied';

    private function __construct(
        public readonly TenantPermission $permission,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The caller is a member, and their role does not carry the permission.
     */
    public static function forRole(TenantPermission $permission, TenantRole $role, ?string $tenantId): self
    {
        return new self($permission, sprintf(
            'Role [%s] does not hold [%s] in tenant %s.',
            $role->value,
            $permission->value,
            self::fingerprint($tenantId),
        ));
    }

    /**
     * The caller has no accepted `tenant_users` row for the bound tenant — a stranger,
     * a removed member, or an invitation that has not been accepted.
     */
    public static function withoutMembership(TenantPermission $permission, ?string $tenantId): self
    {
        return new self($permission, sprintf(
            'No accepted membership in tenant %s, so [%s] cannot be granted.',
            self::fingerprint($tenantId),
            $permission->value,
        ));
    }

    /**
     * No tenant is bound. There is nothing to be a member of, so the answer is "no"
     * rather than "which tenant did you mean?".
     */
    public static function withoutTenant(TenantPermission $permission): self
    {
        return new self($permission, sprintf(
            'No tenant is bound, so [%s] cannot be granted. Stack tenant.permission after resolve.tenant.',
            $permission->value,
        ));
    }

    /**
     * Nothing identified the caller: no authenticated user, no verified API key.
     */
    public static function withoutIdentity(TenantPermission $permission): self
    {
        return new self($permission, sprintf(
            'No authenticated user and no verified API key, so [%s] cannot be granted.',
            $permission->value,
        ));
    }

    /**
     * A verified API key that was not issued for this permission — including a key
     * issued before scopes existed, whose scope list is empty.
     */
    public static function forToken(TenantPermission $permission, ?string $tokenId): self
    {
        return new self($permission, sprintf(
            'API key %s was not issued with the [%s] scope.',
            self::fingerprint($tokenId),
            $permission->value,
        ));
    }

    /**
     * A verified API key whose tenant is not the bound one. Refused rather than
     * reasoned about: a key is bound to exactly one tenant, so this means something
     * upstream rebound the context.
     */
    public static function forForeignToken(TenantPermission $permission, ?string $tokenId, ?string $tenantId): self
    {
        return new self($permission, sprintf(
            'API key %s does not act as tenant %s; refusing [%s].',
            self::fingerprint($tokenId),
            self::fingerprint($tenantId),
            $permission->value,
        ));
    }

    /**
     * The audited platform bypass (`actingAsPlatform()`) is not a tenant role.
     *
     * Platform administration has its own boundary — the `platform-admin` guard, IP
     * allowlist, and admin throttle of task 30.1 — and tenant RBAC deliberately does not
     * double as it. Permitting everything here would make the platform bypass a way past
     * the tenant matrix; denying keeps the two boundaries separate.
     */
    public static function inPlatformMode(TenantPermission $permission): self
    {
        return new self($permission, sprintf(
            'Platform mode is not a tenant role, so [%s] is refused. Platform-admin actions go '
            .'through the platform-admin guard, and per-tenant work through TenantContext::runFor().',
            $permission->value,
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
