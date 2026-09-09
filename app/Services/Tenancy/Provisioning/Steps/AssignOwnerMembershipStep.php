<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning\Steps;

use App\Exceptions\Tenancy\InvalidProvisioningSpecException;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use Illuminate\Support\Carbon;

/**
 * Step 3 — the owner's membership row, when the spec names an owner (Req 1.8 / A1).
 *
 * ## Why this is part of provisioning, and why it is optional
 *
 * Req 1.8 does not list a membership among the six things it names, but a tenant with
 * no `tenant_users` row is unreachable by any human: `SessionTenantResolver` discovers
 * which tenants a signed-in user may act in by reading that table, so without a row
 * nobody can ever open the panel. Creating it later, outside the transaction, would
 * mean a window in which a fully provisioned tenant exists that its own owner cannot
 * see — so when there *is* an owner, the membership belongs in the same atomic unit.
 *
 * It is optional because the platform legitimately provisions tenants both ways, and
 * the design says so:
 *
 * - **self-service registration** (design.md, User Panel feature 1) verifies the OTP
 *   *and then* provisions — the `User` already exists, so `owner` is passed and the
 *   membership is created here;
 * - **an admin creating a tenant** (Admin panel feature 2) has no user yet: the account
 *   is invited afterwards, and that invitation writes its own `tenant_users` row with
 *   `invited_at` set and `joined_at` null.
 *
 * Inventing a placeholder user for the second case would be worse than leaving the
 * table empty — it would be an account with no owner that *looks* owned.
 *
 * ## `joined_at`, not `invited_at`
 *
 * The owner supplied at provisioning time is a user who has already authenticated
 * (registration verifies the OTP first), so the membership is accepted, not pending.
 * `TenantUser::isPending()` reads `joined_at === null`, and an owner who shows as a
 * pending invitation would be re-invited by any dunning or onboarding sweep.
 */
final class AssignOwnerMembershipStep implements TenantProvisioningStep
{
    public function name(): string
    {
        return 'owner.membership';
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $userId = $context->spec->ownerUserId;

        if ($userId === null) {
            $context->record('owner', null);

            return;
        }

        if (! User::query()->whereKey($userId)->exists()) {
            // Checked rather than left to the foreign key: the FK error is opaque, and the
            // spec value came from a call site that can fix it.
            throw InvalidProvisioningSpecException::unknownOwner((string) $userId);
        }

        $now = Carbon::now();
        $role = $context->spec->ownerRole;

        $membership = new TenantUser;
        // `tenant_id` is named explicitly: provisioning runs before any tenant context
        // exists, so nothing can be inherited from `TenantContext`.
        $membership->forceFill([
            'tenant_id' => $context->tenant()->id,
            'user_id' => $userId,
            'role' => $role,
            'invited_at' => $now,
            'joined_at' => $now,
        ]);
        $membership->save();

        $context->record('owner', ['user_id' => $userId, 'role' => $role->value]);
    }

    /**
     * Nothing to compensate: the membership row is inside `provision()`'s transaction.
     */
    public function rollback(TenantProvisioningContext $context): void {}
}
