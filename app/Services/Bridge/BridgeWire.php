<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reading values out of a bridge response body, defensively and in one place.
 *
 * The sidecar is a **separate deployable in another language**, which makes its response
 * bodies untrusted input in the same sense a request body is: it can be a version ahead or
 * behind, it can be fronted by a reverse proxy that returns HTML, and a field this platform
 * expects to be a string can arrive as a number, a nested object, or `null`.
 *
 * Every accessor here therefore answers *"give me this field if it is usable, and `null`
 * otherwise"* and never casts blindly. `(string) $payload['status']` on an array is a PHP
 * error inside a queue worker; `stringOrNull()` is a missing field the DTO can refuse
 * loudly with `BridgeUnreachableException::malformedResponse()`.
 *
 * The division of labour is deliberate: this class decides whether a value is *readable*,
 * and the DTO decides whether a readable value is *sufficient*. Nothing here supplies a
 * default, because a default is how a failed operation comes to look like a successful one.
 */
final class BridgeWire
{
    /**
     * A non-empty scalar field as a trimmed string, or null.
     *
     * Booleans are excluded: `true` stringifies to `"1"`, which would silently become a
     * plausible-looking id or status. Numbers are allowed because ids and timestamps
     * legitimately arrive unquoted from JSON.
     */
    public static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * A boolean field, or null when the field says nothing.
     *
     * JSON `true`/`false` and the string/int spellings a loosely typed sidecar may produce
     * are all accepted; anything else is "not stated" rather than `false`, so a caller can
     * tell a real `false` from a missing field.
     */
    public static function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * A timestamp field as an immutable instant, or null.
     *
     * Both shapes a JSON API realistically sends are accepted: an ISO-8601 string and a
     * numeric epoch (seconds, or milliseconds — WhatsApp's own protocol uses seconds, while
     * a JavaScript `Date.now()` produces milliseconds, and the sidecar is JavaScript).
     * Anything unparseable is `null` rather than `now()`: a fabricated "last seen just now"
     * would make a dead session look alive.
     */
    public static function timestampOrNull(mixed $value): ?CarbonImmutable
    {
        if (is_int($value) || is_float($value)) {
            $seconds = (int) $value;

            if ($seconds <= 0) {
                return null;
            }

            // Anything past ~2286-11-20 in seconds is far more plausibly milliseconds.
            return CarbonImmutable::createFromTimestamp(
                $seconds > 9_999_999_999 ? intdiv($seconds, 1000) : $seconds
            );
        }

        $string = self::stringOrNull($value);

        if ($string === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (Throwable) {
            // A malformed date is a field we cannot read, not a reason to fail the whole
            // response: every DTO that needs one treats null as "not stated".
            return null;
        }
    }

    /**
     * A nested object as a string-keyed array, or an empty array.
     *
     * Empty rather than null because every caller iterates it, and JSON's `{}` and a missing
     * key mean the same thing to all of them.
     *
     * @return array<string, mixed>
     */
    public static function arrayOrEmpty(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalised = [];

        foreach ($value as $key => $item) {
            $normalised[(string) $key] = $item;
        }

        return $normalised;
    }

    /**
     * A JSON list as a plain list, or an empty list.
     *
     * @return list<mixed>
     */
    public static function listOrEmpty(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
