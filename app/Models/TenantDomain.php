<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainChallengeMethod;
use App\Enums\DomainVerificationFailure;
use App\Services\Domains\VerifiedDomainDirectory;
use App\Services\Url\BaseUrlCache;
use App\Support\Url\CanonicalBase;
use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tenant's claim on a custom domain, verified or not (Req 9.3, 9.7 / A9).
 *
 * ```php
 * // the read the base-URL resolver issues — verified rows only, by construction
 * $host = TenantDomain::canonicalHostFor($tenant->id);   // ?string
 * ```
 *
 * ## A row only counts once it is verified
 *
 * Both reads that can reach URL generation refuse an unverified row, and they refuse it
 * in two independent places:
 *
 *  1. `canonicalHostFor()` filters on `verified()` in SQL, so an unverified row is not
 *     in the result set;
 *  2. `canonicalHost()` returns null unless `verified_at` is set, so even a row handed
 *     to the resolver directly — by a future caller, a test, or task 5.5's verification
 *     code — cannot yield a host.
 *
 * The second is what makes the guarantee structural rather than a property of one
 * query: there is no accessor on this model that hands out a host without checking. Use
 * `$domain->host` only for the claim itself (the verification screen, the DNS
 * instructions); use `canonicalHost()` anywhere the value becomes a URL.
 *
 * ## Normalisation is a write-time invariant
 *
 * `host` is normalised through `CanonicalBase::host()` on assignment — lowercased,
 * punycoded, root dot removed, ports and paths refused. The table's global
 * `unique(host)` is only meaningful because of it: one spelling per host means one
 * owner per host, and "one domain, one owner" is what stops a second tenant claiming a
 * host whose traffic it would then receive.
 *
 * ## Not `BelongsToTenant` — a reviewed exemption
 *
 * This table answers *"which tenant owns this host?"*, which is asked before a tenant is
 * bound (task 5.5's routing) and must be answerable across tenants (the "already in
 * use" refusal of Req 9.7 cannot be explained if another tenant's claim is invisible).
 * The same argument as `TenantUser`, whose docblock records it in full. Isolation comes
 * from the access path: every read here names its tenant explicitly.
 * `TenantOwnedModelsGuardTest::tenantScopeExemptions()` records the decision.
 *
 * ## Verification state (task 5.5)
 *
 * The columns below `host` are the challenge and the evidence: which proof the tenant
 * chose, the token it must publish, when that expires, and what the last check saw.
 * `App\Services\Domains\DomainVerifier` is the only writer of any of them, and
 * `App\Services\Domains\DomainRegistrar` the only writer of `host`. They do not widen
 * the read path by one inch — `canonicalHost()` still asks `verified_at` and nothing
 * else, so no combination of challenge state can make an unverified row usable.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $host
 * @property DomainChallengeMethod|null $challenge_method
 * @property string|null $challenge_token
 * @property Carbon|null $challenge_issued_at
 * @property Carbon|null $challenge_expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property DomainVerificationFailure|null $last_failure_reason
 * @property Carbon|null $tls_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class TenantDomain extends Model
{
    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Named in `InvalidBaseUrlException` messages when a stored host cannot be used.
     */
    public const string HOST_SOURCE = 'tenant_domains.host';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'host',
        'challenge_method',
        'challenge_token',
        'challenge_issued_at',
        'challenge_expires_at',
        'verified_at',
        'last_checked_at',
        'last_failure_reason',
        'tls_expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'challenge_method' => DomainChallengeMethod::class,
            'challenge_issued_at' => 'datetime',
            'challenge_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            // Cast to the enum so a value written by a newer release and read by an older
            // one surfaces as a cast error at the boundary rather than as a string the
            // panel cannot translate.
            'last_failure_reason' => DomainVerificationFailure::class,
            'tls_expires_at' => 'datetime',
        ];
    }

    /**
     * The verified host this tenant's URLs are built from, or null when it has none.
     *
     * The most recently verified row wins, with the host as a total-order tiebreak so
     * two rows verified in the same second never swap between requests. Newest-wins is
     * what makes a domain change an ordinary verification: the new host takes over the
     * moment it is confirmed, and the old row can stay while DNS drains.
     *
     * The cost of that choice, recorded because it is operator-visible: signed URLs
     * bind their host (Req 9.6, task 5.3), so links issued under the previous domain
     * stop verifying at the cutover. Exports and payment links are short-lived (at most
     * 3600 seconds), so the window is bounded.
     */
    public static function canonicalHostFor(string $tenantId): ?string
    {
        if ($tenantId === '') {
            return null;
        }

        return static::query()
            ->where('tenant_id', $tenantId)
            ->verified()
            ->orderByDesc('verified_at')
            ->orderBy('host')
            ->first()
            ?->canonicalHost();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Ownership *and* TLS confirmed (Req 9.7) — the only state in which this row may
     * be used to build a URL.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * The host this row contributes to URL generation — null unless verified.
     *
     * Every path that turns a domain into a base goes through here, so "an unverified
     * domain is never used for URL generation" holds even for a caller that never
     * queried `verified()`.
     */
    public function canonicalHost(): ?string
    {
        return $this->isVerified() ? $this->host : null;
    }

    /**
     * Whether a challenge has been issued and has not expired.
     *
     * Both halves, because an expired token must be as useless as an absent one: a
     * challenge value published in a public DNS zone is readable for ever, so a claim
     * whose deadline has passed has to be re-issued rather than re-checked.
     */
    public function hasLiveChallenge(?Carbon $at = null): bool
    {
        if ($this->challenge_token === null || $this->challenge_method === null) {
            return false;
        }

        $expiresAt = $this->challenge_expires_at;

        return $expiresAt === null || ! ($at ?? Carbon::now())->greaterThan($expiresAt);
    }

    /**
     * Rows that may be used for URL generation.
     *
     * @param  Builder<TenantDomain>  $query
     * @return Builder<TenantDomain>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    /**
     * Verified rows whose evidence is older than $staleAfter, stalest first.
     *
     * The re-check sweep's whole query (`wa:domains:recheck`), served by
     * `idx(verified_at, last_checked_at)`. Only verified rows: an unverified claim is
     * checked when its tenant asks, not on the platform's clock — nobody is waiting on
     * a pending claim except the tenant looking at it, and re-probing every abandoned
     * claim for ever would spend the platform's egress on hosts nobody will ever fix.
     *
     * A row never checked (`last_checked_at IS NULL`) sorts first, which is what makes a
     * domain verified before this column existed pick itself up on the first sweep.
     *
     * @param  Builder<TenantDomain>  $query
     * @return Builder<TenantDomain>
     */
    public function scopeDueForRecheck(Builder $query, Carbon $staleAfter): Builder
    {
        return $query
            ->verified()
            ->where(function (Builder $stale) use ($staleAfter): void {
                $stale->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $staleAfter);
            })
            ->orderByRaw('last_checked_at is null desc')
            ->orderBy('last_checked_at')
            ->orderBy('host');
    }

    /**
     * Normalise on the way in, so the column holds one spelling per host.
     *
     * A mutator rather than a service method: every writer — task 5.5's verification
     * flow, a seeder, a console command, tinker — goes through attribute assignment, so
     * this is the one place that cannot be bypassed by forgetting to call something.
     *
     * @throws \App\Exceptions\Url\InvalidBaseUrlException when $host is not a usable hostname
     */
    public function setHostAttribute(string $host): void
    {
        $this->attributes['host'] = CanonicalBase::host($host, self::HOST_SOURCE);
    }

    /**
     * Drop the resolver's cached view of every base the moment a claim changes.
     *
     * Registered on the model rather than at call sites so a domain verified by task
     * 5.5, by a console command, or by hand in tinker all take effect immediately —
     * Req 9.3's override is useless if it waits out a cache TTL. `saved` covers the
     * verification itself (`verified_at` moving from null to a timestamp) as well as a
     * host being corrected; `deleted` covers a claim withdrawn.
     *
     * **Two caches, one event, on purpose.** A claim changing affects both directions of
     * the host relationship, and they are cached separately because they are read by
     * different layers: `BaseUrlCache` holds *tenant → base URL* (URL generation) and
     * `VerifiedDomainDirectory` holds *host → tenant* plus the verified-host list
     * (request routing, and task 5.4's accepted-host allowlist). Flushing them from the
     * same model event is what makes revocation immediate in **both** directions — a
     * domain that stopped resolving must stop being emitted *and* stop being accepted, and
     * a revocation that only cleared one of the two would leave the platform routing a
     * host it no longer links to, or linking to one it no longer routes.
     */
    protected static function booted(): void
    {
        $invalidate = static function (self $domain): void {
            app(BaseUrlCache::class)->flush();
            app(VerifiedDomainDirectory::class)->flush();
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
