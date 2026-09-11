<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\CircuitScope;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * A `BridgeClient` wrapped in the two things every fallible network dependency on this
 * platform gets: a **circuit breaker** and a **bounded retry budget** (Req 31.1, 31.3 / NFR2
 * applied to Req 2.9 / A2).
 *
 * Composed exactly as `RetryPolicy`'s docblock prescribes, breaker innermost — the same shape
 * as `GuardedKmsClient` and `GuardedDomainProbe`:
 *
 * ```
 *   attempt loop (RetryPolicy: how long to wait, and whether to bother)
 *     └── breaker (CircuitBreaker: may this call run at all?)
 *           └── HttpBridgeClient (one HTTP request to the sidecar)
 * ```
 *
 * ## The breaker is per **session**, and that is the isolation guarantee
 *
 * `CircuitScope::Bridge`'s `name` is the session id — the scoping that enum documents
 * (*"`bridge` | `{sessionId}` | one WA bridge session"*). One tenant's session flapping through
 * a reconnect loop therefore fences off **that session** and nothing else: every other
 * tenant's sends keep flowing through their own breakers. A single platform-wide bridge breaker
 * would have made one tenant's bad number an outage for everybody, which is precisely the
 * noisy-neighbour failure row-level isolation exists to prevent.
 *
 * A session id already belongs to exactly one tenant, so no tenant dimension has to be encoded
 * into the name — `CircuitScope::isTenantPartitioned()` returns false for `bridge` for that
 * reason.
 *
 * ## What counts as a failure, and what does not
 *
 * | Outcome | Breaker | Retried inline? |
 * |---|---|---|
 * | `BridgeUnreachableException` — never answered | **failure** | yes, within the budget |
 * | `BridgeRequestFailedException` where the session has no socket | **failure** | yes: the sidecar is up but this session is not, and the reconnect loop may land between attempts |
 * | any other `BridgeRequestFailedException` (401, 422 `not_on_whatsapp`, …) | failure recorded, rethrown as-is | **no** — the matrix says fail fast, and a second identical request cannot change the answer |
 * | `CircuitOpenException` | not attempted | **no** — a breaker's decision cannot change inside the loop |
 *
 * The third row is the one worth being careful about: a 401 or a `not_on_whatsapp` is *not* a
 * failure of the dependency in any useful sense, but `CircuitBreaker::call()` counts every
 * throwable by design (see its docblock: a breaker that second-guessed which failures "really"
 * count would be a second, hidden retry policy). The retry decision is where the distinction is
 * made, and it is made by `RetryPolicy` reading `BridgeErrorClassifier` — one policy, one place.
 *
 * ## Why the inline retry is deliberately tiny
 *
 * This waits **inside** whatever request or job asked, so the budget is capped twice: `attempts`
 * (default 2) and `max_delay_ms` (default 250 ms per wait). The real budget is the queue's —
 * `ErrorClass::Bridge` gets five attempts on jittered backoff, and releasing the job is both
 * cheaper and safer than sleeping a worker here. `Illuminate\Support\Sleep` rather than
 * `usleep()` so the wait is assertable in tests instead of real.
 *
 * ## Fail closed, and never twice for a send
 *
 * Every exit that is not a value is one of the two bridge exceptions; there is no path through
 * this class that turns a failure into a plausible success. The inline retry does mean a send
 * whose response was lost can be put on the wire twice — that is unavoidable for any
 * at-least-once transport, and it is why the send pipeline keys sends on an idempotency key
 * (task 9.3) rather than on hope. Losing a send is not recoverable; a duplicate is.
 */
final readonly class GuardedBridgeClient implements BridgeClient
{
    /**
     * Inline attempts per operation, and the longest single inline wait.
     */
    public const int DEFAULT_ATTEMPTS = 2;

    public const int DEFAULT_MAX_DELAY_MS = 250;

    /**
     * Breaker name for the operations that name no session — today, only the health probe.
     *
     * Deliberately not a session id: a reachability probe is about the *process*, and giving it
     * a per-session breaker would let one session's history suppress the platform's ability to
     * ask whether the bridge is up at all.
     */
    public const string TRANSPORT_BREAKER = '_transport';

    public function __construct(
        private BridgeClient $inner,
        private CircuitBreaker $breaker,
        private RetryPolicy $retry,
        private int $attempts = self::DEFAULT_ATTEMPTS,
        private int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle
    |--------------------------------------------------------------------------
    */

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        return $this->guarded(
            'session.provision',
            $sessionId,
            fn (): SessionInitDto => $this->inner->provisionSession($sessionId, $method, $phone, $authStateDir),
        );
    }

    public function startSession(string $sessionId): void
    {
        $this->guarded('session.start', $sessionId, function () use ($sessionId): bool {
            $this->inner->startSession($sessionId);

            return true;
        });
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->guarded('session.stop', $sessionId, function () use ($sessionId, $logout): bool {
            $this->inner->stopSession($sessionId, $logout);

            return true;
        });
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        return $this->guarded(
            'session.state',
            $sessionId,
            fn (): SessionStateDto => $this->inner->sessionState($sessionId),
        );
    }

    public function qr(string $sessionId): ?string
    {
        return $this->guarded('session.qr', $sessionId, fn (): ?string => $this->inner->qr($sessionId));
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        return $this->guarded(
            'session.pairing_code',
            $sessionId,
            fn (): string => $this->inner->pairingCode($sessionId, $phone),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Messaging
    |--------------------------------------------------------------------------
    */

    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        return $this->guarded(
            'message.text',
            $sessionId,
            fn (): SentMessageDto => $this->inner->sendText($sessionId, $jid, $text, $opts),
        );
    }

    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        return $this->guarded(
            'message.media',
            $sessionId,
            fn (): SentMessageDto => $this->inner->sendMedia($sessionId, $jid, $media, $opts),
        );
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->guarded('presence.update', $sessionId, function () use ($sessionId, $jid, $presence): bool {
            $this->inner->sendPresence($sessionId, $jid, $presence);

            return true;
        });
    }

    public function checkNumbers(string $sessionId, array $numbers): array
    {
        /** @var array<array-key, NumberCheck> $checks */
        $checks = $this->guarded(
            'numbers.check',
            $sessionId,
            fn (): array => $this->inner->checkNumbers($sessionId, $numbers),
        );

        return $checks;
    }

    /*
    |--------------------------------------------------------------------------
    | Transport health
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the bridge is answering — `false` rather than an exception, and `false` without a
     * call when the transport breaker is open.
     *
     * Not retried: a probe that retried would report "up" for a bridge that answered one call in
     * three, and the dashboard's job is to show that as degraded, not as healthy.
     */
    public function isReachable(): bool
    {
        try {
            return (bool) $this->breaker->call(
                CircuitScope::Bridge,
                self::TRANSPORT_BREAKER,
                fn (): bool => $this->inner->isReachable(),
            );
        } catch (Throwable) {
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Run one bridge operation through this session's breaker, retrying only what the matrix
     * says is worth retrying.
     *
     * @template TReturn
     *
     * @param  string  $operation  short label for messages — never a URL, never a payload
     * @param  callable(): TReturn  $call
     * @return TReturn
     *
     * @throws BridgeUnreachableException when the call could not be completed
     * @throws BridgeRequestFailedException when the bridge refused and the refusal is terminal
     */
    private function guarded(string $operation, string $sessionId, callable $call): mixed
    {
        $attempts = max(1, $this->attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->breaker->call(CircuitScope::Bridge, $sessionId, $call);
            } catch (CircuitOpenException) {
                // The door is shut: the bridge was not called, and no amount of waiting inside
                // this loop will open it.
                throw BridgeUnreachableException::circuitOpen($operation, $sessionId);
            } catch (Throwable $e) {
                $failure = $this->failureFor($operation, $e);

                if ($attempt >= $attempts) {
                    throw $failure;
                }

                $decision = $this->retry->decide($e, $attempt);

                if (! $decision->shouldRetry) {
                    throw $failure;
                }

                $this->wait($decision->delayMs);
            }
        }
    }

    /**
     * The exception this failure surfaces as.
     *
     * A bridge exception is passed through unchanged — it already carries the status and code the
     * classifier and the caller need. Anything else becomes a transport failure: a client library
     * throwing its own type must still fail closed, and must not let a message that may quote the
     * bridge URL or its bearer token escape into a log.
     */
    private function failureFor(string $operation, Throwable $e): Throwable
    {
        return $e instanceof BridgeUnreachableException || $e instanceof BridgeRequestFailedException
            ? $e
            : BridgeUnreachableException::transportFailed($operation);
    }

    /**
     * Wait out a backoff, clamped so an inline bridge call cannot inherit a queue-sized delay.
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
            'inner' => $this->inner::class,
            'breaker' => CircuitScope::Bridge->value.':{sessionId}',
            'attempts' => $this->attempts,
            'maxDelayMs' => $this->maxDelayMs,
        ];
    }
}
