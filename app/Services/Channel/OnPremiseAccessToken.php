<?php

declare(strict_types=1);

namespace App\Services\Channel;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * One bearer token issued by an On-Premise client's `POST /v1/users/login`, **with the expiry
 * it came with** — the thing that makes `ON_PREMISE` authentication different from every other
 * mode's (Req 8.5 / A8; design § Channel Mode 2.7: *"client API user/password/token"*).
 *
 * ```php
 * // OnPremiseChannelDriver, once per unit of work
 * $token = new OnPremiseAccessToken($value, $expiresAt);
 *
 * $token->isUsableAt(CarbonImmutable::now(), OnPremiseTokenCache::EXPIRY_SKEW_SECONDS);
 * $token->value();     // on the wire, and nowhere else
 * ```
 *
 * ## Why this is a type and not a `string`
 *
 * Cloud API's system-user access token is long-lived and stored: the driver reads it from
 * `channel_credentials.secret_config` on every call and never thinks about when it stops
 * working (`CloudApiErrorClassifier` maps a `190` to `AUTH` and the tenant re-enters it). The
 * On-Premise client works the other way round: what is **stored** is a username and password,
 * and the thing that goes on the wire is a bearer token the client mints on demand and stamps
 * with an `expires_after` — typically seven days, and configurable per deployment.
 *
 * A bare string cannot carry that, and the two failure modes of pretending it can are both
 * bad:
 *
 * | Pretend it is… | What happens |
 * |---|---|
 * | permanent | it expires mid-campaign, and every remaining send fails `AUTH` — which is **fail-fast by design**, so the messages are lost rather than deferred |
 * | single-use | one login per send: a 9,000-recipient campaign performs 9,000 logins against a container sized for the sends alone |
 *
 * So the expiry travels with the value, and the two callers that matter read it: `isUsableAt()`
 * decides whether the cached token may still be used, and `expiresAt` is what makes that
 * decision auditable in a test rather than a matter of timing.
 *
 * ## The expiry is always known, and that is the cache's guarantee rather than the wire's
 *
 * `$expiresAt` is **not** nullable, even though a client could answer with an unparseable
 * `expires_after` or none at all. `OnPremiseTokenCache` substitutes a short compiled-in TTL in
 * that case (`OnPremiseTokenCache::FALLBACK_TTL_SECONDS`), for the reason
 * `VerifiesProviderSignature::hmacAlgorithm()` falls back to `sha256`: an absent value must
 * degrade to a *working conservative* answer, and the conservative answer for a token whose
 * lifetime is unknown is "assume it is nearly over". A nullable field here would push that
 * decision onto every reader, and the reader that treated `null` as "no expiry" would
 * reintroduce the first row of the table above.
 *
 * ## It does not outlive the request, and cannot be made to
 *
 * Three properties, the same three `ChannelCredentials` relies on:
 *
 * 1. `value` is **private**, so no serialiser walking public properties can see it;
 * 2. `__serialize()` **throws**, so a job that closes over one fails at dispatch rather than
 *    writing a live bearer token into the `jobs` table in the clear — and, if it fails, into
 *    `failed_jobs` indefinitely;
 * 3. `__debugInfo()` reports the expiry and `[redacted]`, so `dd()` and PHPUnit's failure
 *    exporter print no credential.
 *
 * The holder (`OnPremiseTokenCache`) is bound `scoped()`, which is what bounds the lifetime to
 * one request or job; this class is what stops the value escaping that boundary sideways.
 */
final class OnPremiseAccessToken
{
    /**
     * @param  string  $value  the bearer token, as the client issued it
     * @param  CarbonImmutable  $expiresAt  when the client says it stops working
     *
     * @throws InvalidArgumentException when the token is empty
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $value,
        public readonly CarbonImmutable $expiresAt,
    ) {
        if (trim($this->value) === '') {
            throw new InvalidArgumentException(
                'An On-Premise access token cannot be empty: a blank bearer would be sent as a valid '
                .'Authorization header and refused as an auth failure, which is fail-fast — so the send '
                .'would be lost rather than retried with a real token.'
            );
        }
    }

    /**
     * The token, for the `Authorization: Bearer` header and nothing else.
     *
     * Do not assign it to a property, log it, interpolate it into an exception message, or
     * return it from anything a controller can reach.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Whether this token may still be used at `$now`, with `$skewSeconds` of head-room.
     *
     * The head-room is the point. A token that expires in two seconds is *technically* valid
     * and is the worst possible thing to start a send with: the request is in flight when it
     * lapses, the client refuses it, and the refusal is `AUTH` — never retried. Treating the
     * last `$skewSeconds` as already expired converts that lost message into one extra login.
     */
    public function isUsableAt(CarbonImmutable $now, int $skewSeconds = 0): bool
    {
        return $now->addSeconds(max(0, $skewSeconds))->lessThan($this->expiresAt);
    }

    /**
     * Seconds until the client stops honouring this token; `0` once it has lapsed.
     *
     * For a health report and for a test that wants to assert the expiry was read from the
     * client's answer rather than invented.
     */
    public function secondsUntilExpiry(?CarbonImmutable $now = null): int
    {
        $reference = $now ?? CarbonImmutable::now();

        return max(0, (int) $reference->diffInSeconds($this->expiresAt, false));
    }

    /**
     * What `dd()`, `var_dump()`, and a test-failure diff are allowed to see.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'value' => '[redacted]',
            'expiresAt' => $this->expiresAt->toIso8601String(),
        ];
    }

    /**
     * Refuse to be serialised — see the class docblock.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'Refusing to serialise an On-Premise access token: a queue or cache payload is stored in '
            .'the clear, so this would write a live bearer token to disk and leave it in failed_jobs if '
            .'the job never succeeds. Pass the tenant id and the channel mode, and log in again where '
            .'the token is used.'
        );
    }
}
