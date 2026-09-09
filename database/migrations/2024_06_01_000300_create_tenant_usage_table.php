<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('kind', QuotaKind::values());

            // '2025-06' (monthly) / '2025-06-14' (daily) / 'CURRENT' (gauge kinds).
            $table->string('period_key', 32);

            // Unsigned so the database itself refuses a negative counter
            // (Correctness Property 4: quota never negative).
            $table->unsignedBigInteger('used')->default(0);
            $table->unsignedBigInteger('limit')->default(0);
            $table->timestamps();

            // One counter row per tenant + quota + period: the target of the
            // atomic upsert QuotaGuard::consume() performs under a cache lock.
            $table->unique(['tenant_id', 'kind', 'period_key'], 'tenant_usage_tenant_kind_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage');
    }
};
