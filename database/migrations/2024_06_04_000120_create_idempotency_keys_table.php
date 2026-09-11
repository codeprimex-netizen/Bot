<?php

declare(strict_types=1);

use App\Enums\IdempotencyState;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic side-effect dedup ledger behind `IdempotencyStore::once(scope, key)`
 * (Req 31.2 / NFR2).
 *
 * `uniq(scope, key)` is the whole mechanism: the insert *is* the lock. A duplicate
 * caller loses the race on the unique index rather than on an advisory lock, so
 * dedup holds across processes, hosts, and restarts without any coordination
 * service — and holds even if two callers arrive in the same millisecond.
 *
 * ## Tenancy: nullable `tenant_id`, and deliberately **not** `BelongsToTenant`
 *
 * Same reviewed exemption as `outbox`, for the same two reasons, recorded in
 * `tenantScopeExemptions()` in `TenantOwnedModelsGuardTest`:
 *
 * 1. **Null is legitimate and the trait cannot write it.** The busiest caller is
 *    payment-gateway webhook intake, which dedups on `gateway_event_id` *before* it
 *    knows which tenant the event belongs to — that lookup is the very thing that
 *    resolves the tenant. Under `BelongsToTenant` the row could not be written at
 *    all (`MissingTenantContextException`), so the dedup that Req 31.2 mandates
 *    would fail exactly where it is most needed.
 * 2. **Isolation is already in the key.** `scope` names the namespace
 *    (`gateway:razorpay`, `saga:{sagaId}`) and, where a key could otherwise collide
 *    across tenants, the caller puts the tenant id in the scope. Uniqueness is
 *    therefore explicit and reviewable at the call site instead of depending on an
 *    implicit `WHERE tenant_id = ?` that a null-tenant row would fall outside of.
 *
 * The column is still worth keeping: it attributes a key to its tenant for
 * debugging and cost, and it cascades on offboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();

            // Nullable — pre-tenant-resolution webhook intake writes null here.
            TenantSchema::tenantId($table, ['scope'])->nullable();

            // The namespace, so two subsystems can use the same natural key without
            // colliding: 'gateway:razorpay', 'wa:inbound', 'saga:{id}'.
            $table->string('scope', 96);

            // The caller's natural key: a gateway event id, a WA message id, a
            // `{sagaId}:{stepName}` pair. `key` is a MySQL reserved word, so it is
            // only ever referenced through the query builder / Eloquent (which quote
            // identifiers) and never in raw SQL.
            $table->string('key', 191);

            $table->enum('state', IdempotencyState::values())->default(IdempotencyState::InFlight->value);

            // Replay material for a duplicate caller. `result` is the payload the
            // first caller's operation returned, replayed verbatim so a retried
            // webhook gets the same answer as the original; `response_hash` is the
            // design's compact fingerprint of it, cheap to compare and safe to log.
            $table->json('result')->nullable();
            $table->string('response_hash', 64)->nullable();

            // Fingerprint of the *request*. A caller reusing one key for two
            // different payloads is a client bug, not a duplicate, and this is what
            // lets task 3.4 detect it and refuse instead of silently replaying an
            // answer to a question that was never asked.
            $table->string('request_fingerprint', 64)->nullable();

            // Lease held by the caller currently running the operation. Its age is
            // how a crashed holder is told from a slow one.
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Pruning horizon. Nullable means "keep until pruned by policy"; the
            // index is on the raw column so the pruner's range delete is indexed and
            // does not scan what is, in steady state, the largest table here.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['scope', 'key'], 'idempotency_keys_scope_key_unique');
            $table->index('expires_at', 'idempotency_keys_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
