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

    /*
    |--------------------------------------------------------------------------
    | KMS / Vault transport (task 4.2, Req 32.6 / NFR3)
    |--------------------------------------------------------------------------
    | A KMS is a network dependency, so it fails in ways a config-file master key
    | cannot: unreachable, unauthorized, rate-limited, fenced off by its own circuit
    | breaker. Every one of them lands here, which is what keeps "the KMS is down"
    | and "there is no master key" on the same fail-closed path — a 503 the caller
    | retries, never a write that skips encryption.
    */

    /**
     * A KMS-backed wrapper is selected but the store is not configured (no address,
     * no token, no key name), so nothing can be sealed or opened.
     *
     * Deliberately *not* a degradation to the config master key: silently falling back
     * to a weaker key store is how a production deployment ends up believing it uses a
     * KMS when it does not.
     */
    public static function kmsNotConfigured(string $detail): self
    {
        return new self(sprintf(
            'The configured key store is unusable: %s. Encryption fails closed until it is configured '
            .'(wa.security.kms) or wa.security.encryption.wrapper is pointed back at a wrapper that works.',
            self::redact($detail),
        ));
    }

    /**
     * The KMS could not be reached at all — DNS, TLS, connect timeout, or a circuit
     * breaker holding the door shut after repeated failures.
     *
     * Carries no URL and no provider message: both routinely quote request payloads.
     */
    public static function kmsUnreachable(string $operation): self
    {
        return new self(sprintf(
            'The key store could not be reached for [%s]. No plaintext fallback exists; the operation '
            .'fails closed and may be retried.',
            self::redact($operation),
        ));
    }

    /**
     * The KMS answered, and refused: a bad token, a policy denial, a missing key, or
     * a context that did not authenticate.
     *
     * The status code is kept because it is what an operator needs (403 means fix the
     * policy, 404 means fix the key name, 5xx means wait); the body is discarded.
     */
    public static function kmsRejected(string $operation, int $status): self
    {
        return new self(sprintf(
            'The key store rejected [%s] with status %d. Nothing was decrypted and nothing was written.',
            self::redact($operation),
            $status,
        ));
    }

    /**
     * The KMS answered 200 with something this client cannot use — a missing field, a
     * non-base64 payload, a ciphertext in an unknown format.
     *
     * Treated exactly like a refusal: a response we cannot parse is a response we
     * must not act on.
     */
    public static function kmsMalformedResponse(string $operation): self
    {
        return new self(sprintf(
            'The key store returned an unusable response for [%s], so it was discarded rather than trusted.',
            self::redact($operation),
        ));
    }

    /**
     * A stored `kms_key_id` does not name a key this client can address — material
     * restored from another deployment, or a key store swapped underneath the data.
     */
    public static function kmsUnknownKeyId(string $keyId): self
    {
        return new self(sprintf(
            'Key id [%s] is not addressable by the configured key store, so material sealed under it '
            .'cannot be opened here.',
            self::redact($keyId),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Signing secrets (task 4.2 — dual-secret HMAC rotation)
    |--------------------------------------------------------------------------
    */

    /**
     * A scope has no signing secret to sign with, and one could not be issued.
     *
     * Verification does *not* land here — an inbound signature checked against a
     * scope that has no secret is simply invalid (`false`), because an unknown scope
     * must never produce a 503 an attacker can trigger at will.
     */
    public static function missingSigningSecret(string $scope): self
    {
        return new self(sprintf(
            'No signing secret could be issued for scope [%s], so nothing can be signed for it.',
            self::redact($scope),
        ));
    }

    /**
     * A stored signing secret would not open: the master key it was sealed under is
     * gone, or the row was tampered with.
     */
    public static function signingSecretUnreadable(string $scope, int $version): self
    {
        return new self(sprintf(
            'The signing secret for scope [%s] at version %d did not open under its master key.',
            self::redact($scope),
            $version,
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
