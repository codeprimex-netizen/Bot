<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

use App\Enums\KeyPurpose;
use App\Services\Security\CipherPayload;

/**
 * A stored value did not authenticate under the key and context it claims —
 * refused, never returned (Req 32.5 / NFR3).
 *
 * A sibling of `KeyUnavailableException` in the `SecurityException` family of
 * design § Error Handling, and deliberately a *different* class, because the two
 * failures want opposite handling:
 *
 * | | `KeyUnavailableException` | this |
 * |---|---|---|
 * | cause | key store down / key missing | ciphertext tampered, truncated, replayed across tenants, or not a ciphertext at all |
 * | retry | **yes** — the key store recovers | **no** — the bytes will not start authenticating |
 * | HTTP | 503 | 500 (a data-integrity fault, not a client's doing) |
 *
 * Retrying an AEAD failure forever would turn one corrupt row into a hot loop, so
 * this one is terminal.
 *
 * ## What reaches this exception
 *
 * The three ways an AES-GCM read fails, all indistinguishable to the caller by
 * design (distinguishing them is a padding-oracle-shaped mistake):
 *
 * - **tamper** — a byte of ciphertext, IV, or tag was changed;
 * - **cross-tenant replay** — a ciphertext moved to another tenant's row. The
 *   tenant id and key version are bound as additional authenticated data, so the
 *   AEAD tag stops verifying even though the bytes are intact (this is the
 *   mechanism behind the per-tenant isolation Req 32.1 asks for);
 * - **not a ciphertext** — a plaintext value in an encrypted column. It is
 *   *rejected* rather than passed through: silently returning it is the plaintext
 *   fallback Req 32.5 forbids, and it would hide a column that never got encrypted.
 *
 * As with every `SecurityException`, no plaintext, ciphertext, or key material
 * appears in the message — only lengths, versions and fingerprints.
 */
final class CiphertextIntegrityException extends SecurityException
{
    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'ciphertext_integrity';

    /**
     * The only sentence a client is ever shown.
     */
    public const string PUBLIC_MESSAGE = 'This value could not be read securely.';

    /**
     * The AEAD tag did not verify: tampering, truncation, or a ciphertext replayed
     * into another tenant (the tenant id is part of the authenticated context).
     */
    public static function authenticationFailed(string $tenantId, KeyPurpose $purpose, int $version): self
    {
        return new self(sprintf(
            'A %s value of tenant %s failed authentication under key version %d. '
            .'It was tampered with, truncated, or written for a different tenant, purpose, or version; '
            .'it is rejected rather than returned.',
            $purpose->value,
            self::fingerprint($tenantId),
            $version,
        ));
    }

    /**
     * The value is not one of this platform's envelopes at all — most often a
     * plaintext value sitting in an encrypted column.
     */
    public static function malformed(string $reason, string $value): self
    {
        return new self(sprintf(
            'Value is not a [%s] envelope (%s; %s). A non-envelope value in an encrypted column is refused, '
            .'never passed through as plaintext.',
            CipherPayload::PREFIX,
            self::redact($reason),
            self::describeLength($value),
        ));
    }

    /**
     * The envelope authenticates but declares a different purpose than the caller
     * asked for — a `FIELD` value read through an `EXPORT` cast, say. Refused: the
     * purposes are separate key lineages on purpose.
     */
    public static function purposeMismatch(KeyPurpose $expected, KeyPurpose $found): self
    {
        return new self(sprintf(
            'Envelope declares purpose %s but was read as %s; the two are separate key lineages.',
            $found->value,
            $expected->value,
        ));
    }

    /**
     * The decrypted bytes were meant to be a JSON document (an `EncryptedArray`
     * attribute) and are not. The plaintext itself is never included.
     */
    public static function malformedJson(string $tenantId, string $attribute): self
    {
        return new self(sprintf(
            'The value decrypted for tenant %s attribute [%s] is not a JSON object, so it cannot be cast.',
            self::fingerprint($tenantId),
            self::redact($attribute),
        ));
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }
}
