<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;
use SensitiveParameter;
use Throwable;

/**
 * The `KeyWrapper` that seals DEKs through a **KMS / Vault** instead of a master key
 * in configuration (Req 32.6 / NFR3; design § Encryption, secrets & key management).
 *
 * This is the class task 4.1 predicted, and it is as thin as that prediction: a KMS
 * `Encrypt`/`Decrypt` call *is* a wrap/unwrap, and every cloud KMS and Vault transit
 * takes an encryption context that `$context` maps straight onto. So
 * `EnvelopeFieldCipher`, the `Encrypted` casts, the ciphertext format, the key
 * lifecycle and every existing test are untouched by switching to a KMS — it is one
 * config value:
 *
 * ```
 * WA_KEY_WRAPPER="App\Services\Security\KmsKeyWrapper"
 * WA_KMS_DRIVER=vault
 * WA_VAULT_ADDR=https://vault.internal:8200
 * WA_VAULT_TOKEN=…            # or WA_VAULT_TOKEN_FILE for an injected token
 * ```
 *
 * ## What it adds beyond delegation
 *
 * 1. **Fail-closed translation.** A KMS client can fail in its own vocabulary (an SDK
 *    exception, a transport error). Everything that is not a value becomes
 *    `KeyUnavailableException` — 503, retryable, no plaintext fallback — and no
 *    provider message, URL, token or blob is allowed into it.
 * 2. **Key material never lands anywhere.** The DEK passed to `wrap()` is marked
 *    sensitive so it is redacted from stack traces, and this object holds no state at
 *    all: no cache of unwrapped keys (that belongs to `EnvelopeFieldCipher`, scoped to
 *    one request), nothing to leak through `__debugInfo()`.
 * 3. **Master-key rotation** (`MasterKeyRotator`), which the config wrapper cannot do.
 *
 * ## No caching here, deliberately
 *
 * It would be easy to memoise unwrapped DEKs at this layer and cheap in KMS calls —
 * and it would defeat the point of a KMS. A cached key that outlives the request
 * outlives a revoked master key too, so a compromised node would keep decrypting after
 * the key was revoked. `EnvelopeFieldCipher` already caches unwrapped DEKs for exactly
 * one unit of work (`scoped()` binding, `forgetKeys()`), which is the bound design
 * asks for: "cached in-process, never persisted in plaintext". This class adds nothing
 * to that lifetime.
 */
final readonly class KmsKeyWrapper implements KeyWrapper, MasterKeyRotator
{
    public function __construct(private KmsClient $kms) {}

    public function activeKeyId(): string
    {
        return $this->attempt('kms.keys.read', fn (): string => $this->kms->activeKeyId());
    }

    public function wrap(#[SensitiveParameter] string $dataKey, string $context): WrappedKey
    {
        if ($dataKey === '') {
            // Sealing nothing would produce a perfectly valid blob that unwraps to an
            // unusable key, i.e. a key store that appears to work and silently is not.
            throw KeyUnavailableException::kmsNotConfigured('refusing to seal an empty data key');
        }

        return $this->attempt(
            'kms.encrypt',
            fn (): KmsSeal => $this->kms->encrypt($dataKey, $context),
        )->toWrappedKey();
    }

    public function unwrap(string $keyId, string $wrapped, string $context): string
    {
        if ($keyId === '' || $wrapped === '') {
            throw KeyUnavailableException::kmsUnknownKeyId($keyId);
        }

        return $this->attempt(
            'kms.decrypt',
            fn (): string => $this->kms->decrypt($keyId, $wrapped, $context),
        );
    }

    public function rotateMasterKey(): string
    {
        return $this->attempt('kms.rotate', fn (): string => $this->kms->rotate());
    }

    /**
     * Run one KMS operation, collapsing every non-value outcome onto the fail-closed
     * path.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws KeyUnavailableException
     */
    private function attempt(string $label, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (KeyUnavailableException $e) {
            throw $e;
        } catch (Throwable) {
            throw KeyUnavailableException::kmsUnreachable($label);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['kms' => $this->kms::class];
    }
}
