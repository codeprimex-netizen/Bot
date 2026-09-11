<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide, admin-editable settings (Req 9.3 / A9; design § Base URL §U.2:
 * *"`platform_settings['base_url']` — an admin-editable override … so ops can change
 * the domain without a redeploy (cache-invalidated on save)"*).
 *
 * ## Platform-owned, not tenant-owned
 *
 * No `tenant_id`, and `App\Models\PlatformSetting` gets no `BelongsToTenant`: a
 * setting here belongs to the deployment, is read by every tenant, and is writable
 * only by a platform admin. The same relationship `plans` has to `tenant_usage` —
 * one shared row, many tenants consuming it. Per-tenant configuration never lands
 * here; it goes in a tenant-owned table with `TenantSchema::tenantId()`.
 *
 * ## Why key/value rather than a column per setting
 *
 * The alternative — one wide `settings` row with a column per knob — makes every new
 * setting a migration, and the admin System-settings screen (task 32.6) exists
 * precisely so an operator can change platform configuration without a deploy. The
 * cost of key/value is that the shape of a value is not enforced by the schema; that
 * is paid back in PHP, where `PlatformSettingObserver` validates a known key on write
 * (a malformed `base_url` is refused at the edit that caused it) and the reader
 * validates again, exactly as `plans.features`/`plans.limits` are handled.
 *
 * | Column | Why |
 * |---|---|
 * | `key` | the stable handle a reader names, e.g. `base_url`. Unique — two rows for one setting has no meaning, and the constraint is what makes "read the setting" a single-row lookup rather than a policy |
 * | `value` | JSON, so a setting can be a string, a number, a flag, or a map without a schema change. Nullable: an explicit null is "configured to nothing" |
 *
 * Task 32.6 builds the admin screen over this table and may add columns of its own
 * (a description, a secret marker for the gateway keys of §Security & Compliance).
 * Nothing here presumes their shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            // ULID rather than an auto-increment id, matching every other table the
            // platform owns; the row is addressed by `key` in practice.
            $table->ulid('id')->primary();

            // `key` is reserved in MySQL. Laravel's schema grammar and Eloquent quote
            // every identifier, so the design's column name is kept as-is — the same
            // choice `plans.interval` already makes.
            $table->string('key')->unique();

            $table->json('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
