<?php

declare(strict_types=1);

namespace App\Services\Channel;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Where an On-Premise bearer token lives, and for exactly how long — the answer to *"a token
 * that expires mid-campaign must be re-obtained rather than causing a run of auth failures,
 * without being memoised past the request or job that decrypted the credentials"*
 * (Req 8.5 / A8; design § Channel Mode 2.7).
 *
 * ```php
 * // OnPremiseChannelDriver: one login per unit of work, not one per send
 * $token = $this->tokens->remember(
 *     OnPremiseTokenCache::keyFor($credentials, OnPremiseChannelDriver::USERNAME_SECRET_KEY, OnPremiseChannelDriver::PASSWORD_SECRET_KEY),
 *     fn (): OnPremiseAccessToken => $this->login($credentials),
 * );
 * ```
 *
 * ## Why the token is cached at all, and why the cache is an object
 *
 * `CloudApiChannelDriver` is emphatic that credentials are resolved *"on **every** call rather
 * than memoised"*, and this class looks like the opposite of that. It is not, and the
 * difference is what is being held:
 *
 * | | Cloud API | On-Premise |
 * |---|---|---|
 * | what is stored | a long-lived system-user access token | a **username and password** |
 * | what goes on the wire | that same stored token | a bearer token minted by `POST /v1/users/login`, with an `expires_after` |
 * | cost of not caching | none — nothing is minted | one login per send: 9,000 logins for a 9,000-recipient campaign |
 *
 * So the *stored* material is still read through `ChannelCredentialStore` on every call, exactly
 * as 7.2 requires — a rotated password is picked up by the next send. What is cached is the
 * short-lived thing the provider just issued, and it is cached in a **separate object** rather
 * than on the driver for three reasons:
 *
 * 1. **The driver stays `final readonly`.** A mutable property on it would make the driver
 *    stateful, and a stateful driver held by a `scoped()` router is a much easier thing to get
 *    wrong than a collaborator whose entire job is one lifetime decision.
 * 2. **The lifetime is stated once, in the container.** This binding is `scoped()`, which
 *    Laravel discards at the end of every request and the queue worker resets between jobs —
 *    the same mechanism `ChannelServiceProvider` already relies on for
 *    `ChannelCredentialStore` and `ChannelRouter`, and for the same reason: *"one tenant's
 *    resolved credentials cannot be carried into another tenant's job inside a long-lived
 *    worker"*.
 * 3. **It is assertable.** A test can call `size()` and `has()` and prove that one unit of work
 *    performed one login, which is not something a private property on a readonly class lets
 *    anybody check.
 *
 * ## Why this is safe under Req 8.5, stated precisely
 *
 * Req 8.5 requires decrypted credential values be held *"only within the lifetime of the
 * request or job that uses them, discarding them before that request or job returns"*. Four
 * things make that true here rather than hoped for:
 *
 * - the binding is `scoped()`, so the **holder** does not outlive the unit of work;
 * - a bearer token is not a stored credential value at all — it is minted during this unit of
 *   work and dies with the container's own expiry, so nothing that was decrypted is retained;
 * - `__serialize()` throws on both this class and `OnPremiseAccessToken`, so neither can ride a
 *   queue payload out of the unit of work sideways;
 * - the key is derived from the **password**, so a credential rotation cannot be answered with a
 *   token minted from the value it replaced — see `keyFor()`.
 *
 * ## The two constants are the whole expiry policy
 *
 * - `EXPIRY_SKEW_SECONDS` — a token inside its last minute is treated as already expired. This
 *   is the row Req 8.5's *"re-obtained rather than causing a run of auth failures"* turns on: an
 *   auth failure is `ErrorClass::Auth`, which is **structurally** zero attempts, so a send that
 *   started with two seconds of token left is a **lost message**, not a deferred one. One extra
 *   login is the cheaper end of that trade by a wide margin.
 * - `FALLBACK_TTL_SECONDS` — what an unparseable or absent `expires_after` is treated as. Short
 *   on purpose: "unknown lifetime" must degrade to *nearly over*, never to *forever*.
 */
final class OnPremiseTokenCache
{
    /**
     * How much of a token's tail is treated as already expired — see the class docblock.
     */
    public const int EXPIRY_SKEW_SECONDS = 60;

    /**
     * The lifetime assumed when the client's `expires_after` could not be read.
     */
    public const int FALLBACK_TTL_SECONDS = 300;

    /**
     * @var array<string, OnPremiseAccessToken>
     */
    private array $tokens = [];

    /**
     * The token for `$key`, minting one through `$login` when there is no usable one.
     *
     * "Usable" is `OnPremiseAccessToken::isUsableAt()` with `EXPIRY_SKEW_SECONDS` of head-room,
     * so a token whose remaining life is shorter than a plausible request is replaced rather
     * than spent.
     *
     * @param  callable(): OnPremiseAccessToken  $login  performs `POST /v1/users/login`
     */
    public function remember(string $key, callable $login): OnPremiseAccessToken
    {
        $existing = $this->tokens[$key] ?? null;

        if ($existing !== null && $existing->isUsableAt(CarbonImmutable::now(), self::EXPIRY_SKEW_SECONDS)) {
            return $existing;
        }

        $token = $login();
        $this->tokens[$key] = $token;

        return $token;
    }

    /**
     * Drop the token for `$key`, so the next `remember()` logs in again.
     *
     * The seam the driver's one-shot re-authentication uses: an On-Premise client can decide a
     * token is no longer good for reasons this process cannot see — a restart that dropped its
     * session table, an operator revoking the user, a clock this side being ahead. When it
     * answers *access denied* to a call made with a token this cache still believes in, the
     * cached copy is the thing that is wrong, and it is discarded before the single replay.
     */
    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }

    /**
     * Drop every token held.
     *
     * For a caller that has just changed credentials wholesale, and for a test that wants the
     * next call to log in without reasoning about keys.
     */
    public function flush(): void
    {
        $this->tokens = [];
    }

    /**
     * Whether a token is held for `$key` — regardless of whether it is still usable.
     *
     * Deliberately not "…and is usable": a test asserting that an expired token was *replaced*
     * needs to distinguish "nothing was cached" from "something stale was cached", and a
     * usability-aware predicate collapses the two.
     */
    public function has(string $key): bool
    {
        return isset($this->tokens[$key]);
    }

    /**
     * How many distinct logins this unit of work has performed.
     *
     * The number a test asserts to prove a campaign logged in once rather than once per send.
     */
    public function size(): int
    {
        return count($this->tokens);
    }

    /**
     * The cache key for one login — derived from the credentials, **including the secrets that
     * authenticate it**.
     *
     * The tenant id and the credential row id are in the key for the reason
     * `DedupesChannelSends` puts the tenant in the dedup scope: two tenants on the same
     * platform can perfectly well point at containers that issue interchangeable-looking
     * tokens, and answering tenant B's login with tenant A's bearer would send B's traffic
     * authenticated as A.
     *
     * The **named secrets' values** are in it as well, hashed, and that is the part worth
     * arguing for: without them, a tenant who rotated the client's password would keep being
     * handed the token minted from the old one until the request ended. Including them means a
     * rotation produces a different key and therefore a fresh login, which is Req 8.6's *"apply
     * new sends using the new credentials"* holding for the token layer too — not only for the
     * stored bag.
     *
     * The whole thing is a single SHA-256 digest, so a secret cannot be recovered from a key
     * that reaches a debug dump or a test failure message.
     *
     * @param  string  ...$secretKeys  the secret keys a login is performed with
     */
    public static function keyFor(ChannelCredentials $credentials, string ...$secretKeys): string
    {
        $material = [
            $credentials->tenantId,
            $credentials->mode->value,
            $credentials->credentialId ?? '<unstored>',
        ];

        foreach ($secretKeys as $secretKey) {
            // A missing secret contributes a marker rather than nothing, so "no password stored"
            // and "the password is the empty string" are different keys. The login will fail
            // either way; what matters is that it is not answered from a cache entry minted when
            // the field was filled in.
            $material[] = $secretKey.'='.($credentials->secret($secretKey) ?? '<absent>');
        }

        return hash('sha256', implode("\0", $material));
    }

    /**
     * The token an On-Premise `POST /v1/users/login` answer describes.
     *
     * `expires_after` is the client's own spelling — `"2024-05-01 15:29:26+00:00"` — and it is
     * parsed here rather than by the driver because this class owns the fallback. Three answers
     * are treated as *"lifetime unknown"* and get `FALLBACK_TTL_SECONDS`: an absent field, one
     * that is not a date, and one that is already in the past (a container whose clock is behind
     * this one, which is otherwise a token that can never be used and therefore an infinite
     * login loop).
     */
    public static function mint(string $value, ?string $expiresAfter, ?CarbonImmutable $now = null): OnPremiseAccessToken
    {
        $reference = $now ?? CarbonImmutable::now();
        $fallback = $reference->addSeconds(self::FALLBACK_TTL_SECONDS);

        return new OnPremiseAccessToken($value, self::expiryFrom($expiresAfter, $reference) ?? $fallback);
    }

    /**
     * A usable future instant from the client's `expires_after`, or null.
     */
    private static function expiryFrom(?string $expiresAfter, CarbonImmutable $now): ?CarbonImmutable
    {
        $raw = trim((string) $expiresAfter);

        if ($raw === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            // A verifier-style rule: an unreadable value is treated as absent, never as a raise.
            // A login is on the send path, and an exception here would turn a cosmetic change in
            // the client's date format into every send failing.
            return null;
        }

        return $parsed->greaterThan($now) ? $parsed : null;
    }

    /**
     * Refuse to be serialised.
     *
     * The holder of live bearer tokens must not be able to reach a queue payload or a cache
     * entry, for `ChannelCredentials::__serialize()`'s reason: both are stored in the clear.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'Refusing to serialise the On-Premise token cache: it holds live bearer tokens, and a queue '
            .'or cache payload is stored in the clear. It is bound scoped() so that one unit of work '
            .'gets one instance; resolve it from the container inside handle() instead of passing it.'
        );
    }

    /**
     * What a debug dump may see: how many tokens, and when each expires. Never a value.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $expiries = [];

        foreach ($this->tokens as $key => $token) {
            // The key is already a digest, so a short prefix of it is a correlation handle that
            // reveals nothing — the same discipline the exception fingerprints use.
            $expiries[substr($key, 0, 8)] = $token->expiresAt->toIso8601String();
        }

        return ['tokens' => count($this->tokens), 'expiresAt' => $expiries];
    }
}
