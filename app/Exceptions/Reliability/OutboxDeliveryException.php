<?php

declare(strict_types=1);

namespace App\Exceptions\Reliability;

use App\Services\Reliability\OutboxDelivery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The receiver did not accept an outbox delivery (Req 31.4 / NFR2, Algorithm 6).
 *
 * ## Why it carries an HTTP status
 *
 * The relay does not decide for itself how to react to a failure — it asks
 * `RetryPolicy::decide()`, which classifies the throwable. `PlatformErrorClassifier`
 * classifies anything implementing `HttpExceptionInterface` **from its status code**, so
 * carrying the receiver's status is what gives every failure the right treatment without a
 * single new mapping:
 *
 * | The receiver said | Class | What the relay does |
 * |---|---|---|
 * | `5xx`, or the transport could not reach it | `NETWORK` | retry on jittered backoff, 5 attempts |
 * | `429` | `RATE_LIMIT` | defer, honouring `Retry-After` verbatim when one was sent |
 * | `408` / `504` | `TIMEOUT` | retry sooner, give up earlier |
 * | `401` | `AUTH` | park: a revoked credential does not renew itself on the fourth attempt |
 * | other `4xx` | `VALIDATION` | park: the receiver rejected *this body*, deterministically |
 *
 * `undeliverable()` is the same idea for a failure that is ours rather than the receiver's —
 * a row with no destination, or a destination that is not a URL. It reports `422`, so it
 * classifies as `VALIDATION` and parks on the first attempt instead of retrying a
 * misconfiguration twelve times.
 *
 * ## What the message may contain
 *
 * The message is written to `outbox.last_error` and read by operators, so it names the
 * event type, the status, and a **fingerprint** of the dedup key — never the payload and
 * never the response body. The row already holds the payload; a copy of a provider's error
 * page in a `TEXT` column, on the platform's highest-volume table, helps nobody (see
 * `OutboxMessage::MAX_ERROR_LENGTH`, which truncates whatever gets through anyway).
 */
final class OutboxDeliveryException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * Status reported when the row cannot be delivered at all — our misconfiguration, not
     * the receiver's refusal. `422` classifies as `VALIDATION`, i.e. fail fast.
     */
    public const int UNDELIVERABLE_STATUS = 422;

    /**
     * Status reported when a transport failed without one of its own (a receiver that
     * answered something unusable). `502` classifies as `NETWORK`, i.e. retryable.
     */
    public const int DEFAULT_STATUS = 502;

    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(
        string $message,
        private readonly int $status,
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The receiver answered, and said no.
     *
     * @param  int|null  $retryAfterSeconds  a wait the receiver named, honoured verbatim for a `429`
     */
    public static function rejected(OutboxDelivery $delivery, int $status, ?int $retryAfterSeconds = null): self
    {
        return new self(
            sprintf(
                'Delivery of %s %s was refused with HTTP %d on attempt %d.',
                $delivery->eventType,
                $delivery->fingerprint(),
                $status,
                $delivery->attempt,
            ),
            $status >= 400 ? $status : self::DEFAULT_STATUS,
            $retryAfterSeconds === null || $retryAfterSeconds <= 0
                ? []
                : ['Retry-After' => (string) $retryAfterSeconds],
        );
    }

    /**
     * The row cannot be delivered as it stands — no destination, or one this transport
     * cannot use. Parked on the first attempt: an operator has to change something.
     */
    public static function undeliverable(OutboxDelivery $delivery, string $reason): self
    {
        return new self(
            sprintf(
                'Cannot deliver %s %s: %s. The row is kept and parked for an operator, never dropped.',
                $delivery->eventType,
                $delivery->fingerprint(),
                $reason,
            ),
            self::UNDELIVERABLE_STATUS,
        );
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Whether this refusal will look exactly the same on the next attempt.
     */
    public function isTerminal(): bool
    {
        return $this->status === self::UNDELIVERABLE_STATUS;
    }
}
