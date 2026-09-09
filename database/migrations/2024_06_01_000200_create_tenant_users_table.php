<?php

declare(strict_types=1);

use App\Enums\TenantRole;
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
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('role', TenantRole::values());
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id'], 'tenant_users_tenant_id_user_id_unique');
            $table->index(['user_id', 'tenant_id'], 'tenant_users_user_id_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
