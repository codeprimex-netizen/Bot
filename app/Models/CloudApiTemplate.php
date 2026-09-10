<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelTemplateCategory;
use App\Enums\ChannelTemplateStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CloudApiTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a tenant's approved-template registry for the official channel modes
 * (Req 8.12 / A8).
 *
 * ```php
 * // the question task 8.4 asks before allowing a free-form send outside the 24h window
 * $template = CloudApiTemplate::sendable($credential->id, 'order_update', 'en_US');
 * ```
 *
 * ## A mirror of the provider's state, not a source of truth
 *
 * The provider approves templates; this table remembers what it said, so the
 * `TemplateRequiredException` check (task 8.4) is a local read rather than a provider call
 * on every send. Two consequences are encoded here rather than left to callers:
 *
 * - **`status` may only be trusted as of `synced_at`.** `isStale()` says when a re-sync is
 *   due, and `ChannelTemplateStatus::isSyncable()` says which states the provider can still
 *   change on its own — so a sweep re-reads `PENDING`/`APPROVED`/`PAUSED` and leaves
 *   `REJECTED` alone.
 * - **Only `APPROVED` sends.** `isSendable()` delegates to the enum, and `PAUSED` is not
 *   sendable: the provider has stopped accepting it, so attempting the send would turn a
 *   local block into a remote failure mid-campaign.
 *
 * Nothing in this class writes: syncing, submission, and versioning belong to task 8.4.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $credential_id
 * @property string $name
 * @property string $language
 * @property ChannelTemplateCategory $category
 * @property string $body
 * @property array<array-key, mixed>|null $components
 * @property ChannelTemplateStatus $status
 * @property string|null $provider_template_id
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read ChannelCredential $credential
 */
class CloudApiTemplate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CloudApiTemplateFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ChannelTemplateStatus::Pending->value,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'credential_id',
        'name',
        'language',
        'category',
        'body',
        'components',
        'status',
        'provider_template_id',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ChannelTemplateCategory::class,
            'status' => ChannelTemplateStatus::class,
            'components' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a message may be sent with this template right now.
     */
    public function isSendable(): bool
    {
        return $this->status->isSendable();
    }

    /**
     * Whether the mirrored status is older than `$staleAfter` and the provider could have
     * changed it since.
     *
     * Both halves: a `REJECTED` template is not stale however long ago it was read, because
     * only the tenant can change it (by resubmitting, which creates a new submission).
     */
    public function isStale(Carbon $staleAfter): bool
    {
        if (! $this->status->isSyncable()) {
            return false;
        }

        return $this->synced_at === null || $this->synced_at->lessThanOrEqualTo($staleAfter);
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * The approved template for one account, name, and language — or null.
     *
     * The exact lookup the 24-hour-window gate performs, expressed once so every caller
     * asks it the same way: scoped to the tenant by `BelongsToTenant`, pinned to the
     * provider account by `credential_id` (a template approved on one account cannot be
     * sent through another), and filtered to `APPROVED` in SQL rather than in PHP.
     */
    public static function sendable(string $credentialId, string $name, string $language): ?self
    {
        return static::query()
            ->forCredential($credentialId)
            ->where('name', $name)
            ->where('language', $language)
            ->approved()
            ->first();
    }

    /**
     * @param  Builder<CloudApiTemplate>  $query
     * @return Builder<CloudApiTemplate>
     */
    public function scopeForCredential(Builder $query, string $credentialId): Builder
    {
        return $query->where('credential_id', $credentialId);
    }

    /**
     * @param  Builder<CloudApiTemplate>  $query
     * @return Builder<CloudApiTemplate>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ChannelTemplateStatus::Approved);
    }

    /**
     * @param  Builder<CloudApiTemplate>  $query
     * @return Builder<CloudApiTemplate>
     */
    public function scopeWithStatus(Builder $query, ChannelTemplateStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Templates whose status the provider can still change, stalest first — the sync
     * sweep's whole query, served by `idx(tenant_id, status)`.
     *
     * A row never synced sorts first, which is what makes a locally created template pick
     * itself up on the first sweep.
     *
     * @param  Builder<CloudApiTemplate>  $query
     * @return Builder<CloudApiTemplate>
     */
    public function scopeDueForSync(Builder $query, Carbon $staleAfter): Builder
    {
        return $query
            ->whereIn('status', array_values(array_filter(
                ChannelTemplateStatus::cases(),
                static fn (ChannelTemplateStatus $status): bool => $status->isSyncable(),
            )))
            ->where(function (Builder $stale) use ($staleAfter): void {
                $stale->whereNull('synced_at')->orWhere('synced_at', '<=', $staleAfter);
            })
            ->orderByRaw('synced_at is null desc')
            ->orderBy('synced_at')
            ->orderBy('name');
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The provider account this template is approved against.
     *
     * @return BelongsTo<ChannelCredential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(ChannelCredential::class, 'credential_id');
    }
}
