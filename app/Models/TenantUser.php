<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantRole;
use Database\Factories\TenantUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membership of one user in one tenant, with the role that user holds there.
 *
 * A user may hold rows in several tenants (different roles per tenant); the
 * `uniq(tenant_id, user_id)` constraint keeps that at most one row per pair.
 * Platform super-admins have no row here — they act via `actingAsPlatform()`.
 *
 * Modelled as a Pivot so it can back `Tenant::users()` while still being a
 * first-class model with its own id, relations, and timestamps.
 *
 * ## Why this table carries `tenant_id` but *not* `BelongsToTenant`
 *
 * It is the table that **answers** "which tenant is acting?", so it cannot also be
 * filtered by the answer. `SessionTenantResolver` reads it by `user_id`, before any
 * tenant is bound, to discover which tenants a signed-in user may act in; scoping it
 * would make resolution circular and force a `withoutTenantScope()` bypass onto the
 * very path that establishes the scope — a bypass on the security boundary is worse
 * than none.
 *
 * Two further reasons: `BelongsToMany::attach()`/`detach()` operate on a base query
 * builder, so a global scope on a pivot silently does not apply to the main write
 * path (false assurance), and a *user's* view of their memberships is cross-tenant by
 * nature ("switch tenant" lists all of them).
 *
 * Isolation of membership rows therefore comes from the access path, not a scope:
 * read them through `Tenant::tenantUsers()` / `Tenant::users()` (already constrained
 * to one tenant) or with an explicit `where('user_id', ...)` for the acting user.
 * `TenantOwnedModelsGuardTest` records this exemption explicitly, so it is a reviewed
 * decision rather than an omission.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $user_id
 * @property TenantRole $role
 * @property \Illuminate\Support\Carbon|null $invited_at
 * @property \Illuminate\Support\Carbon|null $joined_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TenantUser extends Pivot
{
    /** @use HasFactory<TenantUserFactory> */
    use HasFactory;

    protected $table = 'tenant_users';

    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'invited_at',
        'joined_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * An invitation that has not been accepted yet.
     */
    public function isPending(): bool
    {
        return $this->joined_at === null;
    }
}
