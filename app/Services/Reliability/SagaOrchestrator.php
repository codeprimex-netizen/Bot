<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Exceptions\Reliability\InvalidSagaDefinitionException;
use App\Models\Saga;

/**
 * Runs a persisted multi-step transaction so that it either completes entirely or
 * leaves nothing behind (Req 15.5 / B6, Req 31.5 / NFR2, Algorithm 8, Correctness
 * Property 18; design.md §"Components and Interfaces → 5. Reliability primitives").
 *
 * ```php
 * $outcome = $orchestrator->run($saga);
 *
 * $outcome->isCompleted()      // every forward step landed
 * $outcome->isCompensated()    // a step failed; everything completed was undone
 * $outcome->needsAttention()   // a *compensation* failed; the saga still owes work
 * ```
 *
 * ## The guarantee, stated as the caller sees it
 *
 * `run()` is **total and idempotent**. Whatever it is handed — a fresh saga, one that a
 * crashed worker left half-done, one already unwinding, one that finished last week — it
 * returns an outcome and never leaves a completed step's side effect unaccounted for:
 *
 * - a `COMPLETED` or `FAILED` saga is a no-op (`SagaOutcome::isReplay()`);
 * - a `COMPENSATING` saga **resumes its unwind**, and is never restarted forward;
 * - a `RUNNING` saga resumes at its first `PENDING` step — already-`DONE` steps are not
 *   re-executed, and a `FAILED` step anywhere means unwind rather than continue, even if
 *   the saga row itself still says `RUNNING` (the crash window between marking the step
 *   and marking the saga).
 *
 * That is what makes the recovery sweep in `Saga`'s docblock correct, and what makes it
 * safe for a duplicate job delivery to race the original.
 *
 * ## Where a caller's obligations lie
 *
 * A tenant must be bound (`TenantContext`) — sagas are tenant-owned business
 * transactions and every read and write below is scoped. Recovery across tenants uses
 * the sanctioned two-step shape documented on `Saga`: `withoutTenantScope()` to find the
 * work, `runFor()` so the run itself is scoped to the owner.
 *
 * The saga's `type` must be claimed by a registered `SagaDefinition`. Steps do not have
 * to exist yet — the first run lays them out from the definition and persists them
 * *before* executing anything, so a crash between two steps is recoverable from the
 * database alone.
 */
interface SagaOrchestrator
{
    /**
     * Run (or resume, or unwind) this saga to a reported outcome.
     *
     * Does not throw for a business failure: a step that fails produces an unwind and a
     * `SagaOutcome`, because a compensated saga is a handled result rather than an error.
     * Callers that want the loud form ask for it — `SagaOutcome::throwUnlessCompleted()`.
     *
     * @throws InvalidSagaDefinitionException the saga's type has no definition, or its
     *                                        persisted steps no longer match the code
     *                                        that owns them — a deployment error, raised
     *                                        before anything runs
     */
    public function run(Saga $saga): SagaOutcome;
}
