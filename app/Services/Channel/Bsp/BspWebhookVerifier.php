<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp;

use App\Enums\ChannelMode;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Services\Channel\Concerns\VerifiesProviderSignature;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * The **origin** half of `BspGatewayChannelDriver::parseWebhook()` for eight partners that
 * authenticate a callback eight different ways — written once, so no adapter implements a MAC
 * comparison of its own (Req 8.4 / A8; Req 32.1 / NFR3; Correctness Property 23).
 *
 * ```php
 * // TwilioAdapter::verifyWebhook() — the scheme is Twilio's; the comparison is not
 * $verifier->assertHmacBase64(
 *     $request,
 *     TwilioAdapter::SIGNATURE_HEADER,
 *     $credentials->requireSecret('auth_token'),
 *     algorithm: 'sha1',
 *     payload: self::canonicalPayload($request),
 * );
 * ```
 *
 * ## It extends `Concerns\VerifiesProviderSignature` rather than replacing it
 *
 * That trait is the platform's webhook-origin discipline — HMAC over the **raw** bytes, in
 * constant time, with no secret and no presented value in any message — and it is what tasks
 * 7.2 and 7.3 use directly. It is reached here by `use`, not reimplemented, so the three
 * properties it documents hold for the BSP mode too and `wa.security.hmac.algorithm` stays the
 * single place the platform's default digest is named.
 *
 * What it cannot do alone is the reason this class exists: the trait's `assertSignature()`
 * verifies a **lower-case hex** digest of the raw body, which is Meta's presentation and about
 * half of these partners'. The other half do something else, and each difference is real:
 *
 * | Scheme | Partners | Why the trait cannot express it |
 * |---|---|---|
 * | hex HMAC over the raw body | 360dialog (`X-Hub-Signature-256`) | — it is exactly `assertSignature()`, and that is what is called |
 * | **base64** HMAC | Twilio (SHA-1), Gupshup (SHA-256) | `bareDigest()` splits a presented value at its first `=`, and base64 padding *is* `=`; and the digest is not hex, so the trait's hex guard reduces it to `''`, which matches nothing |
 * | base64 HMAC over a **canonical string**, not the body | Twilio | the MAC covers the request URL plus the sorted form parameters — the raw body is not the signed material at all |
 * | a **signed JWT** carrying a `payload_hash` claim | Vonage (`Authorization: Bearer`), MessageBird (`MessageBird-Signature-JWT`) | the proof is a token whose signature covers its own claims; the body is bound to it indirectly, through a digest inside those claims |
 * | a **shared secret** presented in a header | Infobip, WATI, Kaleyra | there is no MAC to compare — see the honesty note below |
 *
 * ## The three partners with no published webhook MAC
 *
 * design § Channel Mode 2.7 gives `BSP_GATEWAY` a *"webhook signing secret"* and says nothing
 * about how any partner presents it. For five partners the partner's own public API settles it.
 * For **Infobip, WATI and Kaleyra** it does not: none of the three publishes an HMAC webhook
 * signature, and each documents callback protection as custom headers or HTTP auth the operator
 * configures on the callback itself.
 *
 * So those three get `assertSharedSecretHeader()` — a constant-time comparison of the stored
 * `webhook_secret` against a header whose *name* comes from the credentials
 * (`webhook_secret_header`), because the operator chooses it at the partner. That is weaker
 * than a MAC and is written down as such rather than dressed up: it proves possession of the
 * secret but binds nothing to the body, so a captured callback can be replayed. Inventing a
 * signature scheme for them would be worse — it would verify nothing while looking like it
 * verified something, and the day the partner shipped a real one the platform would have to
 * break the fake one.
 *
 * The recipient check (`WebhookVerificationException::wrongRecipient()`) is what limits the
 * blast radius in all eight cases, and it is not weakened by any of this: it is taken from the
 * credentials, never from the payload, and it runs for every partner alike.
 *
 * ## What it deliberately does not do
 *
 * It does not decode a body, does not look at a payload, and does not check the recipient —
 * for `VerifiesProviderSignature`'s reason, one layer further in: the recipient identity is a
 * phone number for six partners and an application name for Gupshup, so a shared verifier that
 * guessed at it would need the provider switch that having eight adapters avoids.
 */
final readonly class BspWebhookVerifier
{
    use VerifiesProviderSignature;

    /**
     * The presentation prefix a bearer-token header carries.
     */
    public const string BEARER_PREFIX = 'Bearer ';

    /**
     * The JWT algorithms a signed-webhook token may declare.
     *
     * An allowlist, and the whole of the `alg` handling: accepting the token's own `alg` is the
     * canonical JWT vulnerability — `none` makes every token valid, and an asymmetric name
     * would have the platform verify an RSA signature with the HMAC secret as a public key.
     * Both partners that sign a webhook here sign it HS256, and anything else is refused.
     */
    public const array JWT_ALGORITHMS = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];

    /**
     * The claim a signed-webhook JWT binds the request body with, and the one MessageBird also
     * binds the callback URL with.
     */
    public const string PAYLOAD_HASH_CLAIM = 'payload_hash';

    public const string URL_HASH_CLAIM = 'url_hash';

    /**
     * How far a token's `exp` may be in the past before it is refused, in seconds.
     *
     * A small allowance for clock skew between the partner and this platform, and no more: the
     * point of an expiry is that a captured callback stops being replayable, and an unbounded
     * tolerance would remove it.
     */
    public const int CLOCK_SKEW_SECONDS = 60;

    /**
     * Always `BSP_GATEWAY` — the mode every refusal from this class names.
     *
     * The one member `VerifiesProviderSignature` requires. It is the *mode*, not the partner:
     * `WebhookVerificationException` is keyed on `ChannelMode`, and the partner is identified
     * in a refusal by the header name it presented its proof in, which is partner-specific and
     * safe to print.
     */
    public function mode(): ChannelMode
    {
        return ChannelMode::BspGateway;
    }

    /**
     * Refuse the request unless `$header` carries a **hex** HMAC of its raw body under
     * `$secret` — 360dialog's `X-Hub-Signature-256`, and Meta's presentation generally.
     *
     * Straight through to `VerifiesProviderSignature::assertSignature()`, so the raw-bytes rule,
     * the constant-time comparison and the `sha256=` prefix tolerance are the platform's rather
     * than this class's.
     *
     * @throws WebhookVerificationException when the header is absent or the digest does not match
     */
    public function assertHmacHex(Request $request, string $header, #[SensitiveParameter] string $secret): void
    {
        $this->assertSignature($request, $header, $secret);
    }

    /**
     * Refuse the request unless `$header` carries a **base64** HMAC of `$payload` under
     * `$secret` — Twilio's `X-Twilio-Signature` and Gupshup's `X-Gupshup-Signature`.
     *
     * `$payload` defaults to the raw body, which is Gupshup's signed material. Twilio's is a
     * canonical string it builds itself from the request URL and the sorted form parameters, and
     * passing it explicitly is why this parameter exists — the alternative would be a verifier
     * that knew about Twilio, in a class whose whole purpose is not to.
     *
     * The comparison is written here rather than delegated because
     * `VerifiesProviderSignature::signatureMatches()` normalises through `bareDigest()`, which
     * (correctly, for a hex digest) truncates at the first `=` and then requires lower-case hex.
     * A base64 digest fails both halves: its padding is `=`, and its alphabet is not hex. Both
     * sides are compared as raw bytes after a strict decode, so a presented value that is not
     * valid base64 cannot match anything.
     *
     * @param  string  $algorithm  the partner's digest — `sha1` for Twilio, `sha256` for Gupshup
     * @param  string|null  $payload  the signed material; the raw body when null
     *
     * @throws WebhookVerificationException when the header is absent, the algorithm is unavailable,
     *                                      or the digest does not match
     */
    public function assertHmacBase64(
        Request $request,
        string $header,
        #[SensitiveParameter] string $secret,
        string $algorithm = 'sha256',
        ?string $payload = null,
    ): void {
        $presented = trim((string) $request->header($header, ''));

        if ($presented === '') {
            throw WebhookVerificationException::missingSignature($this->mode(), $header);
        }

        if ($secret === '' || ! in_array($algorithm, hash_hmac_algos(), true)) {
            // An empty secret produces a perfectly valid MAC over anything, so a credential that
            // failed to decrypt would otherwise leave a verifier that accepts whatever an
            // attacker can compute — `VerifiesProviderSignature::signatureMatches()`'s argument.
            // An algorithm this build cannot compute is the same situation: nothing can be
            // verified, so nothing is accepted.
            throw WebhookVerificationException::badSignature($this->mode(), $header);
        }

        $decoded = base64_decode($presented, true);
        $expected = hash_hmac($algorithm, $payload ?? $request->getContent(), $secret, binary: true);

        if ($decoded === false || ! hash_equals($expected, $decoded)) {
            throw WebhookVerificationException::badSignature($this->mode(), $header);
        }
    }

    /**
     * Refuse the request unless `$header` presents `$secret` verbatim — the scheme for the three
     * partners that publish no webhook MAC (see the class docblock).
     *
     * Constant time, and length-blinded: `VerifiesProviderSignature::tokensMatch()` hashes both
     * sides first, because `hash_equals()` returns immediately when the lengths differ and
     * comparing raw tokens therefore leaks the stored one's length. A bearer presentation is
     * tolerated, since an operator configuring `Authorization` at the partner will usually write
     * one.
     *
     * Reported as a *signature* failure rather than a distinct kind, deliberately: to a caller
     * these are the same refusal (`WebhookVerificationException::PUBLIC_MESSAGE` is uniform
     * anyway), and the header name in the message is what tells an operator which check ran.
     *
     * @throws WebhookVerificationException when the header is absent or does not present the secret
     */
    public function assertSharedSecretHeader(
        Request $request,
        string $header,
        #[SensitiveParameter] string $secret,
    ): void {
        $presented = trim((string) $request->header($header, ''));

        if ($presented === '') {
            throw WebhookVerificationException::missingSignature($this->mode(), $header);
        }

        if (str_starts_with($presented, self::BEARER_PREFIX)) {
            $presented = trim(substr($presented, strlen(self::BEARER_PREFIX)));
        }

        if (! self::tokensMatch($secret, $presented)) {
            throw WebhookVerificationException::badSignature($this->mode(), $header);
        }
    }

    /**
     * Refuse the request unless `$header` carries a JWT signed with `$secret` whose
     * `payload_hash` claim is the SHA-256 of the raw body — Vonage's and MessageBird's scheme.
     *
     * Four checks, and every one of them is load-bearing:
     *
     * 1. **`alg` is on the allowlist.** Trusting the token's own `alg` is how a JWT verifier is
     *    defeated: `{"alg":"none"}` makes every token valid.
     * 2. **The signature covers `header.payload`,** compared in constant time over raw bytes.
     * 3. **`payload_hash` matches the body.** Without it the JWT proves only that *some* token
     *    was signed — a captured one would authenticate any body an attacker liked, which is
     *    precisely the replay these partners bind the digest to prevent. A body-less callback
     *    (a partner ping) may omit the claim; a body-carrying one may not.
     * 4. **`exp`, when present, has not passed** beyond `CLOCK_SKEW_SECONDS`.
     *
     * @param  bool  $bindsUrl  also require `url_hash` to be the SHA-256 of the full request URL
     *                          — MessageBird sends it, and it is what stops a callback captured on
     *                          one tenant's route key from being replayed onto another's
     *
     * @throws WebhookVerificationException when any of the four checks fails
     */
    public function assertSignedJwt(
        Request $request,
        string $header,
        #[SensitiveParameter] string $secret,
        bool $bindsUrl = false,
    ): void {
        $presented = trim((string) $request->header($header, ''));

        if ($presented === '') {
            throw WebhookVerificationException::missingSignature($this->mode(), $header);
        }

        if (str_starts_with($presented, self::BEARER_PREFIX)) {
            $presented = trim(substr($presented, strlen(self::BEARER_PREFIX)));
        }

        $claims = $this->jwtClaims($presented, $secret);

        if ($claims === null) {
            throw WebhookVerificationException::badSignature($this->mode(), $header);
        }

        $body = $request->getContent();
        $payloadHash = self::stringClaim($claims, self::PAYLOAD_HASH_CLAIM);

        if ($body !== '') {
            if ($payloadHash === null || ! hash_equals(hash('sha256', $body), strtolower($payloadHash))) {
                // The token is authentic and says nothing about *this* body. Refused as a bad
                // signature because that is what it is: the proof does not cover the payload.
                throw WebhookVerificationException::badSignature($this->mode(), $header);
            }
        }

        if ($bindsUrl) {
            $urlHash = self::stringClaim($claims, self::URL_HASH_CLAIM);

            if ($urlHash === null || ! hash_equals(hash('sha256', $request->fullUrl()), strtolower($urlHash))) {
                throw WebhookVerificationException::badSignature($this->mode(), $header);
            }
        }

        $expiry = $claims['exp'] ?? null;

        if (is_int($expiry) && $expiry + self::CLOCK_SKEW_SECONDS < time()) {
            throw WebhookVerificationException::badSignature($this->mode(), $header);
        }
    }

    /**
     * A JWT's claims when its signature checks out under `$secret`, or `null`.
     *
     * Public because an adapter may need a claim the checks above do not cover, and because
     * asserting on the decode in isolation is how the `alg` allowlist gets tested. Returns
     * `null` for every way of being invalid — a malformed token, an unlisted algorithm, a bad
     * signature — because to a caller they are the same answer and distinguishing them in a
     * public-endpoint refusal is a hint.
     *
     * @return array<string, mixed>|null
     */
    public function jwtClaims(string $token, #[SensitiveParameter] string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = self::decodeSegment($encodedHeader);
        $claims = self::decodeSegment($encodedClaims);
        $signature = self::base64UrlDecode($encodedSignature);

        if ($header === null || $claims === null || $signature === null) {
            return null;
        }

        $algorithm = self::stringClaim($header, 'alg');

        if ($algorithm === null || ! array_key_exists($algorithm, self::JWT_ALGORITHMS)) {
            return null;
        }

        $expected = hash_hmac(
            self::JWT_ALGORITHMS[$algorithm],
            $encodedHeader.'.'.$encodedClaims,
            $secret,
            binary: true,
        );

        return hash_equals($expected, $signature) ? $claims : null;
    }

    /**
     * One base64url JWT segment as a claim map, or `null` when it is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeSegment(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $normalised */
        $normalised = [];

        foreach ($decoded as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * Strict base64url decode, or `null`.
     *
     * Strict because a token segment is machine-generated: a value carrying characters outside
     * the alphabet is not a segment that lost its padding, it is not a segment.
     */
    private static function base64UrlDecode(string $segment): ?string
    {
        $padded = strtr($segment, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * One claim as a non-empty string, or `null`.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function stringClaim(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
