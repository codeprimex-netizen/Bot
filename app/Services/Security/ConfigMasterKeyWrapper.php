<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;
use SensitiveParameter;

/**
 * The default `KeyWrapper`: master keys from configuration, AES-256-GCM wrapping.
 *
 * **Real, not a stub.** Envelope encryption is worthless if the default path is a
 * fake that has to be replaced before anything works — the tempting shortcut then
 * becomes "turn encryption off in dev", and the code grows a plaintext branch. So
 * this implementation actually seals DEKs, actually authenticates them, and is
 * usable in production for single-node deployments (design § Optional dependency
 * matrix: "KMS/Vault → Laravel app-key encryption + `.env`/`platform_settings`
 * (dev/small)"). Task 4.2 swaps it for a cloud-KMS/Vault-backed `KeyWrapper`
 * without `FieldCipher` noticing.
 *
 * ## How master key material is resolved
 *
 * 1. `master_keys[$keyId]` — the operator-supplied master key, base64 (with or
 *    without Laravel's `base64:` prefix) or raw bytes. Several ids may be listed at
 *    once so a rotated-out master key stays *unwrappable* while its DEKs are
 *    re-wrapped (design § Key rotation).
 * 2. Only for the id named by `app_key_id`, and only if step 1 found nothing:
 *    `APP_KEY`. That is what makes a fresh checkout work with zero setup, and it is
 *    documented in `.env.example` as a dev/small-deployment convenience — a
 *    dedicated `WA_MASTER_KEY` decouples "rotate the app key" from "lose every
 *    tenant's DEK".
 * 3. Otherwise: `KeyUnavailableException`. Never a null key, never an unwrapped
 *    write.
 *
 * Whatever the source, the material is never used as a cipher key directly: the
 * wrapping key is derived with HKDF-SHA256 and a per-id info string, so (a) any
 * input length yields a correct 32-byte key, (b) two master key ids derive
 * unrelated wrapping keys even if an operator reuses material, and (c) the app key
 * is domain-separated from Laravel's own use of it — a wrapped DEK is not a
 * `Crypt::encrypt()` payload and cannot be confused for one.
 *
 * The wrap itself is AES-256-GCM over `iv || tag || ciphertext`, base64-encoded,
 * with the caller's `$context` as additional authenticated data — so a DEK blob
 * lifted into another tenant's `encryption_keys` row does not open.
 */
final class ConfigMasterKeyWrapper implements KeyWrapper
{
    /**
     * Authenticated cipher used for the wrap. Distinct from the field cipher's
     * algorithm on purpose: they are independent choices.
     */
    public const string ALGORITHM = 'aes-256-gcm';

    /**
     * Bytes of the derived wrapping key.
     */
    private const int WRAPPING_KEY_BYTES = 32;

    /**
     * AES-GCM's standard nonce and tag sizes.
     */
    private const int IV_BYTES = 12;

    private const int TAG_BYTES = 16;

    /**
     * Least master key material we will derive a wrapping key from. Below this the
     * key store is not protecting anything, so it is refused rather than accepted
     * with a warning nobody reads.
     */
    private const int MIN_MATERIAL_BYTES = 16;

    /**
     * HKDF info prefix — domain separation from every other use of the same bytes.
     */
    private const string DERIVATION_INFO = 'wa:master-key-wrap:v1:';

    /**
     * Derived wrapping keys, memoised per key id for the lifetime of this object
     * (one request or one job — see the `scoped()` binding in
     * `SecurityServiceProvider`). Never persisted, never logged.
     *
     * @var array<string, string>
     */
    private array $wrappingKeys = [];

    /**
     * @param  array<string, string>  $masterKeys  master key material by key id
     * @param  string  $activeKeyId  id new DEKs are sealed under
     * @param  string  $appKeyId  the one id allowed to fall back to `APP_KEY`
     * @param  string|null  $appKey  `APP_KEY` as configured (`base64:` prefix tolerated)
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly array $masterKeys,
        private readonly string $activeKeyId,
        private readonly string $appKeyId = 'app',
        #[SensitiveParameter]
        private readonly ?string $appKey = null,
    ) {}

    /**
     * Parse the `WA_MASTER_KEYS` environment format — `id=material,id2=material2` —
     * into the map this class takes.
     *
     * Exists so additional (typically rotated-out) master key ids can be supplied
     * without a code change, which is what keeps master-key rotation in task 4.2 an
     * operations task rather than a deploy.
     *
     * @return array<string, string>
     */
    public static function parseKeyList(string $list): array
    {
        $keys = [];

        foreach (explode(',', $list) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }

            [$id, $material] = explode('=', $pair, 2);
            $id = trim($id);
            $material = trim($material);

            if ($id !== '' && $material !== '') {
                $keys[$id] = $material;
            }
        }

        return $keys;
    }

    public function activeKeyId(): string
    {
        if ($this->activeKeyId === '') {
            throw KeyUnavailableException::unknownMasterKey('<empty>');
        }

        // Resolve eagerly: "which key id?" must not succeed when that key cannot
        // actually be used, or the failure would surface later, mid-write.
        $this->wrappingKey($this->activeKeyId);

        return $this->activeKeyId;
    }

    public function wrap(#[SensitiveParameter] string $dataKey, string $context): WrappedKey
    {
        $keyId = $this->activeKeyId();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $sealed = openssl_encrypt(
            $dataKey,
            self::ALGORITHM,
            $this->wrappingKey($keyId),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
            self::TAG_BYTES,
        );

        if ($sealed === false) {
            throw KeyUnavailableException::wrapFailed($keyId, self::ALGORITHM);
        }

        return new WrappedKey($keyId, self::ALGORITHM, base64_encode($iv.$tag.$sealed));
    }

    public function unwrap(string $keyId, string $wrapped, string $context): string
    {
        $blob = base64_decode($wrapped, true);

        if ($blob === false || strlen($blob) <= self::IV_BYTES + self::TAG_BYTES) {
            // A truncated or non-base64 blob is unopenable; it is reported exactly like
            // a failed tag check so nothing about the stored bytes is disclosed.
            throw KeyUnavailableException::unwrapFailed($keyId, self::ALGORITHM);
        }

        $dataKey = openssl_decrypt(
            substr($blob, self::IV_BYTES + self::TAG_BYTES),
            self::ALGORITHM,
            $this->wrappingKey($keyId),
            OPENSSL_RAW_DATA,
            substr($blob, 0, self::IV_BYTES),
            substr($blob, self::IV_BYTES, self::TAG_BYTES),
            $context,
        );

        if ($dataKey === false) {
            throw KeyUnavailableException::unwrapFailed($keyId, self::ALGORITHM);
        }

        return $dataKey;
    }

    /**
     * The 32-byte wrapping key derived from the master key material of `$keyId`.
     */
    private function wrappingKey(string $keyId): string
    {
        if (isset($this->wrappingKeys[$keyId])) {
            return $this->wrappingKeys[$keyId];
        }

        $material = $this->material($keyId);
        $length = strlen($material);

        if ($length < self::MIN_MATERIAL_BYTES) {
            throw KeyUnavailableException::weakMasterKey($keyId, $length, self::MIN_MATERIAL_BYTES);
        }

        return $this->wrappingKeys[$keyId] = hash_hkdf(
            'sha256',
            $material,
            self::WRAPPING_KEY_BYTES,
            self::DERIVATION_INFO.$keyId,
        );
    }

    /**
     * Raw master key material for `$keyId`, or a fail-closed error.
     */
    private function material(string $keyId): string
    {
        $configured = $this->masterKeys[$keyId] ?? '';

        if ($configured !== '') {
            return self::decodeMaterial($configured);
        }

        // The documented dev/small-deployment fallback, and only for the one id that
        // is allowed to use it.
        if ($keyId === $this->appKeyId && $this->appKey !== null && $this->appKey !== '') {
            return self::decodeMaterial($this->appKey);
        }

        throw KeyUnavailableException::unknownMasterKey($keyId);
    }

    /**
     * Accept base64 (with or without Laravel's `base64:` prefix) or raw bytes.
     *
     * Being permissive here is safe because the result is run through HKDF either
     * way: a value that merely *looks* like base64 still produces a strong,
     * deterministic wrapping key. What matters is that the same input always yields
     * the same key, which it does.
     */
    private static function decodeMaterial(#[SensitiveParameter] string $material): string
    {
        if (str_starts_with($material, 'base64:')) {
            $decoded = base64_decode(substr($material, 7), true);

            return $decoded === false ? '' : $decoded;
        }

        $decoded = base64_decode($material, true);

        return $decoded === false ? $material : $decoded;
    }

    /**
     * Keep derived wrapping keys out of dumps, stack traces, and log context.
     *
     * `dd($wrapper)` on a debug page would otherwise print every wrapping key this
     * process has derived.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'activeKeyId' => $this->activeKeyId,
            'configuredKeyIds' => array_keys($this->masterKeys),
            'derivedWrappingKeys' => count($this->wrappingKeys).' (redacted)',
        ];
    }
}
