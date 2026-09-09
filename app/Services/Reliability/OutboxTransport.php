<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use Throwable;

/**
 * How an outbox row actually leaves the platform — the one part of Algorithm 6 that is
 * channel-specific, and therefore the one part behind an interface (Req 31.4 / NFR2;
 * NFR4.2).
 *
 * The relay owns claiming, ordering, the two clocks, backoff, and the dedup header; a
 * transport owns nothing but the call itself. That split is what lets Phase 5+ deliver an
 * effect over a WhatsApp bridge, a queue, or an internal handler without touching a line of
 * the relay — and what lets the relay be tested end to end against a recording fake.
 *
 * ## The contract, which is two sentences long
 *
 * 1. **Return** only when the receiver has accepted the effect. A returning `deliver()` is
 *    what marks the row `SENT`, so returning early — after enqueueing the work somewhere
 *    else, say — converts "delivered at least once" into "possibly never", which is the one
 *    failure mode the outbox exists to remove.
 * 2. **Throw** otherwise, and throw something meaningful. The relay does not interpret the
 *    failure itself: it asks `RetryPolicy::decide()`, which classifies the throwable
 *    (`ErrorClassifier`) and reads the retry matrix. So a transport gets the right backoff
 *    for free by throwing an exception that classifies — `ConnectionException` for an
 *    unreachable receiver, an `HttpExceptionInterface` (like
 *    `OutboxDeliveryException::rejected()`) so the status code decides, or its own typed
 *    exception plus a registered classifier
 *    (`wa.reliability.retry.exceptions` / `.classifiers`).
 *
 * A transport that guards its provider with a `CircuitBreaker` may let
 * `CircuitOpenException` escape: the relay recognises that one specially and reschedules
 * the row **without spending an attempt**, because a call that was never made is not a
 * failed attempt.
 *
 * ## What a transport must *not* do
 *
 * - Retry internally. The relay's backoff is the retry, and a transport that also retries
 *   multiplies the two budgets and holds the claim lease open while it does.
 * - Dedup. That is the consumer's job, on the `X-Dedup-Key` header it is being sent.
 * - Touch the `outbox` table. It is given an `OutboxDelivery`, not a model, on purpose.
 */
interface OutboxTransport
{
    /**
     * Deliver one effect, and return only once the receiver has accepted it.
     *
     * @throws Throwable when the receiver did not accept it
     */
    public function deliver(OutboxDelivery $delivery): void;
}
