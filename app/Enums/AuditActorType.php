<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who performed an audited action (Req 24.2 / D1).
 *
 * The distinction that matters for the audit trail is *whose authority* the write
 * was made under, not which table the identity lives in: a platform super-admin
 * acting cross-tenant (`Admin`) is a different claim from a tenant member acting
 * inside their own tenant (`User`), and both are different from work nobody
 * requested (`System` — schedulers, webhook intake, retention jobs).
 */
enum AuditActorType: string
{
    /** A platform super-admin, acting under the `platform-admin` guard. */
    case Admin = 'ADMIN';

    /** An authenticated end user, acting inside one tenant. */
    case User = 'USER';

    /** No human: a queued job, scheduler, webhook, or console command. */
    case System = 'SYSTEM';

    /**
     * Whether this actor is a human whose identity should be resolvable.
     */
    public function isHuman(): bool
    {
        return match ($this) {
            self::Admin, self::User => true,
            self::System => false,
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
