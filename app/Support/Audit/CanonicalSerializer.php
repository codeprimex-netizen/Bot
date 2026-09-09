<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Exceptions\Audit\AuditPayloadException;

/**
 * The deterministic serialization the audit hash chain is built on
 * (Req 24.5 / D1, Correctness Property 17).
 *
 * A hash chain is only as meaningful as the bytes it hashes. `json_encode()` is
 * *not* those bytes: object key order follows insertion order, floats depend on the
 * `serialize_precision` ini setting, and `1` and `"1"` are indistinguishable once a
 * value has been through a loose comparison. Any of those makes an honest row look
 * tampered — or, worse, lets two different payloads hash the same.
 *
 * So the encoding here is explicit about **type**, **length**, and **order**:
 *
 * | Value              | Encoding                                              |
 * |--------------------|-------------------------------------------------------|
 * | `null`             | `n;`                                                  |
 * | `true` / `false`   | `b1;` / `b0;`                                         |
 * | `int`              | `i<decimal>;` — e.g. `i-42;`                           |
 * | `float`            | `d<16 hex>;` — the raw IEEE-754 big-endian bits         |
 * | `string`           | `s<byteLength>:<bytes>;`                              |
 * | list               | `l<count>:<item><item>…;`                             |
 * | map                | `m<count>:<key><value><key><value>…;` keys sorted      |
 *
 * Three properties follow, and they are what the verifier relies on:
 *
 * 1. **Stable order.** Map keys are sorted by raw byte value (`strcmp`), so
 *    `['b' => 1, 'a' => 2]` and `['a' => 2, 'b' => 1]` encode identically — an
 *    audit row survives a round-trip through JSON, a queue, or a different PHP
 *    version's array ordering.
 * 2. **No ambiguity.** Every value carries its type tag and every string its byte
 *    length, so no payload can be crafted whose encoding collides with another's:
 *    `['a' => 'b;c']` cannot masquerade as `['a' => 'b', 'c' => …]`, and `1`,
 *    `1.0`, `'1'`, and `true` all encode differently.
 * 3. **No float ambiguity.** Floats are hashed as their exact 8 bytes rather than
 *    as text, so precision, locale, and `serialize_precision` cannot change a hash.
 *    `NAN`/`INF` have no meaningful audit value and are refused.
 *
 * Values that cannot be canonicalized (objects, resources) are refused rather than
 * coerced — see `AuditPayloadNormalizer`, which converts the shapes callers legitimately
 * pass (enums, dates, arrayables) *before* they reach this class.
 */
final class CanonicalSerializer
{
    /**
     * Guard against pathological nesting (and, with it, unbounded recursion).
     */
    public const int MAX_DEPTH = 32;

    /**
     * Canonically encode one JSON-representable value.
     *
     * @throws AuditPayloadException when the value has no deterministic form
     */
    public function encode(mixed $value): string
    {
        return $this->write($value, '', 0);
    }

    /**
     * The SHA-256 of the canonical encoding — the primitive the chain composes.
     */
    public function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    private function write(mixed $value, string $path, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw AuditPayloadException::tooDeep(self::MAX_DEPTH, $path);
        }

        return match (true) {
            $value === null => 'n;',
            is_bool($value) => $value ? 'b1;' : 'b0;',
            is_int($value) => 'i'.$value.';',
            is_float($value) => $this->float($value, $path),
            is_string($value) => $this->string($value),
            is_array($value) => $this->array($value, $path, $depth),
            default => throw AuditPayloadException::unsupportedType(get_debug_type($value), $path),
        };
    }

    /**
     * The float's exact bit pattern, so text formatting can never enter the hash.
     */
    private function float(float $value, string $path): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw AuditPayloadException::nonFiniteFloat($path);
        }

        // 'E' is big-endian IEEE-754 double: byte-identical on every platform PHP
        // runs on, unlike 'd' (machine order) or any decimal rendering.
        return 'd'.bin2hex(pack('E', $value)).';';
    }

    /**
     * Length-prefixed, so the delimiter can never be forged from inside a value.
     */
    private function string(string $value): string
    {
        return 's'.strlen($value).':'.$value.';';
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function array(array $value, string $path, int $depth): string
    {
        if (array_is_list($value)) {
            $encoded = '';

            foreach ($value as $index => $item) {
                $encoded .= $this->write($item, $this->child($path, (string) $index), $depth + 1);
            }

            return 'l'.count($value).':'.$encoded.';';
        }

        // Keys are compared as raw bytes rather than with PHP's default sort, which
        // would order numeric-looking keys numerically and locale-aware collation
        // would order accented ones by locale. Neither is reproducible.
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($value));
        usort($keys, static fn (string $a, string $b): int => strcmp($a, $b));

        $encoded = '';

        foreach ($keys as $key) {
            $encoded .= $this->string($key).$this->write($value[$key], $this->child($path, $key), $depth + 1);
        }

        return 'm'.count($keys).':'.$encoded.';';
    }

    private function child(string $path, string $key): string
    {
        return $path === '' ? $key : $path.'.'.$key;
    }
}
