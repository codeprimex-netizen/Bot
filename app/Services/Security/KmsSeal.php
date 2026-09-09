<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * What a KMS hands back when it seals something: the blob, the exact master key
 * **version** that sealed it, and a label for the algorithm.
 *
 * The version is the part that is easy to leave out and impossible to reconstruct
 * later. Without it, "re-wrap every DEK still sealed under the previous master key"
 * degrades into "open every DEK in the database and see" — which is both a full
 * table of KMS calls and a moment where every tenant's key material is in one
 * process's memory at once. With it, the sweep is one indexed query
 * (`encryption_keys.kms_key_id`).
 *
 * Maps onto `encryption_keys.{kms_key_id, algorithm, wrapped_dek}` and onto
 * `signing_secrets.{kms_key_id, algorithm, sealed_secret}`.
 *
 * Holds no plaintext: `$ciphertext` is already sealed.
 */
final readonly class KmsSeal
{
    /**
     * @param  string  $keyId  master key **version** that sealed this blob, in the
     *                         implementation's own id format (`activeKeyId()`)
     * @param  string  $algorithm  short label of the sealing mechanism, stored so an
     *                             algorithm migration is data rather than a guess
     *                             (`encryption_keys.algorithm` holds 32 chars)
     * @param  string  $ciphertext  the sealed blob, in a transport-safe encoding
     */
    public function __construct(
        public string $keyId,
        public string $algorithm,
        public string $ciphertext,
    ) {}

    /**
     * The same three values in the shape `KeyWrapper` callers expect.
     */
    public function toWrappedKey(): WrappedKey
    {
        return new WrappedKey($this->keyId, $this->algorithm, $this->ciphertext);
    }
}
