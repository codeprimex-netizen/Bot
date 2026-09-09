<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * A sealed DEK together with everything needed to open it again: the master key it
 * was sealed under and the algorithm that sealed it.
 *
 * Those three values travel together because storing the blob without them is how
 * a key store becomes un-rotatable — you cannot re-wrap what you cannot attribute.
 * They map one-to-one onto `encryption_keys.wrapped_dek`, `.kms_key_id` and
 * `.algorithm`.
 *
 * Holds **no** plaintext key material: `$blob` is already ciphertext, so this
 * object is safe to pass around, and `__toString()`-shaped accidents cannot leak a
 * DEK through it.
 */
final readonly class WrappedKey
{
    /**
     * @param  string  $keyId  master key that sealed the DEK (`encryption_keys.kms_key_id`)
     * @param  string  $algorithm  label of the sealing algorithm (`encryption_keys.algorithm`)
     * @param  string  $blob  the sealed DEK, in whatever transport-safe encoding the
     *                        wrapper defined — opaque to `FieldCipher`
     */
    public function __construct(
        public string $keyId,
        public string $algorithm,
        public string $blob,
    ) {}
}
