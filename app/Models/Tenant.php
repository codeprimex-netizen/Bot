<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $subdomain
 * @property TenantStatus $status
 * @property string|null $plan_id
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property string $timezone
 * @property string $locale
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
        ];
    }

    /**
     * Membership rows linking users to this tenant.
     *
     * @return HasMany<TenantUser, $this>
     */
    public function tenantUsers(): HasMany
    {
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
        return $this->belongsToMany(User::class, 'tenant_users')
            ->using(TenantUser::class)
            ->withPivot(['id', 'role', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Period-bucketed quota counters for this tenant.
     *
     * @return HasMany<TenantUsage, $this>
     */
    public function usage(): HasMany
    {
        return $this->hasMany(TenantUsage::class);
    }

    /**
     * Whether the tenant may perform billable/outbound work right now.
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
}
