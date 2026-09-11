<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Exceptions\Reliability\UnrecordableResultException;
use Throwable;

/**
 * Generic side-effect dedup: run this operation **at most once** per `(scope, key)`, and
 * hand every later caller the first one's answer (Req 31.2 / NFR2; Req 25.2 / D2;
 * design.md §"Components and Interfaces → 5. Reliability primitives",
 * §"Idempotency everywhere").
 *
 * ```php
 * $outcome = $store->once("gateway:{$provider}", $event->id, fn () => $this->capture($event));
 *
 * return $outcome->isReplay()
 *     ? response()->noContent()          // already processed on an earlier delivery
 *     : response()->json($outcome->value);
 * ```
 *
 * This is the primitive behind the design's *"dedup keys everywhere"*: inbound webhooks on
 * `wa_message_id`, payment gateways on `gateway_event_id` (Req 25.2 — processed exactly
 * once), sends on `idempotency_key`, saga forward and compensation actions on
 * `Saga::stepKey()` / `Saga::compensationKey()` (Algorithm 8), and every other side effect
 * that must not happen twice.
 *
 * ## The insert is the lock
 *
 * There is no coordination service and no advisory lock: dedup is `uniq(scope, key)` on
 * `idempotency_keys`, so a duplicate loses on the unique index. That is what makes the
 * guarantee hold across processes, hosts, restarts, and cache flushes — and hold for two
 * callers that arrive in the same millisecond.
 *
 * ## Scope is where isolation lives
 *
 * `idempotency_keys` is deliberately **not** tenant-scoped (its `tenant_id` is nullable
 * because gateway intake dedups *before* it knows the tenant). So any key whose natural
 * form could collide across tenants must name the tenant in its **scope** — exactly what
 * `QuotaGuard::idempotencyScope()` and `QuotaNotifier::noticeScope()` do:
 * `"{prefix}:{tenantId}:{kind}"`. A key that is globally unique on its own (a gateway event
 * id, a ULID) needs only a subsystem scope. Getting this wrong is the one way to break
 * Property 1 through this class, and it is visible at the call site.
 *
 * ## What happens when things go wrong
 *
 * | Situation | Behaviour |
 * |---|---|
 * | key completed earlier | the recorded result is replayed; the operation does not run (`IdempotencyOutcome::isReplay()`) |
 * | duplicate while the first caller is still running | wait for the caller's budget, then refuse with `OperationInFlightException` (409 + `Retry-After`) — never run the operation twice |
 * | the operation throws | the key is released (`FAILED`, or the claim is rolled back) and the exception propagates unchanged; a retry may run again |
 * | the holder crashed | its lease goes stale after `IdempotencyKey::STALE_LOCK_SECONDS` and the key is retaken |
 * | same key, different request payload | refused with `IdempotencyKeyReuseException` (422) rather than replaying an answer to a different question |
 * | the operation returns something unstorable | the key is settled `COMPLETED` (the side effect happened) and `UnrecordableResultException` is thrown |
 *
 * `IdempotencyOptions` chooses *how* the key is claimed, because the trade-off is the
 * caller's to make and both existing dedup call sites need different answers — see
 * `IdempotencyMode`.
 *
 * ## How the saga orchestrator (task 3.5) should call this
 *
 * ```php
 * // Forward action — an external effect, so the default lease is right.
 * $store->once("saga:{$saga->id}", $saga->stepKey($step), fn () => $step->forward());
 *
 * // Compensation — a *different* key, deliberately.
 * $store->once("saga:{$saga->id}", $saga->compensationKey($step), fn () => $step->compensate());
 * ```
 *
 * The two directions must use `Saga::stepKey()` and `Saga::compensationKey()`
 * respectively, and never the same key: the forward action has already recorded its key
 * `COMPLETED`, so a compensation sharing it would be *replayed* instead of run — every
 * unwind a silent no-op, and Property 18 ("leaving no partial side effect") broken. See
 * `Saga::compensationKey()`, which says the same thing from the other side.
 *
 * Two further notes for that task: a saga is resumed by a worker, so it should pass
 * `IdempotencyOptions::failingFast()` and let the job's backoff absorb a 409 rather than
 * block a worker slot on someone else's lease; and a compensation that must survive a long
 * unwind should be kept at least as long as the saga can stay `COMPENSATING`
 * (`IdempotencyOptions::keptFor()`).
 */
interface IdempotencyStore
{
    /**
     * Run `$op` at most once for `(scope, key)`; replay the recorded result thereafter.
     *
     * @param  string  $scope  dedup namespace — `"gateway:razorpay"`, `"saga:{id}"`, `"{prefix}:{tenantId}:{kind}"` where a key could collide across tenants
     * @param  string  $key  the caller's natural key for this unit of work
     * @param  callable():mixed  $op  the guarded operation; its return value must be JSON-encodable (array, scalar, or null) because it *is* the replay material
     * @param  IdempotencyOptions|null  $options  null for the safe default: an `IN_FLIGHT` lease with the operation run outside any transaction
     *
     * @throws OperationInFlightException 409 — another caller holds the key and did not finish inside the wait budget
     * @throws IdempotencyKeyReuseException 422 — the key was first used with a different request payload
     * @throws UnrecordableResultException the operation succeeded but its result cannot be recorded for replay
     * @throws Throwable whatever `$op` threw, unchanged — the key is left retryable
     */
    public function once(string $scope, string $key, callable $op, ?IdempotencyOptions $options = null): IdempotencyOutcome;

    /**
     * Delete keys past their retention horizon; returns how many rows went.
     *
     * Only `IdempotencyKey::scopePrunable()` rows are eligible, so a row with **no**
     * `expires_at` is never deleted at any age: pruning a completed key silently re-arms
     * the side effect it was recording, because the next retry becomes indistinguishable
     * from a first attempt.
     *
     * @param  int|null  $batch  rows per delete statement; null = configured default
     */
    public function prune(?int $batch = null): int;
}
