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
