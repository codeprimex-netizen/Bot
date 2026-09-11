<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned HMAC signing secrets with a **dual-secret rotation window**
 * (Req 32.6 / NFR3; design § Key rotation — "webhook/HMAC secrets support dual-secret
 * rotation (accept old+new during overlap)"; STRIDE row "Bridge → webhook: HMAC
 * signature verify + per-session shared secret").
 *
 * ## Why a table, and why this is the only one task 4.2 adds
 *
 * DEK rotation needed no new storage — `encryption_keys` already versions keys, and the
 * `ACTIVE` row's `created_at` is when the lineage last rotated. HMAC secrets have no
 * such home, and they cannot borrow `encryption_keys`: a DEK is *used to encrypt* and is
 * never shown to anybody, while a webhook secret is **shared with a peer** and has to
 * survive a rotation *twice over* — once for signing (only the newest may sign) and once
 * for verification (old and new are both accepted for an overlap window). Without the
 * overlap, rotating a secret rejects every signature already in flight, which is a
 * self-inflicted outage on the one path an attacker would love to see fail open.
 *
 * ## Shape
 *
 * | Column | Why |
 * |---|---|
 * | `scope` | what the secret protects — `bridge:session:{id}`, `gateway:{slug}`, `tenant:{id}:api`. Opaque here; each subsystem owns its own naming |
 * | `version` | monotonic per scope, so rotations are an append and the history is readable |
 * | `active_flag` | derived from "is this the signing secret?", carrying `uniq(scope, active_flag)` — the database, not convention, enforces *one* signer |
 * | `accepted_until` | NULL on the signer; a deadline on a rotated-out secret. Verification accepts NULL or a future deadline, so the overlap is data rather than code |
 * | `sealed_secret` | the secret sealed by `KeyWrapper` (KMS/master key), bound by AAD to `scope|version` — a blob moved to another scope's row does not open |
 * | `kms_key_id` | which master key sealed it, so `MasterKeyRewrapper` can move these rows forward with one indexed query, exactly as it does for DEKs |
 *
 * ## Tenancy: nullable `tenant_id`, and deliberately **not** `BelongsToTenant`
 *
 * Same reasoning as `outbox` and `idempotency_keys` (task 3.1), and for the same two
 * reasons:
 *
 * 1. **Null is a legitimate value.** A payment-gateway webhook secret belongs to the
 *    platform, not to a tenant. `BelongsToTenant` cannot write a null `tenant_id` — its
 *    `creating` hook raises `MissingTenantContextException` — so the trait would make
 *    legal rows impossible.
 * 2. **Verification runs before tenant resolution.** Checking an inbound webhook's HMAC
 *    is what *identifies* the session, and therefore the tenant; a tenant-scoped read
 *    would fail closed on the one path that has no tenant yet. The rotation sweep runs in
 *    the console with no tenant bound either.
 *
 * Isolation is provided explicitly instead: the `scope` string carries the tenant (or
 * session) identity, and the tenant column exists for attribution and for the
 * offboarding cascade. The exemption is recorded in `TenantOwnedModelsGuardTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_secrets', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Attribution and offboarding cascade; null for platform-owned secrets.
            // See the docblock above for why the trait is deliberately absent.
            TenantSchema::tenantId($table, ['scope'])->nullable();

            // What the secret protects. 191 chars: InnoDB's utf8mb4 index limit, and the
            // same ceiling `circuit_breakers.name` uses — a truncated scope would merge
            // two peers onto one secret.
            $table->string('scope', 191);

            // Monotonic per scope. Not carried in the signature: peers send an HMAC of
            // the body and nothing else, which is exactly why verification must try
            // every acceptable version rather than look one up.
            $table->unsignedInteger('version');

            // 1 on the secret that signs, NULL on every rotated-out one. Derived from
            // `accepted_until` by App\Models\SigningSecret; never set by hand.
            $table->unsignedTinyInteger('active_flag')->nullable();

            // NULL = this is the signer. A timestamp = rotated out, still accepted for
            // verification until then. Past it the secret is refused even while the row
            // survives, so the window is enforced by the clock and not by the purge job.
            $table->timestamp('accepted_until')->nullable();

            // When this secret stopped being the signer; null while it is.
            $table->timestamp('rotated_at')->nullable();

            // Which master key sealed the secret, and with what — same contract as
            // `encryption_keys`, so one re-wrap sweep serves both tables.
            $table->string('kms_key_id', 191);
            $table->string('algorithm', 32);

            // The secret, sealed by KeyWrapper. Never plaintext at rest: a dump of this
            // table without the master key cannot forge or verify a signature.
            $table->text('sealed_secret');

            $table->timestamps();

            // One row per version of a scope.
            $table->unique(['scope', 'version'], 'signing_secrets_scope_version_unique');

            // "Exactly one signer per scope", enforced by the database (NULLs do not
            // collide in a unique index, on MySQL 8 and SQLite alike).
            $table->unique(['scope', 'active_flag'], 'signing_secrets_scope_active_unique');

            // The verification read: every acceptable secret of one scope.
            $table->index(['scope', 'accepted_until'], 'signing_secrets_scope_accepted_index');

            // The purge sweep: secrets whose overlap window has closed.
            $table->index('accepted_until', 'signing_secrets_accepted_until_index');

            // "Every secret still sealed under master key X" — the re-wrap sweep.
            $table->index('kms_key_id', 'signing_secrets_kms_key_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_secrets');
    }
};
