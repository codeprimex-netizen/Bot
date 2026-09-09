<?php

declare(strict_types=1);

use App\Enums\SagaStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A persisted multi-step business transaction (Req 31.5 / NFR2, Algorithm 8,
 * Correctness Property 18).
 *
 * Sagas exist on disk rather than in a job's memory for one reason: a crash
 * mid-flight must resume, and an unwind that is interrupted must continue
 * unwinding. The rows *are* Algorithm 8's `done[]` list.
 *
 * ## Tenancy: fully tenant-owned (`BelongsToTenant`), unlike its siblings
 *
 * This is the one reliability table that holds tenant business content — a saga's
 * `state` carries the order, the amounts, the customer — and every saga belongs to
 * exactly one tenant, so `tenant_id` is **not null** here. That makes the fail-closed
 * global scope correct rather than obstructive: a panel or job reading sagas is
 * already acting for a tenant, and one tenant must never see another's orders
 * (Correctness Property 1).
 *
 * The crash-recovery sweep is the one caller that legitimately spans tenants, and it
 * is written in the sanctioned two-step shape:
 *
 * ```php
 * foreach (Saga::withoutTenantScope()->resumable()->cursor() as $saga) {
 *     $tenants->runFor($saga->tenant, fn () => $orchestrator->run($saga));
 * }
 * ```
 *
 * `withoutTenantScope()` to *find* the work (greppable, reviewable), then
 * `runFor()` so the orchestration itself — and every order/payment row it touches —
 * runs correctly scoped to the owning tenant. The sweep therefore never needs
 * platform mode, and no query inside a saga run is unscoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sagas', function (Blueprint $table): void {
            // ULID: a saga id appears inside idempotency keys
            // (`{sagaId}:{stepName}`), in logs, and in support conversations, so it
            // needs to be non-guessable and safe to quote — while still sorting by
            // creation time, which an opaque UUID would not.
            $table->ulid('id')->primary();

            // Not null: every saga is one tenant's business transaction.
            // idx(tenant_id, status) serves the panel's "in-flight orders" view.
            TenantSchema::tenantId($table, ['status']);

            // Left as a string rather than an enum: the design's `ORDER_FULFILLMENT|...`
            // is open-ended by intent (later phases add drip, export, and integration
            // sagas), and a MySQL ENUM would turn each of those into an ALTER TABLE on
            // a hot table. The orchestrator resolves the type to its step definitions.
            $table->string('type', 64);

            // The business key this saga is *about* (an order id, a payment id). With
            // the unique below it gives natural idempotency: "start the fulfilment
            // saga for order X" twice yields one saga, not two racing unwinds.
            $table->string('correlation_id', 191)->nullable();

            $table->enum('status', SagaStatus::values())->default(SagaStatus::Running->value);

            // The saga's accumulated working state — inputs plus whatever each step
            // hands to the next (payment link, reservation id).
            $table->json('state')->nullable();

            // Index of the step being executed (0-based), so a resumed saga knows
            // where it stopped without replaying every step's status.
            $table->unsignedInteger('current_step')->default(0);

            $table->text('last_error')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // One saga per (tenant, type, business key). MySQL and SQLite both allow
            // repeated NULLs in a unique index, so ad-hoc sagas with no correlation
            // key are unconstrained — exactly the intent.
            $table->unique(
                ['tenant_id', 'type', 'correlation_id'],
                'sagas_tenant_type_correlation_unique'
            );

            // The recovery sweep's index. It runs across tenants, so it cannot use
            // the leading-tenant index above; `updated_at` second lets it prefer the
            // sagas that have been stuck longest.
            $table->index(['status', 'updated_at'], 'sagas_status_updated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sagas');
    }
};
