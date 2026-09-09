<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted circuit-breaker state (Req 31.3 / NFR2, Algorithm 7).
 *
 * ## Why this table has no `tenant_id` column
 *
 * Breakers *are* partly per-tenant — the design scopes LLM breakers "per provider
 * **and** per tenant" so one abusive tenant cannot fail-fast a shared provider for
 * everybody. That dimension is carried **inside `name`** (`openai:{tenantId}`, see
 * `CircuitBreaker::compositeName()`), not in a scoped column, for three reasons:
 *
 * 1. **The breaker must work when nothing else does.** Its readers are the paths
 *    that run with no tenant bound: the gateway webhook handler (which resolves its
 *    tenant *after* a guarded call), the bridge reconnect loop, the platform health
 *    dashboard. `TenantScope` fails closed with `MissingTenantContextException` when
 *    no tenant is bound — correct for tenant data, but fatal in a reliability
 *    primitive whose entire job is to survive failure. A breaker that throws while
 *    a provider is down is worse than no breaker.
 * 2. **Identity is `(scope, name)`, not `(tenant, …)`.** Two of the four families
 *    (`gateway`, gateway-wide outages; `bridge`, one session) have no meaningful
 *    tenant, and a nullable tenancy column that is null for half the rows buys no
 *    isolation while inviting a scoped query that silently misses them.
 * 3. **The rows are counters, not tenant content.** No message body, contact, or
 *    order detail lives here — only a state, four integers, and timestamps, always
 *    fetched by exact key. There is nothing to leak, and nothing a tenant-facing
 *    screen lists across rows.
 *
 * Per-tenant *visibility* (the panel showing "your provider is degraded") is a read
 * of one known key, so it needs no scope: task 3.2 derives the key from the acting
 * tenant and reads that single row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuit_breakers', function (Blueprint $table): void {
            $table->id();

            // `(scope, name)` is the breaker's identity and the target of the
            // upsert that Algorithm 7 performs on every guarded call, so it is
            // unique — two workers racing to open the same breaker converge on one
            // row instead of forking its counters. 191 chars keeps the composite
            // index inside InnoDB's utf8mb4 key limit with room to spare.
            $table->enum('scope', CircuitScope::values());
            $table->string('name', 191);

            $table->enum('state', CircuitState::values())->default(CircuitState::Closed->value);

            // Counters for the *current* rolling window only; Algorithm 7 rotates
            // the window (resetting both) rather than accumulating for ever, so the
            // error rate it derives describes recent behaviour and not history.
            // Unsigned so the database itself refuses a negative count.
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);

            // Half-open probe accounting: `probes` is the budget spent (capped at
            // the family's probe limit, so an open dependency admits at most N
            // callers), `successes` records how many of them came back healthy.
            $table->unsignedSmallInteger('half_open_probes')->default(0);
            $table->unsignedSmallInteger('half_open_successes')->default(0);

            // Start of the current failure window. Null on a never-exercised
            // breaker: there is no window until the first call.
            $table->timestamp('window_started_at')->nullable();

            // When the breaker opened — the clock the OPEN -> HALF_OPEN check reads
            // (`now() - opened_at >= openDuration`). Null unless OPEN.
            $table->timestamp('opened_at')->nullable();

            // Last outcome timestamps: what an operator actually wants to see next
            // to a red breaker ("failing for 40s" vs "recovered a minute ago").
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();

            $table->timestamps();

            $table->unique(['scope', 'name'], 'circuit_breakers_scope_name_unique');

            // The health dashboard's one cross-cutting query: "what is not closed
            // right now?" — a handful of rows out of a table that is otherwise only
            // ever read by exact key.
            $table->index('state', 'circuit_breakers_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_breakers');
    }
};
