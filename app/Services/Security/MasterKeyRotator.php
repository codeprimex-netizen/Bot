<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;

/**
 * A `KeyWrapper` whose key store can mint a **new master key version on demand**
 * (Req 32.6 / NFR3 — "rotate the KMS master key on schedule").
 *
 * Separate from `KeyWrapper` because rotation is a capability, not part of sealing:
 * `ConfigMasterKeyWrapper` cannot mint anything (its master keys are operator-supplied
 * environment values, and rotating them means adding an id to `master_keys` and
 * pointing `master_key_id` at it), while `KmsKeyWrapper` can, because the KMS owns the
 * material. `RotateMasterKey` therefore asks — `instanceof MasterKeyRotator` — and
 * tells the operator which of the two rotations they are performing, rather than
 * pretending it rotated something it did not.
 *
 * Either way the *interesting* half of a master-key rotation is the same, and it is
 * not here: `MasterKeyRewrapper` re-seals every stored DEK and signing secret under
 * the new key id. This interface only advances the key.
 */
interface MasterKeyRotator
{
    /**
     * Mint a new master key version and return the new `activeKeyId()`.
     *
     * MUST be non-destructive: the previous version stays able to *open* what it
     * sealed, so nothing becomes undecryptable between this call and the re-wrap
     * sweep that follows it.
     *
     * @throws KeyUnavailableException when the key store refuses or is unreachable
     */
    public function rotateMasterKey(): string;
}
