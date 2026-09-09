<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\EncryptionKey;
use Throwable;

/**
 * Re-seals per-tenant DEKs under a new master key (`encryption_keys.wrapped_dek`).
 *
 * This is the half of master-key rotation that actually matters, and the reason it is
 * cheap: a DEK is re-*wrapped*, never re-generated, so **no field ciphertext is touched
 * at all**. Every value in the database keeps naming the same key version, and that
 * version keeps opening it — the only thing that changed is which master key seals the
 * DEK on its way to and from the row.
 *
 * ```
 *  before:  wrapped_dek = Seal(master_v1, DEK_t,3)      ciphertext names v3 ─┐
 *  after:   wrapped_dek = Seal(master_v2, DEK_t,3)      ciphertext names v3 ─┘  unchanged
 * ```
 *
 * That distinction is the whole safety argument for scheduling this: a re-wrap cannot
 * orphan data, whereas a *re-key* would have to rewrite every encrypted column in the
 * same breath or lose it.
 *
 * The queries name no tenant, because rotation is platform maintenance that must cover
 * every tenant: `withoutTenantScope()` is the sanctioned, greppable bypass for exactly
 * this (task 0.3), and the sweep runs in the console with no tenant bound, so the
 * ownership guard has nothing to refuse.
 */
final readonly class EncryptionKeyRewrapStore implements RewrapStore
{
    public function __construct(private KeyWrapper $wrapper) {}

    public function label(): string
    {
        return 'encryption_keys';
    }

    public function pending(string $activeKeyId): int
    {
        return EncryptionKey::withoutTenantScope()
            ->where('kms_key_id', '!=', $activeKeyId)
            ->count();
    }

    /**
     * @return array{rewrapped: int, failed: int}
     */
    public function rewrap(string $activeKeyId, int $limit): array
    {
        $rewrapped = 0;
        $failed = 0;

        $keys = EncryptionKey::withoutTenantScope()
            ->where('kms_key_id', '!=', $activeKeyId)
            // Oldest lineages first, so a limited sweep makes deterministic progress.
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($keys as $key) {
            // The identical AAD the DEK was sealed with — `EnvelopeFieldCipher` exposes
            // it publicly precisely so this sweep cannot drift from the format.
            $context = EnvelopeFieldCipher::wrapContext($key->tenant_id, $key->purpose, $key->version);

            try {
                $sealed = $this->wrapper->wrap(
                    $this->wrapper->unwrap($key->kms_key_id, $key->wrapped_dek, $context),
                    $context,
                );
            } catch (Throwable) {
                // The old master key is gone, or the row was tampered with. Leave it
                // exactly as it is: overwriting would destroy the only blob that still
                // opens this tenant's data. Counted, so the operator sees it while the
                // old key can still be restored.
                $failed++;

                continue;
            }

            // Only the seal changes. `version` and `status` are deliberately untouched:
            // rotating the master key must not move a lineage's rotation clock, and must
            // not demote the active version.
            $key->kms_key_id = $sealed->keyId;
            $key->algorithm = $sealed->algorithm;
            $key->wrapped_dek = $sealed->blob;
            $key->save();

            $rewrapped++;
        }

        return ['rewrapped' => $rewrapped, 'failed' => $failed];
    }
}
