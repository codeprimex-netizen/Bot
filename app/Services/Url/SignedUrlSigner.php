<?php

declare(strict_types=1);

namespace App\Services\Url;

use App\Enums\SignedUrlVerdict;
use App\Exceptions\Security\KeyUnavailableException;
use App\Exceptions\Url\InvalidBaseUrlException;
use App\Exceptions\Url\InvalidSignedUrlException;
use App\Exceptions\Url\UnbuildableUrlException;
use App\Services\Security\SigningSecretStore;
use App\Support\Url\CanonicalBase;
use App\Support\Url\UrlPath;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Signed, expiring URLs whose signature **binds the canonical host** (Req 9.6 / A9;
 * design § Base URL §U.4, §U.5; Correctness Property 27).
 *
 * ```php
 * $url = $signer->sign($base, '/exports/01J.../download', now()->addMinutes(15));
 * // https://bot.example.com/exports/01J.../download?wa_issued=…&wa_expires=…&wa_signature=…
 *
 * $signer->verify($url)->isValid();   // true here, false at any other host
 * ```
 *
 * ## The attack this exists to stop
 *
 * A signature over path-and-expiry alone is portable. An attacker who obtains one export
 * link — a forwarded email, a shared browser history, a referer header — can replay the
 * path, expiry and signature against any host that runs this platform, or any host they
 * can make resolve to it: a staging node, a tenant's custom domain, a machine of their
 * own behind a permissive proxy. The signature still checks out, because nothing in it
 * says *where* the link was for, and the platform's own signature vouches for the
 * request. So the host is a signed field:
 *
 * ```
 * HMAC( 'wa:signed-url:v1' | len:authority | len:path | len:issued | len:expires )
 *                              ▲
 *                              └── CanonicalBase::authority() — host[:port], one spelling
 * ```
 *
 * Verification recomputes over the authority of the URL **as presented**, so a
 * transplanted link produces a different expected signature and is refused. There is no
 * host field beside the signature to compare — nothing to strip, nothing to forge, and
 * nothing that tells a holder which host a link was issued for.
 *
 * Every field is length-prefixed (`15:bot.example.com`) so no two different tuples
 * serialise to the same payload. Without that, an authority ending in a path-like suffix
 * and a path beginning with one are interchangeable — the ambiguity behind the whole
 * family of signed-URL confusion bugs, including the Laravel 11 advisory that motivated
 * this platform's move to Laravel 12 (design decision 23).
 *
 * ## Why not the framework's signer
 *
 * `URL::signedRoute()` / `Illuminate\Routing\Middleware\ValidateSignature` sign the URL
 * *including* its host — but the host they use comes from the request-aware URL
 * generator, which is precisely the input Req 9.1 forbids and Property 27 forbids
 * structurally. Wiring the base into it would mean teaching a request-derived generator
 * to ignore the request. This class instead takes a `CanonicalBase` as an argument: there
 * is no code path by which a request host can become a signed host, because there is no
 * parameter that accepts one. What is added on top of a plain HMAC is the length-prefixed
 * framing, the explicit 3600-second ceiling enforced at *both* ends, and a total
 * verification result.
 *
 * ## The signing secret, and what rotating it costs
 *
 * `SigningSecretStore` under the platform-wide scope `url:signed` — the same
 * dual-secret, KMS-sealed store the bridge and gateway webhook secrets use (task 4.2),
 * not a new secret and not `APP_KEY`. Two consequences, both wanted:
 *
 *  - **rotation is invisible to links in the wild.** The overlap window
 *    (`wa.security.hmac.overlap_hours`, 48h by default) is orders of magnitude longer
 *    than the 3600-second ceiling on a link's life, so every outstanding export and
 *    payment link still verifies under the retired secret while only new links are signed
 *    with the new one. Deriving the key from `APP_KEY` instead would make every rotation a
 *    hard cut-over that kills links already in people's inboxes.
 *  - **the secret is never shared.** Unlike a webhook scope, nothing calls
 *    `currentSecret()` for this one: no peer, no tenant and no browser ever needs it, so
 *    the scope is platform-wide rather than per tenant. A per-tenant secret would add a
 *    row and a rotation per tenant to defend against a party that never holds the secret;
 *    cross-tenant separation comes from the bound authority and the signed path instead.
 *
 * Signing fails closed (`KeyUnavailableException`, 503) when the key store cannot issue a
 * secret. Verification never fails open and never throws: an unopenable secret makes a
 * signature invalid, not accepted.
 */
final class SignedUrlSigner
{
    /**
     * The longest a signed URL may be valid for, in seconds (Req 9.6).
     *
     * A constant and not config: a config key here would be a way to raise a security
     * ceiling from an env file, and Req 9.6 names this number. `wa.url.signed.ttl_seconds`
     * sets the *default* below it and is refused if it exceeds it.
     */
    public const int MAX_WINDOW_SECONDS = 3600;

    /**
     * `SigningSecretStore` scope holding the URL-signing secret. Platform-wide: no peer
     * and no tenant ever receives this secret.
     */
    public const string SECRET_SCOPE = 'url:signed';

    /**
     * Query parameters a signed URL carries — and the only ones it may carry.
     *
     * Deliberately **not** the framework's `expires`/`signature`. Two signers with the
     * same parameter names but different guarantees (ours binds the host, the
     * framework's binds whatever the request generator produced) is the kind of
     * near-miss a reader cannot see: a route protected by the wrong middleware would
     * look like it worked. With distinct names each signer simply refuses the other's
     * links.
     */
    public const string ISSUED_PARAM = 'wa_issued';

    public const string EXPIRES_PARAM = 'wa_expires';

    public const string SIGNATURE_PARAM = 'wa_signature';

    /**
     * Domain separator: this HMAC is over a signed URL and nothing else, and the version
     * makes a future change of the payload shape a refusal rather than an ambiguity.
     */
    private const string PAYLOAD_VERSION = 'wa:signed-url:v1';

    /**
     * A lowercase hex digest of a plausible length for any `hash_hmac` algorithm
     * (`sha256` is 64 characters, `sha512` 128).
     */
    private const string SIGNATURE_PATTERN = '/^[0-9a-f]{32,256}$/';

    /**
     * A unix timestamp, bounded so `(int)` cannot overflow on hostile input.
     */
    private const string TIMESTAMP_PATTERN = '/^[0-9]{1,12}$/';

    /**
     * Named in `InvalidBaseUrlException` messages when a presented host cannot be
     * normalised.
     */
    private const string PRESENTED_HOST_SOURCE = 'the presented URL';

    /**
     * Schemes a presented URL may use, so the scheme's default port is well defined.
     *
     * @var array<string, int>
     */
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(private readonly SigningSecretStore $secrets) {}

    /**
     * A signed, expiring URL for `$path` under `$base`.
     *
     * @param  string  $path  path relative to the base's mount point
     * @param  DateTimeInterface|null  $expiresAt  null uses `wa.url.signed.ttl_seconds`
     *
     * @throws UnbuildableUrlException when the path is unusable, or the window has passed
     *                                 or exceeds `MAX_WINDOW_SECONDS`
     * @throws KeyUnavailableException when no signing secret can be issued
     */
    public function sign(CanonicalBase $base, string $path, ?DateTimeInterface $expiresAt = null): string
    {
        // Emptiness is judged on the path the *caller* passed, before the mount point is
        // prefixed: otherwise `signed('')` would refuse on a root-mounted deployment and
        // quietly sign the mount root on one mounted under `/app`.
        $relative = UrlPath::normalize($path, 'signed');

        if ($relative === '') {
            throw UnbuildableUrlException::emptyPath('signed');
        }

        $absolute = UrlPath::under($base, $relative, 'signed');

        // One clock read for both timestamps, so the window written into the URL is
        // exactly the window that was checked.
        $issuedAt = Carbon::now()->getTimestamp();
        $expires = $expiresAt === null
            ? $issuedAt + $this->defaultTtlSeconds()
            : $expiresAt->getTimestamp();

        $window = $expires - $issuedAt;

        if ($window <= 0) {
            throw UnbuildableUrlException::expiryNotInFuture($expires, $issuedAt);
        }

        if ($window > self::MAX_WINDOW_SECONDS) {
            // Refused here, not clamped and not left to verification: a caller that asked
            // for a month-long link would otherwise hand out a URL it believes in and the
            // platform rejects an hour later, which is the worst of both.
            throw UnbuildableUrlException::windowTooLong($window, self::MAX_WINDOW_SECONDS);
        }

        $signature = $this->signature($base->authority(), $absolute, $issuedAt, $expires);

        return $this->origin($base).$absolute.'?'.http_build_query(
            [
                self::ISSUED_PARAM => $issuedAt,
                self::EXPIRES_PARAM => $expires,
                self::SIGNATURE_PARAM => $signature,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * Whether `$presentedUrl` is a signed URL of this platform that is still valid, and
     * if not, why (for the log — never for the response; see `SignedUrlVerdict`).
     *
     * **Total.** Every input produces a verdict: an empty string, a URL with no host, a
     * repeated parameter, a signature of the wrong shape, a secret that cannot be opened.
     * Untrusted input therefore cannot choose between two HTTP outcomes, which is the
     * same reason `SigningSecretStore::verify()` returns `false` rather than raising.
     *
     * `$presentedUrl` must be absolute (`https://host/path?…`), because the host is what
     * is being checked. Callers build it from the request they are serving — that request
     * host is untrusted input to a *comparison* here, which is safe, and is never an
     * input to URL generation, which would not be.
     */
    public function verify(string $presentedUrl): SignedUrlVerdict
    {
        // One clock read: every expiry comparison below is against the same instant, so a
        // link cannot be inside the window for one check and outside it for the next.
        $now = Carbon::now()->getTimestamp();

        if ($presentedUrl === '' || preg_match('/[\x00-\x20\x7F]/', $presentedUrl) === 1) {
            return SignedUrlVerdict::Malformed;
        }

        $parts = parse_url($presentedUrl);

        if (! is_array($parts)) {
            return SignedUrlVerdict::Malformed;
        }

        $authority = $this->presentedAuthority($parts);
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        $query = $this->presentedQuery(isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '');

        if ($authority === null || $path === '' || ! str_starts_with($path, '/') || $query === null) {
            return SignedUrlVerdict::Malformed;
        }

        $issued = $query[self::ISSUED_PARAM];
        $expires = $query[self::EXPIRES_PARAM];
        $signature = $query[self::SIGNATURE_PARAM];

        if (preg_match(self::TIMESTAMP_PATTERN, $issued) !== 1
            || preg_match(self::TIMESTAMP_PATTERN, $expires) !== 1
            || preg_match(self::SIGNATURE_PATTERN, $signature) !== 1) {
            return SignedUrlVerdict::Malformed;
        }

        $issuedAt = (int) $issued;
        $expiresAt = (int) $expires;

        // The signature is checked *first*, so nothing below is a statement about
        // unauthenticated data: an attacker's arbitrary expiry can never be reported as
        // "expired" (which would confirm the rest of the URL was well formed), and the
        // authority is part of what is checked, so a transplanted link fails here.
        if (! $this->secrets->verify(
            self::SECRET_SCOPE,
            $this->payload($authority, $path, $issuedAt, $expiresAt),
            $signature,
        )) {
            return SignedUrlVerdict::SignatureMismatch;
        }

        // Both halves of Req 9.6's window rule. The first refuses a link whose *declared*
        // window is too long; the second refuses one whose *remaining* life is, which is
        // what a clock that jumped at signing time would leave behind. Neither is
        // reachable through `sign()`, which is the point of checking anyway.
        if ($expiresAt - $issuedAt <= 0
            || $expiresAt - $issuedAt > self::MAX_WINDOW_SECONDS
            || $expiresAt - $now > self::MAX_WINDOW_SECONDS) {
            return SignedUrlVerdict::WindowTooLong;
        }

        // Exclusive boundary: a link that expires at `t` is refused from `t` onwards.
        if ($now >= $expiresAt) {
            return SignedUrlVerdict::Expired;
        }

        return SignedUrlVerdict::Valid;
    }

    /**
     * Verify or refuse, for a caller that guards an action.
     *
     * Returns nothing on success and raises one exception — one status, one sentence —
     * for every kind of failure, so a caller cannot accidentally turn the verdict into an
     * oracle and cannot act on a half-checked URL (Req 9.6).
     *
     * @throws InvalidSignedUrlException
     */
    public function assertValid(string $presentedUrl): void
    {
        $verdict = $this->verify($presentedUrl);

        if (! $verdict->isValid()) {
            throw InvalidSignedUrlException::refused($verdict);
        }
    }

    /**
     * `scheme://host[:port]` — the base without its mount point, since the mount point is
     * part of the signed path.
     */
    private function origin(CanonicalBase $base): string
    {
        return $base->scheme.'://'.$base->authority();
    }

    /**
     * Default link lifetime, refused rather than clamped when it is outside the range Req
     * 9.6 allows: a deployment that asked for day-long export links must find that out at
     * the first link, not from a support ticket.
     *
     * @throws UnbuildableUrlException
     */
    private function defaultTtlSeconds(): int
    {
        $configured = config('wa.url.signed.ttl_seconds', 900);
        $ttl = is_numeric($configured) ? (int) $configured : 0;

        if ($ttl < 1 || $ttl > self::MAX_WINDOW_SECONDS) {
            throw UnbuildableUrlException::configuredTtl($ttl, self::MAX_WINDOW_SECONDS);
        }

        return $ttl;
    }

    /**
     * The signature, as the bare lowercase hex digest a URL carries.
     *
     * `SigningSecretStore` presents signatures as `sha256=<hex>` for webhook headers; the
     * prefix is stripped for a query parameter, and `verify()` accepts either form.
     *
     * @throws KeyUnavailableException
     */
    private function signature(string $authority, string $path, int $issuedAt, int $expiresAt): string
    {
        $signature = $this->secrets->sign(
            self::SECRET_SCOPE,
            $this->payload($authority, $path, $issuedAt, $expiresAt),
        );

        $prefix = config('wa.security.hmac.prefix');

        if (is_string($prefix) && $prefix !== '' && str_starts_with($signature, $prefix)) {
            return substr($signature, strlen($prefix));
        }

        return $signature;
    }

    /**
     * The signed payload: the canonical authority, the absolute path, and the window.
     *
     * Every field is length-prefixed, so no two distinct tuples share a payload and no
     * field can absorb characters from the next — the framing that makes host binding
     * mean host binding rather than "the concatenation happened to differ".
     */
    private function payload(string $authority, string $path, int $issuedAt, int $expiresAt): string
    {
        return implode('|', [
            self::PAYLOAD_VERSION,
            $this->field($authority),
            $this->field($path),
            $this->field((string) $issuedAt),
            $this->field((string) $expiresAt),
        ]);
    }

    private function field(string $value): string
    {
        return strlen($value).':'.$value;
    }

    /**
     * `host[:port]` of the presented URL in the same spelling `CanonicalBase::authority()`
     * produces, or null when the URL has no usable origin.
     *
     * Normalised through `CanonicalBase::host()` — the one normaliser — so a link
     * presented with an uppercase, root-dotted or unicode spelling of the host it was
     * signed for still verifies, while a *different* host cannot be spelled into a match.
     * The scheme's default port is dropped for the same reason.
     *
     * @param  array<string, int|string>  $parts
     */
    private function presentedAuthority(array $parts): ?string
    {
        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $rawHost = isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';

        if ($rawHost === '' || ! array_key_exists($scheme, self::DEFAULT_PORTS)) {
            // Without a scheme there is no default port to drop, so there is no single
            // spelling of the authority to compare — and without a host there is nothing
            // to bind at all.
            return null;
        }

        try {
            $host = CanonicalBase::host($rawHost, self::PRESENTED_HOST_SOURCE);
        } catch (InvalidBaseUrlException) {
            // A host we cannot normalise cannot equal one we signed. Refusing here keeps
            // verification total: an unparseable host is a verdict, never an exception.
            return null;
        }

        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;

        if ($port === null || $port === self::DEFAULT_PORTS[$scheme]) {
            return $host;
        }

        return $host.':'.$port;
    }

    /**
     * The three parameters a signed URL carries, or null if the query is anything other
     * than exactly those three, each once.
     *
     * An unexpected parameter is refused rather than ignored: the signature covers the
     * path and these three values, so anything else in the query is space an attacker can
     * write in freely. A repeated parameter is refused too — "last one wins" differs
     * between PHP, proxies and web servers, and a difference there is a difference between
     * what was verified and what is served.
     *
     * Values are decoded here rather than by `parse_str`, which mangles keys containing
     * dots or spaces and silently keeps the last of a repeated pair.
     *
     * @return array{wa_issued: string, wa_expires: string, wa_signature: string}|null
     */
    private function presentedQuery(string $query): ?array
    {
        if ($query === '') {
            return null;
        }

        $found = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                return null;
            }

            $split = strpos($pair, '=');

            if ($split === false) {
                return null;
            }

            $key = rawurldecode(substr($pair, 0, $split));

            if (array_key_exists($key, $found)) {
                return null;
            }

            $found[$key] = rawurldecode(substr($pair, $split + 1));
        }

        if (count($found) !== 3
            || ! isset($found[self::ISSUED_PARAM], $found[self::EXPIRES_PARAM], $found[self::SIGNATURE_PARAM])) {
            return null;
        }

        return [
            self::ISSUED_PARAM => $found[self::ISSUED_PARAM],
            self::EXPIRES_PARAM => $found[self::EXPIRES_PARAM],
            self::SIGNATURE_PARAM => $found[self::SIGNATURE_PARAM],
        ];
    }
}
