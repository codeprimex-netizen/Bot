<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditActorType;
use App\Exceptions\Audit\AppendOnlyViolationException;
use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the append-only, hash-chained audit trail (Req 24.2, 24.5 / D1;
 * Req 34.1 / NFR5; Correctness Property 17).
 *
 * ## Read-only by construction
 *
 * This model **reads** the trail; it never writes it. Writing means computing
 * `prev_hash` from the chain tip under a lock and hashing the row's canonical form —
 * so `AuditLog::create()` would produce a row that fails verification, and it is
 * refused with a message pointing at `AuditService::write()`. `AppendOnly` refuses
 * updates and deletes on top of that, and the migration installs matching database
 * triggers plus the production `REVOKE UPDATE, DELETE` grants.
 *
 * ## Two chains, one table
 *
 * `chain_key` is `tenant_id` for a tenant-scoped action and the literal
 * `platform` for a platform-wide one (Req 1.5 / A1: a super-admin acting with no
 * tenant bound). `tenant_id` is therefore **nullable**, and the model still uses
 * `BelongsToTenant`, which gives exactly the behaviour wanted:
 *
 * | Context            | `AuditLog::query()` returns                                  |
 * |--------------------|--------------------------------------------------------------|
 * | tenant bound       | that tenant's entries only — never another tenant's, never platform ones |
 * | platform mode      | everything (the audited bypass of Req 1.5)                   |
 * | nothing bound      | fails closed with `MissingTenantContextException`             |
 *
 * A tenant panel can therefore be handed this model directly. Platform entries have
 * `tenant_id IS NULL`, so a tenant-scoped query (`where tenant_id = ?`) excludes them
 * structurally — a tenant can never read the platform trail, which is the correct
 * reading of Req 24 (the audit viewer is a platform-admin screen).
 *
 * `AuditService` itself reads chains through the sanctioned `withoutTenantScope()`
 * bypass, because appending must work identically in all three contexts above —
 * including inside `asPlatform()`, where the entry being written is *about* the
 * bypass.
 *
 * @property string $id
 * @property string $chain_key
 * @property int $sequence
 * @property string|null $tenant_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<array-key, mixed> $payload
 * @property AuditActorType $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string|null $request_id
 * @property string|null $trace_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $prev_hash
 * @property string $row_hash
 * @property \Illuminate\Support\Carbon $created_at
 */
class AuditLog extends Model
{
    use AppendOnly;
    use BelongsToTenant;
    use HasUlids;

    /**
     * The chain platform-wide entries are appended to — the one chain that has no
     * tenant. Never a valid tenant id (ULIDs are 26 uppercase-ish chars), so it
     * cannot collide with one.
     */
    public const string PLATFORM_CHAIN = 'platform';

    /**
     * What the first row of a chain links to. A fixed value rather than `NULL` so the
     * verifier has one rule for every position instead of a special case for the
     * first — and so "row 1 was deleted" is a detectable `GENESIS_MISMATCH` rather
     * than an unremarkable start.
     */
    public const string GENESIS_HASH = 'GENESIS';

    /**
     * Nothing is guarded, and nothing needs to be: creating, updating, and deleting all
     * refuse outright, so mass assignment has no write to protect. Leaving `$guarded`
     * empty means a developer who tries `AuditLog::create([...])` gets the *useful*
     * failure — "write it through AuditService" — instead of a mass-assignment error
     * that would send them to add a `$fillable` entry.
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
            'sequence' => 'integer',
            'payload' => 'array',
            'actor_type' => AuditActorType::class,
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
     * The append-only builder, so `AuditLog::query()->update(...)` and
     * `->delete()` fail by class rather than by luck.
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
     * Whether this entry belongs to the platform chain rather than a tenant's.
     */
    public function isPlatformEntry(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * The chain key a tenant id (or its absence) maps to — the single definition of
     * the `chain_key = tenant_id ?? 'platform'` invariant.
     */
    public static function chainKeyFor(?string $tenantId): string
    {
        return $tenantId ?? self::PLATFORM_CHAIN;
    }

    /**
     * Whether this row's chain matches its tenant attribution. Verified on every
     * walk: moving a row between chains must not be a way to hide it.
     */
    public function hasCoherentChainKey(): bool
    {
        return $this->chain_key === self::chainKeyFor($this->tenant_id);
    }
}
