<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\KeyPurpose;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\EncryptionKey;
use App\Models\Tenant;

/**
 * Field-level **envelope encryption**, per tenant (Req 32.5 / NFR3; design
 * § Components → `FieldCipher`, § Per-tenant encryption & data residency).
 *
 * ```
 *  plaintext ─┬─▶ AEAD(DEK_t,v, aad = tenant|purpose|version) ─▶ wac1.FIELD.v.iv.tag.ct
 *             │
 *          DEK_t,v ◀── unwrap(master key, aad = tenant|purpose|version) ── encryption_keys.wrapped_dek
 * ```
 *
 * Two keys, two jobs. The per-tenant **DEK** does the bulk encryption, which is
 * what makes the crypto *isolated*: one tenant's key never touches another
 * tenant's bytes, and a compromised or exported DEK is scoped to a single
 * customer. The **master key** never leaves the key store and only ever seals
 * DEKs, which is what makes the whole set rotatable — rotating the master key
 * re-wraps some rows, it does not re-encrypt the database.
 *
 * ## Guarantees
 *
 * 1. **Authenticated.** AES-256-GCM. A modified byte is a failure, not a plausible
 *    plaintext (`CiphertextIntegrityException`).
 * 2. **Tenant-bound.** The tenant id, purpose and key version are additional
 *    authenticated data. A ciphertext copied into another tenant's row does not
 *    decrypt there — the isolation is cryptographic, not a comparison somebody has
 *    to remember to write (Req 32.1).
 * 3. **Versioned.** `encrypt` always writes under the active version; `decrypt`
 *    reads whatever version the value names, including a `RETIRING` one. Rotation is
 *    therefore non-breaking, and re-encryption can be lazy.
 * 4. **Fail-closed, always.** Every "cannot get the key" path throws
 *    `KeyUnavailableException` (503, retryable) and every "cannot authenticate"
 *    path throws `CiphertextIntegrityException`. There is **no** implementation of
 *    this interface that may return the plaintext it was given, an empty string, or
 *    null when encryption is unavailable — including for a value that is not an
 *    envelope at all, which is refused rather than passed through (Req 32.5).
 *
 * ## Using it from a model (the ergonomic path)
 *
 * Prefer the casts over calling this interface by hand — they resolve the tenant
 * from the model's own `tenant_id`, so a column is encrypted by declaring it:
 *
 * ```php
 * use App\Casts\Encrypted;
 * use App\Casts\EncryptedArray;
 *
 * protected function casts(): array
 * {
 *     return [
 *         'access_token' => Encrypted::class,        // one secret string
 *         'secret_config' => EncryptedArray::class,  // a whole JSON blob of them
 *     ];
 * }
 * ```
 *
 * ## Signature note
 *
 * design writes `encrypt(int $tenantId, ...)`; tenant keys in this codebase are
 * ULID strings (`Tenant` uses `HasUlids`), so the parameter is widened to
 * `Tenant|string` — the same contract, typed for the ids that actually exist.
 * `rotate()` returns the new key instead of `void` for the same reason a factory
 * returns what it made: the caller (and task 4.2's scheduled rotation) needs the
 * new version number to report and to drive a backfill.
 */
interface FieldCipher
{
    /*
    |--------------------------------------------------------------------------
    | Design contract (design.md § Components and Interfaces → FieldCipher)
    |--------------------------------------------------------------------------
    */

    /**
     * Encrypt one value for one tenant under that tenant's **active** DEK.
     *
     * The tenant's key lineage is created on first use if it does not exist yet, so
     * a tenant provisioned before this feature (or by a factory in a test) encrypts
     * correctly rather than failing — `provision()` is the explicit form for
     * `TenantLifecycle`.
     *
     * @throws KeyUnavailableException when no usable key can be obtained — never a
     *                                 plaintext fallback
     */
    public function encrypt(Tenant|string $tenant, string $plaintext, KeyPurpose $purpose = KeyPurpose::Field): string;

    /**
     * Decrypt a value written for `$tenant`, under whichever key version it names.
     *
     * @throws KeyUnavailableException when the named version is missing, retired, or
     *                                 cannot be unwrapped
     * @throws CiphertextIntegrityException when the value is not a well-formed
     *                                      envelope, or fails authentication —
     *                                      including a value belonging to another
     *                                      tenant
     */
    public function decrypt(Tenant|string $tenant, string $ciphertext, KeyPurpose $purpose = KeyPurpose::Field): string;

    /**
     * Rotate a tenant's DEK: mint a new `ACTIVE` version and mark the previous one
     * `RETIRING` (design § Key rotation).
     *
     * Existing ciphertext keeps decrypting under the retiring version; new writes use
     * the new one. The scheduled command and the re-encryption backfill are task 4.2
     * — this is the primitive both build on.
     *
     * @throws KeyUnavailableException when a new key cannot be generated or sealed;
     *                                 the previous version is then left untouched
     */
    public function rotate(Tenant|string $tenant, KeyPurpose $purpose = KeyPurpose::Field): EncryptionKey;

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    */

    /**
     * Create the tenant's first DEK for a purpose, or return the existing active one
     * (idempotent).
     *
     * Called by `TenantLifecycle::provision`, which runs **before** any tenant
     * context exists — so implementations must name the tenant explicitly rather
     * than read it from `TenantContext`.
     *
     * @throws KeyUnavailableException when the key store cannot seal a new DEK
     */
    public function provision(Tenant|string $tenant, KeyPurpose $purpose = KeyPurpose::Field): EncryptionKey;

    /**
     * Whether a stored value was written under a version other than the active one,
     * i.e. whether re-encrypting it would move it forward.
     *
     * The read side of the lazy re-encryption strategy: a caller that is about to
     * write anyway can ask this and refresh the value for free.
     *
     * @throws KeyUnavailableException when the tenant's active version cannot be resolved
     * @throws CiphertextIntegrityException when the value is not a well-formed envelope
     */
    public function isStale(Tenant|string $tenant, string $ciphertext, KeyPurpose $purpose = KeyPurpose::Field): bool;

    /**
     * Re-encrypt a value under the tenant's current active version.
     *
     * Decrypt-then-encrypt with both halves fail-closed: the backfill of task 4.2
     * iterates rows and calls this, and a row it cannot authenticate stops that row
     * rather than blanking it.
     *
     * @throws KeyUnavailableException
     * @throws CiphertextIntegrityException
     */
    public function reencrypt(Tenant|string $tenant, string $ciphertext, KeyPurpose $purpose = KeyPurpose::Field): string;

    /**
     * Whether a value is one of this platform's envelopes.
     *
     * A *format* test only — it proves nothing about authenticity, so it must never
     * be used to decide whether to return a value. Its legitimate use is
     * migration tooling asking "has this column been encrypted yet?".
     */
    public function isEnvelope(string $value): bool;

    /**
     * Drop every unwrapped DEK this instance is holding in memory.
     *
     * Implementations cache unwrapped DEKs for the lifetime of one request or job
     * (design: "cached in-process, never persisted in plaintext"). This shortens that
     * window explicitly — after a long-running command has finished with a tenant,
     * or immediately after a rotation.
     */
    public function forgetKeys(): void;
}
