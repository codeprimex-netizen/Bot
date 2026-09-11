<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Provisioning;

/**
 * One unit of work in tenant provisioning (Req 1.8 / A1; design.md §"Tenant
 * lifecycle" → Provisioning).
 *
 * Req 1.8 names six things a new tenant needs — *tenant record, wallet, default
 * chatbot, per-tenant DEK, storage prefix, seed plan* — and requires all of them
 * **atomically**. Those six belong to five different phases of the build, so the list
 * cannot be a body of code inside `provision()`: whichever phase went first would own
 * a method the other four have to keep editing, and the phase that forgot to edit it
 * would ship a tenant missing a piece with nothing to notice.
 *
 * So the list is data. `wa.tenancy.provisioning.steps` is the ordered pipeline,
 * `TenantProvisioningStepRegistry` resolves it from the container, and
 * `TenantLifecycle::provision()` runs it without knowing what is in it. **A later
 * phase adds a provisioning concern by appending a class to that config array and
 * nothing else.**
 *
 * ## Req 1.8 is not fully satisfied yet, and this is where the debt is recorded
 *
 * Two of the six things Req 1.8 lists have no table yet, so they have no step. They
 * are not stubbed — an empty step class that "creates the wallet" would make the
 * omission invisible, which is the failure mode this registry exists to prevent:
 *
 * | Req 1.8 element | Status | Owed by |
 * |---|---|---|
 * | tenant record | `Steps\CreateTenantRecordStep` | done |
 * | seed plan | `Steps\AssignSeedPlanStep` | done |
 * | per-tenant DEK | `Steps\ProvisionEncryptionKeyStep` | done |
 * | storage prefix | `Steps\EnsureStoragePrefixStep` | done |
 * | **wallet** | **missing** — `wallets` does not exist | **task 10.1 must register a `CreateWalletStep`** |
 * | **default chatbot** | **missing** — `chatbots` does not exist | **task 11.1 must register a `CreateDefaultChatbotStep`** |
 *
 * `TenantProvisioningStepRegistryTest` asserts the currently registered list
 * verbatim, so both rows above are a failing assertion waiting for their phase rather
 * than a line in a document somebody has to re-read.
 *
 * ## Atomicity is a contract between this interface and the pipeline
 *
 * `provision()` runs the whole pipeline inside **one database transaction**, so a
 * step whose only effect is SQL on the default connection needs no `rollback()` — the
 * transaction is its rollback, and its implementation is a no-op.
 *
 * A step that touches anything the database cannot undo — a filesystem directory, a
 * key store, a cache, a remote API — **must** compensate in `rollback()`, and the
 * pipeline calls those compensations in reverse order after the transaction has been
 * rolled back. Two rules follow, and a step that breaks either one breaks Req 1.8's
 * "atomically":
 *
 * 1. **Order side effects late.** Put a step with external effects after the database
 *    steps it depends on, so the common failure (a constraint violation) happens
 *    before anything outside the database has been touched at all.
 * 2. **`rollback()` must be safe to call after a failed `apply()`** — including one
 *    that failed halfway, and including one that never ran to completion. It receives
 *    the same context and must tolerate "there was nothing to undo".
 *
 * ## Why the signature is a context object rather than `(Tenant $tenant, array $spec)`
 *
 * The first step *creates* the tenant, so there is no `Tenant` to pass it; and the
 * last step audits what the others did, so it needs to read their outcomes. A
 * `TenantProvisioningContext` carries both — the validated spec, the tenant once it
 * exists, and the evidence each step records — and keeps `provision()` from growing a
 * side channel to hand values between steps.
 *
 * ## Shape of an implementation
 *
 * ```php
 * final class CreateWalletStep implements TenantProvisioningStep   // task 10.1
 * {
 *     public function name(): string { return 'wallet'; }
 *
 *     public function apply(TenantProvisioningContext $context): void
 *     {
 *         $wallet = new Wallet;
 *         // provision() runs before any tenant context exists: name the tenant.
 *         $wallet->forceFill(['tenant_id' => $context->tenant()->id, 'balance_micros' => 0]);
 *         $wallet->save();
 *
 *         $context->record('wallet', ['currency' => $wallet->currency]);
 *     }
 *
 *     public function rollback(TenantProvisioningContext $context): void
 *     {
 *         // Nothing to do: the row is inside provision()'s transaction.
 *     }
 * }
 * ```
 *
 * Note the explicit `tenant_id`. Provisioning runs **before** any tenant is bound, so
 * a step must never rely on `TenantContext` — `BelongsToTenant` would refuse the write
 * with `MissingTenantContextException`, and every read must name its tenant
 * (`forTenant()`) rather than inherit one.
 */
interface TenantProvisioningStep
{
    /**
     * Short, stable, snake-case identifier for this step — `tenant.record`, `plan`,
     * `encryption_key`, `wallet`.
     *
     * It is not decoration: the names of the steps that ran are written into the
     * `tenant.provisioned` audit entry, so this is how an operator later establishes
     * *which* pipeline a given tenant was created by. Treat a rename as a change to
     * the audit trail's vocabulary.
     */
    public function name(): string;

    /**
     * Do this step's work, or throw.
     *
     * Throwing aborts the whole provisioning: the transaction is rolled back and every
     * step already applied — this one included — is compensated. There is no partial
     * success and no "best effort" outcome to report, which is why this returns
     * `void`; anything worth telling the caller goes into `$context->record()` and
     * ends up in the audit entry.
     */
    public function apply(TenantProvisioningContext $context): void;

    /**
     * Undo whatever this step did that the database transaction cannot undo.
     *
     * Called only when provisioning failed, in reverse application order, **after**
     * the transaction has been rolled back — so the database is already clean and this
     * method exists purely for state outside it. A step with no external effects
     * implements it as an empty method, which documents that fact rather than leaving
     * a reader to work it out.
     *
     * Must not throw: a compensation that fails must not replace the original failure,
     * which is the one the caller needs to see. The pipeline suppresses anything
     * raised here, so an implementation that cannot compensate should log rather than
     * propagate.
     */
    public function rollback(TenantProvisioningContext $context): void;
}
