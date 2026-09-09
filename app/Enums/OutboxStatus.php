<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Delivery state of one transactional-outbox row (Req 31.4 / NFR2, Algorithm 6).
 *
 *   PENDING --deliver--> SENT
 *   PENDING --transient failure--> FAILED --retry--> SENT
 *
 * Three states, and the set is deliberately closed:
 *
 * - `PENDING` — written in the same transaction as the state change, not yet
 *   attempted (or scheduled for a future `available_at`).
 * - `FAILED` — an attempt failed transiently; `next_attempt_at` holds the backoff
 *   gate. **`FAILED` is not terminal.** It means "retry me later", which is why it
 *   is claimable alongside `PENDING`.
 * - `SENT` — the receiver acked. The only terminal state.
 *
 * There is no `DEAD`/`ABANDONED` state, and that is a requirement rather than an
 * omission: Req 31.4 says the relay "never loses the row", so a row that keeps
 * failing keeps its place in the queue with a growing backoff and stays visible to
 * operators via `attempts` and `last_error`. If a poison-message quarantine is ever
 * wanted it must be an explicit, audited operator action against a real row — never
 * an implicit state the relay can drop work into.
 */
enum OutboxStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Failed = 'FAILED';

    /**
     * Whether the relay may pick a row in this state up for delivery.
     *
     * This is the state half of the claim predicate in Algorithm 6
     * (`status IN {PENDING, FAILED}`); the time half is `next_attempt_at <= now()`.
     * `App\Models\OutboxMessage::scopeClaimable()` applies both.
     *
     * @see claimableValues() for the same set as raw column values
     */
    public function isClaimable(): bool
    {
        return match ($this) {
            self::Pending, self::Failed => true,
            self::Sent => false,
        };
    }

    /**
     * Whether the row is finished and will never be attempted again.
     */
    public function isTerminal(): bool
    {
        return $this === self::Sent;
    }

    /**
     * Whether at least one delivery attempt has already been made.
     */
    public function hasBeenAttempted(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Human-readable label for the outbox inspector.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Failed => 'Failed (will retry)',
        };
    }

    /**
     * The claimable states, as an enum list.
     *
     * @return array<int, self>
     */
    public static function claimable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->isClaimable(),
        ));
    }

    /**
     * The claimable states as raw column values, for `whereIn()`.
     *
     * @return array<int, string>
     */
    public static function claimableValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::claimable());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
