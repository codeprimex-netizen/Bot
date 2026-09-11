<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use RuntimeException;

/**
 * Internal signal: another caller claimed this key while we were trying to.
 *
 * @internal Never leaves `DatabaseIdempotencyStore`. It exists because a claim can be
 * lost *inside* an open transaction (`IdempotencyMode::Transactional`), and the only way
 * to abandon a transaction is to throw out of it — after which the store re-reads the key
 * and either replays the winner's result or refuses with `OperationInFlightException`.
 *
 * Deliberately **not** the caller-facing 409: whether a lost claim becomes a replay or a
 * refusal is decided after the re-read, not here.
 */
final class ClaimRaceLost extends RuntimeException
{
    public static function on(string $scope, string $key): self
    {
        // Neither value reaches a client or a log through this exception; it is caught one
        // frame up. The message exists for the (unexpected) case of it surfacing in a trace.
        return new self(sprintf(
            'Lost the idempotency claim race (scope hash %s, key hash %s).',
            substr(hash('sha256', $scope), 0, 8),
            substr(hash('sha256', $key), 0, 8),
        ));
    }
}
