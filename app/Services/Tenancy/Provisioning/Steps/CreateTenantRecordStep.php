<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning\Steps;

use App\Enums\TenantStatus;
use App\Exceptions\Tenancy\TenantAlreadyExistsException;
use App\Models\Tenant;
use App\Services\Tenancy\Provisioning\TenantProvisioningContext;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Step 1 — the `tenants` row itself (Req 1.8 / A1).
 *
 * Everything else in the pipeline hangs off this row, so it is first and it is the only
 * step that may create it. Three decisions live here.
 *
 * ## The status is `TRIAL`, always
 *
 * Not a spec value. The design's lifecycle diagram starts `[*] --> TRIAL: provision`,
 * and `TenantStatus` encodes `TRIAL` as the only state with an edge to every other one
 * — so a tenant that started anywhere else would be outside the state machine before
 * it had done anything. A tenant that should be `ACTIVE` immediately (a migrated
 * account, an internal workspace) gets there through `activate()`, which audits the
 * conversion; provisioning straight into `ACTIVE` would lose that record.
 *
 * ## Uniqueness is checked *and* enforced
 *
 * `tenants.slug` and `tenants.subdomain` are both unique indexes, and the pre-check
 * below does not replace them — it exists so the common case (a customer picking a
 * taken name) is a clean 409 naming the field, rather than a driver-specific
 * constraint error. The unique index still catches the race the pre-check cannot, and
 * that too is translated to the same 409.
 *
 * The third check has no index behind it at all: a label is also taken when it is some
 * existing tenant's `slug` and that tenant has no `subdomain`, because
 * `SubdomainTenantResolver` resolves `{slug}.{apex}` for exactly those tenants. Both
 * of the new tenant's labels are held to that, so provisioning can never quietly take
 * over the host another tenant is reachable at.
 *
 * ## `forceFill`, not `fill`
 *
 * `status` is not in `Tenant::$fillable` — the status is the lifecycle's to change, not
 * a mass-assignable attribute — and this step is the one legitimate writer of the
 * initial value.
 */
final class CreateTenantRecordStep implements TenantProvisioningStep
{
    public function name(): string
    {
        return 'tenant.record';
    }

    public function apply(TenantProvisioningContext $context): void
    {
        $spec = $context->spec;

        $this->assertLabelsAvailable($spec->slug, $spec->subdomain);

        $tenant = new Tenant;
        $tenant->forceFill([
            ...$spec->tenantAttributes(),
            'status' => TenantStatus::Trial,
        ]);

        try {
            $tenant->save();
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent provisioning of the same label. Reported as
            // the same conflict the pre-check reports, so a caller handles one outcome.
            throw TenantAlreadyExistsException::slug($spec->slug);
        }

        $context->setTenant($tenant);
        $context->record('status', TenantStatus::Trial->value);
        $context->record('slug', $tenant->slug);
        $context->record('subdomain', $tenant->subdomain);
        $context->record('timezone', $tenant->timezone);
        $context->record('locale', $tenant->locale);
        $context->record('trial_ends_at', $tenant->trial_ends_at?->toIso8601String());
    }

    /**
     * Nothing to compensate: the row is written inside `provision()`'s transaction, and
     * the transaction is its rollback.
     */
    public function rollback(TenantProvisioningContext $context): void {}

    /**
     * Refuse a slug or subdomain that is taken — by an index, or by an existing
     * tenant's implicit host.
     */
    private function assertLabelsAvailable(string $slug, ?string $subdomain): void
    {
        if (Tenant::query()->where('slug', '=', $slug)->exists()) {
            throw TenantAlreadyExistsException::slug($slug);
        }

        if ($subdomain !== null && Tenant::query()->where('subdomain', '=', $subdomain)->exists()) {
            throw TenantAlreadyExistsException::subdomain($subdomain);
        }

        // Both labels are checked against the fallback lookup, because either can end up
        // being the host: the subdomain when it is set, the slug when it is not.
        foreach (['slug' => $slug, 'subdomain' => $subdomain] as $attribute => $label) {
            if ($label !== null && $this->hostTaken($label)) {
                throw TenantAlreadyExistsException::hostTaken($attribute, $label);
            }
        }
    }

    /**
     * Whether an existing tenant already answers on `{$label}.{apex}` through the
     * slug fallback — mirrors `SubdomainTenantResolver::tenantForLabel()`.
     */
    private function hostTaken(string $label): bool
    {
        return Tenant::query()
            ->where('slug', '=', $label)
            ->whereNull('subdomain')
            ->exists();
    }
}
