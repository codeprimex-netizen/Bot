<?php

declare(strict_types=1);

use App\Enums\TenantTier;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_tiers', function (Blueprint $table): void {
            $table->id();

            // Exactly one tier row per tenant: the unique index is what makes
            // "the tenant's tier" a single fact rather than a set of them, and it
            // doubles as the leading tenant index every scoped read uses.
            TenantSchema::tenantId($table, [], 'tenant_tiers_tenant_id_unique', unique: true);

            // Absence of a row means the configured default tier, so this column is
            // never null: a row exists precisely to state something explicit.
            $table->enum('tier', TenantTier::values())->default(TenantTier::Shared->value);

            // Weighted-fair dispatch share (Req 1.7 / A1). Null means "whatever this
            // tier's configured default is", so raising every dedicated tenant's share
            // stays a config flip; a number here pins one tenant against that curve.
            $table->unsignedInteger('lane_weight')->nullable();

            // Geography this tenant's shard, bucket, and (where offered) LLM endpoint
            // are pinned to, e.g. 'eu-central-1' (data residency).
            $table->string('data_region', 32)->nullable();

            // Explicit shard pin, mapped to a database connection through
            // `wa.tenancy.tiers.shard.connections`. Null leaves the tenant on the
            // consistent-hash ring (or the shared connection when no ring is set up).
            $table->string('shard_key', 64)->nullable();

            // Per-tenant connection details for the DEDICATED_DB cutover, e.g.
            // {"connection": "tenant_shard_eu"}. Only the connection *name* lives here;
            // credentials stay in config/database.php, never in a tenant-writable row.
            $table->json('dedicated_conn')->nullable();

            $table->timestamps();

            // The dispatch scheduler and the shard router both sweep by these, across
            // tenants, so neither index leads with tenant_id on purpose.
            $table->index('tier', 'tenant_tiers_tier_index');
            $table->index('shard_key', 'tenant_tiers_shard_key_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_tiers');
    }
};
