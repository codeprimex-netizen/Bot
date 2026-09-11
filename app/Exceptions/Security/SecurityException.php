<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

use RuntimeException;

/**
 * Base of the `SecurityException` family of design § Error Handling.
 *
 * Every subclass obeys one rule that is stricter than the tenancy exceptions'
 * rule: **no secret may ever reach a message.** Not key material, not plaintext,
 * not ciphertext, not even a prefix of any of them — an exception message is the
 * single most likely value to end up in a log aggregator, a bug tracker, and an
 * HTTP response, so the safe form is the *only* form these classes can build.
 *
 * What a message may therefore contain: class names, algorithm labels, key
 * *versions*, byte *lengths*, and fingerprinted identifiers. The helpers below are
 * what subclasses use to keep it that way, mirroring
 * `App\Exceptions\Tenancy\CrossTenantAccessException`.
 */
abstract class SecurityException extends RuntimeException
{
    /**
     * A short, stable, non-reversible stand-in for an identifier, so two log lines
     * about the same tenant or key can be correlated without the log carrying the
     * id itself.
     */
    protected static function fingerprint(?string $id): string
    {
        if ($id === null || $id === '') {
            return '<none>';
        }

        return '#'.substr(hash('sha256', $id), 0, 8);
    }

    /**
     * Keep untrusted input out of logs verbatim: control characters are escaped and
     * the value is truncated.
     *
     * Only ever applied to *labels* (a key id, an algorithm name, a cast name) —
     * never to a secret, which has no safe truncation.
     */
    protected static function redact(string $value): string
    {
        $escaped = addcslashes($value, "\0..\37\177");

        return mb_strimwidth($escaped, 0, 120, '…');
    }

    /**
     * The only thing a message may say about a secret: how long it was.
     */
    protected static function describeLength(string $value): string
    {
        return sprintf('%d bytes', strlen($value));
    }
}
