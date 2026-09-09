<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuotaKind;
use Database\Factories\TenantUsageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period-bucketed quota counter: "tenant T used N of its limit L for quota
 * kind K during period P".
 *
 * `uniq(tenant_id, kind, period_key)` is what makes `QuotaGuard::consume()`
 * atomic and consume-once — it upserts this row under a cache lock rather than
 * read-modify-writing it.
 *
 * @property int $id
 * @property string $tenant_id
 * @property QuotaKind $kind
 * @property string $period_key
 * @property int $used
 * @property int $limit
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TenantUsage extends Model
{
    /** @use HasFactory<TenantUsageFactory> */
    use HasFactory;

    protected $table = 'tenant_usage';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'kind',
        'period_key',
        'used',
        'limit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => QuotaKind::class,
            'used' => 'integer',
            'limit' => 'integer',
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
     * Allowance left in this bucket, never negative.
     */
    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function isExhausted(): bool
    {
        return $this->used >= $this->limit;
    }

    /**
     * Narrow to the counter row for one quota kind in one period.
     *
     * @param  Builder<TenantUsage>  $query
     * @return Builder<TenantUsage>
     */
    public function scopeForBucket(Builder $query, QuotaKind $kind, string $periodKey): Builder
    {
        return $query->where('kind', $kind)->where('period_key', $periodKey);
    }
}
