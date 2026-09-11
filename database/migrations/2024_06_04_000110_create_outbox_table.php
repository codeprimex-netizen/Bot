<?php

declare(strict_types=1);

use App\Enums\OutboxStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox (Req 31.4 / NFR2, Algorithm 6, Correctness Property 16).
 *
 * A side-effecting intent is written here **in the same transaction** as the state
 * change that caused it, which turns a dual write (commit + call out) into a single
 * atomic one. A relay then delivers each row with its `dedup_key`, so a crash
 * between commit and delivery loses nothing and a redelivery is deduplicated at the
 * consumer: effective exactly-once.
 *
 * ## Tenancy: nullable `tenant_id`, and deliberately **not** `BelongsToTenant`
 *
 * The column exists (attribution, per-tenant cost accounting, and cascade cleanup
 * when a tenant is offboarded — a cancelled tenant's undelivered webhooks must go
 * with it), but the model is a reviewed exemption from the tenant global scope,
 * recorded in `tenantScopeExemptions()` in `TenantOwnedModelsGuardTest`. Two
 * independent reasons, either sufficient:
 *
 * 1. **Null is a legitimate value here, and `BelongsToTenant` cannot produce it.**
 *    Platform-level effects (gateway callback acks, platform notifications) are
 *    enqueued with no tenant at all. The trait's `creating` hook fills `tenant_id`
 *    from the context and throws `MissingTenantContextException` when it cannot — so
 *    on this table the trait would make a legal row *impossible to write*.
 * 2. **The relay is a platform worker with no tenant bound.** Its claim query spans
 *    tenants by design (one worker, one ordered queue). Under a fail-closed scope
 *    every relay tick would throw, so the relay would have to say
 *    `withoutTenantScope()` on every query — a scope that is bypassed 100% of the
 *    time is not isolation, it is ceremony.
 *
 * What replaces the scope: rows are addressed by the relay by `id`/`dedup_key` and
 * never by tenant, and `OutboxMessage::forTenant()` is the one tenant-facing read
 * (the panel's delivery log), which states its tenant explicitly. The payload is
 * also the enqueuer's own already-authorised data, so nothing crosses a boundary
 * here that was not already on the right side of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table): void {
            // Auto-increment rather than ULID: Algorithm 6 claims `ORDER BY id`, and
            // a monotonic integer gives the relay stable FIFO ordering plus the
            // cheapest possible index on the platform's highest-volume queue table.
            $table->id();

            // Nullable: a platform-level effect has no tenant (see the class
            // docblock). The leading index serves the panel's delivery log and the
            // offboarding sweep; the relay uses the claim index below instead.
            TenantSchema::tenantId($table, ['created_at'])->nullable();

            // What changed, and what happened to it. `aggregate_*` ties the effect
            // back to the row whose transaction produced it, which is how an
            // operator answers "why was this webhook sent?".
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->string('event_type', 96);

            // Where it goes: a webhook endpoint id/URL, a queue name, a channel.
            // Nullable because the routing for some event types is resolved by the
            // relay from subscriptions rather than pinned at enqueue time.
            $table->string('destination', 255)->nullable();

            $table->json('payload');

            // The exactly-once hinge (Property 16). Unique, so an enqueue that is
            // itself retried inside a retried transaction cannot create a second
            // row for the same effect; sent to the consumer as a header so it can
            // dedup a redelivery.
            $table->string('dedup_key', 191)->unique('outbox_dedup_key_unique');

            $table->enum('status', OutboxStatus::values())->default(OutboxStatus::Pending->value);
            $table->unsignedInteger('attempts')->default(0);

            // Two distinct clocks, not one duplicated:
            //  - `available_at` is the *intent*: the earliest this effect should ever
            //    be delivered. Set once at enqueue (now, or later for a deferred
            //    effect) and never moved.
            //  - `next_attempt_at` is the *retry gate*: initialised to `available_at`,
            //    then pushed forward by backoff-with-jitter after each failure.
            // The relay only ever tests `next_attempt_at` (a single-column range
            // predicate that the claim index can serve), and keeping `available_at`
            // immutable is what lets an operator tell "delayed by design" apart from
            // "delayed by 9 failed attempts".
            //
            // Both are NOT NULL with a `CURRENT_TIMESTAMP` default: a null retry gate
            // would fall outside the claim's range predicate and strand the row for
            // ever, so "deliver as soon as possible" is spelled `now()`, never null.
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('next_attempt_at')->useCurrent();

            // Truncated by the model on write: enough to diagnose, never a place for
            // a provider to dump a response body into the database.
            $table->text('last_error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // The relay's claim index, matching Algorithm 6's predicate exactly:
            //   WHERE status IN (PENDING, FAILED) AND next_attempt_at <= now()
            //   ORDER BY id LIMIT n FOR UPDATE SKIP LOCKED
            // Equality-ish set on `status` first, then the range on
            // `next_attempt_at`; InnoDB appends the primary key, so the ORDER BY is
            // satisfied by the index too. `SKIP LOCKED` is MySQL-only and compiles
            // away on SQLite (see OutboxMessage::CLAIM_LOCK) — the index is what
            // keeps the claim cheap on both.
            $table->index(['status', 'next_attempt_at'], 'outbox_claim_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
