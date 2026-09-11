<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to send through a channel driver — including the attempts that were
 * **refused before any driver call** (Req 8.3, 8.10, 8.11 / A8; design § Channel Mode data
 * model `channel_send_log`; § Error handling row *"Unsupported op for mode"*: *"logged to
 * `channel_send_log` (BLOCKED); never dispatched"*).
 *
 * ## Why a capability block needs its own table
 *
 * Req 8.3 requires an operation unsupported by a session's mode to be rejected *before* any
 * driver call, with **no side effect**. Taken literally that means there is no message row,
 * no provider id, and no failed HTTP request to look at afterwards: the refusal is
 * invisible. This log is the deliberate exception — the one side effect a block is allowed
 * to have — so that "your group send was refused because this number is on Cloud API" is
 * answerable to the tenant, and so Properties 21 and 26 can assert on *what happened*
 * rather than only on what did not.
 *
 * The same row shape covers the failover audit Req 8.10/8.11 asks for: each attempt in a
 * chain writes its own row, `failover_from` naming the mode the dispatch came from, so
 * "source mode, target mode, trigger reason" is reconstructable per dispatch without a
 * second table. `ChannelSendResult::FailedOver` marks the non-terminal steps.
 *
 * ## Who writes it
 *
 * | Writer | Rows |
 * |---|---|
 * | task 8.1's `channelSendGate` | every `SENT`, every gate `BLOCKED` (capability, template/window, provider rate) |
 * | task 8.5's failover | `FAILED_OVER` per abandoned mode, and the terminal `FAILED` when the chain is exhausted |
 * | tasks 6.3 / 7.2 | the capability refusals raised by `assertSupported()` |
 *
 * Nothing in this phase writes it — the gate does not exist yet. The table and its model
 * arrive here because the migration must precede the writers, and because `route_key`-style
 * decisions (uniqueness, indexes, retention) belong with the rest of the Channel Mode
 * schema rather than being invented mid-pipeline.
 *
 * ## Append-only, and high-volume
 *
 * A row is written once and never updated: there is no `updated_at`, and
 * `App\Models\ChannelSendLog` refuses updates and deletes. The volume is therefore on the
 * order of **one row per outbound message**, which puts it in the same class as `messages`
 * — the tables task 36.1 partitions by month. design.md's partitioning list (`messages`,
 * `messages_inbound`, `event_log`, `flow_analytics_events`, `llm_usage`) does not name this
 * table, which is an omission rather than a decision: it did not exist when that list was
 * written. **Task 36.1 should include `channel_send_log`** in its time-RANGE partitioning
 * and `DROP PARTITION` retention; nothing in this migration prevents that, since the index
 * below already leads with the columns a monthly partition prunes on.
 *
 * ## `unique(tenant_id, idempotency_key)` — a deliberate deviation
 *
 * design.md writes `idempotency_key(uniq)`, i.e. globally unique. Taken literally that is a
 * cross-tenant defect: the key comes from the message (and, through the public API, from the
 * *caller*), so tenant A could insert `msg-1` and make tenant B's `msg-1` send unrecordable
 * — a tenant-visible failure caused by another tenant's choice of string. The platform
 * already solved this exact problem for `idempotency_keys`, whose uniqueness is
 * `(scope, key)` with the tenant embedded in the scope. Per-tenant uniqueness preserves the
 * property the constraint is *for* — one row per attempt, so a retried job cannot double-log
 * — without making one tenant's key space another tenant's business.
 *
 * The column is nullable, because a refusal can precede a message: a group operation blocked
 * by `assertSupported()` has a session and a capability but no message and therefore no
 * idempotency key. NULLs are distinct in a unique index, so those rows coexist freely, which
 * is the behaviour wanted here.
 *
 * ## `idx(tenant_id, session_id, created_at)`
 *
 * design.md's index, and the shape of every read: this tenant's log for this number, newest
 * first (the session's activity panel, an operator explaining a block, the failover audit
 * for one number). Leading with `tenant_id` because `BelongsToTenant` constrains every query
 * by it anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_send_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // idx(tenant_id, session_id, created_at): this number's send history, newest
            // first — and the prefix a monthly partition prunes on (task 36.1).
            TenantSchema::tenantId($table, ['session_id', 'created_at']);

            $table->foreignUlid('session_id')
                ->constrained('sessions_wa')
                ->cascadeOnDelete();

            // `App\Enums\ChannelMode` value — the mode actually attempted, which during a
            // failover is not the session's current mode.
            $table->string('mode', 16);

            // `App\Enums\BspProvider` value; NULL for every mode but BSP_GATEWAY.
            $table->string('provider', 16)->nullable();

            // `App\Enums\ChannelCapability` value — what was being attempted. 32 fits
            // FREE_FORM_ANYTIME with room to spare.
            $table->string('capability', 32);

            // The message's idempotency key. Nullable: a capability refusal can precede any
            // message. Unique per tenant, not globally — see the docblock.
            $table->string('idempotency_key', 190)->nullable();

            // `App\Enums\ChannelSendResult` value.
            $table->string('result', 16);

            // The gate's own reason code on a BLOCKED row (MODE_CAPABILITY,
            // TEMPLATE_REQUIRED, an anti-ban reason). A short code rather than an enum
            // because the gates that produce them belong to later tasks (8.1, 8.4, 9.6) and
            // each owns its own vocabulary; enumerating them here would be guessing at
            // reasons nothing yet raises.
            $table->string('block_reason', 64)->nullable();

            // The provider's own message id, once it has accepted the send.
            $table->string('provider_message_id', 190)->nullable();

            // The mode this attempt was failed over *from*; NULL on a first attempt.
            $table->string('failover_from', 16)->nullable();

            // Append-only: written once, never updated, so there is no `updated_at`.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['tenant_id', 'idempotency_key'],
                'channel_send_log_tenant_idempotency_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_send_log');
    }
};
