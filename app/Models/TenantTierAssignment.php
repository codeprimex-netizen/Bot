<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantTier;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Tenancy\TierResolver;
use Database\Factories\TenantTierAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One tenant's place on the isolation ladder: "tenant T runs on tier X, with lane
 * weight W, pinned to shard S in region R" (Req 1.6 / A1).
 *
 * The table is `tenant_tiers`; the class is named for what a row *is* — an
 * assignment — so that `App\Enums\TenantTier` (the tier itself) and this model can
 * be used side by side without either being aliased.
 *
 * **A row is an override, not a requirement.** A tenant with no row runs on the
 * tier named by `wa.tenancy.tiers.default` with that tier's configured lane weight,
 * which is why the vast majority of tenants never get one. Nothing reads this model
 * directly except `TierResolver` — everything else asks the resolver, so a tier
 * change never becomes a code change.
 *
 * Tenant-owned (`BelongsToTenant`): reads are scoped and `tenant_id` is stamped on
 * create like every other tenant table. The resolver itself runs on schedulers and
 * platform paths that bind no tenant, so it reads through the explicit
 * `forTenant()` hatch rather than the ambient context.
 *
 * @property int $id
 * @property string $tenant_id
 * @property TenantTier $tier
 * @property int|null $lane_weight
 * @property string|null $data_region
 * @property string|null $shard_key
 * @property array<string, mixed>|null $dedicated_conn
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class TenantTierAssignment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantTierAssignmentFactory> */
    use HasFactory;

    protected $table = 'tenant_tiers';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'tier',
        'lane_weight',
        'data_region',
        'shard_key',
        'dedicated_conn',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => TenantTier::class,
            'lane_weight' => 'integer',
            'dedicated_conn' => 'array',
        ];
    }

    /**
     * Drop the resolver's cached view of this tenant the moment the row changes.
     *
     * This is the explicit invalidation half of the resolver's caching: without it a
     * tier flip would sit behind the cache TTL, and a `DEDICATED_DB` cutover would
     * keep routing writes to the old connection for minutes after the row said
     * otherwise. Registered on the model rather than at call sites so *every* writer
     * (admin panel, provisioning, tinker, seeder) invalidates.
     */
    protected static function booted(): void
    {
        $invalidate = static function (self $assignment): void {
            app(TierResolver::class)->forget($assignment->tenant_id);
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * The database connection named by `dedicated_conn`, if any.
     *
     * Only the name is honoured — credentials come from `config/database.php`, so a
     * row can move a tenant between *configured* connections but can never introduce
     * a new database host.
     */
    public function connectionName(): ?string
    {
        $name = $this->dedicated_conn['connection'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
