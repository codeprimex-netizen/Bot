<?php

declare(strict_types=1);

namespace App\Exceptions\Audit;

use InvalidArgumentException;

/**
 * An audit entry could not be built because its payload cannot be represented
 * deterministically — so it cannot be hashed, and therefore cannot be chained
 * (Req 24.5 / D1, Correctness Property 17).
 *
 * The audit log fails **closed**: refusing the write is the only honest outcome,
 * because a row whose hash cannot be reproduced is a row a verifier will later
 * report as tampering. Callers fix this at the call site by passing plain,
 * JSON-representable data (scalars, arrays, enums, dates) rather than live objects.
 *
 * Like the tenancy exceptions, messages name the *shape* of the offending value
 * and never its contents: an audit payload is exactly the place untrusted and
 * sensitive values arrive, and an exception message is not a redacted channel.
 */
final class AuditPayloadException extends InvalidArgumentException
{
    public static function unsupportedType(string $type, string $path): self
    {
        return new self(sprintf(
            'Audit payload value at [%s] is of type %s, which has no deterministic canonical '
            .'form. Pass scalars, arrays, enums, or dates instead.',
            self::path($path),
            $type,
        ));
    }

    public static function nonFiniteFloat(string $path): self
    {
        return new self(sprintf(
            'Audit payload value at [%s] is NAN or INF, which cannot be serialized canonically.',
            self::path($path),
        ));
    }

    public static function notEncodable(string $reason): self
    {
        return new self(sprintf(
            'Audit payload could not be encoded as JSON (%s), so it cannot be stored or hashed.',
            $reason,
        ));
    }

    public static function tooDeep(int $maxDepth, string $path): self
    {
        return new self(sprintf(
            'Audit payload nests deeper than the configured %d levels at [%s]; flatten it before auditing.',
            $maxDepth,
            self::path($path),
        ));
    }

    /**
     * Paths are structural (key names only), which is why they may be shown: the
     * values behind them never are.
     */
    private static function path(string $path): string
    {
        return $path === '' ? 'payload' : 'payload.'.$path;
    }
}
