<?php

declare(strict_types=1);

namespace App\Exceptions\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An **official** messaging backend answered, and its answer was a refusal — the provider's
 * own status code and error envelope carried into PHP without interpretation
 * (Req 8.4, 8.6 / A8; Req 31.1 / NFR2; design § Channel Mode 2.2).
 *
 * ```php
 * // inside CloudApiChannelDriver, when Meta answers a non-2xx
 * throw ChannelRequestFailedException::refused(
 *     mode: ChannelMode::CloudApi,
 *     operation: 'cloud_api.message.text',
 *     status: 400,
 *     errorCode: '130429',            // Meta's error.code
 *     errorSubcode: '2494055',        // Meta's error.error_subcode
 *     errorType: 'OAuthException',    // Meta's error.type
 * );
 * ```
 *
 * ## It is the sibling of `BridgeRequestFailedException`, deliberately
 *
 * That class is the same idea for the Baileys sidecar, and the distinction it draws is the
 * one that decides what happens next — a distinction about **who knows something**:
 *
 * | Exception | The backend… | Retryable? |
 * |---|---|---|
 * | `App\Exceptions\Bridge\BridgeUnreachableException` | never answered — outcome unknown | always (`BRIDGE`) |
 * | this one | answered, and refused | *depends on what it said* |
 *
 * So this exception decides nothing about its own fate either. It carries the evidence, and
 * a per-mode `ErrorClassifier` registered in `wa.reliability.retry.classifiers` maps that
 * evidence onto an `App\Enums\ErrorClass` — `App\Services\Channel\CloudApiErrorClassifier`
 * for `CLOUD_API`, and one each for the modes of tasks 7.3 and 7.4. One policy, one place,
 * and an operator can still reclassify a single code during an incident without a release.
 *
 * ## Why one class for three modes, and why it carries `$mode`
 *
 * `CLOUD_API`, `ON_PREMISE` and `BSP_GATEWAY` refuse in three different vocabularies —
 * Meta's `{"error": {"code", "error_subcode", "type", "fbtrace_id"}}`, the On-Premise
 * client's `{"errors": [{"code", "title"}]}`, and eight partner shapes — but the *evidence a
 * retry decision needs* is the same five fields in all of them, so three near-identical
 * exception classes would be three places to keep one shape true.
 *
 * What must **not** be shared is the interpretation: provider error numbers collide across
 * providers (Meta's `4` is an app-level rate limit; a partner's `4` is anything at all), so a
 * classifier that read `errorCode` without checking `mode` would mis-map one provider's
 * refusal onto another's policy. `$mode` (with `$provider` for a partner) is therefore part of
 * the evidence, and each classifier's first act is to check it and return `null` when the
 * failure is not its own.
 *
 * ## What ends up in a message
 *
 * The mode, the operation label, the HTTP status, and the provider's machine-readable
 * code/subcode/type — a closed vocabulary of identifiers, normalised to
 * `NORMALISED_CODE_PATTERN` before it is interpolated.
 *
 * Never the provider's prose. Meta's `error.message` and `error_user_msg` quote the request
 * that produced them — including, on a `401`, a fragment of the `Authorization` header — and
 * these messages reach logs and, through the connection screen, tenants. The same rule
 * `BridgeRequestFailedException` states for the sidecar's `message` field, for the same
 * reason. A driver that wants to show a tenant *why* a probe failed renders its own sentence
 * from the code and passes it through `ChannelCredentials::redact()`
 * (`App\Services\Channel\ChannelHealth`).
 *
 * `fbtrace_id` is the one provider-supplied string that is carried, because it is an opaque
 * correlation handle Meta support asks for and it quotes nothing of the request.
 */
final class ChannelRequestFailedException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * The status this reports to an HTTP client when the provider's own status suggests the
     * condition is transient — a 5xx, or a rate limit.
     *
     * The provider's status is deliberately **not** passed through, exactly as
     * `BridgeRequestFailedException` refuses to: a `400` from Meta carrying error code
     * `130429` is a rate limit, not a malformed tenant request, and forwarding it verbatim
     * would tell an API client the opposite of what happened.
     */
    public const int STATUS_UNAVAILABLE = 503;

    /**
     * The status for a refusal that will not become an acceptance by waiting.
     */
    public const int STATUS_REFUSED = 422;

    public const string PUBLIC_MESSAGE = 'The messaging provider refused this operation.';

    public const string ERROR_CODE = 'channel_request_failed';

    /**
     * The shape a provider code, subcode, or type is reduced to before it is interpolated.
     *
     * Meta's codes are integers, its `type` is a bare identifier (`OAuthException`), and a
     * partner's are alphanumeric tokens. Anything outside this is dropped rather than
     * escaped: these values are compared against constants and printed into messages, so a
     * value carrying whitespace, punctuation, or a control character is not one of them.
     */
    public const string NORMALISED_CODE_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/';

    /**
     * @param  string  $operation  short label: `cloud_api.message.text`, `cloud_api.register`, …
     * @param  int  $status  the HTTP status the provider answered with
     * @param  string|null  $errorCode  the provider's own error code (Meta `error.code`)
     * @param  string|null  $errorSubcode  the provider's sub-code (Meta `error.error_subcode`)
     * @param  string|null  $errorType  the provider's error type (Meta `error.type`)
     * @param  int|null  $retryAfterSeconds  a numeric `Retry-After` the provider named
     * @param  string|null  $traceId  the provider's correlation handle (Meta `error.fbtrace_id`)
     */
    private function __construct(
        public readonly ChannelMode $mode,
        public readonly ?BspProvider $provider,
        public readonly string $operation,
        public readonly int $status,
        public readonly ?string $errorCode,
        public readonly ?string $errorSubcode,
        public readonly ?string $errorType,
        public readonly ?int $retryAfterSeconds,
        public readonly ?string $traceId,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The provider refused the operation.
     *
     * Every provider-supplied field is normalised on the way in, so a reader of
     * `$errorCode` never has to wonder whether it is a token or a sentence.
     */
    public static function refused(
        ChannelMode $mode,
        string $operation,
        int $status,
        ?string $errorCode = null,
        ?string $errorSubcode = null,
        ?string $errorType = null,
        ?int $retryAfterSeconds = null,
        ?string $traceId = null,
        ?BspProvider $provider = null,
    ): self {
        $code = self::normalise($errorCode);
        $subcode = self::normalise($errorSubcode);
        $type = self::normalise($errorType);

        return new self(
            mode: $mode,
            provider: $provider,
            operation: $operation,
            status: $status,
            errorCode: $code,
            errorSubcode: $subcode,
            errorType: $type,
            retryAfterSeconds: $retryAfterSeconds !== null && $retryAfterSeconds > 0 ? $retryAfterSeconds : null,
            traceId: self::normalise($traceId),
            message: sprintf(
                '%s refused operation [%s] with status %d%s%s%s. The provider\'s own prose is not '
                .'quoted here — it echoes the request that produced it, including an Authorization '
                .'fragment on a 401.',
                $mode->value,
                self::normaliseOperation($operation),
                $status,
                $code === null ? ' and no error code' : sprintf(' and code [%s]', $code),
                $subcode === null ? '' : sprintf(', subcode [%s]', $subcode),
                $type === null ? '' : sprintf(', type [%s]', $type),
            ),
        );
    }

    /**
     * Whether the provider's error code is one of `$codes`.
     *
     * Takes integers as well as strings because every provider that matters writes its codes
     * as numbers in JSON and as constants in documentation; comparing
     * `$e->errorCode === '130429'` at a call site would invite one classifier to compare an
     * int and another a string.
     */
    public function hasErrorCode(int|string ...$codes): bool
    {
        if ($this->errorCode === null) {
            return false;
        }

        foreach ($codes as $code) {
            if ($this->errorCode === (string) $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the provider's sub-code is one of `$subcodes`.
     *
     * Meta reuses one code for several conditions and separates them here — `error.code` 33
     * with sub-code 33 is an unknown node, and the sub-code is the only field that says so.
     */
    public function hasErrorSubcode(int|string ...$subcodes): bool
    {
        if ($this->errorSubcode === null) {
            return false;
        }

        foreach ($subcodes as $subcode) {
            if ($this->errorSubcode === (string) $subcode) {
                return true;
            }
        }

        return false;
    }

    /**
     * The status an HTTP client is told — see `STATUS_UNAVAILABLE`.
     *
     * Three pieces of evidence make it a 503, and deliberately **not** the provider's error
     * code: reading the code here would be a second copy of the classifier's table, and the two
     * would disagree the day one of them was corrected.
     *
     * | Evidence | Why it is transient |
     * |---|---|
     * | a 5xx | the provider is unwell |
     * | a `429` | the provider is pacing us |
     * | a numeric `Retry-After` | the provider **named a time to come back** |
     *
     * The last row is the one that matters for Meta, which answers **`400`** for a throughput
     * limit (`130429`) with a `Retry-After` beside it. Forwarding that 400 would tell an API
     * client its request was malformed when the honest answer is "try again in two minutes".
     */
    public function getStatusCode(): int
    {
        return $this->status >= 500 || $this->status === 429 || $this->retryAfterSeconds !== null
            ? self::STATUS_UNAVAILABLE
            : self::STATUS_REFUSED;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        if ($this->retryAfterSeconds === null) {
            return [];
        }

        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }

    public function publicMessage(): string
    {
        return self::PUBLIC_MESSAGE;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    /**
     * A provider token, or null when the value could not be one.
     */
    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return preg_match(self::NORMALISED_CODE_PATTERN, $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * The operation label, bounded and stripped of anything not printable.
     *
     * Every caller passes a constant from this codebase; the guard is for the driver that one
     * day builds a label from a path segment.
     */
    private static function normaliseOperation(string $operation): string
    {
        return mb_strimwidth(addcslashes($operation, "\0..\37\177"), 0, 60, '…');
    }
}
