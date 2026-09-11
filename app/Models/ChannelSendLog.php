<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\ChannelSendResult;
use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ChannelSendLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to send through a channel driver — including the attempts that were refused
 * before any driver was called (Req 8.3, 8.10, 8.11 / A8).
 *
 * ```php
 * // the row task 6.3/8.1 writes when a mode cannot do what was asked
 * ChannelSendLog::create([
 *     'session_id' => $session->id,
 *     'mode' => $session->channel_mode,
 *     'capability' => ChannelCapability::Groups,
 *     'result' => ChannelSendResult::Blocked,
 *     'block_reason' => 'MODE_CAPABILITY',
 * ]);
 * ```
 *
 * ## Why a refusal is worth a row
 *
 * Req 8.3 requires an unsupported operation to be rejected *before* any driver call, with no
 * side effect — so there is no message row, no provider id, and no failed request to inspect
 * afterwards. This log is the one side effect a block is allowed to have, which is what makes
 * "your group send was refused because this number is on Cloud API" answerable at all, and
 * what gives Properties 21 and 26 something to assert on beyond an absence.
 *
 * ## Append-only, and where its enforcement stops
 *
 * `AppendOnly` refuses instance updates and deletes, and `AppendOnlyBuilder` refuses the mass
 * paths that fire no model events. Unlike `audit_logs` and `abuse_events`, this table gets
 * **no `BEFORE UPDATE`/`BEFORE DELETE` triggers**, and that is a deliberate difference rather
 * than an omission: those two tables carry evidence that must outlive the tenant it describes,
 * so they hold no cascading foreign key, and triggers are safe. This table is operational
 * history that *should* disappear with its tenant (offboarding, Req 34.x), so it keeps its
 * cascades — and SQLite fires triggers for cascaded deletes where MySQL does not, so
 * installing them would make the guard behave differently on the test engine than in
 * production. Model-level append-only plus a cascading FK is the honest combination here;
 * pretending to a stronger guarantee than the schema can keep would be worse.
 *
 * `created_at` is stamped in the `creating` hook because `AppendOnly` turns Eloquent's
 * timestamp handling off (a row is never dirty-saved, so there is no `updated_at` to
 * maintain). The column also carries a database default, so a raw insert by a later
 * high-throughput writer still gets a timestamp.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $session_id
 * @property ChannelMode $mode
 * @property BspProvider|null $provider
 * @property ChannelCapability $capability
 * @property string|null $idempotency_key
 * @property ChannelSendResult $result
 * @property string|null $block_reason
 * @property string|null $provider_message_id
 * @property ChannelMode|null $failover_from
 * @property Carbon|null $created_at
 * @property-read Tenant $tenant
 * @property-read Session $session
 */
class ChannelSendLog extends Model
{
    use AppendOnly;
    use BelongsToTenant;

    /** @use HasFactory<ChannelSendLogFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var string
     */
    protected $table = 'channel_send_log';

    /**
     * Only `created_at` exists; `AppendOnly::usesTimestamps()` keeps Eloquent from looking
     * for `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'session_id',
        'mode',
        'provider',
        'capability',
        'idempotency_key',
        'result',
        'block_reason',
        'provider_message_id',
        'failover_from',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ChannelMode::class,
            'provider' => BspProvider::class,
            'capability' => ChannelCapability::class,
            'result' => ChannelSendResult::class,
            'failover_from' => ChannelMode::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $log): void {
            if ($log->created_at === null) {
                $log->setAttribute('created_at', Carbon::now());
            }
        });
    }

    /**
     * The append-only builder, so `ChannelSendLog::query()->update(...)` and `->delete()`
     * fail by class rather than by luck.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return AppendOnlyBuilder<static>
     */
    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        /** @var AppendOnlyBuilder<static> $builder */
        $builder = new AppendOnlyBuilder($query);

        return $builder;
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the provider was contacted at all.
     */
    public function reachedProvider(): bool
    {
        return $this->result->reachedProvider();
    }

    /**
     * Whether this row is a capability refusal — the one an unsupported-mode explanation
     * reads.
     */
    public function isCapabilityBlock(): bool
    {
        return $this->result === ChannelSendResult::Blocked
            && ! $this->capability->supportedOn($this->mode);
    }

    /**
     * Whether this attempt was reached by failing over from another mode (Req 8.10).
     */
    public function isFailoverAttempt(): bool
    {
        return $this->failover_from !== null && $this->failover_from !== $this->mode;
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * One session's attempts, newest first — served by `idx(tenant_id, session_id, created_at)`.
     *
     * @param  Builder<ChannelSendLog>  $query
     * @return Builder<ChannelSendLog>
     */
    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId)->orderByDesc('created_at');
    }

    /**
     * @param  Builder<ChannelSendLog>  $query
     * @return Builder<ChannelSendLog>
     */
    public function scopeWithResult(Builder $query, ChannelSendResult $result): Builder
    {
        return $query->where('result', $result);
    }

    /**
     * Attempts on one mode.
     *
     * @param  Builder<ChannelSendLog>  $query
     * @return Builder<ChannelSendLog>
     */
    public function scopeOnMode(Builder $query, ChannelMode $mode): Builder
    {
        return $query->where('mode', $mode);
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
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
