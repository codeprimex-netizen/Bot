<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The family a circuit breaker belongs to — the first half of a breaker's
 * `(scope, name)` identity (design § Reliability → Circuit breakers).
 *
 * A breaker is *never* global: it is always keyed by the dependency it guards, so
 * one failing LLM provider cannot fail-fast the payment gateway, and one tenant
 * hammering a provider cannot open that provider's breaker for everybody else
 * (Req 31.3 / NFR2).
 *
 * | Scope      | `name` is…                          | Guarded dependency          |
 * |------------|-------------------------------------|-----------------------------|
 * | `provider` | `{provider}` or `{provider}:{tenant}` | LLM / embedding provider  |
 * | `tenant`   | `{tenantId}`                        | a whole tenant's egress     |
 * | `gateway`  | `{gateway}`                         | payment gateway             |
 * | `bridge`   | `{sessionId}`                       | one WA bridge session       |
 *
 * The per-tenant dimension the design calls for is carried **inside `name`**, not
 * in a `tenant_id` column — see `App\Models\CircuitBreaker` for why that table is
 * platform-level, and use `CircuitBreaker::compositeName()` so the encoding is
 * built in exactly one place.
 */
enum CircuitScope: string
{
    case Provider = 'provider';
    case Tenant = 'tenant';
    case Gateway = 'gateway';
    case Bridge = 'bridge';

    /**
     * Whether breakers in this family are conventionally keyed per tenant as well
     * as per dependency.
     *
     * `provider` is: the design scopes LLM breakers "per provider **and** per
     * tenant" so a single abusive tenant cannot trip a shared provider. `gateway`
     * and `bridge` are not — a gateway outage is global, and a bridge session
     * already belongs to exactly one tenant.
     */
    public function isTenantPartitioned(): bool
    {
        return $this === self::Provider;
    }

    /**
     * Human-readable label for the platform health dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Provider => 'LLM provider',
            self::Tenant => 'Tenant egress',
            self::Gateway => 'Payment gateway',
            self::Bridge => 'WA bridge session',
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
