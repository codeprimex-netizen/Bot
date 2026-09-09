<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning\Steps;

use App\Enums\KeyPurpose;
use App\Services\Security\FieldCipher;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;

/**
 * Step 5 — the tenant's per-tenant data-encryption key (Req 1.8 / A1; Req 32.5 / NFR3).
 *
 * `FieldCipher::provision()` is the whole implementation: it mints a DEK, seals it under
 * the master key, and stores the sealed form in `encryption_keys`. It is idempotent —
 * it returns the existing active key for a lineage that already has one — and it names
 * its tenant explicitly, which it must, because provisioning runs before any tenant
 * context exists.
 *
 * ## Why the key is provisioned eagerly at all
 *
 * The cipher creates a lineage on first use anyway, so nothing would *break* without
 * this step. What it buys is that the failure happens here. Sealing a DEK is the one
 * part of provisioning that depends on an external system (`wa.security.encryption.wrapper`
 * — the config wrapper today, a cloud KMS after task 4.2), and a key store that is down
 * fails **closed** with `KeyUnavailableException`. Discovering that during provisioning
 * aborts a signup that can be retried; discovering it on first use means a live tenant
 * whose every encrypted write fails. Req 1.8 names the DEK for this reason.
 *
 * ## Ordering and compensation
 *
 * Placed after the database steps and before the storage step: sealing is the first
 * thing in the pipeline that involves anything outside the database, so every cheap
 * failure (duplicate slug, missing plan) has already had its chance.
 *
 * The key *row* is an insert inside `provision()`'s transaction, so a rollback removes
 * it. What survives a rollback is the unwrapped DEK the cipher memoised in process, and
 * that is what `rollback()` clears: leaving it would keep a plaintext key for a tenant
 * that no longer exists in memory for the rest of the request or job — pointless, and
 * the opposite of the "cached in-process for one unit of work" lifetime the design
 * relies on. Wrapping itself creates no state in the key store (it is an encrypt call,
 * not a registration), so there is nothing remote to undo.
 */
final class ProvisionEncryptionKeyStep implements TenantProvisioningStep
{
    public function __construct(private readonly FieldCipher $cipher) {}

    public function name(): string
    {
        return 'encryption.dek';
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $key = $this->cipher->provision($context->tenant(), KeyPurpose::Field);

        // Never the key material, and not the wrapped blob either: what an operator needs
        // is which lineage and version this tenant started on, and under which master key.
        $context->record('encryption_key', [
            'purpose' => $key->purpose->value,
            'version' => $key->version,
            'algorithm' => $key->algorithm,
        ]);
    }

    public function rollback(TenantProvisioningContext $context): void
    {
        // Drops every unwrapped DEK this instance holds, including the one just minted for
        // a tenant row that has now been rolled back.
        $this->cipher->forgetKeys();
    }
}
