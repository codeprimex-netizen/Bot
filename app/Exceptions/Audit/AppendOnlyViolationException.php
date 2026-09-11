<?php

declare(strict_types=1);

namespace App\Exceptions\Audit;

use RuntimeException;

/**
 * Something tried to change or remove a row of an append-only table
 * (Req 24.2 / D1; Req 34.1 / NFR5).
 *
 * `audit_logs` (and, from task 33.x, `event_log`) are **write-once**. In production
 * that is a database grant: the application role holds `INSERT`/`SELECT` and no
 * `UPDATE`/`DELETE`, so the invariant survives a compromised application entirely.
 * This exception is the *application-level* half of the same rule, and it exists for
 * two reasons the grant cannot cover:
 *
 * 1. **The test and local suites run on SQLite**, which has no grant system at all —
 *    without an in-application guard, "append-only" would be an untested claim.
 * 2. **A typed failure is a better bug report than a driver error.** A developer who
 *    calls `$auditLog->update()` gets a sentence explaining that audit history is
 *    corrected by *appending a correcting entry*, not a 1142 access-denied.
 *
 * The two halves are complementary, not redundant: model events do not fire for
 * `saveQuietly()`, raw query-builder writes, or a SQL console — which is exactly
 * what the grants and the `BEFORE UPDATE`/`BEFORE DELETE` triggers installed by
 * `App\Support\Database\AppendOnlyTable` are there for.
 */
final class AppendOnlyViolationException extends RuntimeException
{
    /**
     * @param  class-string  $model
     */
    public static function forUpdate(string $model): self
    {
        return new self(sprintf(
            'Refusing to update [%s]: the table is append-only, and rewriting a row would break '
            .'the audit hash chain (Req 24.5 / D1). Record a *correcting* entry instead — the '
            .'history is the record, and mistakes are part of it.',
            $model,
        ));
    }

    /**
     * @param  class-string  $model
     */
    public static function forDelete(string $model): self
    {
        return new self(sprintf(
            'Refusing to delete [%s]: the table is append-only. Audit rows leave only with the '
            .'tenant they belong to (retention / right-to-delete, Req 28.2 / D5), which drops that '
            .'tenant\'s whole chain rather than punching a hole in it.',
            $model,
        ));
    }

    /**
     * @param  class-string  $model
     */
    public static function forDirectWrite(string $model): self
    {
        return new self(sprintf(
            'Refusing to create [%s] directly: an audit row is only valid as part of its hash '
            .'chain, so it must be written through App\Services\Audit\AuditService::write(), which '
            .'computes prev_hash and row_hash under the chain lock.',
            $model,
        ));
    }
}
