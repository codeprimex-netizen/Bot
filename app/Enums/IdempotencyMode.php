<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How `IdempotencyStore::once()` claims a key — the one knob that decides what
 * happens to the guarded operation when a worker dies mid-flight (Req 31.2 / NFR2).
 *
 * Not persisted: this is a *call-site* decision, made by the caller who knows whether
 * the side effect can be rolled back and whether a duplicate is worse than a miss. The
 * row's own lifecycle is `IdempotencyState`.
 *
 * | Mode | Claim | Runs the op | A crash mid-op means | Use it for |
 * |---|---|---|---|---|
 * | `LEASE` | `IN_FLIGHT` insert, committed | outside any store transaction | the lease goes stale and the key is retaken after `STALE_LOCK_SECONDS` | side effects the database cannot roll back: an HTTP call, a webhook fire, a saga step |
 * | `TRANSACTIONAL` | insert, inside the caller's transaction | inside that same transaction | the claim rolls back with the op — nothing happened at all | side effects that are *only* database writes (a counter increment, a ledger row) |
 * | `AT_MOST_ONCE` | `COMPLETED` insert-or-ignore, committed **before** the op | after the claim is committed | the effect is skipped for ever; the key is already burned | effects where a duplicate is worse than a miss: a notification, an announcement |
 *
 * There is deliberately no "at least once" mode: that is what *not* using an
 * idempotency key looks like.
 */
enum IdempotencyMode: string
{
    /**
     * Claim an `IN_FLIGHT` lease, run the operation outside any transaction, then settle
     * the row `COMPLETED` (or `FAILED`, which is retryable).
     *
     * The default, because it is the only mode that is safe when the side effect is not a
     * database write: the claim is committed before the effect starts, so a duplicate
     * that arrives mid-flight is refused rather than allowed to run the effect a second
     * time, and a crashed holder's key is retaken once its lease goes stale.
     */
    case Lease = 'LEASE';

    /**
     * Claim and run and settle inside **one** database transaction.
     *
     * The strongest guarantee available, and only available when the guarded operation
     * is purely database work: the ledger row and the side effect commit together, so
     * there is no interleaving in which one exists without the other, and a throwing
     * operation leaves no trace at all — not even a `FAILED` row.
     *
     * The cost is that the operation must not do anything the database cannot roll back
     * (no HTTP calls, no file writes, no queue dispatch outside `afterCommit`) and must
     * not be slow, because it is holding a transaction open. This is the shape
     * `QuotaGuard::consume()` needs: "the ledger row and the increment are written in one
     * transaction".
     */
    case Transactional = 'TRANSACTIONAL';

    /**
     * Claim the key as `COMPLETED` up front, then run the operation at most once — and
     * never again, even if it throws.
     *
     * The bargain `QuotaNotifier` makes: telling a tenant twice about the same exhausted
     * quota is spam that teaches them to ignore the channel, while missing one notice is
     * a nuisance. Only choose it when that trade is genuinely the right way round.
     *
     * Because the claim is committed before the operation runs, its `result` is written a
     * moment *after* the row appears: a duplicate arriving in that window replays a null
     * payload. Use `LEASE` or `TRANSACTIONAL` where the recorded result — rather than the
     * fact of the claim — is the answer a duplicate needs.
     */
    case AtMostOnce = 'AT_MOST_ONCE';

    /**
     * Whether the claim, the operation and the settle all run in one transaction.
     */
    public function isTransactional(): bool
    {
        return $this === self::Transactional;
    }

    /**
     * Whether the operation runs *after* a committed `COMPLETED` claim, so a failure is
     * never retried.
     */
    public function burnsKeyBeforeExecution(): bool
    {
        return $this === self::AtMostOnce;
    }

    /**
     * Whether a throwing operation leaves the key retryable.
     *
     * True for `LEASE` (the row is settled `FAILED`) and `TRANSACTIONAL` (the claim is
     * rolled back); false for `AT_MOST_ONCE` by design.
     */
    public function releasesKeyOnFailure(): bool
    {
        return $this !== self::AtMostOnce;
    }

    public function label(): string
    {
        return match ($this) {
            self::Lease => 'Lease (claim, run, settle)',
            self::Transactional => 'Transactional (claim + run + settle in one transaction)',
            self::AtMostOnce => 'At most once (claim first, never retried)',
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
