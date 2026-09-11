<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp sessions — the **reused engine table**, made tenant-owned (Req 2.1–2.6 / A2;
 * design.md § Data Models: *"the 22 existing engine tables are reused"*).
 *
 * The column list comes from the single-tenant engine's design
 * (`.kiro/specs/whatsapp-auto-messenger/design.md` § Data Models) with exactly one structural
 * change — `tenant_id`, and the uniqueness rules that follow from it.
 *
 * ## Why the table is called `sessions_wa`
 *
 * Laravel's database session driver owns `sessions`. The engine's design records the same
 * reason, and the name is load-bearing for two later phases: `channel_webhook_routes`
 * (task 6.1) and `conversations` (task 11.1) both declare
 * `session_id -> sessions_wa`, so the table name and the primary-key type below are a
 * published contract, not an implementation detail.
 *
 * ## The primary key is a ULID, and that is what makes the Bridge tenant-blind
 *
 * design.md § Bridge multi-tenancy: *"Sessions are already keyed by an opaque `sessionId`
 * (ULID); the platform simply guarantees every `sessionId` maps to exactly one tenant."* Two
 * properties of the key carry that guarantee:
 *
 * 1. **Opaque.** A ULID reveals nothing about its owner, so a session id can be handed to a
 *    process that has no tenant concept (the Node sidecar) and used as a directory name on
 *    disk without leaking anything.
 * 2. **Not guessable in bulk.** An auto-increment id would let one tenant enumerate the
 *    platform's whole session space and probe for ids to reach; row-level isolation would
 *    still refuse each attempt, but the attempts would be free to construct.
 *
 * The mapping is one-way and total: `tenant_id` is `NOT NULL` with a cascading foreign key, so
 * every session has exactly one owner and offboarding a tenant removes its sessions.
 *
 * ## Uniqueness of `name`: per tenant, never global
 *
 * The engine had `name(uniq)` — one namespace, one operator. On a shared-schema platform that
 * would be a cross-tenant information leak *and* a denial of service: "Main" would be taken
 * for everybody once one tenant used it, and the refusal would tell that tenant somebody else
 * exists. `unique(tenant_id, name)` is the multi-tenant form of the same intent.
 *
 * ## Indexes
 *
 * - `idx(tenant_id, status)` — from `TenantSchema::tenantId()`. Every read is already
 *   constrained by `tenant_id` (`TenantScope`), and the second-most-common predicate is the
 *   status: the pool query that picks a healthy session to send from, and the sweep that finds
 *   sessions needing a reconnect. This is the engine's `idx(status)` with the tenancy column in
 *   front of it, which is the only useful shape here.
 * - `unique(tenant_id, name)` — the constraint above; also serves "this tenant's sessions,
 *   alphabetically" in the panel.
 *
 * Task 6.1 adds `channel_mode` and its own `idx(tenant_id, channel_mode)`; this migration
 * deliberately does not, so that the default-`BAILEYS` column arrives with the enum and the
 * credential tables that give it meaning.
 *
 * ## Soft deletes, because credentials outlive the row
 *
 * `deleted_at` is the engine's own column and it earns its place: deleting a session means
 * unlinking a device and destroying an auth-state directory, and the row has to survive long
 * enough for the messages that reference it to keep resolving. The hard delete belongs to
 * tenant offboarding (task 34.3), which verifies the auth-state files are gone as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions_wa', function (Blueprint $table): void {
            // Opaque, per the Bridge contract above. Also the auth-state directory name and the
            // circuit-breaker key (`CircuitScope::Bridge`), so it must be filesystem-safe —
            // which base32 ULIDs are.
            $table->ulid('id')->primary();

            // idx(tenant_id, status): this tenant's sessions, filtered by connection state —
            // the pool query and the reconnect sweep.
            TenantSchema::tenantId($table, 'status');

            // The tenant's own label for the number ("Support", "Sales"). Per-tenant unique, see
            // the docblock.
            $table->string('name', 120);

            // Known only once pairing completes, which is why it is nullable: a session exists
            // before anybody knows which number will answer it. Digits only, E.164, no
            // separators — the bridge client normalises to that shape on the way out.
            $table->string('phone', 20)->nullable();

            // The WhatsApp account's own display name and multi-device slot, as reported by the
            // protocol. Diagnostics, and the "replaced by another client" explanation.
            $table->string('push_name')->nullable();
            $table->string('device_id')->nullable();

            $table->string('status', 32)->default(SessionStatus::Initializing->value);

            // Pointer to the auth-state directory (`tenants/{tenantId}/{sessionId}` on the
            // `wa_auth` disk). Derived rather than authoritative — `TenantStorage` computes the
            // path from the tenant and the id — but stored so an operator can find a session's
            // credentials from the row, and so a future change of layout is auditable.
            $table->string('auth_ref')->nullable();

            /*
             * Anti-ban and pool-selection state (Req 4.1–4.4 / A4, enforced by task 9.6).
             *
             * These are *data*, not policy: the warm-up ramp, the gaussian delay window, the
             * daily cap and the risk-driven throttle all read them, and none of that logic
             * exists yet. They live here rather than in a later migration because they are part
             * of the engine table this migration reuses, and because a send pipeline that had
             * to ALTER the sessions table to gain a delay window would be redesigning the table
             * rather than using it.
             */
            $table->timestamp('warmup_start_at')->nullable();
            $table->unsignedInteger('daily_quota')->default(0);
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedInteger('delay_min_ms')->default(0);
            $table->unsignedInteger('delay_max_ms')->default(0);
            $table->unsignedInteger('sent_today')->default(0);

            // How many times this session has had to reconnect. The risk score reads it, and an
            // operator reads it to spot a number the network keeps dropping.
            $table->unsignedInteger('reconnects')->default(0);

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The multi-tenant form of the engine's `name(uniq)`. Leads with `tenant_id`, so it
            // is also the index behind "this tenant's sessions by name".
            $table->unique(['tenant_id', 'name'], 'sessions_wa_tenant_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions_wa');
    }
};
