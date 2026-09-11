<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\CircuitScope;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Support\Sleep;
use SensitiveParameter;
use Throwable;

/**
 * A `KmsClient` wrapped in the two things every fallible network dependency on this
 * platform gets: a **circuit breaker** and a **bounded retry budget**
 * (Req 31.3, 31.1 / NFR2 applied to Req 32.6 / NFR3).
 *
 * A KMS is on the hot path of every encrypted read and write, which makes it the
 * worst possible place for an unguarded network call: when Vault is slow, *every*
 * request in the pool waits on it, and the platform stops for a reason that has
 * nothing to do with the platform. So the two primitives of Phase 2 are composed
 * exactly as `RetryPolicy`'s docblock prescribes — `$retry->run(fn () => $breaker->call(...))`
 * — with the breaker innermost:
 *
 * ```
 *   attempt loop (RetryPolicy: how long to wait, and whether to bother)
 *     └── breaker (CircuitBreaker: may this call run at all?)
 *           └── VaultTransitKmsClient (one HTTP call)
 * ```
 *
 * ## What each layer contributes
 *
 * - **Breaker** (`CircuitScope::Provider`, name `wa.security.kms.guard.breaker`): once
 *   the store has failed enough, calls stop being attempted and fail immediately. That
 *   is the difference between "encryption is unavailable" (a fast 503 the caller
 *   retries) and "every worker is blocked on a 5-second timeout".
 * - **Retry**: a single dropped packet should not surface as a failed write, so a
 *   transient failure is retried a small, bounded number of times with jittered
 *   backoff from the platform's own matrix.
 *
 * ## Why the retry is deliberately tiny
 *
 * This waits **inline**, inside whatever request or job asked to encrypt something, so
 * the budget is capped twice: `attempts` (default 2) and `max_delay_ms` (default 250 ms
 * per wait). The KMS is not the place to spend a caller's latency on optimism — the
 * exception is retryable, so the queue worker or the HTTP client retries the whole
 * unit of work, which is both cheaper and safer than sleeping here. `Illuminate\Support\Sleep`
 * is used rather than `usleep()` so the wait is assertable in tests instead of real.
 *
 * ## Fail closed, in one direction only
 *
 * Every exit that is not a value is a `KeyUnavailableException` (503, retryable):
 * a tripped breaker, an exhausted budget, an unexpected SDK error. There is no path
 * through this class that returns plaintext, an empty string, or a partially decrypted
 * value — and `CircuitOpenException` is never retried inline, because a breaker's
 * decision cannot change within the loop and burning the budget on it only delays the
 * caller's own fallback (which, for encryption, is to stop).
 */
final readonly class GuardedKmsClient implements KmsClient
{
    /**
     * Inline attempts per operation, and the longest single inline wait.
     */
    public const int DEFAULT_ATTEMPTS = 2;

    public const int DEFAULT_MAX_DELAY_MS = 250;

    public function __construct(
        private KmsClient $inner,
        private CircuitBreaker $breaker,
        private RetryPolicy $retry,
        private string $breakerName = 'kms',
        private int $attempts = self::DEFAULT_ATTEMPTS,
        private int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ) {}

    public function activeKeyId(): string
    {
        return $this->guarded('kms.keys.read', fn (): string => $this->inner->activeKeyId());
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $context): KmsSeal
    {
        return $this->guarded('kms.encrypt', fn (): KmsSeal => $this->inner->encrypt($plaintext, $context));
    }

    public function decrypt(string $keyId, string $ciphertext, string $context): string
    {
        return $this->guarded('kms.decrypt', fn (): string => $this->inner->decrypt($keyId, $ciphertext, $context));
    }

    public function rotate(): string
    {
        return $this->guarded('kms.rotate', fn (): string => $this->inner->rotate());
    }

    /**
     * Run one KMS operation through the breaker, retrying only what the matrix says is
     * worth retrying.
     *
     * @template TReturn
     *
     * @param  string  $label  short operation name for messages — never a URL, never a payload
     * @param  callable(): TReturn  $operation
     * @return TReturn
     *
     * @throws KeyUnavailableException always, when the operation cannot be completed
     */
    private function guarded(string $label, callable $operation): mixed
    {
        $attempts = max(1, $this->attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->breaker->call(CircuitScope::Provider, $this->breakerName, $operation);
            } catch (CircuitOpenException) {
                // The door is shut: the operation was not attempted, and no amount of
                // waiting inside this loop will open it.
                throw KeyUnavailableException::kmsUnreachable($label);
            } catch (Throwable $e) {
                $failure = $e instanceof KeyUnavailableException
                    ? $e
                    // A client throwing its own exception type must still fail closed, and
                    // must not let a provider message — which may quote a request body —
                    // escape into a log.
                    : KeyUnavailableException::kmsUnreachable($label);

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
     * Wait out a backoff, clamped so an inline crypto call cannot inherit a
     * queue-sized delay.
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
            'breaker' => CircuitScope::Provider->value.':'.$this->breakerName,
            'attempts' => $this->attempts,
            'maxDelayMs' => $this->maxDelayMs,
        ];
    }
}
