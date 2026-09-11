<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning\Steps;

use App\Enums\StorageArea;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use App\Services\Tenancy\TenantStorage;

/**
 * Step 6 — the tenant's storage prefix (Req 1.8, 1.4 / A1).
 *
 * The prefix is `{wa.tenancy.storage_prefix}/{tenantId}`, and `TenantStorage` derives it
 * from the tenant on every call, so in one sense it needs no creating: the first export
 * or media upload creates its directories implicitly. What is created here is the
 * **auth-state directory**, and it is the one that cannot be created implicitly — the
 * Node/Baileys bridge is handed an absolute path and opens it directly, so it has to
 * exist, with the disk's `0700` permissions, before a session is ever paired.
 *
 * ## Why this step is last
 *
 * It is the only step whose effect the database cannot undo. Everything before it is SQL
 * on the default connection plus one in-process cache, so putting the filesystem write
 * at the end means the overwhelming majority of provisioning failures — a taken slug, an
 * unseeded plan, an unreachable key store — happen with nothing on disk to clean up.
 *
 * ## The compensation, and why it is safe
 *
 * `rollback()` deletes the directory. Deleting a directory is the kind of compensation
 * that has to be argued rather than assumed, and the argument is the tenant id: it is a
 * ULID minted moments earlier in the same call, so this prefix cannot be anything but
 * the one this run created, and it cannot contain anything but what this run put there
 * (nothing). `TenantStorage::deleteAuthState()` resolves the path from the tenant and
 * containment-checks it, so the deletion cannot escape the prefix even if the tenant
 * were somehow wrong.
 */
final class EnsureStoragePrefixStep implements TenantProvisioningStep
{
    public function __construct(private readonly TenantStorage $storage) {}

    public function name(): string
    {
        return 'storage.prefix';
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $tenant = $context->tenant();

        $this->storage->ensureAuthStateDirectory($tenant);

        // The prefix itself is deliberately not recorded: it is `{prefix}/{tenant_id}` and
        // the audit entry already names the tenant, so storing it again would only add a
        // string the payload redactor might mask digits out of.
        $context->record('storage', [
            'area' => StorageArea::AuthState->value,
            'disk' => $this->storage->diskName(StorageArea::AuthState),
            'created' => true,
        ]);
    }

    public function rollback(TenantProvisioningContext $context): void
    {
        if ($context->hasTenant()) {
            $this->storage->deleteAuthState($context->tenant());
        }
    }
}
