<?php

declare(strict_types=1);

use App\Enums\AuditActorType;
use App\Support\Database\AppendOnlyTable;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only, hash-chained audit trail (Req 24.2, 24.5 / D1; Req 34.1 / NFR5;
 * Correctness Property 17).
 *
 * Every privileged action — admin CRUD, impersonation, login attempts, plan and
 * billing changes, group settings toggles, compliance and retention actions, and the
 * audited `actingAsPlatform()` scope bypass of Req 1.5 — appends one row here.
 *
 * ## Append-only, enforced three ways
 *
 * 1. **Grants (production).** The application role gets `SELECT, INSERT` and nothing
 *    else. The statements are in
 *    `App\Support\Database\AppendOnlyTable::revokeStatements()` so the runbook cannot
 *    drift from the code, and the deployment runs them once per environment as an
 *    admin user (a migration user must not hold `GRANT`).
 * 2. **Triggers (every environment, installed below).** `BEFORE UPDATE` and
 *    `BEFORE DELETE` guards that abort the statement on MySQL *and* SQLite — which is
 *    what makes the invariant provable in the test suite, where no grant system
 *    exists.
 * 3. **Application (`AppendOnly` + `AppendOnlyBuilder`).** Typed failures with a
 *    sentence explaining that history is corrected by appending, not by editing.
 *
 * ## Why `tenant_id` carries no foreign key here
 *
 * Every other tenant-owned table gets `ON DELETE CASCADE` from `TenantSchema`. This one
 * deliberately does not, and the reason is the append-only rule itself: a cascade is a
 * `DELETE`, so it would either be blocked by the guards (SQLite *does* fire triggers for
 * cascaded deletes; MySQL does not) or silently punch rows out of an immutable table —
 * and "immutable, except when another table's row goes away" is not an invariant anyone
 * can reason about. It would also make the behaviour differ between the production and
 * test engines, which is the one thing a security control must never do.
 *
 * So audit rows **outlive the tenant they describe** (`tenant_id` becomes a dangling
 * identifier, which is exactly what an audit trail of an offboarding needs), and removing
 * them is an explicit, privileged retention action rather than a side effect: the
 * right-to-delete pipeline (Req 28.2 / D5, task 28.x) purges a departed tenant's chain
 * under a maintenance role. Because chains are **per tenant**, that purge drops exactly
 * one chain and leaves every other chain verifiable — one of the reasons the chain is not
 * global.
 *
 * ## The chain
 *
 * `row_hash = SHA-256(prev_hash || canonical(entry))`, where `canonical()` is
 * `App\Support\Audit\CanonicalSerializer` and `entry` is every stored column except
 * `row_hash` itself. Chaining is per `chain_key` (`tenant_id`, or `platform` for
 * platform-wide actions), and `unique(chain_key, sequence)` is what makes two
 * concurrent appends unable to occupy the same position — the database, not the
 * application, is the final arbiter of chain order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
            |------------------------------------------------------------------
            | Chain identity
            |------------------------------------------------------------------
            | One chain per tenant, plus one for platform-wide actions. `chain_key`
            | is redundant with `tenant_id` by construction (`tenant_id ?? 'platform'`)
            | and stored anyway: it is `NOT NULL`, so it can carry the unique index
            | that prevents two writers taking the same position — something a
            | nullable `tenant_id` cannot do portably (MySQL treats NULLs as
            | distinct in a unique index, so two platform rows could share a
            | sequence).
            */
            $table->string('chain_key', 64);
            $table->unsignedBigInteger('sequence');

            // The `tenant_id` half of the same identity: nullable, because a
            // platform super-admin acting with no tenant bound is a first-class
            // audited actor (Req 1.5 / A1). `TenantSchema` also gives us the
            // leading-tenant index every scoped read needs. `constrained: false`
            // — no cascade into an append-only table; see the class docblock.
            TenantSchema::tenantId($table, ['action', 'created_at'], constrained: false)->nullable();

            /*
            |------------------------------------------------------------------
            | What happened
            |------------------------------------------------------------------
            */
            // Dotted, past-tense, stable: 'tenant.suspended', 'user.impersonated',
            // 'plan.limits.changed', 'platform_mode.entered'.
            $table->string('action', 96);

            // The thing acted upon, as a polymorphic pair (no FK: subjects live in
            // many tables, and an audit row must outlive the row it describes).
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();

            // Redacted before it ever gets here: phone numbers masked, message
            // bodies reduced to content hashes, secrets dropped
            // (`AuditPayloadRedactor`).
            $table->json('payload');

            /*
            |------------------------------------------------------------------
            | Who did it
            |------------------------------------------------------------------
            */
            $table->enum('actor_type', AuditActorType::values());
            $table->string('actor_id', 64)->nullable();
            // Display identity (email / name) captured at write time, so the entry
            // stays readable after the account is renamed or deleted.
            $table->string('actor_label', 190)->nullable();

            /*
            |------------------------------------------------------------------
            | Correlation (panel → job → bridge → webhook, design § Observability)
            |------------------------------------------------------------------
            */
            $table->string('request_id', 64)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            /*
            |------------------------------------------------------------------
            | The chain itself
            |------------------------------------------------------------------
            */
            // 64 hex chars of SHA-256. The first row of a chain links to the fixed
            // genesis hash rather than to NULL, so the verifier has one rule for
            // every position instead of a special case for the first.
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64);

            // Microsecond precision, and part of the hash: an audit trail whose
            // timestamps could be edited unnoticed is not an audit trail. No
            // `updated_at` — the row is never updated.
            $table->dateTime('created_at', 6);

            /*
            |------------------------------------------------------------------
            | Indexes
            |------------------------------------------------------------------
            | Two structural, three for the Admin audit viewer (task 30.6). Kept
            | deliberately few: this table is insert-heavy and never updated, so
            | every extra index is pure write cost.
            */

            // No two rows may claim the same position in a chain — the DB-level
            // guarantee that concurrent appends cannot fork or duplicate the chain.
            $table->unique(['chain_key', 'sequence'], 'audit_logs_chain_position_unique');

            // No row may be replayed into another position: its hash covers its
            // position, so a duplicate hash means a copied row.
            $table->unique('row_hash', 'audit_logs_row_hash_unique');

            // Viewer filters: "everything this admin did", "everything that happened
            // to this tenant/plan/session", and the platform-wide reverse-chronological
            // feed. (Tenant + action + time is covered by TenantSchema above.)
            $table->index(['actor_type', 'actor_id', 'created_at'], 'audit_logs_actor_index');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'audit_logs_subject_index');
            $table->index('created_at', 'audit_logs_created_at_index');
        });

        AppendOnlyTable::guard('audit_logs');
    }

    public function down(): void
    {
        // The guards would not block a DROP, but removing them first keeps the
        // teardown explicit rather than incidental.
        AppendOnlyTable::unguard('audit_logs');

        Schema::dropIfExists('audit_logs');
    }
};
