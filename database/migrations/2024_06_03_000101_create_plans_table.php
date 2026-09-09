<?php

declare(strict_types=1);

use App\Enums\BillingInterval;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plan catalogue (Req 25.1 / D2) — what a tenant may do (`features`) and how
 * much of it (`limits`), read by `PlanGate` and `QuotaGuard`.
 *
 * Platform-level, **not** tenant-owned: a plan is shared by every tenant on it, so
 * it gets no `tenant_id` and its model gets no `BelongsToTenant`. Compare
 * `tenant_usage`, which is the per-tenant *consumption* of these limits.
 *
 * The two JSON columns are deliberately schemaless-in-SQL and validated in PHP
 * (`App\Support\Billing\PlanFeatures` / `PlanLimits`): a new feature gate or quota
 * kind is a data edit in the admin panel, not a migration, while a malformed map
 * still fails loudly rather than granting access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            // ULID rather than an auto-increment id: `tenants.plan_id` and
            // `subscriptions.plan_id` are ULIDs, and plan ids appear in gateway
            // metadata and checkout URLs where a guessable sequence leaks the size of
            // the catalogue.
            $table->ulid('id')->primary();

            $table->string('name');

            // The stable handle used by seeders, config (`wa.tenancy.default_plan_slug`)
            // and support tooling — unique so those references can never be ambiguous.
            $table->string('slug')->unique();

            // Minor units (cents) so money is never a float.
            $table->unsignedInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');

            // `interval` is a reserved word in MySQL; Eloquent and the schema grammar
            // always quote identifiers, so the design's column name is kept as-is.
            $table->enum('interval', BillingInterval::values())->default(BillingInterval::Month->value);

            // {"ai": true, "campaigns": false, ...} — feature flags for PlanGate.
            $table->json('features');

            // {"MESSAGES_MONTHLY": 25000, "SESSIONS": null, ...} — QuotaKind => int,
            // null meaning unlimited. Read by QuotaGuard.
            $table->json('limits');

            $table->boolean('active')->default(true);

            // Display order on the pricing/upgrade screens.
            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();

            // design.md's `idx(active)`: the "is this plan still sellable?" filter that
            // every catalogue read starts with.
            $table->index('active', 'plans_active_index');

            // The sort index — the actual pricing-page query is
            // `where active = 1 order by sort`, which this serves end to end (filter
            // *and* order) instead of sorting a filtered set in memory.
            $table->index(['active', 'sort'], 'plans_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
