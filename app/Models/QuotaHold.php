<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuotaHoldStatus;
use App\Enums\QuotaKind;
use App\Enums\QuotaReason;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One parked unit of work: "tenant T's campaign C ran out of `MESSAGES_MONTHLY` in period
 * 2025-06, and it is owed a resume at 2025-07-01T00:00 in T's timezone"
 * (Req 3.4 / A3; Req 20.3 / C3; Req 31.1 / NFR2).
 *
 * The model owns the *shape* of a hold — its casts, its claim predicate, its retention
 * horizon — and nothing about parking or resuming. Those are
 * `App\Services\Tenancy\QuotaParkingLot`, which is the only thing that should write here.
 *
 * Tenant-owned: `BelongsToTenant` scopes every read to the acting tenant, so one tenant
 * can never see (or resume) another's parked work. The period-reset sweep spans tenants
 * through the sanctioned `withoutTenantScope()` + `TenantContext::runFor()` pair — see the
 * migration docblock.
 *
 * @property string $id
 * @property string $tenant_id
 * @property QuotaKind $quota_kind
 * @property string $period_key
 * @property QuotaReason $reason
 * @property int $units
 * @property QuotaHoldStatus $status
 * @property string|null $holdable_type
 * @property string|null $holdable_id
 * @property string|null $resumer
 * @property array<string, mixed>|null $payload
 * @property string $dedup_key
 * @property Carbon $resume_at
 * @property Carbon $paused_at
 * @property Carbon|null $claimed_at
 * @property string|null $claimed_by
 * @property Carbon|null $resumed_at
 * @property Carbon|null $notified_at
 * @property int $resume_attempts
 * @property int $pause_count
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Model|null $holdable
 */
class QuotaHold extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /**
     * How long a `RESUMING` claim is honoured before its holder is presumed dead.
     *
     * A worker `SIGKILL`ed between claiming a hold and handing the work back would
     * otherwise park that work for ever — the one outcome this whole table exists to
     * prevent. The window is generous relative to a hand-back (which is a status flip and
     * a queue dispatch), because retaking a live claim risks resuming the same campaign
     * twice, and that is the worse failure.
     */
    public const int CLAIM_LEASE_SECONDS = 300;

    /**
     * Cap on stored error text — enough to diagnose a failing resumer, not enough to make
     * this table a log sink.
     */
    public const int MAX_ERROR_LENGTH = 2000;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'quota_kind',
        'period_key',
        'reason',
        'units',
        'status',
        'holdable_type',
        'holdable_id',
        'resumer',
        'payload',
        'dedup_key',
        'resume_at',
        'paused_at',
        'claimed_at',
        'claimed_by',
        'resumed_at',
        'notified_at',
        'resume_attempts',
        'pause_count',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota_kind' => QuotaKind::class,
            'reason' => QuotaReason::class,
            'status' => QuotaHoldStatus::class,
            'payload' => 'array',
            'units' => 'integer',
            'resume_attempts' => 'integer',
            'pause_count' => 'integer',
            'resume_at' => 'datetime',
            'paused_at' => 'datetime',
            'claimed_at' => 'datetime',
            'resumed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * The parked work itself — a campaign, an enrollment, an import batch — or null when
     * the unit of work is not a row.
     *
     * @return MorphTo<Model, $this>
     */
    public function holdable(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Holds the sweep may claim: parked and due, or claimed so long ago that their holder
     * is presumed dead.
     *
     * Ordered by `resume_at` so the work that has been waiting longest goes first — a
     * hold that keeps losing the race to fresher ones would be a slow drop.
     *
     * @param  Builder<QuotaHold>  $query
     * @return Builder<QuotaHold>
     */
    public function scopeClaimable(Builder $query, ?int $leaseSeconds = null): Builder
    {
        $lease = now()->subSeconds(max(1, $leaseSeconds ?? self::CLAIM_LEASE_SECONDS));

        // The whole disjunction is grouped, so composing this scope with any other
        // constraint — the tenant global scope above all — cannot let one branch of the
        // `OR` escape it.
        return $query
            ->where(function (Builder $claimable) use ($lease): void {
                $claimable
                    ->where(function (Builder $due): void {
                        $due->where('status', QuotaHoldStatus::QuotaPaused)->where('resume_at', '<=', now());
                    })
                    ->orWhere(function (Builder $abandoned) use ($lease): void {
                        $abandoned->where('status', QuotaHoldStatus::Resuming)->where('claimed_at', '<=', $lease);
                    });
            })
            ->orderBy('resume_at');
    }

    /**
     * Holds that still owe their owner a resume.
     *
     * @param  Builder<QuotaHold>  $query
     * @return Builder<QuotaHold>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', QuotaHoldStatus::openValues());
    }

    /**
     * Parked and unclaimed — the only state a claim may transition from.
     *
     * @param  Builder<QuotaHold>  $query
     * @return Builder<QuotaHold>
     */
    public function scopePaused(Builder $query): Builder
    {
        return $query->where('status', QuotaHoldStatus::QuotaPaused);
    }

    /**
     * Narrow to one quota counter — the set a returning allowance affects.
     *
     * @param  Builder<QuotaHold>  $query
     * @return Builder<QuotaHold>
     */
    public function scopeForKind(Builder $query, QuotaKind $kind): Builder
    {
        return $query->where('quota_kind', $kind);
    }

    /**
     * Finished holds past their retention horizon — what the sweep prunes.
     *
     * Only terminal rows are ever selected: an open hold is work somebody is still owed,
     * and no retention policy may delete that.
     *
     * @param  Builder<QuotaHold>  $query
     * @return Builder<QuotaHold>
     */
    public function scopePrunable(Builder $query, int $retentionDays): Builder
    {
        return $query
            ->whereIn('status', [QuotaHoldStatus::Resumed, QuotaHoldStatus::Cancelled])
            ->where('updated_at', '<=', now()->subDays(max(1, $retentionDays)));
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isPaused(): bool
    {
        return $this->status->isPaused();
    }

    public function isResumed(): bool
    {
        return $this->status === QuotaHoldStatus::Resumed;
    }

    /**
     * Whether it is worth re-asking `QuotaGuard` about this hold yet.
     */
    public function resumeIsDue(): bool
    {
        return ! $this->resume_at->isFuture();
    }

    /**
     * Whether a `RESUMING` claim has outlived its lease.
     */
    public function claimHasExpired(?int $leaseSeconds = null): bool
    {
        if ($this->claimed_at === null) {
            return true;
        }

        return $this->claimed_at->addSeconds(max(1, $leaseSeconds ?? self::CLAIM_LEASE_SECONDS))->isPast();
    }

    /**
     * Truncate error text to what the column is for.
     */
    public static function truncateError(string $error): string
    {
        return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }
}
