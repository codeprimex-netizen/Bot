<?php

declare(strict_types=1);

namespace App\Services\Channel\Concerns;

use App\Enums\ChannelMode;
use App\Exceptions\Channel\WebhookVerificationException;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * The origin half of `ChannelDriver::parseWebhook()` for the modes whose signing secret is a
 * **tenant credential** rather than a platform-issued one — written once, so the three
 * official drivers of tasks 7.2–7.4 cannot each invent an HMAC scheme (Req 8.4 / A8;
 * Req 32.1 / NFR3).
 *
 * ```php
 * final readonly class CloudApiChannelDriver implements ChannelDriver
 * {
 *     use VerifiesProviderSignature;
 *
 *     public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
 *     {
 *         // 1. origin: the raw body, under the tenant's Meta app secret
 *         $this->assertSignature($request, self::SIGNATURE_HEADER, $appSecret);
 *         // 2. recipient, 3. shape — the driver's own
 *     }
 * }
 * ```
 *
 * ## Why this is not `SigningSecretStore`
 *
 * `BaileysChannelDriver` verifies the sidecar's callback through
 * `App\Services\Security\SigningSecretStore`, and it is right to: the bridge's webhook secret
 * is **issued by this platform**, so it lives in `signing_secrets`, is sealed by `KeyWrapper`,
 * and gets that store's dual-secret rotation window for free.
 *
 * An official provider's secret is the opposite shape. Meta's **app secret** is issued by
 * Meta, entered by the tenant, and stored in `channel_credentials.secret_config` (design
 * § 2.7's per-mode table: *"system-user access token, webhook verify token, app secret"*).
 * The platform cannot rotate it, cannot version it, and never signs anything with it — it
 * only ever verifies. So `SigningSecretStore`'s two features are both inapplicable: there is
 * no `sign()` direction, and rotation is the tenant re-entering a value in a panel, which is
 * a `channel_credentials` write and not a `signing_secrets` version bump.
 *
 * What *is* shared with that store, and is read from the same place rather than restated, is
 * the **scheme**: `wa.security.hmac.algorithm` and `wa.security.hmac.prefix`. That config
 * already documents itself as *"`sha256=<hex>` — the shape Meta and most gateways use"*, and
 * changing the algorithm is described there as a platform-wide migration. One algorithm, one
 * presentation, two stores.
 *
 * ## The three properties every check here has
 *
 * 1. **Over the raw bytes.** `$request->getContent()`, never a re-encoding of the decoded
 *    array: `json_encode(json_decode($body))` changes whitespace, key order and unicode
 *    escaping, and the signature is over bytes. This is the single most common way a webhook
 *    verifier is written wrong, and its failure mode is that *nothing* verifies.
 * 2. **Constant time.** `hash_equals()` on both signatures and tokens. A byte-at-a-time
 *    comparison of a verify token is a webhook takeover in a few thousand requests.
 * 3. **No secret and no presented value in any message.** The exception's own docblock
 *    settles that: quoting the presented signature lets whoever reads logs replay it, and
 *    quoting the expected one is printing the secret.
 *
 * ## What it deliberately does not do
 *
 * It does not decode the body, does not look at the payload, and does not check the
 * **recipient**. Recipient checking is per-provider (Meta's `metadata.phone_number_id`, a
 * partner's sender id) and is the security content of each driver's own `parseWebhook()`
 * (`WebhookVerificationException::wrongRecipient()`, Property 23) — a trait that guessed at
 * it would either be wrong for two modes out of three or would have to grow a provider
 * switch, which is the thing having three drivers avoids.
 *
 * The only member it requires is `mode()`, so the exceptions it raises name the right backend
 * without the trait having to be told twice.
 */
trait VerifiesProviderSignature
{
    /**
     * Which backend this is — supplied by the driver, and named in every refusal.
     */
    abstract public function mode(): ChannelMode;

    /**
     * The HMAC algorithm this platform verifies webhooks with, when config names a usable one.
     *
     * `sha256` is the compiled-in fallback for the reason `HttpBridgeClient::DEFAULT_TIMEOUT`
     * exists: a deleted or nonsensical key must degrade to a working default rather than to
     * an empty digest that verifies against nothing. An algorithm this build cannot compute is
     * treated as absent — `hash_hmac()` with an unknown algorithm raises, and a raise inside a
     * verifier turns a malformed header into a 500 on a public endpoint.
     */
    protected static function hmacAlgorithm(): string
    {
        $configured = config('wa.security.hmac.algorithm');
        $algorithm = is_string($configured) ? strtolower(trim($configured)) : '';

        return in_array($algorithm, hash_hmac_algos(), true) ? $algorithm : 'sha256';
    }

    /**
     * The presentation prefix a signature may carry — `sha256=` by default.
     */
    protected static function hmacPrefix(): string
    {
        $configured = config('wa.security.hmac.prefix');

        return is_string($configured) ? trim($configured) : '';
    }

    /**
     * Refuse `$request` unless `$header` carries a valid HMAC of its **raw body** under
     * `$secret`.
     *
     * The first of the three checks `ChannelDriver::parseWebhook()` prescribes, and the only
     * one that can be shared. A missing header and a wrong signature are distinguished
     * because the causes differ — the first is usually a callback registered without a signing
     * secret, the second is a rotated secret or a forgery — and an operator needs to know
     * which.
     *
     * @param  string  $header  the header the proof is read from; a fixed protocol name, not input
     *
     * @throws WebhookVerificationException when the header is absent or the signature does not match
     */
    protected function assertSignature(
        Request $request,
        string $header,
        #[SensitiveParameter]
        string $secret,
    ): void {
        $presented = trim((string) $request->header($header, ''));

        if ($presented === '') {
            throw WebhookVerificationException::missingSignature($this->mode(), $header);
        }

        if (! self::signatureMatches($request->getContent(), $presented, $secret)) {
            throw WebhookVerificationException::badSignature($this->mode(), $header);
        }
    }

    /**
     * Whether `$presented` is a valid signature of `$payload` under `$secret`.
     *
     * Tolerant of how the peer labelled the digest (`sha256=…`, a bare hex string) and
     * intolerant of everything else: a presented value that is not lower-case hex after the
     * label is stripped reduces to the empty string, which matches nothing. An empty
     * `$secret` is refused outright rather than used — `hash_hmac()` accepts one and produces
     * a perfectly valid digest, so a tenant whose app secret failed to decrypt would
     * otherwise get a verifier that accepts anything an attacker can compute.
     */
    protected static function signatureMatches(
        string $payload,
        string $presented,
        #[SensitiveParameter]
        string $secret,
    ): bool {
        if ($secret === '') {
            return false;
        }

        $digest = self::bareDigest($presented);

        if ($digest === '') {
            return false;
        }

        return hash_equals(hash_hmac(self::hmacAlgorithm(), $payload, $secret), $digest);
    }

    /**
     * Whether two tokens are equal, in constant time and without leaking their lengths.
     *
     * The comparison Meta's `hub.verify_token` handshake needs (task 7.2) and the On-Premise
     * callback's shared secret needs (7.3). Both sides are hashed before comparison for one
     * reason `hash_equals()` alone does not give: that function returns `false` immediately
     * when the lengths differ, so comparing raw tokens leaks the stored token's length. Fixed
     * -width digests make every comparison take the same path — the construction
     * `ChannelWebhookRoute::matchesVerifyToken()` uses, for the same reason.
     *
     * An empty value on either side is `false`: a route with no token stored cannot complete a
     * handshake, and an empty presented token must never match an empty stored one.
     */
    protected static function tokensMatch(
        #[SensitiveParameter]
        string $expected,
        string $presented,
    ): bool {
        if ($expected === '' || $presented === '') {
            return false;
        }

        $algorithm = self::hmacAlgorithm();

        return hash_equals(hash($algorithm, $expected), hash($algorithm, $presented));
    }

    /**
     * A presented signature reduced to its bare lower-case hex digest, or `''`.
     *
     * Deliberately the same normalisation `DatabaseSigningSecretStore::normalize()` performs,
     * including tolerating a peer that labels the algorithm its own way (`hmac-sha256=…`): the
     * digest is what is compared, and a wrong label simply will not match.
     */
    private static function bareDigest(string $signature): string
    {
        $value = trim($signature);
        $prefix = self::hmacPrefix();

        if ($prefix !== '' && str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        } elseif (($equals = strpos($value, '=')) !== false) {
            $value = substr($value, $equals + 1);
        }

        $value = strtolower(trim($value));

        return preg_match('/^[0-9a-f]+$/', $value) === 1 ? $value : '';
    }
}
