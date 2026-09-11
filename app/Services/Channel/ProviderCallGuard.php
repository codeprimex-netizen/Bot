<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelMode;
use App\Enums\CircuitScope;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * One outbound call to an **official** messaging provider, wrapped in the two things every
 * fallible network dependency on this platform gets: a **circuit breaker** and a **bounded
 * inline retry budget** (Req 31.1, 31.3 / NFR2 applied to Req 8.1 / A8; Algorithm 7).
 *
 * ```php
 * // CloudApiChannelDriver, once per Graph API request
 * $response = $this->guard->run(
 *     'cloud_api.message.text',
 *     ProviderCallGuard::breakerName(ChannelMode::CloudApi, $credentials->tenantId),
 *     fn (): Response => $this->post($url, $body, $token),
 *     fn (Throwable $e): Throwable => $this->failureFor('cloud_api.message.text', $e),
 * );
 * ```
 *
 * ## It is `GuardedBridgeClient`, extracted
 *
 * That class is the same composition for the Baileys sidecar, and the ordering it documents is
 * the platform's — breaker **inside** the retry loop, as `RetryPolicy`'s docblock prescribes
 * and as `GuardedKmsClient` and `GuardedDomainProbe` are already built:
 *
 * ```
 *   attempt loop (RetryPolicy: how long to wait, and whether to bother)
 *     └── breaker (CircuitBreaker: may this call run at all?)
 *           └── one HTTP request to the provider
 * ```
 *
 * The reason this is a small collaborator rather than a decorator around a client — the shape
 * `BridgeServiceProvider` composes — is that there is no single client interface to decorate.
 * `CLOUD_API` talks to `graph.facebook.com`, `ON_PREMISE` to a container the tenant runs, and
 * `BSP_GATEWAY` to eight partners with eight auth schemes; the only thing they share is *this
 * policy*. So the policy is the object, and tasks 7.3 and 7.4 take one in their constructors
 * and get the identical breaker family, the identical inline budget, and the identical
 * fail-closed conversion without restating any of it.
 *
 * ## The breaker family, and the design gap it steps around
 *
 * design § 2.6 asks for *"scope `(channel, mode:sessionId)`"*, but `App\Enums\CircuitScope`
 * has no `channel` case, so that scope does not exist in code. Adding one is not this task's:
 * the case is only *needed* by the failover chain of task 8.5, which is the consumer design.md
 * names for it, and a new family also wants a `wa.reliability.circuit.scopes` row of its own.
 *
 * So this uses `CircuitScope::Provider`, which is what `GuardedKmsClient` and
 * `GuardedDomainProbe` already do for non-LLM external dependencies, and whose thresholds are
 * documented as the platform defaults (*"`CircuitScope::Provider` and `::Tenant` are absent on
 * purpose: the defaults **are** the provider row"*). Task 8.5 may introduce
 * `CircuitScope::Channel` and change `breakerName()`; nothing else here moves, because the name
 * is built in exactly one place.
 *
 * ## Per `(mode, tenant)`, not per session — and that is deliberate
 *
 * `GuardedBridgeClient`'s breaker is per **session**, because a Baileys session is one
 * WhatsApp socket that can flap on its own while every other session is healthy.
 *
 * An official provider fails differently. The thing that breaks is the tenant's *account*: an
 * expired system-user token, a revoked WABA, a messaging limit — all of which apply to every
 * session on that credential set at once. Keying the breaker per session would mean N sessions
 * each learning the same outage independently and each spending its own budget discovering it,
 * which is exactly the load-shedding the breaker exists to avoid. Keying it per *mode and
 * tenant* is what `compositeName()` is for, and it keeps one tenant's outage off every other
 * tenant's traffic — the noisy-neighbour bound Req 31.3 asks for.
 *
 * ## Fail closed, in one direction only
 *
 * Every exit that is not a value is a `Throwable` the caller's own `$failureFor` chose, and an
 * open breaker is `BridgeUnreachableException::circuitOpen()` — a 503 classified
 * `ErrorClass::Bridge`, so the work is **kept and re-attempted** rather than dropped or
 * recorded as sent. There is no path through this class that turns a failure into a plausible
 * success.
 *
 * The inline retry does mean a send whose response was lost can reach the provider twice. That
 * is unavoidable for any at-least-once transport, and it is why a driver's `send()` is keyed on
 * an idempotency key (`Concerns\DedupesChannelSends`): losing a send is not recoverable, a
 * duplicate is.
 */
final readonly class ProviderCallGuard
{
    /**
     * Inline attempts per operation, and the longest single inline wait.
     *
     * `GuardedBridgeClient`'s numbers, for its reason: this waits **inside** whatever request
     * or job asked, so the real budget is the queue's (`ErrorClass::RateLimit` gets eight
     * deferred attempts, `Bridge` five) and releasing a job is cheaper and safer than sleeping
     * a worker here.
     */
    public const int DEFAULT_ATTEMPTS = 2;

    public const int DEFAULT_MAX_DELAY_MS = 250;

    public function __construct(
        private CircuitBreaker $breaker,
        private RetryPolicy $retry,
        private int $attempts = self::DEFAULT_ATTEMPTS,
        private int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ) {}

    /**
     * The breaker name for one `(mode, tenant)` pair — built here so there is one spelling.
     *
     * `ChannelMode::webhookSlug()` supplies the mode's segment rather than its enum value: it
     * is already the platform's lowercase, DNS-label-shaped token for the mode (`cloud-api`,
     * `on-premise`, `bsp`) and reusing it means a breaker name a human reads on a health
     * dashboard matches the callback path they see in a panel.
     *
     * The tenant id is the second segment, which is what `CircuitBreakerKey::redacted()`
     * fingerprints — so a breaker for one tenant is correlatable in logs without the id itself
     * being written down.
     */
    public static function breakerName(ChannelMode $mode, string $tenantId): string
    {
        return CircuitBreakerRecord::compositeName($mode->webhookSlug(), $tenantId);
    }

    /**
     * Run one provider call through the breaker, retrying inline only what the retry matrix
     * says is worth retrying.
     *
     * @template TReturn
     *
     * @param  string  $operation  short label for messages — never a URL, never a payload
     * @param  string  $breakerName  from `breakerName()`
     * @param  callable(): TReturn  $call  the request itself
     * @param  callable(Throwable): Throwable  $failureFor  the caller's provider-specific conversion:
     *                                                      what this failure surfaces as
     * @return TReturn
     *
     * @throws Throwable whatever `$failureFor` returned, or `BridgeUnreachableException` when the
     *                   breaker refused the call
     */
    public function run(string $operation, string $breakerName, callable $call, callable $failureFor): mixed
    {
        $attempts = max(1, $this->attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->breaker->call(CircuitScope::Provider, $breakerName, $call);
            } catch (CircuitOpenException) {
                // The door is shut: the provider was **not** called, and no amount of waiting
                // inside this loop opens it. Task 8.5's failover chain is what advances to
                // another mode; this class only ever reports that the door was shut.
                throw BridgeUnreachableException::circuitOpen($operation, $breakerName);
            } catch (Throwable $e) {
                $failure = $failureFor($e);

                if ($attempt >= $attempts) {
                    throw $failure;
                }

                // Decided from the **converted** exception, not the raw one: the classifier
                // chain's opinion about a provider failure lives on the typed exception the
                // driver built from the response (`ChannelRequestFailedException` carries the
                // error code `CloudApiErrorClassifier` reads). The raw `Response` — or a client
                // exception — carries nothing the matrix can key on.
                $decision = $this->retry->decide($failure, $attempt);

                if (! $decision->shouldRetry) {
                    throw $failure;
                }

                $this->wait($decision->delayMs);
            }
        }
    }

    /**
     * Whether a call to `$breakerName` would be admitted right now.
     *
     * Advisory, as `CircuitBreaker::allows()` documents: between this answer and a `run()`
     * another worker may claim the last half-open probe. It exists for the caller that must
     * **choose** between guarded dependencies without attempting one — task 8.5's failover
     * chain — and for a health screen that wants to say "fenced off" without sending traffic.
     */
    public function allows(string $breakerName): bool
    {
        return $this->breaker->allows(CircuitScope::Provider, $breakerName);
    }

    /**
     * Wait out a backoff, clamped so an inline provider call cannot inherit a queue-sized delay.
     *
     * `Illuminate\Support\Sleep` rather than `usleep()` so the wait is assertable in a test
     * instead of real — the same reason `GuardedBridgeClient` uses it.
     */
    private function wait(int $delayMs): void
    {
        $delay = max(0, min($delayMs, max(0, $this->maxDelayMs)));

        if ($delay > 0) {
            Sleep::for($delay)->milliseconds();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'breaker' => CircuitScope::Provider->value.':{mode}:{tenantId}',
            'attempts' => $this->attempts,
            'maxDelayMs' => $this->maxDelayMs,
        ];
    }
}
