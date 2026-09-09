<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;

/**
 * The **transport** half of key management: talk to a KMS / Vault, and nothing else
 * (Req 32.6 / NFR3; NFR4.2 — "KMS behind an interface, swappable, absence is a
 * config flip").
 *
 * ## Why this exists next to `KeyWrapper`
 *
 * They are two different questions, and task 4.1 deliberately left room for the
 * second one:
 *
 * | Interface | Question it answers | Vocabulary |
 * |---|---|---|
 * | `KeyWrapper` | how is a DEK sealed for storage in `encryption_keys`? | `WrappedKey`, AAD context, algorithm label |
 * | `KmsClient` (this) | how do I reach the key store that does the sealing? | endpoints, key **ids and versions**, master-key rotation |
 *
 * `KmsKeyWrapper` is the one class that spans them: it `implements KeyWrapper` and
 * delegates every operation here. Everything above it — `EnvelopeFieldCipher`, the
 * `Encrypted` casts, `DatabaseSigningSecretStore` — is unchanged by which KMS is in
 * use, which is the point.
 *
 * The seam is **three operations wide plus rotation**, because that is genuinely all
 * a KMS is used for here. It is not a general secret store: reading application
 * secrets out of Vault KV is a deployment concern (design § Secrets management), not
 * this interface's.
 *
 * ## Contract
 *
 * - `encrypt()` seals under the **active** master key and reports, in
 *   `KmsSeal::$keyId`, the exact key *version* that did it. That id is persisted next
 *   to the sealed blob, which is what makes the re-wrap sweep of
 *   `MasterKeyRewrapper` possible without opening a single blob.
 * - `decrypt()` MUST keep working for key ids the store no longer issues, for as long
 *   as the store retains them: master-key rotation overlaps by design.
 * - `$context` is additional authenticated data. An implementation that cannot bind
 *   it MUST fail rather than silently ignore it — dropping the AAD would remove the
 *   cryptographic tenant binding `EnvelopeFieldCipher` relies on.
 * - Every failure is a `KeyUnavailableException`: unreachable, unauthorized,
 *   rejected, malformed response, wrong context. There is **no** success-ish return
 *   value, no `null`, and no plaintext fallback (Req NFR3.5).
 * - Implementations MUST NOT log, echo, or embed plaintext key material, tokens, or
 *   sealed blobs — including in exception messages.
 *
 * ## Implementations
 *
 * - `VaultTransitKmsClient` — real, HTTP, the Vault transit engine.
 * - `GuardedKmsClient` — decorator adding the circuit breaker and retry budget every
 *   fallible network dependency on this platform gets.
 * - `Tests\Fixtures\Security\FakeKms` — test-only, lives under `tests/` so it is not
 *   autoloadable in production at all (Property 28 / Req 36).
 */
interface KmsClient
{
    /**
     * The id of the master key version new material is sealed under right now.
     *
     * Format is the implementation's own, opaque above this interface, and stored in
     * `encryption_keys.kms_key_id` / `signing_secrets.kms_key_id`. It MUST identify a
     * *version*, not just a key: "which DEKs are still sealed under the previous
     * master key?" is the question the rotation sweep asks, and a version-less id
     * cannot answer it.
     *
     * @throws KeyUnavailableException when the store is unreachable or has no usable key
     */
    public function activeKeyId(): string;

    /**
     * Seal material under the active master key.
     *
     * @param  string  $plaintext  raw bytes (a DEK, an HMAC secret) — never logged, never persisted here
     * @param  string  $context  additional authenticated data; the identical string must
     *                           be presented to `decrypt()`
     *
     * @throws KeyUnavailableException when the store cannot seal it
     */
    public function encrypt(string $plaintext, string $context): KmsSeal;

    /**
     * Open material sealed by `encrypt()`.
     *
     * @param  string  $keyId  the id reported at seal time (may name a rotated-out version)
     * @param  string  $ciphertext  the blob from `KmsSeal::$ciphertext`
     * @param  string  $context  the exact context used at seal time
     * @return string raw plaintext bytes
     *
     * @throws KeyUnavailableException when the key is gone, the store is unreachable, or
     *                                 the blob/context does not authenticate
     */
    public function decrypt(string $keyId, string $ciphertext, string $context): string;

    /**
     * Ask the store to mint a new version of the master key, and return the new
     * active key id (Req 32.6 — "rotate the KMS master key on schedule").
     *
     * Rotation here is **non-destructive by contract**: the previous version must stay
     * openable, so material sealed under it keeps decrypting until
     * `MasterKeyRewrapper` has moved it forward. An implementation whose store cannot
     * mint versions itself (an operator-managed key list, say) returns the currently
     * configured active id unchanged — the caller compares it against what it had and
     * reports "nothing to rotate" rather than pretending it rotated.
     *
     * @throws KeyUnavailableException when the store refuses or is unreachable
     */
    public function rotate(): string;
}
