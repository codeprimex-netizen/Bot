<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * A table holding material sealed by the master key, and therefore something a
 * master-key rotation has to move forward (Req 32.6 / NFR3).
 *
 * ## Why this is an interface and not two methods on one class
 *
 * Master-key rotation is only correct if it is **exhaustive**. Miss one table and the
 * moment an operator removes the retired master key from the key store, that table
 * becomes permanently unopenable — which is data loss discovered weeks later. Two
 * tables hold sealed material today:
 *
 * | Store | Material | Sealed context |
 * |---|---|---|
 * | `EncryptionKeyRewrapStore` | per-tenant DEKs (`encryption_keys.wrapped_dek`) | `tenant\|purpose\|version` |
 * | `SigningSecretRewrapStore` | HMAC secrets (`signing_secrets.sealed_secret`) | `scope\|version` |
 *
 * and a later phase that seals anything else adds one class plus one line in
 * `wa.security.encryption.rotation.stores` — the same "appending is the entire cost"
 * shape as `wa.tenancy.provisioning.steps` and `wa.dispatch.eligibility.gates`. An
 * entry that cannot be resolved is fatal, because a silently skipped store is a silently
 * un-rotated table.
 *
 * ## What an implementation must guarantee
 *
 * **Re-wrapping changes only the seal.** The material inside is unwrapped and sealed
 * again under the new master key; the DEK bytes, the key version, and the status are
 * untouched, so every ciphertext written under that DEK keeps decrypting. A re-wrap is
 * not a re-encryption and must never behave like one.
 *
 * **A row that cannot be opened is left alone and counted.** Overwriting it with
 * anything — an empty blob, a fresh key — would destroy the only copy of the material
 * that still opens it. The failure is reported so an operator can act while the old
 * master key is still available.
 */
interface RewrapStore
{
    /**
     * Short, stable label for reports and audit payloads — conventionally the table
     * name.
     */
    public function label(): string;

    /**
     * How many rows are still sealed under a master key other than `$activeKeyId`.
     *
     * One indexed query (`idx(kms_key_id)`), and no material is opened — which is why
     * `KmsSeal` records the key *version* in the first place.
     */
    public function pending(string $activeKeyId): int;

    /**
     * Re-seal at most `$limit` rows under `$activeKeyId`.
     *
     * @return array{rewrapped: int, failed: int} rows moved forward, and rows that could
     *                                            not be opened and were therefore left
     *                                            exactly as they were
     */
    public function rewrap(string $activeKeyId, int $limit): array;
}
