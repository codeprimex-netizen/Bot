<?php

declare(strict_types=1);

use App\Enums\KeyPurpose;
use App\Enums\KeyStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant Data Encryption Keys, stored **wrapped** by the KMS master key
 * (Req 32.5 / NFR3; design § Per-tenant encryption and § Data Models).
 *
 * This table holds key *material*, so two properties matter more than anything
 * else about its shape:
 *
 * 1. **Never plaintext at rest.** `wrapped_dek` only ever holds a DEK that has
 *    already been sealed by the master key (`KeyWrapper`), bound to the row that
 *    describes it. A dump of this table without the master key is inert.
 * 2. **Many versions, exactly one active.** Rotation (task 4.2) adds a version
 *    rather than overwriting one, so ciphertext written before the rotation stays
 *    readable. "Exactly one active" is a *security* invariant — two active versions
 *    would make "which key did we write under?" ambiguous — so it is enforced by
 *    the database, not by convention: `active_flag` is 1 on the active row and
 *    NULL everywhere else, and `uniq(tenant_id, purpose, active_flag)` therefore
 *    permits one active row per lineage while ignoring the rest (NULLs do not
 *    collide in a unique index, on MySQL 8 and SQLite alike). The flag is derived
 *    from `status` by the model, never set by hand.
 *
 * Tenant-owned, so the column and its leading index come from `TenantSchema` and
 * the model uses `BelongsToTenant`. `tenant_id` is deliberately **NOT NULL**,
 * narrowing design's `tenant_id->null`: a null-tenant row could never be
 * constrained by `TenantScope`, and the platform has no need for one — platform
 * secrets live in the secret store / `platform_settings` (design § Secrets
 * management), not here. The key material of *one* tenant always belongs to
 * exactly that tenant, which is the whole point of per-tenant crypto isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encryption_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // design: idx(tenant_id, purpose, status) — "this tenant's key of this
            // purpose in this state", the lookup `FieldCipher` issues on every
            // encrypt and decrypt.
            TenantSchema::tenantId($table, ['purpose', 'status'], 'encryption_keys_tenant_purpose_status_index');

            // What the key protects: cryptographic domain separation, bound into the
            // AAD of every ciphertext and of the DEK wrap (App\Enums\KeyPurpose).
            $table->string('purpose', 16)->default(KeyPurpose::Field->value);

            // Monotonic per (tenant, purpose). Carried inside every ciphertext so a
            // value written before a rotation still names the key that can read it.
            $table->unsignedInteger('version');

            // App\Enums\KeyStatus: ACTIVE (written under) | RETIRING (still readable)
            // | RETIRED (refuses to unwrap — fail closed).
            $table->string('status', 16)->default(KeyStatus::Active->value);

            // Derived from `status` by App\Models\EncryptionKey: 1 when ACTIVE, NULL
            // otherwise. Exists solely to carry the unique index below.
            $table->unsignedTinyInteger('active_flag')->nullable();

            // Which master key sealed this DEK. Kept so master-key rotation (task 4.2)
            // can find every DEK still wrapped under a retiring master key without
            // unwrapping any of them.
            $table->string('kms_key_id', 191);

            // Label of the AEAD used both for the wrap and for field ciphertext
            // (e.g. "aes-256-gcm"), so an algorithm migration is data, not a guess.
            $table->string('algorithm', 32);

            // Base64 of `iv || tag || sealed-DEK`. Text rather than a binary column:
            // the value is transport- and dump-safe, and it is *already* ciphertext —
            // no plaintext DEK ever reaches this column.
            $table->text('wrapped_dek');

            // When this version was rotated *out* (set on the row leaving ACTIVE);
            // null while it is the active one.
            $table->timestamp('rotated_at')->nullable();

            $table->timestamps();

            // One row per version of a lineage.
            $table->unique(
                ['tenant_id', 'purpose', 'version'],
                'encryption_keys_tenant_purpose_version_unique',
            );

            // The "exactly one active version" invariant, enforced by the database.
            $table->unique(
                ['tenant_id', 'purpose', 'active_flag'],
                'encryption_keys_tenant_purpose_active_unique',
            );

            // "Every DEK still wrapped under master key X" — the master-key rotation
            // sweep of task 4.2.
            $table->index('kms_key_id', 'encryption_keys_kms_key_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encryption_keys');
    }
};
