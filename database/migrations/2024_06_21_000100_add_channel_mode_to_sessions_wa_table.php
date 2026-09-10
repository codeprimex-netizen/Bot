<?php

declare(strict_types=1);

use App\Enums\ChannelMode;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every session declares exactly one messaging backend (Req 2.6 / A8.1; design § Data
 * Models: *"the reused `sessions_wa` table gains a `channel_mode` (`ChannelMode`) column
 * defaulting to `BAILEYS`, so existing single-tenant/Baileys behaviour is preserved with
 * zero official-API setup"*).
 *
 * The column the creating migration deliberately left out — it said the mode would arrive
 * *"with the enum and the credential tables that give it meaning"*, and this is that
 * migration.
 *
 * ## `NOT NULL DEFAULT 'BAILEYS'` is the whole compatibility story
 *
 * A nullable column would have introduced a fourth answer to "which backend does this
 * session use?" — none — and every reader would then have needed a fallback of its own.
 * With a non-null default there is nothing to fall back to: a row written before this
 * migration, or by code that has never heard of Channel Mode, is a Baileys row, and
 * `ChannelMode::Baileys`'s own predicates reproduce the pre-Channel-Mode behaviour exactly
 * (anti-ban applies, no session window, no template requirement, full capability set).
 * `App\Models\Session` declares the same default in `$attributes`, so an unsaved model
 * answers the same as a stored one.
 *
 * The default is not configurable, and `ChannelMode::default()` is its single source.
 * An operator flag that changed it would move every existing session onto a backend whose
 * credentials nobody has entered — the opposite of the promise above.
 *
 * ## `idx(tenant_id, channel_mode)`
 *
 * The index design.md specifies, and the shape the reads have. Every query is already
 * constrained by `tenant_id` (`TenantScope`), and the questions that then filter by mode
 * are: the panel's per-mode session list, the credential/capability screen ("which of my
 * sessions are on Cloud API?"), and — the one that runs unattended — the sweeps that must
 * treat web-protocol and official modes differently, such as the anti-ban warm-up ramp
 * (task 9.6), which has no business paging through official-mode sessions at all.
 *
 * Declared by hand rather than through `TenantSchema::tenantId()` because the tenancy
 * column already exists; the name still comes from `TenantSchema::indexName()`, so it
 * matches what that helper would have produced and `down()` can drop it by name.
 *
 * ## Stored as a string, like every other enum column on this platform
 *
 * A `string(16)` holding `ChannelMode`'s backed value, not a MySQL `ENUM`. Adding a mode
 * is then a class + an enum case (NFR4), not an `ALTER TABLE` that rewrites a table
 * carrying every tenant's sessions — and the same column shape works on SQLite, which is
 * the test connection. 16 characters fits `BSP_GATEWAY` with room for a longer future
 * value; a value outside the set cannot be read back at all, because the model casts it
 * (`ChannelMode`) and an unknown string is a cast error at the boundary rather than a
 * string no router can dispatch on.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array INDEX_COLUMNS = ['tenant_id', 'channel_mode'];

    public function up(): void
    {
        Schema::table('sessions_wa', function (Blueprint $table): void {
            $table->string('channel_mode', 16)
                ->default(ChannelMode::default()->value)
                ->after('status');

            $table->index(
                self::INDEX_COLUMNS,
                TenantSchema::indexName('sessions_wa', self::INDEX_COLUMNS),
            );
        });
    }

    public function down(): void
    {
        Schema::table('sessions_wa', function (Blueprint $table): void {
            $table->dropIndex(TenantSchema::indexName('sessions_wa', self::INDEX_COLUMNS));
            $table->dropColumn('channel_mode');
        });
    }
};
