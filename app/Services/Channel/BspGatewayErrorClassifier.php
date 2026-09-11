<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Services\Channel\Bsp\BspAdapterRegistry;
use App\Services\Reliability\ErrorClassifier;
use Throwable;

/**
 * Turns a **BSP partner's** refusal into the `ErrorClass` that decides its fate — the
 * `BSP_GATEWAY` link of the classifier chain (design.md § Error Handling; Req 8.1 / A8;
 * Req 31.1 / NFR2).
 *
 * Registered in `wa.reliability.retry.classifiers`, the seam
 * `App\Services\Reliability\ErrorClassifier` documents, beside `BridgeErrorClassifier` (the
 * sidecar) and `CloudApiErrorClassifier` (Meta). This class is deliberately their mirror image in
 * structure and differs from both in exactly one way: it has **eight** code vocabularies to hold
 * apart, not one.
 *
 * ## Two checks before any code is read, and both are the point
 *
 * ```php
 * if (! $e instanceof ChannelRequestFailedException) return null;   // not ours — the chain continues
 * if ($e->mode !== ChannelMode::BspGateway)          return null;   // another official mode's refusal
 * if ($e->provider === null)                         return null;   // a BSP refusal that names no partner
 * ```
 *
 * `ChannelRequestFailedException` is shared by the three official modes, and provider error numbers
 * **collide** — Meta's `4` is an app-level rate limit, MessageBird's `2` is an authentication
 * failure, Twilio's `20003` is the same thing under a different number, and Infobip spells it
 * `UNAUTHORIZED`. So the mode check comes first (`CloudApiErrorClassifier` makes the identical
 * argument in the other direction), and then the **partner** check, because within this one mode the
 * collisions are eight-way.
 *
 * ## One classifier that dispatches, not eight registered classes
 *
 * The alternative — registering one classifier per partner in `wa.reliability.retry.classifiers` —
 * was rejected, and the reasons are concrete:
 *
 * | | one class, dispatching | eight registered classes |
 * |---|---|---|
 * | config | one line, which cannot drift from `BspProvider` | eight lines that must be kept in step with the enum by hand; a ninth partner silently gets no policy and every refusal falls to `default_class` |
 * | work per failure | one mode check, one array lookup | up to eight class instantiations and eight mode checks, seven of which return `null` — and this code runs *while something is already failing* |
 * | completeness | `BspAdapterRegistry` refuses to construct with a partner missing | nothing checks that the eight config entries cover the eight cases |
 * | where a code table lives | beside the partner's request shapes, in its adapter | the same place, but reached through a class whose only content is a mode check |
 *
 * So the **chain protocol** (am I the right classifier? what does the status say?) lives here once,
 * and each partner's **code table** lives in its adapter next to the endpoints and the error
 * envelope it was read from — which is the split that keeps a table honest, since the person adding
 * a partner's route is the person who has its error documentation open.
 *
 * ## The mapping
 *
 * The partner's own code is its precise statement and is consulted **first**, through
 * `Bsp\BspAdapter::classify()`; the HTTP status is its rough one and is the fallback. That order
 * matters here for the same reason it does on Cloud API: several of these partners answer **`400`**
 * or even **`200`** for conditions that are not validation failures at all —
 * `Bsp\Adapters\WatiAdapter` refuses with a `200`, and 360dialog forwards Meta's `130429` throughput
 * limit with Meta's `400`. Reading the status first would fail those sends fast when they would have
 * succeeded a minute later.
 *
 * Each adapter's `classify()` docblock carries its own table. The shared fallback is:
 *
 * | Status | Class | Fate |
 * |---|---|---|
 * | `401` | `AUTH` | **fail fast** — zero attempts, structurally |
 * | `403` | `PERMISSION` | fail fast |
 * | `408`, `504` | `TIMEOUT` | retry |
 * | `429` | `RATE_LIMIT` | **defer**, honouring the partner's `Retry-After` |
 * | `5xx` | `NETWORK` | retry ×5, jittered |
 * | other `4xx` | `VALIDATION` | fail fast |
 * | anything else | `NETWORK` | retry — see below |
 *
 * ## Why an unrecognised refusal is `NETWORK` and not `UNKNOWN`
 *
 * **An unrecognised refusal must keep the work rather than drop it.** A code this release does not
 * recognise came from a partner API newer than this build, or from one of the three partners whose
 * error vocabulary is not publicly enumerated — and neither is evidence that the *request* was
 * wrong. `NETWORK` keeps the message and re-attempts it, where the risk is a duplicate the
 * idempotency key absorbs (`Concerns\DedupesChannelSends`); `UNKNOWN` or a validation verdict would
 * drop a customer's message, which nothing absorbs. `BridgeErrorClassifier` and
 * `CloudApiErrorClassifier` both make this argument, and it is stronger here: with eight partners the
 * odds that a given code is simply unlisted are eight times higher.
 *
 * The one place that is *not* applied is a refusal with a `200` status, which only reaches this class
 * from a partner whose adapter recognised the shape — `WatiAdapter` classifies its own
 * `result: false` as `VALIDATION`, because there is no status for the fallback to read and five
 * retries would put five identical refusals on the partner's account.
 */
final readonly class BspGatewayErrorClassifier implements ErrorClassifier
{
    public function __construct(private BspAdapterRegistry $adapters) {}

    public function classify(Throwable $e): ?ErrorClass
    {
        if (! $e instanceof ChannelRequestFailedException) {
            // Not ours — the chain asks the next classifier. Note that the *unreachable* case is
            // `BridgeUnreachableException`, which `BridgeErrorClassifier` already classifies `BRIDGE`:
            // a partner that never answered is the same situation as a sidecar that never answered,
            // and both must keep the work.
            return null;
        }

        if ($e->mode !== ChannelMode::BspGateway) {
            // Another official mode's refusal, carried in the same shape. Its codes are its own.
            return null;
        }

        $provider = $e->provider;

        if ($provider === null) {
            // A `BSP_GATEWAY` refusal that names no partner cannot be classified without guessing
            // which of eight vocabularies its code belongs to, and guessing wrong retries something
            // terminal or fails fast on something transient. Handed on rather than guessed;
            // `BspGatewayChannelDriver::refusalFor()` always names the partner, so this arm is a
            // guard against a future caller building one without.
            return null;
        }

        return $this->adapters->for($provider)->classify($e) ?? self::fromStatus($e->status);
    }

    /**
     * The HTTP status — every partner's rough statement, read only when its own code says nothing
     * this release knows.
     */
    private static function fromStatus(int $status): ErrorClass
    {
        return match (true) {
            $status === 401 => ErrorClass::Auth,
            $status === 403 => ErrorClass::Permission,
            $status === 408, $status === 504 => ErrorClass::Timeout,
            $status === 429 => ErrorClass::RateLimit,
            $status >= 500 => ErrorClass::Network,
            $status >= 400 => ErrorClass::Validation,
            // Not even a 4xx or 5xx: a partner behaving oddly is a statement about the partner, so the
            // work is kept. See the class docblock.
            default => ErrorClass::Network,
        };
    }
}
