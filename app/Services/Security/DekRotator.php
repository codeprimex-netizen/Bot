<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Exceptions\Security\KeyUnavailableException;
use App\Models\EncryptionKey;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;

/**
 * Scheduled rotation of **per-tenant DEKs** (Req 32.6 / NFR3; design § Key rotation —
 * "per-tenant DEKs rotated via `FieldCipher::rotate` (re-wrap, mark old `RETIRING`, lazy
 * re-encrypt on write)").
 *
 * The rotation itself is task 4.1's, and this class deliberately adds nothing to it: it
 * decides **which** lineages are due, calls `FieldCipher::rotate()`, and records what
 * happened. Reimplementing the transactional demote-and-mint here would be a second,
 * subtly different rotation — the one thing a key lifecycle cannot afford.
 *
 * ## Why "due" is `created_at` on the ACTIVE row
 *
 * A rotation *mints a row*, so the active version's `created_at` is, by construction, the
 * moment the lineage last rotated. That is why this task added **no rotation-state columns
 * anywhere**: the schema already records it, and a separate `last_rotated_at` would be a
 * second source of truth that can disagree with the row it describes.
 *
 * ## Non-destructive, and that is the whole point
 *
 * ```
 *   before:  v3 ACTIVE                       values written under v1, v2, v3
 *   after:   v4 ACTIVE, v3 RETIRING          values written under v1, v2, v3 still decrypt
 * ```
 *
 * New writes use v4; everything already stored keeps naming its own version and keeps
 * decrypting, because `KeyStatus::Retiring` can still decrypt. Re-encryption is lazy — a
 * value moves forward when it is next written, or through `FieldCipher::reencrypt()`.
 *
 * **Nothing here ever retires a version.** `RETIRED` refuses to decrypt, so retiring a
 * version that is still referenced by a single stored value is silent, permanent data loss.
 * Proving "no ciphertext references v3" needs a registry of every encrypted column, which
 * arrives with the models that use the `Encrypted` casts — so the retire step is
 * deliberately absent rather than implemented on a guess. `RETIRING` versions accumulate,
 * which costs one small row per rotation and loses nothing.
 */
final readonly class DekRotator
{
    /**
     * @param  int  $rotateAfterDays  age of the active version at which a lineage is due
     * @param  int  $batch  lineages per sweep
     */
    public function __construct(
        private FieldCipher $cipher,
        private AuditService $audit,
        private int $rotateAfterDays = 90,
        private int $batch = 100,
    ) {}

    /**
     * Active key rows whose lineage is older than the rotation interval, oldest first.
     *
     * Platform maintenance covering every tenant, so the query names no tenant:
     * `withoutTenantScope()` is the sanctioned, greppable bypass for exactly this, and the
     * sweep runs in the console with no tenant bound.
     *
     * @return list<EncryptionKey>
     */
    public function due(?int $limit = null): array
    {
        $threshold = Carbon::now()->subDays(max(0, $this->rotateAfterDays));

        /** @var list<EncryptionKey> $keys */
        $keys = EncryptionKey::withoutTenantScope()
            ->where('status', '=', KeyStatus::Active->value)
            ->where('created_at', '<=', $threshold)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit ?? $this->batch))
            ->get()
            ->all();

        return $keys;
    }

    /**
     * How many lineages are due right now, without rotating any.
     */
    public function dueCount(): int
    {
        return EncryptionKey::withoutTenantScope()
            ->where('status', '=', KeyStatus::Active->value)
            ->where('created_at', '<=', Carbon::now()->subDays(max(0, $this->rotateAfterDays)))
            ->count();
    }

    /**
     * Whether one tenant's lineage is old enough to be swept.
     *
     * Asked by the single-tenant path of the command, so an operator rotating by hand is
     * told "not due yet" instead of quietly resetting a clock — and `--force` is what says
     * they meant it.
     */
    public function isDue(Tenant|string $tenant, KeyPurpose $purpose = KeyPurpose::Field): bool
    {
        $active = EncryptionKey::activeFor($tenant instanceof Tenant ? $tenant->id : $tenant, $purpose);

        if ($active === null || $active->created_at === null) {
            return false;
        }

        return $active->created_at->lessThanOrEqualTo(
            Carbon::now()->subDays(max(0, $this->rotateAfterDays)),
        );
    }

    /**
     * Rotate one lineage and record it on that tenant's audit chain.
     *
     * @throws KeyUnavailableException when a new version cannot be sealed; the previous
     *                                 version is then left ACTIVE and untouched
     */
    public function rotate(Tenant|string $tenant, KeyPurpose $purpose = KeyPurpose::Field): EncryptionKey
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $previous = EncryptionKey::activeFor($tenantId, $purpose);

        $key = $this->cipher->rotate($tenantId, $purpose);

        // The tenant's own chain, because a key rotation is that tenant's history — even
        // though the actor is the scheduler. Versions and ids only: an audit payload is
        // one of the places key material must never appear.
        $this->audit->write(
            'security.dek.rotated',
            [
                'purpose' => $purpose->value,
                'version' => $key->version,
                'previous_version' => $previous?->version,
                'kms_key_id' => $key->kms_key_id,
            ],
            $key,
            tenant: $tenantId,
        );

        return $key;
    }

    /**
     * Rotate every lineage that is due.
     *
     * One tenant's failure is recorded and the sweep continues: a key store that refuses
     * one seal must not leave the rest of the platform unrotated, and the lineage it failed
     * on is left with its previous version still `ACTIVE` — so that tenant keeps working.
     */
    public function rotateDue(?int $limit = null): DekRotationReport
    {
        $report = new DekRotationReport;

        foreach ($this->due($limit) as $key) {
            try {
                $rotated = $this->rotate($key->tenant_id, $key->purpose);
                $report->recordRotated($key->tenant_id, $key->purpose, $key->version, $rotated->version);
            } catch (KeyUnavailableException) {
                $report->recordFailed($key->tenant_id, $key->purpose, $key->version);
            }
        }

        return $report;
    }
}
