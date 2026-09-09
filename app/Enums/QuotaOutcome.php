<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What `QuotaGuard::verdict()` answers — the three-valued result Algorithm 3 branches
 * on (Req 3.4 / A3; design.md §"Components and Interfaces → 1. Tenancy layer":
 * `verdict(...): QuotaVerdict // allow|defer|block`).
 *
 * The middle case is the whole point. Req 3.4 says an exhausted quota must
 * *"defer or block the send (never drop it)"*, so a boolean answer would be wrong in
 * both directions: `false` gives a caller nowhere to put "not yet, try after the
 * period rolls", and `true`/`false` alone cannot tell a job that should be
 * `release()`d apart from one that should fail with an error the tenant sees.
 *
 * | Outcome | What the caller does | Who ends up seeing it |
 * |---|---|---|
 * | `ALLOW` | proceed | nobody |
 * | `DEFER` | `release($verdict->secondsUntilPeriodReset())` — the job stays queued | the tenant's "paused until <date>" notice |
 * | `BLOCK` | refuse now, with an explanation | an error / a disabled control |
 *
 * Nothing here is ever "drop": a `DEFER` keeps the work, and a `BLOCK` produces a
 * refusal the tenant is told about (`QuotaExceededException`), never a silent
 * discard.
 */
enum QuotaOutcome: string
{
    case Allow = 'ALLOW';
    case Defer = 'DEFER';
    case Block = 'BLOCK';

    /**
     * Whether the metered action may go ahead.
     */
    public function isAllowed(): bool
    {
        return $this === self::Allow;
    }

    /**
     * Whether the caller should put the work back on the queue rather than fail it.
     */
    public function isDeferred(): bool
    {
        return $this === self::Defer;
    }

    /**
     * Whether the caller should refuse now: waiting would not help.
     */
    public function isBlocked(): bool
    {
        return $this === self::Block;
    }

    /**
     * Whether the work survives this outcome — true for everything except a block.
     *
     * The predicate Req 3.4's "never drop it" is written in: a deferred send is still
     * going to happen.
     */
    public function keepsWork(): bool
    {
        return $this !== self::Block;
    }

    /**
     * Human-readable label for panels and operator views.
     */
    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allowed',
            self::Defer => 'Deferred until the quota period resets',
            self::Block => 'Blocked',
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
