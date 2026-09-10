<?php

declare(strict_types=1);

namespace App\Exceptions\Bridge;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The WA Bridge could not be reached, or was not asked at all — **503, retryable**
 * (Req 2.9 / A2 guarded by Req 31.1, 31.3 / NFR2).
 *
 * This is the platform's own inability to talk to the sidecar: the process is down, the
 * socket timed out, the retry budget is spent, or the circuit breaker guarding the session
 * is open. Nothing is known about the *message* or the *session* — only that the question
 * did not get through.
 *
 * ## Why this must never look like a delivery outcome
 *
 * A send whose transport failed is a send that **may or may not have happened**, and the
 * one answer that is definitely wrong is "sent". So every exit from the bridge client that
 * is not a decoded response is one of these, and it is classified `ErrorClass::Bridge` —
 * retryable, jittered backoff, five attempts — so the work is kept and re-attempted rather
 * than either dropped or recorded as delivered. Exactly-once at the *message* level is the
 * send pipeline's idempotency key, not this exception's job; this exception only guarantees
 * that the failure is loud and typed.
 *
 * ## Why it never quotes the underlying error
 *
 * The message names the operation (`session.start`, `message.text`, …) and stops there. A
 * cURL or client exception can carry the bridge's URL and bearer token in a request dump,
 * and these messages reach logs and, through the session screen, tenants. The bridge URL is
 * infrastructure detail no tenant needs and no log should hold.
 */
final class BridgeUnreachableException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 503;

    public const string PUBLIC_MESSAGE = 'The WhatsApp connection service is not responding right now. Nothing was sent; please try again shortly.';

    public const string ERROR_CODE = 'bridge_unreachable';

    /**
     * Seconds a client should wait before asking again. Short: the usual cause is a
     * restarting sidecar or a breaker that is about to probe again.
     */
    public const int RETRY_AFTER_SECONDS = 15;

    /**
     * @param  string  $operation  short label: `session.start`, `message.text`, `numbers.check`, …
     */
    private function __construct(
        public readonly string $operation,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The request left the process and did not come back — connection refused, DNS
     * failure, read timeout, or an exhausted inline retry budget.
     */
    public static function transportFailed(string $operation): self
    {
        return new self($operation, sprintf(
            'Could not complete bridge operation [%s]: the request itself failed, so whether it '
            .'took effect is unknown. The underlying client error is deliberately not quoted — it '
            .'can carry the bridge URL and its bearer token.',
            $operation,
        ));
    }

    /**
     * The circuit breaker for this session is open: the bridge was **not** called.
     */
    public static function circuitOpen(string $operation, string $sessionId): self
    {
        return new self($operation, sprintf(
            'Bridge operation [%s] for session %s was not attempted: its circuit breaker is open. '
            .'A dead sidecar answers every call with a full timeout, so refusing fast is the point '
            .'— the breaker probes again on its own.',
            $operation,
            self::fingerprint($sessionId),
        ));
    }

    /**
     * The bridge answered, but with something this client cannot read as a response —
     * a truncated body, HTML from a reverse proxy, JSON of the wrong shape.
     *
     * Retryable like the rest: a proxy returning an error page for one request is exactly
     * the transient condition a re-attempt fixes, and a *persistently* malformed bridge
     * exhausts the budget and surfaces the same way a dead one does.
     */
    public static function malformedResponse(string $operation, string $why): self
    {
        return new self($operation, sprintf(
            'Bridge operation [%s] answered with a body this client cannot read (%s), so the '
            .'outcome is unknown and is treated as a transport failure rather than a result.',
            $operation,
            self::redact($why),
        ));
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
        return ['Retry-After' => (string) self::RETRY_AFTER_SECONDS];
    }

    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * A short, stable hash of an identifier, so two log lines about one session can be
     * correlated without the log carrying the id itself.
     */
    private static function fingerprint(string $value): string
    {
        return $value === '' ? '(none)' : substr(hash('sha256', $value), 0, 8);
    }

    /**
     * Free-form diagnostic text, kept short and stripped of anything that is not plain
     * printable ASCII, so a bridge response body cannot smuggle control characters or a
     * newline into a log line.
     */
    private static function redact(string $value): string
    {
        $clean = (string) preg_replace('/[^\x20-\x7E]+/', ' ', $value);

        return mb_substr(trim($clean), 0, 120);
    }
}
