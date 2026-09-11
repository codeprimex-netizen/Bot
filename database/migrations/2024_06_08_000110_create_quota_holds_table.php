<?php

declare(strict_types=1);

use App\Enums\QuotaHoldStatus;
use App\Enums\QuotaKind;
use App\Enums\QuotaReason;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work parked because a plan allowance ran out mid-run, and the promise to hand it back
 * when the allowance returns (Req 3.4 / A3; Req 20.3 / C3; Req 31.1 / NFR2).
 *
 * One row = one **metered, long-running unit of work** that was refused by a *deferrable*
 * quota verdict (`QuotaVerdict::isDeferred()`, i.e. `PERIOD_EXHAUSTED`). Req 20.3 spells
 * the state `QUOTA_PAUSED` for a campaign; this table is that state, generalized so every
 * later subsystem parks work the same way instead of inventing a second mechanism:
 *
 * | Owner | Task | `resumer` | `holdable` |
 * |---|---|---|---|
 * | bulk campaign | 26.2 | `campaign` | the `campaigns` row |
 * | drip sequence enrollment | 27.x | `sequence` | the enrollment row |
 * | contact import batch | 26.5 | `import` | the import row |
 *
 * ## Why the state is a table and not a column
 *
 * A `campaigns.status = QUOTA_PAUSED` column can say *that* a campaign is parked. It
 * cannot say **which** quota parked it, **which period** was exhausted, **when** the
 * allowance is next expected, whether the tenant has been told, or how many times the
 * hand-back has failed — and without those, "auto-resume at the next period reset or
 * after top-up/upgrade" has to be re-derived by every owner, in slightly different ways.
 * Here it is derived once: the sweep re-asks `QuotaGuard::verdict()` (the only authority
 * on allowance) and hands work back through the owner's registered resumer.
 *
 * Task 26.2 still gets its `campaigns.status = QUOTA_PAUSED` for display and for its own
 * invariants — it is the *projection*; this row is the *mechanism*. See
 * `App\Services\Tenancy\QuotaParkingLot` for the exact three lines 26.2 adds.
 *
 * ## Tenancy: fully tenant-owned, swept across tenants the sanctioned way
 *
 * A hold is one tenant's business work, so `tenant_id` is **not null** and the model uses
 * `BelongsToTenant` (fail-closed global scope, Correctness Property 1). The period-reset
 * sweep is the one caller that legitimately spans tenants, and it is written in the same
 * two-step shape the saga recovery sweep uses:
 *
 * ```php
 * foreach (QuotaHold::withoutTenantScope()->claimable()->get() as $hold) {   // find it
 *     $tenants->runFor($hold->tenant, fn () => $parkingLot->resume($hold));  // act on it
 * }
 * ```
 *
 * so the sweep needs no platform mode and no query inside a resume is unscoped.
 *
 * ## Indexes
 *
 * - `idx(tenant_id, status)` — the panel's "what of mine is paused?" list.
 * - `uniq(tenant_id, dedup_key)` — **one hold per unit of work**. Parking the same
 *   campaign twice updates the existing row rather than accumulating duplicates, which is
 *   what makes `QuotaParkingLot::park()` safe to call from every refused job of a
 *   1 000-message campaign.
 * - `idx(status, resume_at)` — the sweep's claim predicate, matching
 *   `QuotaHold::scopeClaimable()` exactly. It runs across tenants, so it cannot use the
 *   leading-tenant index above.
 * - `idx(holdable_type, holdable_id)` — "is this campaign parked?" from the owner's side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_holds', function (Blueprint $table): void {
            // ULID: a hold id is quoted in support conversations and appears in tenant
            // notifications, so it must be non-guessable while still sorting by creation.
            $table->ulid('id')->primary();

            // Not null: parked work always belongs to exactly one tenant.
            TenantSchema::tenantId($table, ['status']);

            // Which allowance ran out, and which bucket of it. The period key is kept
            // because "resumes when the period rolls" is only meaningful relative to the
            // period that was exhausted — and because a tenant-facing notice quotes it.
            $table->enum('quota_kind', QuotaKind::values());
            $table->string('period_key', 32);

            // Why it parked. Constrained to the same enum `QuotaGuard` produces, so the
            // sweep can ask `QuotaReason::isTransient()` instead of storing a duplicate
            // boolean that could disagree with it. Only a transient reason ever gets
            // written here (`QuotaParkingLot::park()` refuses the rest), but the column
            // is updated by the sweep when a re-check finds a *non*-transient refusal —
            // that is how "upgrade needed" becomes visible to an operator.
            $table->enum('reason', QuotaReason::values());

            // How much the refused work needs, so the re-check asks the same question the
            // original one did: a batch of 400 messages must not resume on an allowance
            // with 3 units left.
            $table->unsignedInteger('units')->default(1);

            $table->enum('status', QuotaHoldStatus::values())->default(QuotaHoldStatus::QuotaPaused->value);

            // The parked work itself, as a polymorphic reference. Nullable because a unit
            // of work need not be a row (a queued batch identified only by a key), in
            // which case `dedup_key` is the whole identity.
            $table->string('holdable_type', 191)->nullable();
            $table->string('holdable_id', 64)->nullable();

            // Which registered handler hands the work back
            // (`wa.tenancy.quota.holds.resumers`). Nullable = "just announce it": the
            // resume event alone is the hand-back, which is enough for owners that
            // re-check their own state.
            $table->string('resumer', 64)->nullable();

            // Whatever the resumer needs and cannot re-derive (a batch cursor, a chunk
            // offset). Deliberately small: the payload is a pointer, not a copy of the
            // work.
            $table->json('payload')->nullable();

            // The identity of the unit of work within its tenant — see the unique index.
            $table->string('dedup_key', 191);

            // The earliest moment it is worth re-asking `QuotaGuard`. Set from the
            // verdict's own `secondsUntilPeriodReset()` at park time, and **pulled
            // forward to now** by anything that can return the allowance early (a plan
            // upgrade, a wallet top-up). One clock rather than two: a top-up does not
            // need a second column, it needs this one to say "now".
            $table->timestamp('resume_at')->useCurrent();

            $table->timestamp('paused_at')->useCurrent();

            // The claim lease (see `QuotaHoldStatus::Resuming`). `claimed_by` is a worker
            // identity kept purely so an operator can tell *which* box is sitting on a
            // stuck hold.
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by', 64)->nullable();

            $table->timestamp('resumed_at')->nullable();

            // When the tenant was last told about *this* hold (Req 3.4's "notify the
            // tenant"). The cross-hold deduplication — one notice per tenant per quota
            // per period, not one per parked job — lives in `idempotency_keys`; see
            // `QuotaNotifier`.
            $table->timestamp('notified_at')->nullable();

            // How many times a hand-back has been attempted and failed, and why. A hold
            // whose resumer keeps throwing stays parked (never dropped) and becomes
            // visible here rather than silently spinning.
            $table->unsignedInteger('resume_attempts')->default(0);

            // How many times this same unit of work has been parked. An operator reading
            // "3" knows the campaign is bigger than the plan, not that the sweep is
            // broken.
            $table->unsignedInteger('pause_count')->default(1);

            $table->text('last_error')->nullable();

            $table->timestamps();

            // One hold per unit of work per tenant. Re-parking after a resume re-opens
            // *this* row (new period, new `resume_at`, `pause_count + 1`) rather than
            // writing a second one, so "is this campaign parked?" is always one row.
            $table->unique(['tenant_id', 'dedup_key'], 'quota_holds_tenant_dedup_unique');

            // The sweep's claim index, matching `scopeClaimable()`:
            //   WHERE (status = QUOTA_PAUSED AND resume_at <= now)
            //      OR (status = RESUMING AND claimed_at <= now - lease)
            $table->index(['status', 'resume_at'], 'quota_holds_claim_index');

            // "Is this campaign parked?", asked from the campaign's side.
            $table->index(['holdable_type', 'holdable_id'], 'quota_holds_holdable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_holds');
    }
};
