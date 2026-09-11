<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the loop the tenants migration left open: `tenants.plan_id` has existed
 * since task 0.1 as an unconstrained ULID column, because `plans` did not exist yet
 * (Req 25.1 / D2).
 *
 * `nullOnDelete` rather than `restrict` or `cascade`: retiring a plan is an ordinary
 * admin action and must never delete tenants, nor be blocked by the tenants still
 * on it. Those tenants fall back to "no plan", which every gate reads as *no
 * features and no allowance* (`PlanGate` denies, `PlanLimits` grants 0) — so a
 * deleted plan degrades to the safe state instead of leaving a dangling id that
 * would read as "plan not found" in one place and "unlimited" in another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Declared explicitly rather than left to the foreign key: MySQL creates a
            // supporting index automatically (and reuses this one), while SQLite — the
            // test connection — creates none, so `$plan->tenants()` and "who is on the
            // plan I am about to retire?" would scan there. Same reasoning as
            // `TenantSchema::tenantId()`.
            $table->index('plan_id', 'tenants_plan_id_index');

            $table->foreign('plan_id', 'tenants_plan_id_foreign')
                ->references('id')
                ->on('plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // By column, not by name: SQLite drops a foreign key by rebuilding the
            // table, which it can only do when it knows the column. The generated name
            // is the one `up()` declared, so both drivers drop the same constraint.
            $table->dropForeign(['plan_id']);
            $table->dropIndex('tenants_plan_id_index');
        });
    }
};
