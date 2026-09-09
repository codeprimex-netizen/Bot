<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Exceptions\Reliability\CircuitOpenException;
use Throwable;

/**
 * Guards an external call — LLM provider, payment gateway, WA bridge — with the
 * per-`(scope, name)` circuit breaker of Algorithm 7 (Req 31.3 / NFR2; Req 13.13 / B4),
 * governed by **Correctness Property 13**.
 *
 * ## Naming: this interface, and the model it is not
 *
 * There are deliberately two `CircuitBreaker`s, one per layer, exactly as design.md names
 * them:
 *
 * | Name | Layer | Answers |
 * |---|---|---|
 * | `App\Services\Reliability\CircuitBreaker` (this) | behaviour | may this call run, and what did it do? |
 * | `App\Models\CircuitBreaker` | persistence | what has happened to this dependency recently? |
 *
 * Renaming either would cost more than it buys: design.md § 5 declares this interface
 * under this name, and task 3.1 shipped the model under that one. They never collide in a
 * single file — implementations import the model **aliased**
 * (`use App\Models\CircuitBreaker as CircuitBreakerRecord;`), which also reads better at
 * the call sites, since a row *is* the breaker's record rather than the breaker itself.
 *
 * ## How to use it
 *
 * ```php
 * // A per-provider, per-tenant breaker — the design's LLM scoping (§1.5).
 * $name = \App\Models\CircuitBreaker::compositeName('openai', $tenantId);
 *
 * try {
 *     $reply = $breaker->call(CircuitScope::Provider, $name, fn () => $openai->complete($prompt));
 * } catch (CircuitOpenException) {
 *     // Not attempted at all — go straight to the next link in the fallback chain.
 * } catch (Throwable $e) {
 *     // The provider was tried and failed; the failure is already recorded on the
 *     // breaker (and may have opened it). Fall back, or surface, as the caller sees fit.
 * }
 * ```
 *
 * Those two `catch` blocks are the whole contract: `CircuitOpenException` means the
 * operation **did not run**, anything else means it ran and threw.
 *
 * ## What this deliberately does not do
 *
 * - **No retries and no backoff.** Attempt policy is `RetryPolicy`'s (task 3.6, the
 *   per-error-class matrix). Compose them rather than merging them:
 *   `$retry->run(fn () => $breaker->call(...))`, with `CircuitOpenException` classified
 *   non-retryable — retrying a fail-fast just burns the caller's budget on a decision that
 *   cannot change within the loop, and falling back is the point.
 * - **No error classification.** Every `Throwable` from the operation counts as a failure
 *   of the dependency. A breaker that second-guessed which failures "really" count would
 *   be a second, hidden retry policy.
 * - **No fallback.** Choosing the next provider, queueing the payment, or reconnecting the
 *   bridge belongs to the caller; the breaker only ever tells it *whether the door is
 *   open*.
 *
 * ## Consumers
 *
 * - **task 16.5 — LLM fallback chain (§1.5).** One `call()` per link, each with its own
 *   breaker: `compositeName($provider, $tenantId)` under `CircuitScope::Provider`, so one
 *   tenant's abuse cannot fence a shared provider off from everybody else. Catch
 *   `CircuitOpenException` → next link; catch anything else → next link (the failure is
 *   already recorded). The local heuristic is the last link and is not guarded.
 * - **task 8.5 — Channel Mode failover.** Uses `allows()` to *choose* a channel without
 *   attempting one, then `call()` on the chosen one.
 * - **bridge reconnect loop.** `CircuitScope::Bridge`, name = session id; the tighter
 *   family row (3 fails / 30s, 15s open, 2 probes) keeps a flapping session from being
 *   hammered while still reconnecting quickly.
 * - **payment gateway calls.** `CircuitScope::Gateway`, name = gateway slug; the 503 and
 *   its `Retry-After` are what the "try again" surface is built from.
 * - **tasks 33.1 / 33.2 — health dashboard and alerts.** Read
 *   `App\Models\CircuitBreaker::query()->unhealthy()` for the fleet-wide view (one indexed
 *   query, no per-breaker calls) and `state()` for a single breaker's live state.
 */
interface CircuitBreaker
{
    /**
     * Run $operation unless the breaker for `(scope, name)` refuses it.
     *
     * The success path records a success (and closes a HALF_OPEN breaker whose probes have
     * proven recovery). The failure path records the failure — rotating the window first,
     * so `failure_count` only ever counts failures inside the current window — opens the
     * breaker if a threshold arm is crossed, and rethrows.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws CircuitOpenException when the breaker is OPEN, or HALF_OPEN with its probe
     *                              budget spent. The operation is **not** invoked.
     * @throws Throwable whatever $operation threw, after the failure is recorded
     */
    public function call(CircuitScope|string $scope, string $name, callable $operation): mixed;

    /**
     * The breaker's current state, without writing anything.
     *
     * An OPEN breaker whose cool-down has elapsed reports `HALF_OPEN`: that is the state
     * the next call will find it in, and reporting `OPEN` would tell a dashboard the
     * dependency is still fenced off when the very next call will probe it. The persisted
     * move is performed by `call()`, which is the only writer.
     *
     * A breaker that has never been exercised has no row and reports `CLOSED`.
     */
    public function state(CircuitScope|string $scope, string $name): CircuitState;

    /**
     * Whether a call would be admitted right now — state **and** probe budget.
     *
     * For callers that must *choose* between guarded dependencies without attempting one
     * (Channel Mode failover, task 8.5). It is a read, so it is advisory: between this
     * answer and a `call()` another worker may claim the last probe, and `call()` will say
     * so. Never use it to decide whether to invoke the dependency yourself — that would
     * move the Property 13 guarantee out of the breaker and into the caller.
     */
    public function allows(CircuitScope|string $scope, string $name): bool;

    /**
     * Force the breaker OPEN — the manual kill-switch.
     *
     * For an operator fencing off a dependency the platform has not (yet) noticed is
     * broken, and for the per-session kill-switch of the ToS enforcement screen. The
     * cool-down starts now, so the breaker recovers on its own exactly as a tripped one
     * does; there is no "held open for ever" state to forget to undo.
     *
     * *Why* it was tripped is not stored here: `circuit_breakers` is a counters table with
     * no free-text column, on purpose (task 3.1). An operator action that trips a breaker
     * is an audited admin action, so the reason belongs in that audit entry (Property 17),
     * where it is tamper-evident — not in a column the breaker itself would overwrite on
     * its next transition.
     */
    public function trip(CircuitScope|string $scope, string $name): void;

    /**
     * Force the breaker CLOSED with a fresh window — the inverse kill-switch.
     *
     * For an operator who knows a dependency is fixed and does not want to wait out the
     * cool-down, and for tests. Deliberately not part of any automatic path: recovery is
     * otherwise only ever *proven* by a probe, never assumed.
     */
    public function reset(CircuitScope|string $scope, string $name): void;
}
