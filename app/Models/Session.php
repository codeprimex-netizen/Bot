<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One WhatsApp connection belonging to one tenant — the reused engine `sessions_wa` row
 * (Req 2.1–2.6 / A2).
 *
 * ```php
 * // Every read is already this tenant's, so there is nothing to remember at the call site.
 * $healthy = Session::query()->sendable()->orderByDesc('weight')->get();
 * ```
 *
 * ## Tenant-owned, and that is the Bridge's whole isolation guarantee
 *
 * `BelongsToTenant` scopes every read to the acting tenant and stamps `tenant_id` on every
 * create, so the mapping design.md relies on — *"every `sessionId` maps to exactly one
 * tenant"* — is enforced by the same machinery as the rest of the platform rather than by a
 * convention the bridge layer has to remember.
 *
 * That mapping is what `App\Services\Bridge\TenantScopedBridgeClient` turns into a structural
 * check: it resolves every session id a caller supplies through `Session::query()->find()`
 * before the sidecar is asked anything, so a foreign id is a `CrossTenantAccessException` (403)
 * and never a request. The sidecar has no tenant column and could not have made that check;
 * this model is where it is possible.
 *
 * ## What lives here, and what does not
 *
 * This model owns the *shape* of a session: its casts, its query scopes, and the questions a
 * row can answer about itself. It owns none of the lifecycle:
 *
 * | Concern | Owner |
 * |---|---|
 * | create / start / stop / delete, `SESSIONS` quota, restore sweep | `SessionManager` (task 9.1) |
 * | which disconnect reasons reconnect, and the backoff | `ReconnectPolicy` (task 9.2) |
 * | enforcing a status transition (this model only *states* legality) | `SessionManager::markState()` (task 9.1) |
 * | warm-up ramp, delay window, quiet hours, risk score | `AntiBanEngine` (task 9.6) |
 * | which backend this session talks through (`channel_mode`) | task 6.1 |
 *
 * The anti-ban columns are readable here (`daily_quota`, `sent_today`, `delay_min_ms`, …)
 * because they are part of the reused table, but nothing in this class interprets them. A
 * predicate like "may this session send another message today?" is the anti-ban gate's, and
 * putting a half-version of it here would give the platform two answers to one question.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $push_name
 * @property string|null $device_id
 * @property SessionStatus $status
 * @property string|null $auth_ref
 * @property Carbon|null $warmup_start_at
 * @property int $daily_quota
 * @property int $weight
 * @property int $delay_min_ms
 * @property int $delay_max_ms
 * @property int $sent_today
 * @property int $reconnects
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 */
class Session extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SessionFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    /**
     * Laravel's database session driver owns `sessions`; see the migration docblock.
     *
     * @var string
     */
    protected $table = 'sessions_wa';

    /**
     * The state a session exists in before anything has been asked of the bridge.
     *
     * Declared here as well as in the migration so a freshly instantiated model already
     * reports it: without it, `Session::create(['name' => ...])->status` is `null` until the
     * row is re-read, and a lifecycle service comparing that against `SessionStatus` would
     * see "no state" for a session that definitely has one.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => SessionStatus::Initializing->value,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'push_name',
        'device_id',
        'status',
        'auth_ref',
        'warmup_start_at',
        'daily_quota',
        'weight',
        'delay_min_ms',
        'delay_max_ms',
        'sent_today',
        'reconnects',
        'last_seen_at',
        'connected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Cast to the enum so a status written by a newer release and read by an older one
            // surfaces as a cast error at the boundary rather than as a string no panel can
            // translate and no transition check can evaluate.
            'status' => SessionStatus::class,
            'warmup_start_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'connected_at' => 'datetime',
            'daily_quota' => 'integer',
            'weight' => 'integer',
            'delay_min_ms' => 'integer',
            'delay_max_ms' => 'integer',
            'sent_today' => 'integer',
            'reconnects' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Sessions the bridge holds a live socket for — `CONNECTED` or `THROTTLED`.
     *
     * @param  Builder<Session>  $query
     * @return Builder<Session>
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->whereIn('status', SessionStatus::online());
    }

    /**
     * Sessions a send may actually be attempted on.
     *
     * Narrower than `online()` on purpose: `THROTTLED` means online and deliberately not
     * sending. Keeping the two scopes distinct is what stops a pool query from quietly
     * defeating the anti-ban gate by picking a session the gate is holding back.
     *
     * @param  Builder<Session>  $query
     * @return Builder<Session>
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->where('status', SessionStatus::Connected);
    }

    /**
     * Sessions in a state that a restart or reconnect can act on.
     *
     * Excludes the terminal states (`LOGGED_OUT`, `REPLACED`), because their stored credentials
     * are void: reconnecting one produces a fresh failure every time and, worse, hides a
     * session that actually needs a human to re-pair it.
     *
     * @param  Builder<Session>  $query
     * @return Builder<Session>
     */
    public function scopeRestartable(Builder $query): Builder
    {
        return $query->whereNotIn('status', [SessionStatus::LoggedOut, SessionStatus::Replaced]);
    }

    /**
     * Sessions in one state.
     *
     * @param  Builder<Session>  $query
     * @return Builder<Session>
     */
    public function scopeWithStatus(Builder $query, SessionStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /*
    |--------------------------------------------------------------------------
    | What the row says
    |--------------------------------------------------------------------------
    */

    public function isOnline(): bool
    {
        return $this->status->isOnline();
    }

    public function canSend(): bool
    {
        return $this->status->canSend();
    }

    /**
     * Whether the stored credentials are void, so only re-pairing can revive this session.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether moving this session to $status is a legal transition.
     *
     * A predicate, never an action: it neither writes nor throws. `SessionManager::markState()`
     * (task 9.1) is the single writer and the thing that raises on an illegal transition —
     * keeping the *statement* of legality here and the *enforcement* there means every writer
     * (a webhook, a console command, a test) is held to one statement.
     */
    public function canTransitionTo(SessionStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }
}
