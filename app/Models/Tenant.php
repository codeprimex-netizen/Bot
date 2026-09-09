<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Models\Scopes\TenantScope;
use App\Observers\TenantPlanObserver;
use App\Services\Tenancy\TenantOwnershipGuard;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An isolated customer account: its own numbers, contacts, campaigns, chatbots,
 * plan, quota, and wallet. Every domain row elsewhere carries this model's id in
 * its `tenant_id` column.
 *
 * The tenant itself is deliberately *not* `BelongsToTenant` — it is the root of
 * the ownership tree, not a member of it.
 *
 * Being the root is exactly why every child relation below starts by asserting that
 * the caller is allowed to hold *this* tenant (Req 1.3 / A1). A `Tenant` instance is
 * reachable from unscoped places by design — resolution, provisioning, platform
 * screens — and two of the relations drop `TenantScope` deliberately, so "which
 * parent am I hanging off?" is the only constraint left on those reads. See
 * `TenantOwnershipGuard::assertRelationAccessible()`.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $subdomain
 * @property TenantStatus $status
 * @property string|null $plan_id
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string $timezone
 * @property string $locale
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy([TenantPlanObserver::class])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'subdomain',
        'status',
        'plan_id',
        'trial_ends_at',
        'suspended_at',
        'cancelled_at',
        'timezone',
        'locale',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The plan this tenant is on — null for a tenant that has none yet, or whose
     * plan was retired under it (`tenants.plan_id` is `nullOnDelete`).
     *
     * Read it through `PlanRepository::forTenant()` on hot paths: `PlanGate` and
     * `QuotaGuard` consult the plan on every message, and this relation is a query
     * each time.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        $this->assertRelationVisible('plan');

        return $this->belongsTo(Plan::class);
    }

    /**
     * Membership rows linking users to this tenant.
     *
     * @return HasMany<TenantUser, $this>
     */
    public function tenantUsers(): HasMany
    {
        $this->assertRelationVisible('tenantUsers');

        return $this->hasMany(TenantUser::class);
    }

    /**
     * Users who are members of this tenant, with their per-tenant role.
     *
     * The `TenantUser` / `'pivot'` template arguments are required as of
     * Laravel 12: `BelongsToMany` gained `TPivotModel` and `TAccessor` template
     * parameters, and `TPivotModel` is invariant — so a relation using a custom
     * pivot must name it here rather than defaulting to the base `Pivot`.
     *
     * @return BelongsToMany<User, $this, TenantUser, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        $this->assertRelationVisible('users');

        return $this->belongsToMany(User::class, 'tenant_users')
            ->using(TenantUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Period-bucketed quota counters for this tenant.
     *
     * The relation is already constrained to `tenant_id = $this->id`, which is at
     * least as strict as `TenantScope` — so the scope is removed here rather than
     * ANDed on top of it. Without that, reading one tenant's counters from a
     * platform-admin screen, a console command, or a scheduler (none of which bind a
     * tenant) would fail closed even though the query names its tenant explicitly.
     * Which `Tenant` instance a caller is allowed to hold is guarded separately, by
     * `assertRelationVisible()` below — so dropping the scope here costs no isolation.
     *
     * @return HasMany<TenantUsage, $this>
     */
    public function usage(): HasMany
    {
        $this->assertRelationVisible('usage');

        $relation = $this->hasMany(TenantUsage::class);
        $relation->getQuery()->withoutGlobalScope(TenantScope::class);

        return $relation;
    }

    /**
     * API keys through which machine callers act as this tenant.
     *
     * Scope removed for the same reason as `usage()`: the relation already names its
     * tenant.
     *
     * @return HasMany<TenantApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        $this->assertRelationVisible('apiTokens');

        $relation = $this->hasMany(TenantApiToken::class);
        $relation->getQuery()->withoutGlobalScope(TenantScope::class);

        return $relation;
    }

    /**
     * Whether the tenant may perform billable/outbound work right now.
     *
     * The convenient read of the same rule `App\Services\Tenancy\TenantLifecycle`
     * enforces. Ask the service — `canSendOutbound()` / `assertCanSendOutbound()` /
     * `canMutate()` — anywhere the answer *gates* something: it accepts a bare tenant
     * id, fails closed on an unknown one, and names the refusal in a 403. This method
     * is for display and for callers that already hold the model.
     */
    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    /**
     * Per-tenant storage prefix for auth state, exports, and media.
     */
    public function storagePrefix(): string
    {
        return trim((string) config('wa.tenancy.storage_prefix', 'tenants'), '/').'/'.$this->id;
    }

    /**
     * Refuse to reach this tenant's children from code acting as a *different*
     * tenant (Req 1.3 / A1) — a `CrossTenantAccessException`, 403.
     *
     * A no-op in platform mode (Req 1.5) and when no tenant is bound, which is what
     * keeps provisioning, tenant resolution, console commands, schedulers, and the
     * admin panel reading any tenant they legitimately hold.
     */
    private function assertRelationVisible(string $relation): void
    {
        app(TenantOwnershipGuard::class)->assertRelationAccessible($this, $relation);
    }
}
