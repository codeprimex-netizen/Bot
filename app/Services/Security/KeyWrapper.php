<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;

/**
 * The **KMS seam**: the one thing `FieldCipher` needs from a key-management
 * system, and nothing else (Req 32.5 / NFR3; design § Encryption, secrets & key
 * management, and NFR4.2 — "KMS behind an interface, swappable, absence is a
 * config flip").
 *
 * Envelope encryption has exactly two halves. `FieldCipher` owns the outer half —
 * generate a per-tenant DEK, use it with an AEAD, bind the tenant into the
 * authenticated context, version it, rotate it. This interface is the inner half:
 * **seal that DEK, and open it again.** That is deliberately the *whole* contract,
 * three methods wide, because it is the part that differs between a cloud KMS, a
 * Vault transit engine, an HSM, and a master key in the environment — and the part
 * `FieldCipher` must never learn anything about.
 *
 * ## Why this shape (and how task 4.2 slots in)
 *
 * Task 4.2 introduces a `KmsClient` interface plus a real cloud-KMS/Vault adapter.
 * It does **not** need to touch `FieldCipher`: it adds one class,
 * `implements KeyWrapper`, that delegates to `KmsClient` (a KMS `Encrypt`/`Decrypt`
 * call *is* a wrap/unwrap, and cloud KMS APIs already take an encryption context —
 * `$context` below maps straight onto it), then points
 * `wa.security.encryption.wrapper` at it. Nothing else changes: the DEKs, the
 * ciphertext format, the rotation lifecycle, and every test stay as they are.
 *
 * Until then the default implementation is `ConfigMasterKeyWrapper`, which is real
 * and works out of the box — not a stub. There is no fake in the production path;
 * test doubles live under `tests/`.
 *
 * ## Contract
 *
 * - `wrap()` MUST produce an **authenticated** sealing of the key, bound to
 *   `$context`, so a wrapped DEK cannot be moved to another tenant's row.
 * - `unwrap()` MUST fail — never return a guess, a truncation, or the input — when
 *   the context differs, the blob is damaged, or the named key is unavailable.
 * - Every failure is a `KeyUnavailableException`. Implementations MUST NOT log,
 *   echo, or embed key material anywhere, including in exception messages.
 * - `unwrap()` MUST keep accepting key ids it no longer issues, so master-key
 *   rotation can overlap (design § Key rotation).
 */
interface KeyWrapper
{
    /**
     * The id of the master key new DEKs are sealed under right now.
     *
     * Persisted next to each wrapped DEK (`encryption_keys.kms_key_id`) so a
     * master-key rotation can find the DEKs that still need re-wrapping without
     * unwrapping any of them.
     *
     * @throws KeyUnavailableException when no usable master key is configured
     */
    public function activeKeyId(): string;

    /**
     * Seal a freshly generated DEK under the active master key.
     *
     * @param  string  $dataKey  raw DEK bytes — never logged, never persisted by an implementation
     * @param  string  $context  additional authenticated data binding the wrap to its row
     *                           (tenant, purpose, version); the same string must be
     *                           presented to `unwrap()`
     *
     * @throws KeyUnavailableException when the key store cannot seal it
     */
    public function wrap(string $dataKey, string $context): WrappedKey;

    /**
     * Open a wrapped DEK.
     *
     * @param  string  $keyId  master key the blob was sealed under (may be a rotated-out id)
     * @param  string  $wrapped  the blob as produced by `wrap()`
     * @param  string  $context  the exact context used at wrap time
     * @return string raw DEK bytes
     *
     * @throws KeyUnavailableException when the key is unavailable, or the blob or
     *                                 context does not authenticate — there is no
     *                                 fallback, by design
     */
    public function unwrap(string $keyId, string $wrapped, string $context): string;
}
