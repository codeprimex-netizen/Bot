<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * State of one `idempotency_keys` row — the generic side-effect dedup ledger
 * behind `IdempotencyStore::once(scope, key, op)` (Req 31.2 / NFR2).
 *
 *   IN_FLIGHT --op returned--> COMPLETED   (replay this result forever)
 *   IN_FLIGHT --op threw-----> FAILED      (the key is released; retry may re-run)
 *
 * The three states exist to distinguish the two things a duplicate caller needs to
 * tell apart, which a bare "have I seen this key?" row cannot:
 *
 * - `IN_FLIGHT` — a first caller holds the key (`locked_at`) and is running the
 *   operation right now. A concurrent duplicate must **wait or reject**, never run
 *   the operation a second time.
 * - `COMPLETED` — the operation ran exactly once and its result is recorded; every
 *   later caller replays that result without re-running anything.
 * - `FAILED` — the operation threw, so no side effect is known to have landed and
 *   the key is retryable. Recording the failure (rather than deleting the row)
 *   keeps the attempt auditable and lets a stuck `IN_FLIGHT` row be told apart from
 *   a genuinely retryable one.
 */
enum IdempotencyState: string
{
    case InFlight = 'IN_FLIGHT';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';

    /**
     * Whether a duplicate caller can be served straight from this row without
     * running the operation again.
     */
    public function isReplayable(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether the operation may be (re-)run for this key.
     *
     * `IN_FLIGHT` is excluded: another worker holds it. Whether *that* worker has
     * crashed is a lease question (`locked_at` + a stale-lock window), decided by
     * the store in task 3.4 — not by the state alone.
     */
    public function allowsExecution(): bool
    {
        return $this === self::Failed;
    }

    public function isInFlight(): bool
    {
        return $this === self::InFlight;
    }

    /**
     * Whether the row has finished its lifecycle (successfully or not).
     */
    public function isSettled(): bool
    {
        return $this !== self::InFlight;
    }

    /**
     * Human-readable label for the operator view.
     */
    public function label(): string
    {
        return match ($this) {
            self::InFlight => 'In flight',
            self::Completed => 'Completed',
            self::Failed => 'Failed (retryable)',
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
