<?php

declare(strict_types=1);

namespace App\Exceptions\Bridge;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A bridge call named a session id that does not exist — **404, terminal**.
 *
 * Raised by `App\Services\Bridge\TenantScopedBridgeClient` while resolving a session id to
 * a row it owns, and it is the *third* of the three answers that resolution can give:
 *
 * | The id names… | Outcome |
 * |---|---|
 * | a session the acting tenant owns | the call proceeds |
 * | a session **another** tenant owns | `CrossTenantAccessException` (403, Req 1.3 / A1) |
 * | no session at all | this exception (404) |
 *
 * Keeping the last two apart is deliberate, and it is the safe direction of the trade:
 * `CrossTenantAccessException` already reveals only that *some* row exists (its message
 * fingerprints the ids and its public message says nothing), while collapsing "nonexistent"
 * into it would make an ordinary typo look like an isolation breach in the logs an operator
 * is watching for real ones.
 *
 * ## Why it is terminal
 *
 * `BridgeErrorClassifier` maps it to `ErrorClass::Validation`, which is fail-fast: a session
 * id that names no row will not start naming one, so retrying is a hot loop against a
 * primary-key lookup. The fix is always a code or data change, never a wait.
 */
final class UnknownSessionException extends RuntimeException implements HttpExceptionInterface
{
    public const int STATUS = 404;

    public const string PUBLIC_MESSAGE = 'No such WhatsApp session.';

    public const string ERROR_CODE = 'unknown_session';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * No `sessions_wa` row has this id, under any tenant.
     */
    public static function forId(string $sessionId): self
    {
        return new self(sprintf(
            'No WhatsApp session with id %s exists, so there is nothing to ask the bridge about. '
            .'The id is fingerprinted rather than quoted, exactly as the cross-tenant denial does '
            .'it, so the two are comparable in a log without either carrying a raw id.',
            self::fingerprint($sessionId),
        ));
    }

    /**
     * The id is not even shaped like one this platform issues (session ids are ULIDs).
     *
     * Checked before the database is asked: a malformed id cannot match a row, and refusing
     * it up front keeps a hostile input from becoming a query at all.
     */
    public static function malformed(string $sessionId): self
    {
        return new self(sprintf(
            'Session id %s is not a ULID, so it cannot name a session this platform issued. '
            .'Refused before the lookup rather than after it.',
            self::fingerprint($sessionId),
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
        return [];
    }

    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    private static function fingerprint(string $value): string
    {
        return $value === '' ? '(empty)' : substr(hash('sha256', $value), 0, 8);
    }
}
