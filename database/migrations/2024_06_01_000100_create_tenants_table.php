<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subdomain')->nullable()->unique();
            $table->enum('status', TenantStatus::values())->default(TenantStatus::Trial->value);

            // FK to `plans` is added by the migration that creates `plans`
            // (phase 1, task 2.1); the column exists from the start so tenancy
            // code can read the seeded plan without a second backfill.
            $table->ulid('plan_id')->nullable();

            $table->timestamp('trial_ends_at')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->timestamps();

            $table->index('status', 'tenants_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
