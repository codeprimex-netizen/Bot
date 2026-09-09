<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * What a caller is allowed to *do* inside one tenant — the vocabulary
 * `App\Services\Rbac\RbacService` decides against (Req 32.1 / NFR3; design.md
 * § STRIDE row "Panels (User/Admin)" and row "Public API").
 *
 * ## One vocabulary for both identities
 *
 * A tenant is reached by two kinds of caller, and both are checked against *this* list:
 *
 * | Caller | Grant comes from | Checked by |
 * |---|---|---|
 * | a panel user | their `TenantRole` in `tenant_users` | `RbacService::allows()` |
 * | a machine caller | the scopes stored on their `TenantApiToken` | `RbacService::tokenAllows()` |
 *
 * The alternative — role permissions here and a separate string vocabulary for token
 * scopes (`messages:send`, `contacts:read`, ...) — needs a mapping between the two, and
 * a mapping is a thing that drifts: the day someone adds a permission and forgets the
 * scope row, either every token silently gains the new power or every token silently
 * loses it, and neither shows up as a failing test. Sharing one enum makes the question
 * "which permission?" have exactly one answer everywhere.
 *
 * ## Deny by default, structurally
 *
 * - `allowedRoles()` is an exhaustive `match`. A case added without a row is a static
 *   analysis error and, at runtime, an `\UnhandledMatchError` — never an accidental
 *   grant. There is no `default => all roles` arm and there must never be one.
 * - There is **no wildcard scope**. A token grants the permissions it lists and nothing
 *   else, so a token issued with no scopes can do nothing at all. A `*` would make the
 *   most dangerous credential the shortest one to write.
 * - Nothing in `config/` can widen the matrix. Which role may do what is a security
 *   decision, not an operator preference; per-tenant custom roles, if they are ever
 *   wanted, would be a new table and a new task, not an env var.
 *
 * ## Relationship to the two existing `TenantRole` predicates
 *
 * `TenantRole::isAdministrative()` and `TenantRole::canHandleConversations()` came first
 * (task 0.2) and stay authoritative: `RbacTest` asserts this matrix agrees with both, so
 * a role that stops being administrative cannot keep its administrative permissions by
 * accident.
 */
enum TenantPermission: string
{
    /*
    |--------------------------------------------------------------------------
    | Tenant administration
    |--------------------------------------------------------------------------
    */

    /** Close, transfer, or otherwise end the tenant — the irreversible ones. */
    case TenantLifecycleManage = 'tenant.lifecycle.manage';

    /** Tenant profile, branding, locale, business hours, anti-ban preferences. */
    case TenantSettingsManage = 'tenant.settings.manage';

    /** Invite, re-role, and remove members (the screen task 30.4 builds). */
    case TenantMembersManage = 'tenant.members.manage';

    /** Plan changes, wallet top-ups, invoices, payment methods. */
    case TenantBillingManage = 'tenant.billing.manage';

    /** Issue and revoke the tenant's API keys, and choose their scopes. */
    case TenantApiTokensManage = 'tenant.api_tokens.manage';

    /** Register outbound webhook endpoints and rotate their signing secrets. */
    case TenantWebhooksManage = 'tenant.webhooks.manage';

    /** Read the tenant's slice of the audit trail. */
    case TenantAuditView = 'tenant.audit.view';

    /*
    |--------------------------------------------------------------------------
    | Operations
    |--------------------------------------------------------------------------
    */

    /** Pair, re-pair, pause, and disconnect WhatsApp sessions. */
    case SessionsManage = 'sessions.manage';

    /** Create, schedule, start, and stop campaigns. */
    case CampaignsManage = 'campaigns.manage';

    /** Edit chatbot flows, prompts, and AI settings. */
    case ChatbotsManage = 'chatbots.manage';

    /** Ingest and curate knowledge-base articles and their embeddings. */
    case KnowledgeManage = 'knowledge.manage';

    /** Create, import, edit, and delete contacts and their tags. */
    case ContactsManage = 'contacts.manage';

    /** Take over a conversation from the bot and reply as a human agent. */
    case ConversationsHandle = 'conversations.handle';

    /** Send an outbound message (directly, or through the public API). */
    case MessagesSend = 'messages.send';

    /*
    |--------------------------------------------------------------------------
    | Read-only
    |--------------------------------------------------------------------------
    */

    /** Read conversations and message history. */
    case MessagesRead = 'messages.read';

    /** Read the contact book. */
    case ContactsRead = 'contacts.read';

    /** Read dashboards, analytics, and exports. */
    case ReportsView = 'reports.view';

    /**
     * The roles this permission is granted to — the whole matrix, in one place.
     *
     * Exhaustive by construction: there is no default arm, so a new case without a row
     * fails static analysis and raises at runtime rather than defaulting to "everyone".
     *
     * @return non-empty-list<TenantRole>
     */
    public function allowedRoles(): array
    {
        return match ($this) {
            // Ending a tenant is the one thing an admin cannot do for an owner.
            self::TenantLifecycleManage => [TenantRole::Owner],

            self::TenantSettingsManage,
            self::TenantMembersManage,
            self::TenantBillingManage,
            self::TenantApiTokensManage,
            self::TenantWebhooksManage,
            self::TenantAuditView => [TenantRole::Owner, TenantRole::Admin],

            self::SessionsManage,
            self::CampaignsManage,
            self::ChatbotsManage,
            self::KnowledgeManage,
            self::ContactsManage => [TenantRole::Owner, TenantRole::Admin, TenantRole::Operator],

            self::ConversationsHandle,
            self::MessagesSend => [
                TenantRole::Owner,
                TenantRole::Admin,
                TenantRole::Operator,
                TenantRole::Agent,
            ],

            self::MessagesRead,
            self::ContactsRead,
            self::ReportsView => [
                TenantRole::Owner,
                TenantRole::Admin,
                TenantRole::Operator,
                TenantRole::Agent,
                TenantRole::Viewer,
            ],
        };
    }

    /**
     * Whether this permission administers the tenant rather than operating it.
     *
     * Kept as its own predicate so the matrix can be checked against
     * `TenantRole::isAdministrative()` instead of the two definitions being asserted to
     * agree by eye.
     */
    public function isAdministrative(): bool
    {
        return match ($this) {
            self::TenantLifecycleManage,
            self::TenantSettingsManage,
            self::TenantMembersManage,
            self::TenantBillingManage,
            self::TenantApiTokensManage,
            self::TenantWebhooksManage,
            self::TenantAuditView => true,

            self::SessionsManage,
            self::CampaignsManage,
            self::ChatbotsManage,
            self::KnowledgeManage,
            self::ContactsManage,
            self::ConversationsHandle,
            self::MessagesSend,
            self::MessagesRead,
            self::ContactsRead,
            self::ReportsView => false,
        };
    }

    /**
     * Whether `$role` holds this permission.
     */
    public function grantedTo(TenantRole $role): bool
    {
        return in_array($role, $this->allowedRoles(), true);
    }

    /**
     * Every permission a role holds, in declaration order.
     *
     * @return list<TenantPermission>
     */
    public static function forRole(TenantRole $role): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $permission): bool => $permission->grantedTo($role),
        ));
    }

    /**
     * A permission key from an untrusted string, or `null`.
     *
     * Used for **stored** values (a token's scope list): an unrecognised entry is
     * dropped rather than raised, because a scope column written by an older release
     * must not make the whole credential unusable — and dropping it denies, which is
     * the safe direction.
     */
    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom(trim($key));
    }

    /**
     * A permission key from a **route declaration**, or a hard failure.
     *
     * A typo in `tenant.permission:...` is a misconfiguration by the developer, not a
     * decision about a caller: raising turns it into a 500 on the first request, which
     * is loud. Denying instead would lock a working screen for every tenant and look
     * exactly like a permissions bug.
     *
     * @throws InvalidArgumentException on an unknown key
     */
    public static function coerce(string $key): self
    {
        $permission = self::tryFromKey($key);

        if ($permission === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown tenant permission [%s]. Known permissions: %s.',
                $key,
                implode(', ', self::keys()),
            ));
        }

        return $permission;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
