<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\ErrorClass;
use App\Exceptions\Audit\AppendOnlyViolationException;
use App\Exceptions\Audit\AuditChainBusyException;
use App\Exceptions\Audit\AuditPayloadException;
use App\Exceptions\Billing\FeatureNotInPlanException;
use App\Exceptions\Billing\MalformedPlanException;
use App\Exceptions\Security\CiphertextIntegrityException;
use App\Exceptions\Security\KeyUnavailableException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Exceptions\Tenancy\MediaRejectedException;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Exceptions\Tenancy\QuotaExceededException;
use App\Exceptions\Tenancy\TenantNotOperationalException;
use Error;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The platform's own typed exceptions, classified once and for all — the last link of the
 * classifier chain before the configured default (design.md § Error Handling).
 *
 * The exceptions this platform raises already *carry* the meaning a retry decision needs:
 * `QuotaExceededException` knows whether it is deferrable and for how long,
 * `KeyUnavailableException` says it is retryable, `CiphertextIntegrityException` says it is
 * not. Re-deriving that at each `catch` is how two call sites end up disagreeing about
 * whether a suspended tenant should be retried, so it is derived here, once.
 *
 * ## The mapping, and the reasoning behind the debatable rows
 *
 * | Exception | Class | Why |
 * |---|---|---|
 * | `QuotaExceededException` (429) | `QUOTA` | Req 31.1: a defer, never a drop. `RetryPolicy` reads its `retryAfterSeconds()` for the wait, and treats a **non-deferrable** one as the *block* half of Req 31.1 — no reset is coming, so waiting is not an option (see `RetryPolicy::isBlocked()`). |
 * | `FeatureNotInPlanException` (402/403) | `PERMISSION` | never retryable: no amount of waiting adds a feature to a plan. |
 * | `TenantNotOperationalException` (403) | `PERMISSION` | task 1.3 settled this: a suspended tenant is a **block**, never a defer. A suspension ends when an admin ends it, not on a timer, and releasing the job would bounce it for the length of the suspension. |
 * | `CrossTenantAccessException` (403) | `PERMISSION` | should be impossible; retrying an isolation breach would only repeat it. |
 * | `KeyUnavailableException` (503) | `NETWORK` when `isRetryable()`, else `VALIDATION` | the key store being unreachable is the textbook transient dependency failure. Its own `isRetryable()` is consulted rather than assumed, so if that ever gains a `false` path the classification follows it instead of retrying a permanent key loss 5 times. |
 * | `CiphertextIntegrityException` | `VALIDATION` | its own docblock: terminal. Bytes that fail an AEAD tag do not start authenticating, and retrying turns one corrupt row into a hot loop. |
 * | `AuditChainBusyException` | `NETWORK` | lock/position contention: nothing is wrong with the entry, the contention clears on its own, and a jittered re-attempt is exactly right (it is also why the audit chain has a retry loop of its own). |
 * | `MissingTenantContextException` | `VALIDATION` | a programming error. The fix is a code change, so a retry can only fail identically — three times, on every job, for ever. |
 * | `AppendOnlyViolationException`, `AuditPayloadException`, `MalformedPlanException`, `MediaRejectedException` | `VALIDATION` | same shape: the input or the platform data is wrong, and it is wrong deterministically. `MediaRejectedException` in particular is a *refusal* (sniffed MIME, byte cap), not the `MEDIA` class — that one is for a transfer that failed. |
 * | `ValidationException` | `VALIDATION` | the framework's own 422. |
 * | `ConnectionException` | `NETWORK` | the HTTP client could not reach the other end. A driver that can tell a timeout apart from a refusal registers its own classifier and returns `TIMEOUT`; this one will not guess from a cURL message. |
 * | `Error` (engine errors) | `VALIDATION` | a `TypeError`/`Error` is a bug in our code. Retrying it triples the log noise and changes nothing. |
 *
 * ## The HTTP status fallback
 *
 * Anything implementing Symfony's `HttpExceptionInterface` — which the platform's own HTTP
 * exceptions and the framework's `abort()` helpers all do — is classified from its status
 * code: `429` → `RATE_LIMIT`, `401` → `AUTH`, `402/403` → `PERMISSION`,
 * `408/504` → `TIMEOUT`, other `4xx` → `VALIDATION`, `5xx` → `NETWORK`. It runs *after*
 * the typed map, so a specific class always beats a status code, and it is what makes a
 * third-party HTTP exception behave sensibly before anybody has written a classifier for it.
 *
 * Everything else returns `null` — "no opinion" — and lands on
 * `wa.reliability.retry.default_class`.
 */
final readonly class PlatformErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $e): ?ErrorClass
    {
        return $this->fromTypedException($e) ?? $this->fromHttpStatus($e);
    }

    /**
     * The platform's (and the framework's) typed exceptions, most specific first.
     */
    private function fromTypedException(Throwable $e): ?ErrorClass
    {
        return match (true) {
            $e instanceof QuotaExceededException => ErrorClass::Quota,

            $e instanceof FeatureNotInPlanException,
            $e instanceof TenantNotOperationalException,
            $e instanceof CrossTenantAccessException => ErrorClass::Permission,

            $e instanceof KeyUnavailableException => $e->isRetryable() ? ErrorClass::Network : ErrorClass::Validation,

            $e instanceof AuditChainBusyException,
            $e instanceof ConnectionException => ErrorClass::Network,

            $e instanceof CiphertextIntegrityException,
            $e instanceof MissingTenantContextException,
            $e instanceof AppendOnlyViolationException,
            $e instanceof AuditPayloadException,
            $e instanceof MalformedPlanException,
            $e instanceof MediaRejectedException,
            $e instanceof ValidationException,
            $e instanceof Error => ErrorClass::Validation,

            default => null,
        };
    }

    /**
     * Classification of last resort: what the HTTP status code says.
     */
    private function fromHttpStatus(Throwable $e): ?ErrorClass
    {
        if (! $e instanceof HttpExceptionInterface) {
            return null;
        }

        $status = $e->getStatusCode();

        return match (true) {
            $status === 401 => ErrorClass::Auth,
            $status === 402, $status === 403 => ErrorClass::Permission,
            $status === 408, $status === 504 => ErrorClass::Timeout,
            $status === 429 => ErrorClass::RateLimit,
            $status >= 500 => ErrorClass::Network,
            $status >= 400 => ErrorClass::Validation,
            default => null,
        };
    }
}
