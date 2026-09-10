<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Services\Reliability\ErrorClassifier;
use Throwable;

/**
 * Turns a Meta Cloud API refusal into the `ErrorClass` that decides its fate — the
 * `CLOUD_API` link of the classifier chain (design.md § Error Handling; Req 8.1 / A8;
 * Req 31.1 / NFR2).
 *
 * Registered in `wa.reliability.retry.classifiers`, which is the seam
 * `App\Services\Reliability\ErrorClassifier` documents for exactly this — and which already
 * names this class as its example: *"`'classifiers' => [CloudApiErrorClassifier::class]`"*.
 * `App\Services\Bridge\BridgeErrorClassifier` is the same construction for the sidecar, and
 * this class is deliberately its mirror image, member for member.
 *
 * ## Why classification lives here and not on the exception
 *
 * `ChannelRequestFailedException` carries Meta's status, code, sub-code and type without
 * interpreting any of them, because the interpretation is a **policy** and policies belong in
 * one place. On the exception it would be a second copy the day an operator needs to
 * reclassify one code during an incident; in config alone the platform would ship with no
 * opinion at all. So: the exception carries evidence, this class reads it, and
 * `wa.reliability.retry.exceptions` can still override a single entry without a release.
 *
 * ## The mode check is the first thing it does
 *
 * `ChannelRequestFailedException` is shared by the three official modes (see its docblock),
 * and provider error numbers **collide**: Meta's `4` is an app-level rate limit, a partner's
 * `4` is whatever that partner decided. So a failure whose `mode` is not `CLOUD_API` returns
 * `null` and the chain continues to task 7.3's and 7.4's classifiers. Without that check this
 * class would map an On-Premise refusal onto Meta's policy — silently, and in the direction
 * that retries something terminal.
 *
 * ## The mapping
 *
 * Meta's `error.code` is its precise statement and is consulted **first**; the HTTP status is
 * its rough one and is the fallback. That order matters more here than on the bridge, because
 * Cloud API returns **`400` for rate limits**: code `130429` with status 400 is a throughput
 * limit, and reading the status first would classify it `VALIDATION` and fail a send fast that
 * would have succeeded ninety seconds later.
 *
 * | Meta code | Meaning | Class | Fate |
 * |---|---|---|---|
 * | `4` | app-level request limit reached | `RATE_LIMIT` | **defer**, honour `Retry-After`, 8 attempts |
 * | `80007` | WABA rate limit hit | `RATE_LIMIT` | defer |
 * | `130429` | Cloud API message throughput limit | `RATE_LIMIT` | defer |
 * | `131048` | spam-rate limit hit | `RATE_LIMIT` | defer |
 * | `131056` | pair rate limit (this sender/recipient pair) | `RATE_LIMIT` | defer |
 * | `0`, `190` | `AuthException` / access token expired, revoked, or invalid | `AUTH` | **fail fast** |
 * | `2500` | an OAuth error on the request itself | `AUTH` | fail fast |
 * | `10`, `200`–`299` | permission denied — the token lacks a scope, or the WABA is not permitted | `PERMISSION` | fail fast |
 * | `131031` | the account has been locked | `PERMISSION` | fail fast |
 * | `131026` | message undeliverable — the recipient is not a WhatsApp user, or cannot receive this | `NOT_ON_WHATSAPP` | fail fast |
 * | `131047` | re-engagement required: outside the 24-hour window without a template | `VALIDATION` | fail fast |
 * | `131051` | unsupported message type | `VALIDATION` | fail fast |
 * | `132000`–`132015` | template problems: parameter count, does not exist, paused, disabled | `VALIDATION` | fail fast |
 * | `133000`–`133016` | number registration / deregistration problems | `VALIDATION` | fail fast |
 * | `1`, `2`, `131000`, `131009`, `131016` | Meta's own transient trouble, or a service temporarily unavailable | `NETWORK` | retry ×5, jittered |
 * | `130472` | user is in an experiment group Meta excluded from this message | `VALIDATION` | fail fast |
 *
 * Then, when the code says nothing this class knows: `401` → `AUTH`, `403` → `PERMISSION`,
 * `408`/`504` → `TIMEOUT`, `429` → `RATE_LIMIT`, `5xx` → `NETWORK`, other `4xx` →
 * `VALIDATION`, anything else → `NETWORK`.
 *
 * ## The two rows that are the point of the task
 *
 * - **A rate limit must be retryable with backoff.** `RATE_LIMIT` *defers* rather than
 *   retries, honours a `Retry-After` verbatim when Meta sent one, and starts from a
 *   one-second window when it did not (`wa.reliability.retry.classes`). Deferring rather than
 *   retrying is what keeps a provider's pacing from eating a budget meant for real faults.
 * - **An invalid token must not be.** `AUTH` is `0` attempts, structurally
 *   (`ErrorClass::disposition()`, which no configuration can turn into a retry). A system-user
 *   token does not renew itself, and a job that retried five times would spend five attempts
 *   proving it — and, worse, would keep a send in flight long enough for task 7.6 to mark
 *   working credentials invalid on the strength of a queue backlog.
 *
 * ## Why an unrecognised refusal is `NETWORK` and not `UNKNOWN`
 *
 * A code this class does not recognise came from a Graph API version newer than this release,
 * and the *status* is still Meta's rough statement — which the fallback reads. Only a refusal
 * with neither a known code nor a 4xx/5xx status reaches the last arm, and that is a provider
 * behaving oddly rather than a request being wrong: `NETWORK` (5 attempts) keeps the work,
 * where the risk is a duplicate the idempotency key absorbs, instead of dropping it, which
 * nothing absorbs. `BridgeErrorClassifier` makes the same argument for `BRIDGE`.
 */
final readonly class CloudApiErrorClassifier implements ErrorClassifier
{
    /**
     * The app-level and account-level pacing codes: Meta is asking for a slower rate.
     */
    public const array RATE_LIMIT_CODES = [4, 80007, 130429, 131048, 131056];

    /**
     * Credentials rejected: the system-user token is expired, revoked, or was never valid.
     */
    public const array AUTH_CODES = [0, 190, 2500];

    /**
     * The token or the account may not do this — a missing scope, a locked account.
     */
    public const array PERMISSION_CODES = [10, 131031];

    /**
     * There is no WhatsApp account to deliver to, on this attempt or any other.
     */
    public const array NOT_ON_WHATSAPP_CODES = [131026];

    /**
     * Meta's own transient trouble.
     */
    public const array TRANSIENT_CODES = [1, 2, 131000, 131009, 131016];

    /**
     * Deterministic refusals of the request itself: the window rule, an unsupported type, an
     * experiment exclusion.
     */
    public const array VALIDATION_CODES = [131047, 131051, 130472];

    public function classify(Throwable $e): ?ErrorClass
    {
        if (! $e instanceof ChannelRequestFailedException) {
            // Not ours — the chain asks the next classifier. Note that the *unreachable* case is
            // `BridgeUnreachableException`, which `BridgeErrorClassifier` already classifies
            // `BRIDGE`: an official provider that never answered is the same situation as a
            // sidecar that never answered, and both must keep the work.
            return null;
        }

        if ($e->mode !== ChannelMode::CloudApi) {
            // Another official mode's refusal, carried in the same shape. Its codes are its own.
            return null;
        }

        return $this->fromCode($e) ?? $this->fromStatus($e->status);
    }

    /**
     * Meta's `error.code` — its precise statement about what went wrong.
     */
    private function fromCode(ChannelRequestFailedException $e): ?ErrorClass
    {
        return match (true) {
            $e->hasErrorCode(...self::RATE_LIMIT_CODES) => ErrorClass::RateLimit,
            $e->hasErrorCode(...self::AUTH_CODES) => ErrorClass::Auth,
            $e->hasErrorCode(...self::PERMISSION_CODES) => ErrorClass::Permission,
            $e->hasErrorCode(...self::NOT_ON_WHATSAPP_CODES) => ErrorClass::NotOnWhatsApp,
            $e->hasErrorCode(...self::TRANSIENT_CODES) => ErrorClass::Network,
            $e->hasErrorCode(...self::VALIDATION_CODES) => ErrorClass::Validation,

            // Meta's block-numbered families, matched by range rather than enumerated: the
            // `2xx` permission block, the `132xxx` template block, and the `133xxx`
            // registration block each add codes between Graph API versions, and every member of
            // each block has the same fate. Enumerating them would mean a new code in a known
            // family silently taking the status fallback.
            $this->inRange($e->errorCode, 200, 299) => ErrorClass::Permission,
            $this->inRange($e->errorCode, 132_000, 132_015) => ErrorClass::Validation,
            $this->inRange($e->errorCode, 133_000, 133_016) => ErrorClass::Validation,

            default => null,
        };
    }

    /**
     * The HTTP status — Meta's rough statement, read only when the code says nothing known.
     */
    private function fromStatus(int $status): ErrorClass
    {
        return match (true) {
            $status === 401 => ErrorClass::Auth,
            $status === 403 => ErrorClass::Permission,
            $status === 408, $status === 504 => ErrorClass::Timeout,
            $status === 429 => ErrorClass::RateLimit,
            $status >= 500 => ErrorClass::Network,
            $status >= 400 => ErrorClass::Validation,
            // Not even a 4xx or 5xx: a provider behaving oddly is a statement about the
            // provider, so the work is kept. See the class docblock.
            default => ErrorClass::Network,
        };
    }

    /**
     * Whether a normalised code is a plain integer inside `[$from, $to]`.
     *
     * `ChannelRequestFailedException` has already reduced the code to a token matching
     * `NORMALISED_CODE_PATTERN`, so a non-numeric one simply is not in any range — which is the
     * right answer rather than a cast to `0`, a code that means `AuthException`.
     */
    private function inRange(?string $code, int $from, int $to): bool
    {
        if ($code === null || preg_match('/^\d{1,9}$/', $code) !== 1) {
            return false;
        }

        $value = (int) $code;

        return $value >= $from && $value <= $to;
    }
}
