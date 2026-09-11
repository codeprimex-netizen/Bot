<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a tenant.
 *
 * The state machine mirrors the design's tenant-lifecycle diagram:
 *
 *   [*] -> TRIAL      (provision)
 *   TRIAL -> ACTIVE   (subscribe)
 *   ACTIVE -> SUSPENDED  (non-payment / abuse)
 *   SUSPENDED -> ACTIVE  (reactivate)
 *   ACTIVE -> CANCELLED  (cancel / offboard)
 *   SUSPENDED -> CANCELLED (cancel)
 *   CANCELLED -> [*]  (hard-delete after the retention window)
 */
enum TenantStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Trial = 'TRIAL';
    case Cancelled = 'CANCELLED';

    /**
     * States this state may legally transition into.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Trial => [self::Active, self::Suspended, self::Cancelled],
            self::Active => [self::Suspended, self::Cancelled],
            self::Suspended => [self::Active, self::Cancelled],
            self::Cancelled => [],
        };
    }

    /**
     * Whether a transition from this state to $to is permitted.
     *
     * A no-op transition (same state) is always permitted so that idempotent
     * lifecycle calls do not fail.
     */
    public function canTransitionTo(self $to): bool
    {
        return $this === $to || in_array($to, $this->allowedNext(), true);
    }

    /**
     * A terminal state has no outgoing transitions.
     */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * Whether the tenant may perform billable/outbound work.
     *
     * Suspended tenants keep inbound logging and read-only panels but must not
     * send; cancelled tenants are offboarded.
     */
    public function isOperational(): bool
    {
        return match ($this) {
            self::Active, self::Trial => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    /**
     * Human-readable label for panels.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Trial => 'Trial',
            self::Cancelled => 'Cancelled',
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
