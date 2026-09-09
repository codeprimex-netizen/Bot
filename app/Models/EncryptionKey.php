<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Models\Builders\TenantScopedBuilder;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Security\WrappedKey;
use Database\Factories\EncryptionKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of one tenant's Data Encryption Key, stored **wrapped** by the KMS
 * master key (Req 32.5 / NFR3; design § Per-tenant encryption).
 *
 * The row is metadata *about* a key plus the key in sealed form. `wrapped_dek`
 * never holds plaintext material — `FieldCipher` seals a freshly generated DEK
 * through `KeyWrapper` before this model ever sees it, and unwraps it again on
 * read — so this table is inert without the master key.
 *
 * ## Reading key material, from every context
 *
 * Key material must resolve in three situations, and the lookups below
 * (`activeFor`, `atVersion`) are written so that all three work identically:
 *
 * | Caller | Tenant context | Path |
 * |---|---|---|
 * | `TenantLifecycle::provision` creating the first DEK | **none yet** — the tenant is being born | `forTenant()` names the tenant explicitly; `TenantScope` is bypassed, so no `MissingTenantContextException` |
 * | ordinary encrypt/decrypt inside a request or job | tenant bound | same query; the explicit `tenant_id` predicate is at least as strict as the scope |
 * | platform-admin key screens, rotation scheduler | `asPlatform()` / `runFor()` | same query |
 *
 * `forTenant()` is one of the two sanctioned, greppable scope bypasses of task 0.3
 * and states in code that this read names its tenant rather than inheriting it.
 * That is *required* here: a tenant-scoped read would fail closed during
 * provisioning and in the scheduler, and a fail-closed key lookup in the one path
 * that creates keys would make the tenant unprovisionable. Isolation is not
 * weakened — a caller can only ever ask for the tenant it names, and asking for a
 * *different* tenant's key from inside a tenant context is still refused by
 * `TenantOwnershipGuard` on write (`CrossTenantAccessException`).
 *
 * ## The "exactly one active version" invariant
 *
 * `active_flag` is derived from `status` in the `saving` hook below — 1 when
 * `ACTIVE`, `NULL` otherwise — purely so the database can carry
 * `uniq(tenant_id, purpose, active_flag)`. Two concurrent rotations therefore lose
 * one insert to a constraint violation instead of both succeeding and leaving the
 * lineage with two "current" keys. Never set the flag by hand; set `status`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property KeyPurpose $purpose
 * @property int $version
 * @property KeyStatus $status
 * @property int|null $active_flag
 * @property string $kms_key_id
 * @property string $algorithm
 * @property string $wrapped_dek
 * @property \Illuminate\Support\Carbon|null $rotated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class EncryptionKey extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EncryptionKeyFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * The version number every lineage starts at. Versions are 1-based so that
     * "version 0" can never be a valid parse of a malformed payload segment.
     */
    public const int FIRST_VERSION = 1;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'purpose',
        'version',
        'status',
        'kms_key_id',
        'algorithm',
        'wrapped_dek',
        'rotated_at',
    ];

    /**
     * The sealed DEK is hidden from every array/JSON serialisation of this model.
     *
     * It is ciphertext, so exposing it is not immediately fatal — but a key blob has
     * no business in an API response, a Livewire payload, or a log line, and the
     * cheapest way to guarantee it never appears is to make the model unable to
     * produce it.
     *
     * @var list<string>
     */
    protected $hidden = [
        'wrapped_dek',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => KeyPurpose::class,
            'status' => KeyStatus::class,
            'version' => 'integer',
            'active_flag' => 'integer',
            'rotated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the derived flag in lockstep with the status it is derived from, so the
        // unique index really does mean "one active version per lineage".
        static::saving(static function (EncryptionKey $key): void {
            $key->setAttribute('active_flag', $key->status === KeyStatus::Active ? 1 : null);
        });
    }

    /**
     * The version new ciphertext must be written under, or null when the tenant has
     * no lineage for this purpose yet.
     */
    public static function activeFor(string $tenantId, KeyPurpose $purpose): ?self
    {
        return self::lineage($tenantId, $purpose)
            ->where('status', '=', KeyStatus::Active->value)
            ->first();
    }

    /**
     * The exact version a stored ciphertext names — including a `RETIRING` one,
     * which is the whole point of versioning (old values stay readable across a
     * rotation).
     */
    public static function atVersion(string $tenantId, KeyPurpose $purpose, int $version): ?self
    {
        return self::lineage($tenantId, $purpose)
            ->where('version', '=', $version)
            ->first();
    }

    /**
     * Highest version number issued for a lineage, or 0 when it has none.
     */
    public static function latestVersion(string $tenantId, KeyPurpose $purpose): int
    {
        $version = self::lineage($tenantId, $purpose)->max('version');

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * Every version of one lineage, newest first — platform-admin key screens and
     * the rotation sweep of task 4.2.
     *
     * @return TenantScopedBuilder<static>
     */
    public static function lineage(string $tenantId, KeyPurpose $purpose): TenantScopedBuilder
    {
        return self::forTenant($tenantId)
            ->where('purpose', '=', $purpose->value)
            ->orderByDesc('version');
    }

    /**
     * The sealed key in the form `KeyWrapper::unwrap()` expects.
     */
    public function toWrappedKey(): WrappedKey
    {
        return new WrappedKey($this->kms_key_id, $this->algorithm, $this->wrapped_dek);
    }

    /**
     * Whether existing ciphertext may still be read under this version.
     */
    public function canDecrypt(): bool
    {
        return $this->status->canDecrypt();
    }

    /**
     * Whether new ciphertext may be written under this version.
     */
    public function canEncrypt(): bool
    {
        return $this->status->canEncrypt();
    }
}
