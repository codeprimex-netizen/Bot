<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the currently bound tenant was identified.
 *
 * Recorded on `TenantContext` so logs, audit entries, and later debugging can
 * tell "resolved from the panel session" apart from "resolved from an API key"
 * — the same tenant reached through different doors has different trust
 * properties (see Req 9 / A9 for why a host-derived source is the weakest).
 */
enum TenantResolutionSource: string
{
    /** No tenant is bound (platform/global context, or nothing matched). */
    case None = 'none';

    /** The authenticated panel user's active tenant, via `tenant_users`. */
    case Session = 'session';

    /** The request host matched `{slug}.{apex}` for a known tenant. */
    case Subdomain = 'subdomain';

    /** A presented API key / bearer token mapped to exactly one tenant. */
    case ApiToken = 'api_token';

    /** Bound in code: queued jobs, schedulers, `runFor()`, console, tests. */
    case Manual = 'manual';

    /**
     * Whether this source was derived from the incoming HTTP request.
     */
    public function isRequestDerived(): bool
    {
        return match ($this) {
            self::Session, self::Subdomain, self::ApiToken => true,
            self::None, self::Manual => false,
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
