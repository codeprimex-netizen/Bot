<?php

declare(strict_types=1);

use App\Enums\SagaStepStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered steps of a saga, with their compensations (Req 31.5 / NFR2,
 * Algorithm 8).
 *
 * ## Tenancy: no `tenant_id`, by design
 *
 * A step has no independent existence: it is reached only through its saga, which is
 * tenant-owned and tenant-scoped, and it dies with it (`cascadeOnDelete`). Ownership
 * is therefore transitive and there is exactly one place it is enforced — copying
 * `tenant_id` down here would create a second source of truth that could disagree
 * with the parent, which is a worse failure mode than the one it guards against.
 *
 * The seam that keeps this honest is a rule about *access*, and it is enforced by
 * the model rather than left to reviewers: `SagaStep` has no by-id public accessor
 * and no route binding. Steps are loaded through `$saga->steps` or
 * `$saga->steps()->…`, so the parent's tenant scope has already applied by the time
 * a step row exists in memory. Task 3.5's orchestrator receives a `Saga` and walks
 * its relation; it never queries this table directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignUlid('saga_id')->constrained('sagas')->cascadeOnDelete();

            // 0-based execution order. Named `position` because `index` is a MySQL
            // reserved word; it pairs with `sagas.current_step`.
            $table->unsignedInteger('position');

            // Stable step name — the second half of the forward/compensation
            // idempotency key `{sagaId}:{stepName}`, which is why it is unique per
            // saga below. Renaming a step in a definition is therefore a
            // migration-visible act, not a silent duplicate execution.
            $table->string('name', 96);

            $table->enum('status', SagaStepStatus::values())->default(SagaStepStatus::Pending->value);

            // The two directions of the step, stored separately so an unwind needs
            // nothing but the row: `payload` is what the forward action was called
            // with, `compensation_payload` is what its undo needs (a reservation id
            // to release, a payment link to void). Capturing the compensation input
            // when the forward action *succeeds* is what makes the unwind survive a
            // crash — the orchestrator cannot recompute it after the fact.
            $table->json('payload')->nullable();
            $table->json('compensation_payload')->nullable();

            // Handle for the compensating action (the design's `compensation_ref`):
            // which compensator to invoke, or the external id it must act on.
            $table->string('compensation_ref', 191)->nullable();

            // Counted separately: a step whose forward action succeeded first try but
            // whose compensation has been retried six times is a very different
            // incident from the reverse, and one counter cannot say which happened.
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('compensation_attempts')->default(0);

            $table->text('last_error')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('compensated_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // The idempotency-key invariant: `{sagaId}:{stepName}` identifies at most
            // one step, so the key cannot address two rows.
            $table->unique(['saga_id', 'name'], 'saga_steps_saga_name_unique');

            // Ordering integrity: no two steps of one saga share a slot, so
            // `orderBy('position')` is a total order and `current_step` is
            // unambiguous.
            $table->unique(['saga_id', 'position'], 'saga_steps_saga_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_steps');
    }
};
