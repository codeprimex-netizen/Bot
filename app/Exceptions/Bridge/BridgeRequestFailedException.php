<?php

declare(strict_types=1);

namespace App\Exceptions\Bridge;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The WA Bridge answered, and its answer was a refusal — the sidecar's own status code and
 * error code, carried into PHP without interpretation (Req 2.9 / A2; Req 7.1 / A7).
 *
 * The distinction from `BridgeUnreachableException` is the one that decides what happens
 * next, and it is a distinction about **who knows something**:
 *
 * | Exception | The bridge… | Retryable? |
 * |---|---|---|
 * | `BridgeUnreachableException` | never answered — outcome unknown | always (`BRIDGE`) |
 * | this one | answered, and refused | *depends on what it said* |
 *
 * So this exception deliberately does **not** decide its own fate. It carries the HTTP
 * status and the bridge's machine-readable `code`, and
 * `App\Services\Bridge\BridgeErrorClassifier` maps that pair onto an `ErrorClass`. That is
 * what keeps one policy in one place: a `401` is `AUTH` and must not be retried until the
 * token is fixed, a `409 session_not_connected` is `BRIDGE` and clears when the session
 * reconnects, a `422 not_on_whatsapp` is `NOT_ON_WHATSAPP` and will never succeed.
 *
 * ## Codes the bridge is expected to use
 *
 * The sidecar is a separate deployable, so this list is a contract rather than an
 * exhaustive enumeration — an unrecognised code falls back to the status code, and an
 * unrecognised status falls back to `BRIDGE`. Nothing is guessed into success.
 */
final class BridgeRequestFailedException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * The session has no live socket, so the operation cannot be carried out yet. Clears on
     * its own when the reconnect loop succeeds — the reason this is retryable.
     */
    public const string CODE_SESSION_NOT_CONNECTED = 'session_not_connected';

    /** The bridge does not know this session id at all (it was never provisioned there). */
    public const string CODE_SESSION_UNKNOWN = 'session_unknown';

    /** The stored credentials were rejected by WhatsApp; the session is logged out. */
    public const string CODE_SESSION_LOGGED_OUT = 'session_logged_out';

    /** The recipient is not a WhatsApp user. There is nothing to send to, ever. */
    public const string CODE_NOT_ON_WHATSAPP = 'not_on_whatsapp';

    /** A media transfer (download from the bridge, upload to it, transcode) failed. */
    public const string CODE_MEDIA_FAILED = 'media_failed';

    /** WhatsApp itself is rate-limiting this session. */
    public const string CODE_RATE_LIMITED = 'rate_limited';

    public const string PUBLIC_MESSAGE = 'The WhatsApp connection service refused this operation.';

    public const string ERROR_CODE = 'bridge_request_failed';

    /**
     * @param  string  $operation  short label: `session.start`, `message.text`, …
     * @param  int  $status  the HTTP status the bridge answered with
     * @param  string|null  $bridgeCode  the bridge's machine-readable error code, when it sent one.
     *                                   Named `bridgeCode` rather than `code` because `Exception::$code`
     *                                   already exists, is an `int`, and is readwrite — shadowing it
     *                                   would be a lie about the base class rather than a rename.
     * @param  int|null  $retryAfterSeconds  a `Retry-After` the bridge named, in seconds
     */
    private function __construct(
        public readonly string $operation,
        public readonly int $status,
        public readonly ?string $bridgeCode,
        public readonly ?int $retryAfterSeconds,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The bridge refused the operation.
     *
     * The bridge's own `message` field is deliberately **not** carried into the exception
     * message: it is written by a separate process, may quote a request body, and reaches
     * logs. The `code` is a closed vocabulary and is safe; the prose is not.
     */
    public static function refused(
        string $operation,
        int $status,
        ?string $code = null,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self($operation, $status, self::normaliseCode($code), $retryAfterSeconds, sprintf(
            'Bridge refused operation [%s] with status %d%s. Its own message is not quoted here — '
            .'only the machine-readable code is, because the prose is written by another process '
            .'and can echo a request body.',
            $operation,
            $status,
            $code === null ? ' and no error code' : sprintf(' and code [%s]', self::normaliseCode($code)),
        ));
    }

    /**
     * Whether the bridge said the session has no usable socket right now.
     *
     * True for both "not connected" and "unknown here": a session the bridge has forgotten
     * (it restarted with an empty in-memory map) is restored by the same reconnect path as
     * one that merely dropped, so both are transient from the caller's point of view.
     * `CODE_SESSION_LOGGED_OUT` is excluded — those credentials are void, and no amount of
     * reconnecting brings them back.
     */
    public function isSessionUnavailable(): bool
    {
        return in_array($this->bridgeCode, [self::CODE_SESSION_NOT_CONNECTED, self::CODE_SESSION_UNKNOWN], true);
    }

    /**
     * The status this exception reports to an HTTP client.
     *
     * The bridge's status is **not** passed through: a `409` from an internal sidecar is not
     * a `409` for the tenant's API client, and a `401` between us and the bridge is
     * certainly not one for the caller. Anything the bridge refuses is either a temporary
     * bridge condition (`503`) or an invalid request (`422`), and `isSessionUnavailable()`
     * is what tells them apart.
     */
    public function getStatusCode(): int
    {
        return $this->isSessionUnavailable() || $this->status >= 500 ? 503 : 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        if ($this->retryAfterSeconds === null || $this->retryAfterSeconds <= 0) {
            return [];
        }

        return ['Retry-After' => (string) $this->retryAfterSeconds];
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
     * Lower-cased, trimmed, and refused entirely if it is not a plain identifier — the
     * `code` is compared against constants and printed into messages, so it may not carry
     * whitespace, punctuation, or control characters.
     */
    private static function normaliseCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalised = strtolower(trim($code));

        return preg_match('/^[a-z0-9_.-]{1,64}$/', $normalised) === 1 ? $normalised : null;
    }
}
