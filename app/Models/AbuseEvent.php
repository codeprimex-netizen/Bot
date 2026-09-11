<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;
use App\Exceptions\Audit\AppendOnlyViolationException;
use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry in the abuse trail: a guardrail refusal, an anti-fraud decision, or a
 * kill-switch flip (Req 13.8 / B4; Req 32.7 / NFR3).
 *
 * ## Read-only by construction, like `AuditLog`
 *
 * `AbuseEvent::create()` refuses. Writing goes through
 * `App\Services\Abuse\AbuseRecorder`, for two reasons that both matter:
 *
 * 1. a row must be **best-effort** — recording an abuse event may never fail the
 *    request it is protecting — and that policy belongs in one place, not at every
 *    call site that happens to detect something;
 * 2. rows on the signup path legitimately have `tenant_id = NULL`, which
 *    `BelongsToTenant`'s `creating` hook cannot write (it raises
 *    `MissingTenantContextException` instead). The recorder inserts through the query
 *    builder, exactly as `HashChainAuditService` does for the same reason.
 *
 * The trait is still here, and it is the point: **reads** are tenant-scoped. A tenant
 * panel may be handed this model directly and will only ever see its own events;
 * platform-level rows (`tenant_id IS NULL`) are excluded structurally by the scope's
 * `where tenant_id = ?`, so the pre-tenant signup trail is not readable by tenants.
 * With nothing bound, reads fail closed.
 *
 * `AppendOnly` refuses updates and deletes on top of that, and the migration installs
 * matching database triggers plus the production `REVOKE UPDATE, DELETE` grants.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property AbuseVector $vector
 * @property GuardAction $action
 * @property array<array-key, mixed> $signals
 * @property array<array-key, mixed> $evidence
 * @property string $surface
 * @property string|null $session_key
 * @property string|null $conversation_key
 * @property string|null $subject_hash
 * @property string|null $content_hash
 * @property int|null $content_length
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $trace_id
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class AbuseEvent extends Model
{
    use AppendOnly;
    use BelongsToTenant;
    use HasUlids;

    /**
     * Nothing is guarded because nothing may be written through the model at all:
     * `creating` refuses outright, so a developer reaching for `AbuseEvent::create()`
     * gets the useful failure ("record it through AbuseRecorder") rather than a
     * mass-assignment error that would send them to edit `$fillable`.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Only `created_at` exists; `AppendOnly::usesTimestamps()` keeps Eloquent from
     * looking for `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vector' => AbuseVector::class,
            'action' => GuardAction::class,
            'signals' => 'array',
            'evidence' => 'array',
            'content_length' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (Model $model): void {
            throw AppendOnlyViolationException::forDirectWrite($model::class);
        });
    }

    /**
     * The append-only builder, so `AbuseEvent::query()->update(...)` and `->delete()`
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

    /**
     * The stored signal values, narrowed back to enum cases.
     *
     * Unknown values are skipped rather than fatal: a row written by a newer
     * deployment must stay readable by an older one during a rolling deploy
     * (Req 35.2 / NFR6), and an unreadable *evidence* row is worse than an
     * incompletely-labelled one.
     *
     * @return list<AbuseSignal>
     */
    public function signals(): array
    {
        $signals = [];

        foreach ($this->signals as $value) {
            $signal = is_string($value) ? AbuseSignal::tryFrom($value) : null;

            if ($signal instanceof AbuseSignal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Whether this row is a kill-switch engagement that is still in force at `$now`.
     *
     * The one query `SessionKillSwitch` asks of a stored row, kept on the model so
     * "engaged, and not expired" has a single definition.
     */
    public function isActiveKill(?Carbon $now = null): bool
    {
        if (! in_array(AbuseSignal::KillSwitchEngaged, $this->signals(), true)) {
            return false;
        }

        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->greaterThan($now ?? Carbon::now());
    }
}
