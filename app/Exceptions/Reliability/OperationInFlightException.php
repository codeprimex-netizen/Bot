<?php

declare(strict_types=1);

namespace App\Exceptions\Reliability;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A duplicate arrived while the *first* caller is still running the guarded operation —
 * refused with **409** and a `Retry-After` (Req 31.2 / NFR2; Req 25.2 / D2).
 *
 * Raised by `IdempotencyStore::once()` when the `(scope, key)` row is `IN_FLIGHT` with a
 * live lease and the caller's wait budget ran out.
 *
 * ## Why refusing beats the two alternatives
 *
 * - **Running the operation anyway** would be the double side effect the ledger exists to
 *   prevent — a payment captured twice, a message sent twice.
 * - **Waiting indefinitely** would let one crashed worker pin every duplicate behind it
 *   for the whole stale-lease window, turning a single dead process into a queue-wide
 *   stall. `once()` waits only for the caller's budget (`IdempotencyOptions::waitingFor`),
 *   which collapses the common case — a gateway retrying within milliseconds while the
 *   first delivery is still in flight — into a correct replay, and refuses beyond it.
 *
 * ## Why 409 and not 425/429/503
 *
 * The request is not rate limited and the server is not degraded: it *conflicts with the
 * current state of the key*, which is exactly 409. The `Retry-After` makes the refusal
 * actionable — every sane webhook sender and queue worker retries on it, and by then the
 * first caller has either finished (the retry replays its result) or died (its lease has
 * gone stale and the retry may run the operation itself). The work is therefore deferred,
 * never dropped (Req 31.1).
 *
 * ## What ends up in a message
 *
 * Scope and key are **fingerprinted**, never printed: a key is routinely a gateway event
 * id or a `{sagaId}:{step}` pair, and a 409 body is the last place either belongs. The
 * client sees a fixed sentence (`PUBLIC_MESSAGE`) — same posture as
 * `CrossTenantAccessException`.
 */
final class OperationInFlightException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * HTTP status: the request conflicts with the current state of this key.
     */
    public const int STATUS = 409;

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'idempotent_operation_in_flight';

    /**
     * The only sentence a client is ever shown: no scope, no key, no hint about what the
     * first caller is doing.
     */
    public const string PUBLIC_MESSAGE = 'This request is already being processed. Retry in a moment.';

    /**
     * Seconds a client is asked to wait before retrying — deliberately short, because the
     * usual holder finishes in milliseconds.
     */
    public const int DEFAULT_RETRY_AFTER = 1;

    private function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }

    /**
     * Another caller holds a live lease on this key and did not finish inside our wait
     * budget.
     */
    public static function held(string $scope, string $key, int $waitedMilliseconds, ?int $retryAfterSeconds = null): self
    {
        return new self(
            sprintf(
                'Idempotency key %s in scope %s is held by another caller (waited %dms); refusing to run the operation twice.',
                self::fingerprint($key),
                self::fingerprint($scope),
                max(0, $waitedMilliseconds),
            ),
            max(1, $retryAfterSeconds ?? self::DEFAULT_RETRY_AFTER),
        );
    }

    /**
     * We saw a reclaimable row (a failed attempt, or a stale lease) but lost the race to
     * retake it — so somebody else is now running the operation.
     */
    public static function lostClaimRace(string $scope, string $key, ?int $retryAfterSeconds = null): self
    {
        return new self(
            sprintf(
                'Lost the race to retake idempotency key %s in scope %s; another caller claimed it first.',
                self::fingerprint($key),
                self::fingerprint($scope),
            ),
            max(1, $retryAfterSeconds ?? self::DEFAULT_RETRY_AFTER),
        );
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    /**
     * A short, stable, non-reversible stand-in for an identifier, so logs can be
     * correlated without carrying gateway event ids or tenant-derived scopes.
     */
    private static function fingerprint(string $value): string
    {
        if ($value === '') {
            return '<none>';
        }

        return '#'.substr(hash('sha256', $value), 0, 8);
    }
}
