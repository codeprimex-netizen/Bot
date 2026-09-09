<?php

declare(strict_types=1);

namespace App\Exceptions\Reliability;

use RuntimeException;

/**
 * A guarded operation returned something that cannot be written to
 * `idempotency_keys.result`, so no duplicate could ever be replayed (Req 31.2 / NFR2).
 *
 * Raised by `IdempotencyStore::once()` when the operation's return value is not
 * JSON-encodable — an object, a resource, a closure, `NAN`/`INF`, or a string that is not
 * valid UTF-8.
 *
 * ## The side effect is not rolled back, and the key stays completed
 *
 * By the time this is thrown the operation has already succeeded: whatever it did to the
 * world has happened. Marking the key retryable would therefore re-run a side effect that
 * already landed, which is precisely the failure the ledger exists to prevent — so the row
 * is settled `COMPLETED` with a **null** result, and this exception is thrown on top.
 *
 * The consequence is worth stating plainly: a duplicate of that key replays `null` rather
 * than the answer the first caller got. That is a bug in the calling code, not a recoverable
 * runtime condition, which is why it is loud and immediate rather than logged — it will
 * surface on the very first call in development, long before a retry in production has a
 * chance to replay the wrong thing.
 *
 * ## No HTTP status
 *
 * Unlike `OperationInFlightException` (409) and `IdempotencyKeyReuseException` (422), this
 * is not something a client did and not something a client can fix: it deliberately carries
 * no `HttpExceptionInterface`, so the handler renders the framework default (500) and the
 * operator sees it as the programming error it is.
 *
 * The fix is always at the call site: return data (`array`, scalar, `null`) from the
 * operation and rebuild any object from it, exactly as `QuotaConsumption::fromLedger()`
 * rebuilds a receipt from the recorded payload.
 */
final class UnrecordableResultException extends RuntimeException
{
    /**
     * Stable machine-readable code for logs and alerting.
     */
    public const string ERROR_CODE = 'idempotent_result_unrecordable';

    /**
     * The sentence a client may be shown, if this ever reaches one.
     */
    public const string PUBLIC_MESSAGE = 'The operation completed but its result could not be recorded.';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The value is of a type the ledger cannot store at all.
     */
    public static function unsupportedType(string $scope, string $key, mixed $value): self
    {
        return new self(sprintf(
            'The operation guarded by idempotency key %s in scope %s returned %s, which cannot be recorded for replay. '
            .'Return JSON-encodable data (array, scalar, or null) and rebuild any object from it at the call site — '
            .'the key has been settled COMPLETED with a null result, because the side effect already happened.',
            self::fingerprint($key),
            self::fingerprint($scope),
            self::describe($value),
        ));
    }

    /**
     * The value is of a storable type but failed to encode (invalid UTF-8, `NAN`, `INF`,
     * or a nesting depth JSON cannot express).
     */
    public static function notEncodable(string $scope, string $key, string $reason): self
    {
        return new self(sprintf(
            'The operation guarded by idempotency key %s in scope %s returned a value that could not be encoded for '
            .'replay (%s). The key has been settled COMPLETED with a null result, because the side effect already happened.',
            self::fingerprint($key),
            self::fingerprint($scope),
            self::redact($reason),
        ));
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    /**
     * The type of the offending value — never the value itself, which may be tenant data.
     */
    private static function describe(mixed $value): string
    {
        return is_object($value) ? 'an instance of '.self::redact($value::class) : 'a '.gettype($value);
    }

    /**
     * A short, stable, non-reversible stand-in for an identifier.
     */
    private static function fingerprint(string $value): string
    {
        if ($value === '') {
            return '<none>';
        }

        return '#'.substr(hash('sha256', $value), 0, 8);
    }

    /**
     * Keep untrusted text out of logs verbatim.
     */
    private static function redact(string $value): string
    {
        return mb_strimwidth(addcslashes($value, "\0..\37\177"), 0, 120, '…');
    }
}
