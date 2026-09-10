<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a session's **optional** multi-mode failover chain is stored (Req 8.10, 8.11 / A8;
 * design § Channel Mode 2.6: *"a tenant may configure a chain, e.g. `CLOUD_API` primary →
 * `BAILEYS` fallback"*, opt-in and plan-gated).
 *
 * design.md specifies the *behaviour* of the chain and never says where it lives, so this
 * migration decides — and the decision is deliberately the smallest one that satisfies the
 * requirement.
 *
 * ## An ordered list of modes, on the session
 *
 * Req 8.11 is about *"each distinct driver in the failover chain"* being attempted at most
 * once per dispatch, and Req 8.10 about advancing *"to the next driver in the ordered
 * failover chain"*. Both are statements about an **ordered set of `ChannelMode` values**,
 * which is exactly what this column holds: a JSON array of `ChannelMode` backed values, in
 * attempt order, or `NULL`.
 *
 * On the session rather than on the tenant, because `channel_mode` is per session: a tenant
 * running a Cloud API number and a Baileys number wants the official number to fall back to
 * the unofficial one and — almost certainly — not the reverse, and design.md's own trade-off
 * note says failover *"is best across **different numbers/sessions** of the tenant, not the
 * same number"*. A tenant-level chain could not express that at all.
 *
 * A column rather than a `session_failover_modes` table, because nothing queries it: the
 * chain is read only after the session row it belongs to has already been loaded
 * (`ChannelRouter::failoverChain()`), it is bounded by the number of modes that exist (four),
 * and it has no attributes of its own — no per-hop budget, no per-hop enable flag. A join
 * table would add a write path, a delete path, and an ordering column to store four strings
 * that are always read together with their parent.
 *
 * ## `NULL` is the default, and the default is *no failover*
 *
 * design § 2.6: *"Default is **no failover** (single mode per session) for predictability;
 * failover is an explicit, audited tenant choice."* So the column is nullable with no
 * default, and every session that existed before this migration keeps exactly one driver.
 * `ChannelRouter::failoverChain()` still returns a **one-element** chain for such a session
 * rather than an empty array, so task 8.5's advance loop has no special case for "not
 * configured".
 *
 * ## Nothing here validates the contents, and that is on purpose
 *
 * The column can hold a mode this release does not know, a mode the tenant has since
 * removed its credentials for, a duplicate, or the session's own primary mode. None of those
 * is a database concern and all of them are ordinary states of stored configuration:
 *
 * | Stored oddity | What `ChannelRouter::failoverChain()` does |
 * |---|---|
 * | a value outside `ChannelMode` (older/newer release, hand-edited row) | skipped, never fatal |
 * | the session's own mode, anywhere in the list | ignored; the primary is always the head |
 * | the same mode twice | deduplicated (Req 8.11 — each distinct driver at most once) |
 * | a mode with no usable credentials for this tenant | omitted from the chain |
 *
 * That is why this is a JSON column and not, say, a `SET` or a normalised table with a
 * foreign key: a stored chain that a later release cannot fully interpret must degrade to a
 * shorter chain, never to a read error on a send path. It is also why `channel_mode` — which
 * a router *must* be able to dispatch on — is a strictly cast, non-null column instead.
 *
 * ## No index
 *
 * There is no query shaped "which sessions fall back to `BAILEYS`?". The chain is read by
 * primary key, through a session the caller already holds, and MySQL cannot usefully index
 * a JSON array's membership without a generated column — which would be storage and write
 * cost for a report nobody has asked for. `idx(tenant_id, channel_mode)` from task 6.1 still
 * serves every per-mode sweep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_wa', function (Blueprint $table): void {
            // Ordered `ChannelMode` values, attempt order, fallbacks only — the primary is
            // `channel_mode` and is never stored twice. NULL = no failover.
            $table->json('failover_modes')->nullable()->after('channel_mode');
        });
    }

    public function down(): void
    {
        Schema::table('sessions_wa', function (Blueprint $table): void {
            $table->dropColumn('failover_modes');
        });
    }
};
