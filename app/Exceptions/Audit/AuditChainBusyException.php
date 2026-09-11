<?php

declare(strict_types=1);

namespace App\Exceptions\Audit;

use RuntimeException;

/**
 * An audit entry could not be appended because the chain's write lock could not be
 * acquired, or because every attempt lost the race for the next chain position
 * (Req 24.5 / D1).
 *
 * Appends to one chain are serialized: position `n+1` has exactly one predecessor,
 * so two writers cannot both hold it. The writer that loses re-reads the tip and
 * retries; this exception is what happens when it keeps losing, or when the lock
 * holder never lets go.
 *
 * It is thrown rather than swallowed **on purpose**. A dropped audit entry is a hole
 * in the evidence of a privileged action, and silently continuing would mean the
 * action happened with no record of it. Callers that genuinely cannot fail the
 * user-facing operation should catch this and re-dispatch the write, never ignore it.
 */
final class AuditChainBusyException extends RuntimeException
{
    public static function lockTimedOut(string $chainKey, int $seconds): self
    {
        return new self(sprintf(
            'Could not acquire the audit chain lock for chain [%s] within %d second(s); the entry '
            .'was not written.',
            $chainKey,
            $seconds,
        ));
    }

    public static function positionContended(string $chainKey, int $attempts): self
    {
        return new self(sprintf(
            'Lost the race for the next position of audit chain [%s] %d time(s) in a row; the entry '
            .'was not written. The chain itself is intact — the database refused the duplicate '
            .'position, which is exactly what it is there for.',
            $chainKey,
            $attempts,
        ));
    }
}
