<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\SigningSecret;
use Throwable;

/**
 * Re-seals HMAC signing secrets under a new master key
 * (`signing_secrets.sealed_secret`).
 *
 * The easy thing to forget in a master-key rotation, and the expensive one to forget:
 * webhook secrets are shared with peers, so a secret that can no longer be opened cannot
 * be re-issued unilaterally — every peer would have to be reconfigured. Re-wrapping
 * changes only the seal, so the secret itself, its version, and its overlap deadline are
 * untouched and every peer keeps working.
 *
 * Rows past their overlap window are re-wrapped too, cheaply and on purpose: they are
 * already refused by `SigningSecretStore::verify()`, but skipping them would leave rows
 * sealed under a master key an operator is about to remove, and "some rows in this table
 * are unopenable" is a worse state to reason about than a few redundant re-seals. The
 * purge sweep removes them on its own schedule.
 */
final readonly class SigningSecretRewrapStore implements RewrapStore
{
    public function __construct(private KeyWrapper $wrapper) {}

    public function label(): string
    {
        return 'signing_secrets';
    }

    public function pending(string $activeKeyId): int
    {
        return SigningSecret::query()
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

        $secrets = SigningSecret::query()
            ->where('kms_key_id', '!=', $activeKeyId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($secrets as $secret) {
            $context = DatabaseSigningSecretStore::sealContext($secret->scope, $secret->version);

            try {
                $sealed = $this->wrapper->wrap(
                    $this->wrapper->unwrap($secret->kms_key_id, $secret->sealed_secret, $context),
                    $context,
                );
            } catch (Throwable) {
                // Left exactly as it is — overwriting would destroy the only copy of a
                // secret a peer is still signing with.
                $failed++;

                continue;
            }

            // Only the seal changes: `version` and `accepted_until` are untouched, so the
            // signer stays the signer and an open overlap window keeps its deadline.
            $secret->kms_key_id = $sealed->keyId;
            $secret->algorithm = $sealed->algorithm;
            $secret->sealed_secret = $sealed->blob;
            $secret->save();

            $rewrapped++;
        }

        return ['rewrapped' => $rewrapped, 'failed' => $failed];
    }
}
