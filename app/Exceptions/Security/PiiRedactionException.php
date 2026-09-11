<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

/**
 * Raised when redaction cannot be *guaranteed* (Req 32.2 / NFR3, Correctness
 * Property 15).
 *
 * Every constructor below is a fail-closed path: the alternative in each case is
 * returning text that may still hold PII, which is precisely the breach Property 15
 * exists to prevent. Nothing here carries the offending text — an exception message
 * is itself log output.
 *
 * The one place this exception is *caught* is the log processor, which cannot let a
 * redaction failure take a request down: it drops the record's content instead
 * (see `App\Logging\PiiRedactionProcessor`).
 */
final class PiiRedactionException extends SecurityException
{
    /**
     * The scanner could not run a built-in pattern over the text — a backtrack or
     * recursion limit, or an encoding the pattern cannot be applied to.
     */
    public static function scanFailed(string $detector, int $pregError): self
    {
        return new self(sprintf(
            'PII scanning failed for detector [%s] (preg error %d); text withheld rather than egressed unredacted.',
            $detector,
            $pregError,
        ));
    }

    /**
     * Rehydration could not run. The masked text is kept rather than half-restored.
     */
    public static function rehydrationFailed(int $pregError): self
    {
        return new self(sprintf('PII rehydration failed (preg error %d).', $pregError));
    }

    /**
     * A structure nested deeper than the redactor will walk. Redacting the shallow
     * part and passing the rest through is not an option.
     */
    public static function tooDeep(int $maxDepth): self
    {
        return new self(sprintf('Refusing to redact a structure nested deeper than %d levels.', $maxDepth));
    }

    /**
     * One unit of work minted an implausible number of tokens. The cap exists so a
     * token index always fits the token grammar; hitting it means the caller is
     * feeding the redactor something other than a message.
     */
    public static function tokenBudgetExhausted(int $maxTokens): self
    {
        return new self(sprintf('PII token budget of %d exhausted for this unit of work.', $maxTokens));
    }

    /**
     * Something tried to serialize a token map.
     *
     * A persisted token map is a plaintext PII store with extra steps: it maps a
     * token back to the exact value redaction removed, and it would outlive the
     * request whose lifetime is the only thing keeping that mapping safe. So the
     * map refuses to be serialized at all rather than trusting every future caller
     * not to cache it.
     */
    public static function notPersistable(): self
    {
        return new self(
            'A PII token map is request-scoped and in-memory only; it cannot be serialized, cached, or logged.'
        );
    }

    /**
     * A token was re-issued for a different value — impossible unless a map is
     * being shared across units of work, which would rehydrate one request's reply
     * with another's data.
     */
    public static function tokenCollision(string $token): self
    {
        return new self(sprintf('Token [%s] is already bound to a different value in this token map.', $token));
    }
}
