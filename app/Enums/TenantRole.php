<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A user's role inside one tenant (`tenant_users.role`).
 *
 * A user may hold a different role in each tenant they belong to. Platform
 * super-admins have no `tenant_users` row at all — they act through the audited
 * `actingAsPlatform()` context instead.
 */
enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';
    case Agent = 'agent';

    /**
     * Whether this role may administer the tenant (billing, members, settings).
     */
    public function isAdministrative(): bool
    {
        return match ($this) {
            self::Owner, self::Admin => true,
            self::Operator, self::Viewer, self::Agent => false,
        };
    }

    /**
     * Whether this role may take part in the live-agent inbox handoff.
     */
    public function canHandleConversations(): bool
    {
        return match ($this) {
            self::Owner, self::Admin, self::Operator, self::Agent => true,
            self::Viewer => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
            self::Agent => 'Agent',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
