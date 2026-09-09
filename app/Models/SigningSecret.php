<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Security\WrappedKey;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of one HMAC signing secret, stored **sealed** by the master key
 * (Req 32.6 / NFR3; design § Key rotation — dual-secret rotation).
 *
 * The row is metadata about a secret plus the secret in sealed form; `sealed_secret`
 * never holds plaintext. Everything about *using* it — signing, verifying, rotating —
 * belongs to `App\Services\Security\SigningSecretStore`, which is the only class that
 * ever opens one.
 *
 * ## The two invariants this model carries
 *
 * 1. **Exactly one signer per scope.** `active_flag` is derived in the `saving` hook
 *    from `accepted_until` — 1 while the secret is the signer (no deadline), NULL once
 *    it has been rotated out — purely so the database can carry
 *    `uniq(scope, active_flag)`. Two concurrent rotations therefore lose one insert to
 *    a constraint violation instead of both succeeding and leaving a scope with two
 *    signers. Never set the flag by hand; set `accepted_until`.
 * 2. **The overlap is a deadline, not a status.** A rotated-out secret is acceptable
 *    for verification while `accepted_until` is in the future and refused after it,
 *    which means the window closes on the clock rather than when a purge job happens
 *    to run. `acceptableFor()` is the only read the verification path uses.
 *
 * **Not tenant-scoped.** `tenant_id` is nullable and this model deliberately does not
 * use `BelongsToTenant`: platform-owned secrets (a payment gateway's) have no tenant,
 * and HMAC verification is what *resolves* the tenant on inbound webhooks, so it runs
 * before one is bound. The migration docblock records the reasoning and
 * `tenantScopeExemptions()` in `TenantOwnedModelsGuardTest` records the decision.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $scope
 * @property int $version
 * @property int|null $active_flag
 * @property Carbon|null $accepted_until
 * @property Carbon|null $rotated_at
 * @property string $kms_key_id
 * @property string $algorithm
 * @property string $sealed_secret
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SigningSecret extends Model
{
    use HasUlids;

    /**
     * The version number every scope starts at, 1-based for the same reason
     * `EncryptionKey` is: "version 0" can never be a valid parse of a malformed value.
     */
    public const int FIRST_VERSION = 1;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'scope',
        'version',
        'accepted_until',
        'rotated_at',
        'kms_key_id',
        'algorithm',
        'sealed_secret',
    ];

    /**
     * The sealed secret is hidden from every array/JSON serialisation.
     *
     * It is ciphertext, so exposing it is not immediately fatal — but a webhook secret
     * has no business in an API response, a Livewire payload, or a log line, and the
     * cheapest way to guarantee it never appears is to make the model unable to produce
     * it.
     *
     * @var list<string>
     */
    protected $hidden = [
        'sealed_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'active_flag' => 'integer',
            'accepted_until' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the derived flag in lockstep with what it is derived from, so the unique
        // index really does mean "one signer per scope".
        static::saving(static function (SigningSecret $secret): void {
            $secret->setAttribute('active_flag', $secret->accepted_until === null ? 1 : null);
        });
    }

    /**
     * The tenant this secret is attributed to, if any.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The secret that signs outbound requests for a scope, or null when the scope has
     * none yet.
     */
    public static function signerFor(string $scope): ?self
    {
        return self::query()
            ->where('scope', '=', $scope)
            ->whereNull('accepted_until')
            ->first();
    }

    /**
     * Every secret a signature may be verified against right now: the signer, plus any
     * rotated-out secret still inside its overlap window — newest first.
     *
     * This is the whole of "dual-secret rotation" as a query. A secret past its
     * deadline is simply not returned, so a retired secret is rejected even if its row
     * has not been purged yet.
     *
     * @return Collection<int, self>
     */
    public static function acceptableFor(string $scope, ?DateTimeInterface $at = null): Collection
    {
        return self::query()
            ->where('scope', '=', $scope)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('accepted_until')
                    ->orWhere('accepted_until', '>', $at ?? Carbon::now());
            })
            ->orderByDesc('version')
            ->get();
    }

    /**
     * A specific version of a scope's secret.
     */
    public static function atVersion(string $scope, int $version): ?self
    {
        return self::query()
            ->where('scope', '=', $scope)
            ->where('version', '=', $version)
            ->first();
    }

    /**
     * Highest version issued for a scope, or 0 when it has none.
     */
    public static function latestVersion(string $scope): int
    {
        $version = self::query()->where('scope', '=', $scope)->max('version');

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * Whether this secret may still verify a signature at `$at`.
     */
    public function isAcceptableAt(?DateTimeInterface $at = null): bool
    {
        return $this->accepted_until === null || $this->accepted_until->isAfter($at ?? Carbon::now());
    }

    /**
     * Whether this is the secret that signs.
     */
    public function isSigner(): bool
    {
        return $this->accepted_until === null;
    }

    /**
     * The sealed secret in the form `KeyWrapper::unwrap()` expects.
     */
    public function toWrappedKey(): WrappedKey
    {
        return new WrappedKey($this->kms_key_id, $this->algorithm, $this->sealed_secret);
    }
}
