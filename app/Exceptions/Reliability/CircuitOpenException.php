<?php

declare(strict_types=1);

namespace App\Exceptions\Reliability;

use App\Enums\CircuitState;
use App\Services\Reliability\CircuitBreakerKey;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A guarded call was **refused without being made** — the breaker for `(scope, name)` is
 * OPEN, or its half-open probe budget is spent (Req 31.3 / NFR2, Algorithm 7,
 * Correctness Property 13).
 *
 * ## This exception *is* the "operation not invoked" guarantee
 *
 * Property 13 says the guarded operation is never invoked while OPEN. `CircuitBreaker::call()`
 * therefore has exactly two failure shapes, and they mean opposite things:
 *
 * | Thrown | Meaning | The caller should |
 * |---|---|---|
 * | `CircuitOpenException` | the operation **did not run**; nothing was attempted | fall back |
 * | anything else | the operation ran and threw; the failure is recorded on the breaker | fall back *or* surface |
 *
 * Nothing else is thrown from `call()` for a breaker reason, and this is thrown for no
 * other reason — so `catch (CircuitOpenException)` is a sound test for "not attempted",
 * which is what makes the LLM fallback chain of design.md §1.5 correct rather than
 * hopeful. When the *guarded operation itself* wraps a nested breaker, the two are told
 * apart by `$e->key` (its `scope` and `name`).
 *
 * ## Why 503 and a `Retry-After`
 *
 * A tripped breaker is a dependency being unavailable, not the caller's fault (4xx) and
 * not a defect in this service (500). The cool-down is a real, known duration, so
 * `retryAfterSeconds` is exact rather than invented — and it is absent, deliberately, when
 * the refusal is a spent probe budget: another worker's probe is in flight and how long it
 * takes is the dependency's business, not ours.
 *
 * ## Redaction
 *
 * A breaker name may carry a tenant id (`CircuitBreaker::compositeName('openai', $tenantId)`).
 * Messages therefore quote the key through `CircuitBreakerKey::redacted()` — dependency
 * verbatim, tenant fingerprinted — the same treatment `CrossTenantAccessException` gives an
 * id, and the public body is a fixed sentence that names nothing at all.
 */
final class CircuitOpenException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * The dependency is unavailable, so this is a 503 and not a 500.
     */
    public const int STATUS = 503;

    /**
     * Stable machine-readable code for API clients and the panel.
     */
    public const string ERROR_CODE = 'circuit_open';

    /**
     * The only sentence a client is shown: no provider name, no key, no tenant.
     */
    public const string PUBLIC_MESSAGE = 'This service is temporarily unavailable. Please try again shortly.';

    private function __construct(
        public readonly CircuitBreakerKey $key,
        public readonly CircuitState $observedState,
        public readonly ?int $retryAfterSeconds,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The breaker is OPEN and still inside its cool-down.
     *
     * @param  int  $retryAfterSeconds  whole seconds until a probe would be admitted
     */
    public static function open(CircuitBreakerKey $key, int $retryAfterSeconds): self
    {
        return new self($key, CircuitState::Open, max(1, $retryAfterSeconds), sprintf(
            'Circuit breaker [%s] is OPEN; the guarded operation was not invoked. A probe is '
            .'admitted in %ds.',
            $key->redacted(),
            max(1, $retryAfterSeconds),
        ));
    }

    /**
     * The breaker is HALF_OPEN and every probe in the budget is already claimed.
     *
     * Not an error state: it is the probe limit doing its job, keeping a recovering
     * dependency from being hit by the whole fleet at once.
     */
    public static function probesExhausted(CircuitBreakerKey $key, int $probeLimit): self
    {
        return new self($key, CircuitState::HalfOpen, null, sprintf(
            'Circuit breaker [%s] is HALF_OPEN with all %d probe(s) claimed; the guarded '
            .'operation was not invoked.',
            $key->redacted(),
            $probeLimit,
        ));
    }

    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * `Retry-After`, and only when there is a real cool-down to point at.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->retryAfterSeconds === null
            ? []
            : ['Retry-After' => (string) $this->retryAfterSeconds];
    }

    /**
     * The breaker's name, unredacted, for callers that need to key their own fallback
     * state off it. Never put this in a log line or a response.
     */
    public function breakerName(): string
    {
        return $this->key->name;
    }

    /**
     * Whether the refusal was the probe limit rather than the cool-down — the case a
     * caller may reasonably treat as "come straight back", since a probe is in flight.
     */
    public function wasProbeBudgetExhausted(): bool
    {
        return $this->observedState->isHalfOpen();
    }

    /**
     * The only sentence a client is shown.
     */
    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
