<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table): void {
            $table->id();

            // A user may belong to several tenants, with a different role in each.
            // Platform super-admins have no row here at all — they operate through
            // the audited actingAsPlatform() context.
            //
            // uniq(tenant_id, user_id) keeps that at most one row per pair and doubles
            // as the leading tenant index. Note this table is deliberately *not*
            // BelongsToTenant — see the TenantUser model for why.
            TenantSchema::tenantId($table, 'user_id', 'tenant_users_tenant_id_user_id_unique', unique: true);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('role', TenantRole::values());
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // The other direction: "which tenants can this user act in?" — the query
            // tenant resolution issues on every panel request.
            $table->index(['user_id', 'tenant_id'], 'tenant_users_user_id_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
