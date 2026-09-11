<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Services\Reliability\ErrorClassifier;
use Throwable;

/**
 * Turns a legacy On-Premise client refusal into the `ErrorClass` that decides its fate — the
 * `ON_PREMISE` link of the classifier chain (design.md § Error Handling; Req 8.1 / A8;
 * Req 31.1 / NFR2).
 *
 * Registered in `wa.reliability.retry.classifiers`, the seam
 * `App\Services\Reliability\ErrorClassifier` documents, beside `BridgeErrorClassifier` and
 * `CloudApiErrorClassifier`. It is deliberately `CloudApiErrorClassifier`'s mirror image, member
 * for member: mode check, then the provider's own code, then the HTTP status, then a refusal
 * that **keeps** the work rather than dropping it.
 *
 * ## The mode check is the first thing it does, and here it is not a formality
 *
 * `ChannelRequestFailedException` is shared by the three official modes, and the On-Premise
 * client's numbering is not merely *different* from Meta's — it **overlaps in the worst
 * direction**. The clearest pair:
 *
 * | Code | On Cloud API | On the On-Premise client |
 * |---|---|---|
 * | `4` | app-level request limit → `RATE_LIMIT`, **deferred and retried** | not a code this client issues; falls to the status, which for a `400` is `VALIDATION` — fail fast |
 * | `1005` | not a Meta code | *access denied* → `AUTH` |
 * | `470` | not used | outside the 24-hour window without a template → `VALIDATION` |
 * | `1013` | not a Meta code | the recipient is not a WhatsApp user → `NOT_ON_WHATSAPP` |
 *
 * Read Meta's table against an On-Premise refusal and a terminal validation error is retried
 * eight times with backoff; read this table against Meta's and a rate limit is dropped as
 * malformed. `CloudApiErrorClassifier` already returns `null` for a non-`CLOUD_API` failure so
 * that this class can claim it, and this one returns `null` for anything that is not
 * `ON_PREMISE` so that task 7.4's can claim its own.
 *
 * ## The mapping
 *
 * The client's envelope is `{"errors": [{"code": 1005, "title": "Access denied", "details": "…"}]}`
 * — a **list**, unlike Meta's single `error` object, and the driver carries `errors[0].code` as
 * the exception's `errorCode`. The code is consulted first and the status second, for
 * `CloudApiErrorClassifier`'s reason: the code is the client's precise statement, the status its
 * rough one.
 *
 * | Code | Meaning | Class | Fate |
 * |---|---|---|---|
 * | `1015` | too many requests — the client is pacing us | `RATE_LIMIT` | **defer**, honour `Retry-After`, 8 attempts |
 * | `471` | spam rate limit hit on this number | `RATE_LIMIT` | defer |
 * | `1005` | access denied — the bearer token is absent, expired, or revoked | `AUTH` | **fail fast** (see the note below) |
 * | `1031` | the account has been locked or deleted | `PERMISSION` | fail fast |
 * | `1013` | the recipient is not a valid WhatsApp user | `NOT_ON_WHATSAPP` | fail fast |
 * | `1026` | the recipient's client cannot receive this message | `NOT_ON_WHATSAPP` | fail fast |
 * | `470` | outside the 24-hour window and not an approved template | `VALIDATION` | fail fast |
 * | `472` | the user is in an experiment group excluded from this message | `VALIDATION` | fail fast |
 * | `1001` | message too long | `VALIDATION` | fail fast |
 * | `1004` | resource already exists (a duplicate registration, a duplicate media id) | `VALIDATION` | fail fast |
 * | `1008`, `1009`, `1010` | a required parameter is missing, invalid, or not accepted | `VALIDATION` | fail fast |
 * | `1025` | the request itself is not valid | `VALIDATION` | fail fast |
 * | `2000`–`2099` | the template family: parameter count, unknown template, hydration, format | `VALIDATION` | fail fast |
 * | `1000` | the client's generic error | `NETWORK` | retry ×5, jittered |
 * | `1011` | service not ready — the container is starting, or its gateway is reconnecting | `NETWORK` | retry |
 * | `1014` | the client's internal error | `NETWORK` | retry |
 * | `1016` | *resend message* — the client is explicitly asking for a retry | `NETWORK` | retry |
 *
 * Then, when the code says nothing this class knows: `401` → `AUTH`, `403` → `PERMISSION`,
 * `404` → `VALIDATION`, `408`/`504` → `TIMEOUT`, `429` → `RATE_LIMIT`, `5xx` → `NETWORK`, other
 * `4xx` → `VALIDATION`, anything else → `NETWORK`.
 *
 * ## `1005` is `AUTH`, and the driver is what stops that costing a campaign
 *
 * This is the row that is genuinely different from Cloud API's, and getting it wrong in either
 * direction is expensive:
 *
 * - classified anything **but** `AUTH`, an actually-wrong password would be retried on a real
 *   budget, and task 7.6 could see a queue backlog and mark working credentials invalid;
 * - classified `AUTH` and left there, a bearer token that lapsed **mid-campaign** would fail
 *   every remaining send with zero attempts — `ErrorClass::disposition()` makes that structural,
 *   and no configuration can turn it into a retry. Those messages are *lost*, not deferred.
 *
 * So the fix is not in the classification. `OnPremiseChannelDriver` discards the cached token and
 * replays the call **once** with a freshly minted one before this class is ever consulted about
 * the outcome; only a refusal that survives a brand-new token reaches here, and a brand-new
 * token being refused is exactly what `AUTH` means. See that driver's `withFreshToken()`.
 *
 * ## Why an unrecognised refusal is `NETWORK` and not `UNKNOWN`
 *
 * `CloudApiErrorClassifier`'s argument, unchanged and slightly stronger here: the On-Premise
 * client is a **container the tenant runs**, so its build is older or newer than anything this
 * release was written against, and a code this class does not recognise is the normal case rather
 * than the exceptional one. Only a refusal with neither a known code nor a 4xx/5xx status reaches
 * the last arm, and that is a container behaving oddly rather than a request being wrong:
 * `NETWORK` (5 attempts) keeps the work, where the risk is a duplicate the idempotency key
 * absorbs, instead of dropping it, which nothing absorbs.
 */
final readonly class OnPremiseErrorClassifier implements ErrorClassifier
{
    /**
     * The client is asking for a slower rate.
     */
    public const array RATE_LIMIT_CODES = [471, 1015];

    /**
     * The bearer token was refused — absent, expired, or revoked.
     *
     * Also what `OnPremiseChannelDriver` matches on to decide whether a single re-login and
     * replay is worth attempting, which is why it is a public constant rather than an inline
     * list: one definition of *"this looks like an expired token"*, read by the classifier and by
     * the driver that tries to make it not matter.
     */
    public const array AUTH_CODES = [1005];

    /**
     * The account may not do this — locked, or deleted.
     */
    public const array PERMISSION_CODES = [1031];

    /**
     * There is nobody to deliver to, on this attempt or any other.
     */
    public const array NOT_ON_WHATSAPP_CODES = [1013, 1026];

    /**
     * The client's own transient trouble, including its explicit *"resend"*.
     */
    public const array TRANSIENT_CODES = [1000, 1011, 1014, 1016];

    /**
     * Deterministic refusals of the request itself — the window rule, a bad parameter, an
     * experiment exclusion, a message over the length cap.
     */
    public const array VALIDATION_CODES = [470, 472, 1001, 1004, 1008, 1009, 1010, 1025];

    /**
     * The template block, matched by range rather than enumerated.
     *
     * `2000` parameter count, `2001` unknown template, `2003` hydration/localizable params,
     * `2004` format or length, and whatever a newer client build adds beside them. Every member
     * of the family has the same fate, so a range is what keeps a new code in a known family from
     * silently taking the status fallback — `CloudApiErrorClassifier`'s reasoning about Meta's
     * `132xxx` block.
     */
    public const int TEMPLATE_CODE_FROM = 2_000;

    public const int TEMPLATE_CODE_TO = 2_099;

    public function classify(Throwable $e): ?ErrorClass
    {
        if (! $e instanceof ChannelRequestFailedException) {
            // Not ours — the chain asks the next classifier. The *unreachable* case is
            // `BridgeUnreachableException`, which `BridgeErrorClassifier` already classifies
            // `BRIDGE`: a container that never answered is the same situation as a sidecar that
            // never answered, and both must keep the work.
            return null;
        }

        if ($e->mode !== ChannelMode::OnPremise) {
            // Another official mode's refusal, carried in the same shape. Its codes are its own —
            // see the collision table in the class docblock.
            return null;
        }

        return $this->fromCode($e) ?? $this->fromStatus($e->status);
    }

    /**
     * Whether `$e` is the client saying the bearer token is no good.
     *
     * The driver's one-shot re-authentication reads this rather than re-deriving it, so
     * *"this looks like an expired token"* has one definition. A `401` with no code at all counts:
     * a container behind a reverse proxy that strips the body still answers the status, and the
     * cheapest correct response to it is one fresh login.
     */
    public static function looksLikeExpiredToken(ChannelRequestFailedException $e): bool
    {
        return $e->mode === ChannelMode::OnPremise
            && ($e->status === 401 || $e->hasErrorCode(...self::AUTH_CODES));
    }

    /**
     * The client's `errors[0].code` — its precise statement about what went wrong.
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

            $this->inRange($e->errorCode, self::TEMPLATE_CODE_FROM, self::TEMPLATE_CODE_TO) => ErrorClass::Validation,

            default => null,
        };
    }

    /**
     * The HTTP status — the client's rough statement, read only when the code says nothing known.
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
            // Not even a 4xx or 5xx: a container behaving oddly is a statement about the
            // container, so the work is kept. See the class docblock.
            default => ErrorClass::Network,
        };
    }

    /**
     * Whether a normalised code is a plain integer inside `[$from, $to]`.
     *
     * `ChannelRequestFailedException` has already reduced the code to a token matching
     * `NORMALISED_CODE_PATTERN`, so a non-numeric one simply is not in any range — which is the
     * right answer rather than a cast to `0`.
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
