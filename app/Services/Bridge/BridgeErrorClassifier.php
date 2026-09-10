<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\ErrorClass;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Bridge\UnknownSessionException;
use App\Services\Reliability\ErrorClassifier;
use Throwable;

/**
 * Turns a bridge failure into the `ErrorClass` that decides its fate — the bridge subsystem's
 * own link in the classifier chain (design.md § Error Handling; Req 7.1 / A7; Req 31.1 / NFR2).
 *
 * Registered in `wa.reliability.retry.classifiers`, which is the seam
 * `App\Services\Reliability\ErrorClassifier` documents for exactly this: a subsystem classifies
 * *its own* provider errors, returns `null` for everything else, and never guesses on another
 * subsystem's behalf.
 *
 * ## Why classification lives here and not on the exceptions
 *
 * `BridgeRequestFailedException` deliberately carries the bridge's status and error code without
 * interpreting them, because the interpretation is a *policy* and policies belong in one place.
 * Putting it on the exception would mean a second copy the day a driver wants to reclassify one
 * code during an incident; putting it in config alone would mean the platform shipped with no
 * opinion at all. So: the exception carries evidence, this class reads it, and an operator can
 * still override one entry through `wa.reliability.retry.exceptions` without a release.
 *
 * ## The mapping
 *
 * | Failure | Class | Fate | Why |
 * |---|---|---|---|
 * | `BridgeUnreachableException` | `BRIDGE` | retry ×5, jittered | the bridge never answered, so the outcome is unknown — the work must be kept, and a restarting sidecar is back in seconds |
 * | `UnknownSessionException` | `VALIDATION` | fail fast | a session id that names no row will not start naming one |
 * | refusal, `session_not_connected` / `session_unknown` | `BRIDGE` | retry | the sidecar is up, this session is not; the reconnect loop may land between attempts |
 * | refusal, `session_logged_out` | `AUTH` | fail fast | the stored credentials are void; only re-pairing fixes it, and that is a tenant action |
 * | refusal, `not_on_whatsapp` | `NOT_ON_WHATSAPP` | fail fast | there is no account to deliver to, on this attempt or any other |
 * | refusal, `media_failed` | `MEDIA` | retry ×3 | a transfer, and transfers fail transiently |
 * | refusal, `rate_limited` | `RATE_LIMIT` | **defer**, honour `Retry-After` | WhatsApp is pacing this session; deferring is what keeps the retry budget for real faults |
 * | refusal, status 401 / 403 | `AUTH` / `PERMISSION` | fail fast | our bearer token is wrong, or the sidecar refused the operation outright |
 * | refusal, status 408 / 504 | `TIMEOUT` | retry ×3, shorter cap | the sidecar timed out talking to WhatsApp |
 * | refusal, status 429 | `RATE_LIMIT` | defer | as above, when the sidecar sent no code |
 * | refusal, any other 4xx | `VALIDATION` | fail fast | we asked for something impossible; a retry asks identically |
 * | refusal, any 5xx | `BRIDGE` | retry | the sidecar itself is unwell |
 *
 * The code is consulted **before** the status, so a `409 session_not_connected` is retried on the
 * bridge budget rather than parked as a 4xx — the code is the sidecar's precise statement and the
 * status is its rough one.
 *
 * ## Why an unrecognised refusal is `BRIDGE` and not `UNKNOWN`
 *
 * A refusal this classifier does not recognise came from a sidecar build newer than this PHP
 * release. `UNKNOWN` (3 attempts) would be defensible, but `BRIDGE` (5) is the safer default in
 * the direction that matters: the failure is definitely *about the bridge*, so attributing it to
 * the bridge keeps the error dashboard honest, and keeping the work slightly longer risks a
 * duplicate — which the send pipeline's idempotency key absorbs — rather than a drop, which
 * nothing absorbs.
 */
final readonly class BridgeErrorClassifier implements ErrorClassifier
{
    public function classify(Throwable $e): ?ErrorClass
    {
        if ($e instanceof BridgeUnreachableException) {
            return ErrorClass::Bridge;
        }

        if ($e instanceof UnknownSessionException) {
            return ErrorClass::Validation;
        }

        if (! $e instanceof BridgeRequestFailedException) {
            // Not ours — the chain asks the next classifier.
            return null;
        }

        return $this->fromCode($e->bridgeCode) ?? $this->fromStatus($e->status);
    }

    /**
     * The sidecar's machine-readable code — its precise statement about what went wrong.
     */
    private function fromCode(?string $code): ?ErrorClass
    {
        return match ($code) {
            BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED,
            BridgeRequestFailedException::CODE_SESSION_UNKNOWN => ErrorClass::Bridge,
            BridgeRequestFailedException::CODE_SESSION_LOGGED_OUT => ErrorClass::Auth,
            BridgeRequestFailedException::CODE_NOT_ON_WHATSAPP => ErrorClass::NotOnWhatsApp,
            BridgeRequestFailedException::CODE_MEDIA_FAILED => ErrorClass::Media,
            BridgeRequestFailedException::CODE_RATE_LIMITED => ErrorClass::RateLimit,
            default => null,
        };
    }

    /**
     * The HTTP status — its rough statement, used when the code says nothing we know.
     */
    private function fromStatus(int $status): ErrorClass
    {
        return match (true) {
            $status === 401 => ErrorClass::Auth,
            $status === 403 => ErrorClass::Permission,
            $status === 408, $status === 504 => ErrorClass::Timeout,
            $status === 429 => ErrorClass::RateLimit,
            $status >= 500 => ErrorClass::Bridge,
            $status >= 400 => ErrorClass::Validation,
            // A refusal that is not even a 4xx or 5xx is a sidecar behaving oddly, which is a
            // statement about the bridge.
            default => ErrorClass::Bridge,
        };
    }
}
