<?php

declare(strict_types=1);

namespace App\Exceptions\Security;

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The key needed to encrypt or decrypt could not be obtained — **503, retryable,
 * and never a plaintext fallback** (Req 32.5 / NFR3; design § Error Handling:
 * "KMS/DEK fetch fails → 503 retryable; no plaintext fallback").
 *
 * ## Why this exception is the feature
 *
 * The dangerous failure mode of field-level encryption is not the crash — it is
 * the *shrug*: a `catch` that returns the value unencrypted "so the page still
 * works", or a `?? null` that quietly turns a protected column into an empty one.
 * Both convert an outage into a permanent data breach, because the plaintext then
 * gets written back to a column everybody believes is encrypted.
 *
 * So `FieldCipher` has no fallback path at all. Every way of not getting a usable
 * key — master key absent, master key unable to unwrap the DEK, no key row for the
 * tenant, a ciphertext naming a version that no longer exists, a version that has
 * been retired, no tenant to resolve a key for — lands here, and this exception
 * carries **no** key material, no plaintext, and no ciphertext (see
 * `SecurityException`).
 *
 * ## Why 503 and retryable
 *
 * The usual cause is the key store being unreachable, which is transient: a queued
 * job should be released and retried rather than failed, and an HTTP caller should
 * be told to come back. Data that *cannot* authenticate is a different problem with
 * a different answer — see `CiphertextIntegrityException`, which is not retryable.
 */
final class KeyUnavailableException extends SecurityException implements HttpExceptionInterface
{
    /**
     * Key store unavailable is a dependency failure, not a client error.
     */
    public const int STATUS = 503;

    /**
     * Seconds a client should wait before retrying.
     */
    public const int RETRY_AFTER = 30;

    /**
     * The only sentence a client is ever shown: nothing about keys, tenants, or the
     * value that failed.
     */
    public const string PUBLIC_MESSAGE = 'Encryption is temporarily unavailable. Please retry shortly.';

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'key_unavailable';

    /**
     * No master key is configured under the id a DEK must be wrapped by (or was
     * wrapped by), so nothing can be sealed or opened.
     */
    public static function unknownMasterKey(string $keyId): self
    {
        return new self(sprintf(
            'No master key is configured under id [%s]. Set a master key (wa.security.encryption.master_keys) '
            .'or bind a KeyWrapper that can resolve it; encryption fails closed until then.',
            self::redact($keyId),
        ));
    }

    /**
     * Master key material exists but is too weak to derive a wrapping key from —
     * refused rather than stretched, because a short master key silently weakens
     * every tenant's DEK.
     */
    public static function weakMasterKey(string $keyId, int $length, int $minimum): self
    {
        return new self(sprintf(
            'Master key [%s] provides only %d bytes of material; at least %d are required. '
            .'Generate one with: php -r "echo base64_encode(random_bytes(32));"',
            self::redact($keyId),
            $length,
            $minimum,
        ));
    }

    /**
     * The wrapped DEK did not open under its master key: the key store rotated
     * without keeping the old key, the row was corrupted, or the stored blob is not
     * a wrap this algorithm produced.
     */
    public static function unwrapFailed(string $keyId, string $algorithm): self
    {
        return new self(sprintf(
            'The wrapped data key sealed under master key [%s] with [%s] did not open. '
            .'No plaintext fallback exists; the read fails closed.',
            self::redact($keyId),
            self::redact($algorithm),
        ));
    }

    /**
     * Sealing a freshly generated DEK failed — the key store is present but not
     * working, so no new ciphertext may be written.
     */
    public static function wrapFailed(string $keyId, string $algorithm): self
    {
        return new self(sprintf(
            'Sealing a new data key under master key [%s] with [%s] failed, so nothing was written.',
            self::redact($keyId),
            self::redact($algorithm),
        ));
    }

    /**
     * A ciphertext names a key version that this tenant does not have — data
     * restored from a foreign backup, or a version deleted while still referenced.
     */
    public static function missingVersion(string $tenantId, KeyPurpose $purpose, int $version): self
    {
        return new self(sprintf(
            'Tenant %s has no %s key at version %d, so a value written under it cannot be read.',
            self::fingerprint($tenantId),
            $purpose->value,
            $version,
        ));
    }

    /**
     * The version exists but has been withdrawn from use. Retiring a version that
     * is still referenced is an operator error, and it fails loudly here rather
     * than degrading.
     */
    public static function unusableVersion(string $tenantId, KeyPurpose $purpose, int $version, KeyStatus $status): self
    {
        return new self(sprintf(
            'The %s key of tenant %s at version %d is %s and may not be used. '
            .'Re-encrypt the remaining ciphertext before retiring a version.',
            $purpose->value,
            self::fingerprint($tenantId),
            $version,
            $status->value,
        ));
    }

    /**
     * No key lineage exists for the tenant at all and one could not be created —
     * provisioning never ran, or ran against a different database.
     */
    public static function notProvisioned(string $tenantId, KeyPurpose $purpose): self
    {
        return new self(sprintf(
            'Tenant %s has no %s key lineage and one could not be provisioned. '
            .'TenantLifecycle::provision creates it; FieldCipher::provision repairs it.',
            self::fingerprint($tenantId),
            $purpose->value,
        ));
    }

    /**
     * A cast (or any caller) asked to encrypt or decrypt without naming a tenant.
     *
     * Per-tenant crypto has no tenant-less mode: guessing one would either pick the
     * wrong key or, worse, invite a plaintext path. The write is refused.
     *
     * @param  class-string  $model
     */
    public static function withoutTenant(string $model, string $attribute): self
    {
        return new self(sprintf(
            'Cannot resolve a per-tenant key for [%s::$%s]: the model carries no tenant_id. '
            .'Encrypted attributes belong on tenant-owned models (BelongsToTenant).',
            self::redact($model),
            self::redact($attribute),
        ));
    }

    /**
     * The platform's own cipher configuration is unusable (unknown AEAD, impossible
     * key length), so refusing is the only safe answer.
     */
    public static function unsupportedAlgorithm(string $algorithm): self
    {
        return new self(sprintf(
            'Cipher [%s] is not an authenticated cipher available to this build, so it cannot be used '
            .'for envelope encryption.',
            self::redact($algorithm),
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
        return ['Retry-After' => (string) self::RETRY_AFTER];
    }

    /**
     * Whether a caller (queue worker, HTTP client) should try again later.
     */
    public function isRetryable(): bool
    {
        return true;
    }

    /**
     * The sentence that may be shown to a client.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }
}
