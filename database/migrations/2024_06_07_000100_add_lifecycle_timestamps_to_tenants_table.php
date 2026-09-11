<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the current lifecycle state began (Req 1.1 / A1; design.md §"Tenant lifecycle").
 *
 * `status` alone says *what* a tenant is, and the audit trail says who changed it and
 * why — but neither is queryable as "how long has this been true?", and offboarding
 * needs exactly that: the design ends the lifecycle at *hard-delete after the retention
 * window*, so the purge job (task 34.3) has to be able to select cancelled tenants
 * whose window has closed without walking a hash chain.
 *
 * Two columns, both nullable, both written only by `TenantLifecycle`:
 *
 * - `suspended_at` — start of the *current* suspension, cleared on reactivation, so a
 *   panel can show "suspended 12 days ago" and dunning can escalate on age.
 * - `cancelled_at` — the retention clock. The purge *due date* is deliberately not
 *   stored: it is `cancelled_at + wa.tenancy.lifecycle.retention_days`, so changing the
 *   policy applies to tenants already in the window instead of needing a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('trial_ends_at');
            $table->timestamp('cancelled_at')->nullable()->after('suspended_at');

            // Task 34.3's purge scan: cancelled tenants ordered by how long ago they
            // were cancelled. Cheap because the column is null for every live tenant.
            $table->index('cancelled_at', 'tenants_cancelled_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex('tenants_cancelled_at_index');
            $table->dropColumn(['suspended_at', 'cancelled_at']);
        });
    }
};
