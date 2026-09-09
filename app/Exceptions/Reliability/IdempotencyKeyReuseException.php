<?php

declare(strict_types=1);

namespace App\Exceptions\Reliability;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One idempotency key, two different requests — refused with **422**
 * (Req 31.2 / NFR2).
 *
 * Raised by `IdempotencyStore::once()` when the caller supplied a request payload
 * (`IdempotencyOptions::matching()`) that does not match the fingerprint recorded the
 * first time the key was used.
 *
 * ## Why this is refused rather than replayed
 *
 * A key that has already been used carries a recorded answer. If the *question* has
 * changed, replaying that answer would tell the client "your request succeeded" about a
 * request that was never performed — the worst possible outcome, because it is silent and
 * looks like success. The alternative, running the new payload under the old key, would
 * apply a second side effect under a key that promises exactly one. Neither is
 * acceptable, so the call is refused and the client is told which of its assumptions is
 * wrong.
 *
 * This is always a **client bug**: a key generated per unit of work cannot arrive with two
 * different payloads. The usual causes are a key derived from something too coarse (a
 * timestamp, a user id) or a retry that rebuilt its body from mutated state.
 *
 * ## Why 422 and not 409
 *
 * 409 (`OperationInFlightException`) means *retry, this will resolve itself*. This will
 * not: the same call will be refused for as long as the key is retained. 422 says the
 * request was understood and is semantically unacceptable, with no `Retry-After` to
 * promise a recovery that is not coming. The fix is a new key, which only the caller can
 * mint.
 *
 * Fingerprints are re-hashed to a short prefix before they reach a message: they are
 * already one-way, but a full 64-character digest in a log line is noise, and the
 * *payloads* they summarise are tenant data.
 */
final class IdempotencyKeyReuseException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * HTTP status: understood, well-formed, and semantically unacceptable.
     */
    public const int STATUS = 422;

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'idempotency_key_reused';

    /**
     * The only sentence a client is ever shown.
     */
    public const string PUBLIC_MESSAGE = 'This idempotency key was already used for a different request. Use a new key.';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The key exists and its recorded request fingerprint differs from this call's.
     */
    public static function mismatch(string $scope, string $key, ?string $recorded, string $attempted): self
    {
        return new self(sprintf(
            'Idempotency key %s in scope %s was first used with request %s and is now being reused with request %s; '
            .'refusing to replay an answer to a different question. Mint a new key per unit of work.',
            self::fingerprint($key),
            self::fingerprint($scope),
            self::digest($recorded),
            self::digest($attempted),
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
        // Deliberately no Retry-After: retrying this call unchanged will be refused again.
        return [];
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
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
     * Shorten an already-one-way request fingerprint for the log line.
     */
    private static function digest(?string $fingerprint): string
    {
        if ($fingerprint === null || $fingerprint === '') {
            return '<unrecorded>';
        }

        return '~'.substr($fingerprint, 0, 8);
    }
}
