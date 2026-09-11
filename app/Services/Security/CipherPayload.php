<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\KeyPurpose;
use App\Exceptions\Security\CiphertextIntegrityException;

/**
 * The on-disk shape of one encrypted field.
 *
 * ```
 * wac1.FIELD.3.PC1CCg8ye5vzWQr9.Ee1p3xnv0Y_1FQ5Rr0oaHg.o0OQ8dtl2mBt
 * ────  ────  ─  ──────────────  ──────────────────────  ──────────
 *   │     │    │        │                   │                 │
 *   │     │    │        │                   │                 └─ ciphertext
 *   │     │    │        │                   └─ AES-GCM tag (16 bytes)
 *   │     │    │        └─ nonce (12 bytes)
 *   │     │    └─ key version — which DEK version can read this
 *   │     └─ key purpose — which key lineage
 *   └─ format marker
 * ```
 *
 * ## Why each part is where it is
 *
 * **The key version is in the payload.** Without it, rotating a DEK would either
 * break every value written before the rotation or force an all-or-nothing
 * re-encryption of the whole database inside the rotation window. With it,
 * `encrypt` can always use the newest version while `decrypt` reads whatever
 * version a given row was written under, and re-encryption becomes lazy (design
 * § Key rotation: "lazy re-encrypt on write").
 *
 * **The tenant id is *not* in the payload.** It is bound as additional
 * authenticated data instead (`FieldCipher`), which is strictly stronger than
 * storing it: a value moved into another tenant's row does not decrypt at all,
 * rather than decrypting and then being compared against something. The AEAD tag
 * *is* the tenant check (Req 32.1, 32.5 / NFR3).
 *
 * **The parts are base64url and dot-separated**, so a ciphertext is a single
 * printable token — safe in a `varchar`, a JSON body, a CSV export, and a URL —
 * and `.` never appears inside a segment, which makes parsing unambiguous. Empty
 * plaintext yields an empty final segment, which is a legitimate value (AES-GCM of
 * zero bytes is zero bytes plus a tag) and round-trips.
 *
 * Immutable and inert: it holds ciphertext only, so passing one around leaks
 * nothing.
 */
final readonly class CipherPayload
{
    /**
     * Format marker. Bumping it is how a future format change stays readable
     * alongside this one — `parse()` refuses anything it does not recognise instead
     * of guessing.
     */
    public const string PREFIX = 'wac1';

    /**
     * Separator between segments. Not part of the base64url alphabet, so it can
     * never occur inside one.
     */
    private const string SEPARATOR = '.';

    private const int SEGMENTS = 6;

    public function __construct(
        public KeyPurpose $purpose,
        public int $keyVersion,
        public string $iv,
        public string $tag,
        public string $ciphertext,
    ) {}

    /**
     * Cheap, allocation-free test for "is this one of ours at all?".
     *
     * Used by `FieldCipher::isEnvelope()` and by the casts to tell an already-encrypted
     * value from something else. It deliberately proves nothing about authenticity —
     * only `decrypt()` does that — so it must never gate a decision to *return* a
     * value.
     */
    public static function looksLikeEnvelope(string $value): bool
    {
        return str_starts_with($value, self::PREFIX.self::SEPARATOR);
    }

    /**
     * Parse a stored value, refusing anything that is not exactly this format.
     *
     * @throws CiphertextIntegrityException when the value is not a well-formed envelope
     */
    public static function parse(string $value): self
    {
        if (! self::looksLikeEnvelope($value)) {
            throw CiphertextIntegrityException::malformed('missing format marker', $value);
        }

        $segments = explode(self::SEPARATOR, $value);

        if (count($segments) !== self::SEGMENTS) {
            throw CiphertextIntegrityException::malformed(
                sprintf('expected %d segments, found %d', self::SEGMENTS, count($segments)),
                $value,
            );
        }

        [, $purpose, $version, $iv, $tag, $ciphertext] = $segments;

        $parsedPurpose = KeyPurpose::tryFrom($purpose);

        if ($parsedPurpose === null) {
            throw CiphertextIntegrityException::malformed('unknown key purpose', $value);
        }

        // Digits only: `(int)` would happily turn "3junk" into 3, and a key version is
        // a lookup key — it has to be exactly what was written.
        if ($version === '' || preg_match('/^[1-9][0-9]*$/', $version) !== 1) {
            throw CiphertextIntegrityException::malformed('key version is not a positive integer', $value);
        }

        $decodedIv = self::decode($iv, $value, 'nonce');
        $decodedTag = self::decode($tag, $value, 'authentication tag');

        return new self(
            $parsedPurpose,
            (int) $version,
            $decodedIv,
            $decodedTag,
            $ciphertext === '' ? '' : self::decode($ciphertext, $value, 'ciphertext'),
        );
    }

    /**
     * The stored form.
     */
    public function encode(): string
    {
        return implode(self::SEPARATOR, [
            self::PREFIX,
            $this->purpose->value,
            (string) $this->keyVersion,
            self::encodeSegment($this->iv),
            self::encodeSegment($this->tag),
            self::encodeSegment($this->ciphertext),
        ]);
    }

    /**
     * base64url without padding: no `+`, `/`, or `=` to be escaped by whatever the
     * value passes through on its way to the column.
     */
    private static function encodeSegment(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @throws CiphertextIntegrityException
     */
    private static function decode(string $segment, string $value, string $what): string
    {
        $padding = strlen($segment) % 4;
        $decoded = base64_decode(
            strtr($segment, '-_', '+/').($padding === 0 ? '' : str_repeat('=', 4 - $padding)),
            true,
        );

        if ($decoded === false || $decoded === '') {
            throw CiphertextIntegrityException::malformed($what.' is not valid base64url', $value);
        }

        return $decoded;
    }
}
