<?php

declare(strict_types=1);

namespace App\Support\Url;

use App\Exceptions\Url\InvalidBaseUrlException;
use Stringable;

/**
 * One deployment origin, in exactly one spelling (Req 9.1, 9.3 / A9;
 * design § Base URL §U.2, §U.5).
 *
 * Everything the platform emits an absolute URL from — `BaseUrl::platform()`,
 * `BaseUrl::forTenant()`, and through them `UrlBuilder` (task 5.2) and the
 * host-bound signatures of task 5.3 — is built from an instance of this class, and
 * an instance can only be obtained by `parse()`ing a configured value.
 *
 * ## Why a value object rather than a string
 *
 * **Canonicality.** A signed URL is signed against a host and verified against a
 * host (Req 9.6), so the two spellings must be byte-identical or a legitimate link
 * fails verification. `https://Bot.Example.com:443/` and `https://bot.example.com`
 * are the same origin and must therefore produce the same string, which is what
 * `parse()` guarantees: scheme and host lowercased, an internationalised host
 * reduced to its punycode form, the root dot and the scheme's default port dropped,
 * the path prefix stripped of its trailing slash and of empty segments.
 *
 * ```php
 * CanonicalBase::parse('HTTPS://Bot.Example.com:443/app/', 'APP_URL')->value();
 * // => 'https://bot.example.com/app'
 * ```
 *
 * **Refusal.** The constructor is private and every rejection is an
 * `InvalidBaseUrlException`, so there is no way to end up holding a base that is
 * "mostly fine": no scheme, a non-HTTP scheme, embedded credentials, a query or
 * fragment, whitespace, or a CR/LF header-injection payload all fail at the point
 * the value is read rather than at the point a webhook is registered with it.
 *
 * ## What this class deliberately does not do
 *
 * It has no notion of a request. It reads no `Host` header, no `X-Forwarded-Host`,
 * no `$_SERVER` — it takes a configured string and normalises it, which is what
 * makes Property 27 structural rather than a rule reviewers must remember: an
 * attacker-controlled host never becomes an input to a base URL because there is
 * no parameter to pass it through. `App\Services\Url\ConfiguredBaseUrl` is the only
 * caller, and `tests/Feature/Url/BaseUrlTest.php` scans both for request-derived
 * reads.
 *
 * @immutable
 */
final class CanonicalBase implements Stringable
{
    /**
     * Ports omitted from the canonical form because the scheme implies them.
     *
     * @var array<string, int>
     */
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Schemes a base URL may use. Anything else (`ftp:`, `javascript:`, `file:`) is
     * either useless as an origin or an injection vector.
     *
     * @var list<string>
     */
    private const array SCHEMES = ['http', 'https'];

    /**
     * RFC 1035 preferred syntax: dot-separated labels of alphanumerics and inner
     * hyphens, each at most 63 characters.
     */
    private const string HOST_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /**
     * Maximum length of a DNS name.
     */
    private const int MAX_HOST_LENGTH = 253;

    /**
     * Characters a path prefix may contain. Deliberately narrow: a base URL's path is
     * a mount point (`/app`), not a route.
     */
    private const string PATH_SEGMENT_PATTERN = '/^[A-Za-z0-9\-._~%+]+$/';

    /**
     * Hosts that only ever reach the machine issuing the request.
     *
     * @var list<string>
     */
    private const array LOOPBACK_HOSTS = ['localhost', '[::1]', '0.0.0.0', '[::]'];

    /**
     * @param  string  $scheme  `http` or `https`, lowercase
     * @param  string  $host  lowercase punycode hostname, or an IP literal (IPv6 bracketed)
     * @param  int|null  $port  explicit port, or null when the scheme's default applies
     * @param  string  $path  `''` or a `/`-prefixed prefix with no trailing slash
     */
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
        public readonly string $path,
    ) {}

    /**
     * The canonical form of $raw.
     *
     * @param  string  $source  where the value came from, named for the error message:
     *                          `config('app.url')`, `platform_settings['base_url']`
     *
     * @throws InvalidBaseUrlException when $raw is empty or is not a usable origin
     */
    public static function parse(string $raw, string $source): self
    {
        // Spaces and tabs only: a copy-paste artefact is forgiven, while a CR, LF or NUL
        // is left in place so the check below can refuse it. Trimming those would repair
        // an injection payload into a valid-looking base and lose the evidence with it.
        $trimmed = trim($raw, " \t");

        if ($trimmed === '') {
            throw InvalidBaseUrlException::missing($source);
        }

        self::assertNoControlCharacters($trimmed, $source, $raw);

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            throw InvalidBaseUrlException::malformed($source, $raw, 'it is not a parseable URL');
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;

        if ($scheme === null) {
            throw InvalidBaseUrlException::malformed(
                $source,
                $raw,
                'it has no scheme (a bare host is ambiguous — write https:// explicitly rather '
                .'than letting the platform guess whether links are encrypted)',
            );
        }

        if (! in_array($scheme, self::SCHEMES, true)) {
            throw InvalidBaseUrlException::malformed($source, $raw, sprintf(
                'the [%s] scheme is not one the platform can serve or link to (http or https)',
                $scheme,
            ));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            // Credentials in a base URL end up in every emitted link, every webhook
            // registration, and every log line that records one.
            throw InvalidBaseUrlException::malformed($source, $raw, 'it carries embedded credentials');
        }

        if (isset($parts['query'])) {
            throw InvalidBaseUrlException::malformed($source, $raw, 'it carries a query string, which every '
                .'path appended to it would silently discard');
        }

        if (isset($parts['fragment'])) {
            throw InvalidBaseUrlException::malformed($source, $raw, 'it carries a fragment, which is a '
                .'client-side concern and is never sent to the server');
        }

        if (! isset($parts['host']) || ! is_string($parts['host'])) {
            throw InvalidBaseUrlException::malformed($source, $raw, 'it has no host');
        }

        return new self(
            scheme: $scheme,
            host: self::host($parts['host'], $source),
            port: self::port($parts['port'] ?? null, $scheme, $source, $raw),
            path: self::path(isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '', $source, $raw),
        );
    }

    /**
     * The canonical form of a bare hostname — a tenant's custom domain as it is
     * stored in `tenant_domains.host`.
     *
     * Lowercased, punycoded, root dot removed. This is the single normaliser behind
     * both the column (`TenantDomain::host` runs every write through it) and the read
     * path, which is what makes the table's global `unique(host)` mean what it says:
     * without one spelling per host, `ACME.example.com.` and `acme.example.com` would
     * be two rows, and two tenants could each hold "the same" domain — a hijack, since
     * the platform would emit one tenant's webhook callbacks to a host the other owns.
     *
     * @throws InvalidBaseUrlException when $raw is not a usable hostname
     */
    public static function host(string $raw, string $source): string
    {
        $host = trim($raw, " \t");

        if ($host === '') {
            throw InvalidBaseUrlException::malformedHost($source, $raw, 'it is empty');
        }

        self::assertNoControlCharactersInHost($host, $source, $raw);

        if (preg_match('/[^\x21-\x7E]/', $host) === 1) {
            // A unicode host is stored and compared in its punycode form: DNS resolves
            // the A-label, and TLS certificates name it, so the A-label is the only
            // spelling that can be matched byte-for-byte later.
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($ascii) || $ascii === '') {
                throw InvalidBaseUrlException::malformedHost(
                    $source,
                    $raw,
                    'it is not a valid internationalised domain name',
                );
            }

            $host = $ascii;
        }

        $host = rtrim(strtolower($host), '.');

        if ($host === '') {
            throw InvalidBaseUrlException::malformedHost($source, $raw, 'it is empty');
        }

        if (str_starts_with($host, '[')) {
            return self::ipv6Host($host, $source, $raw);
        }

        if (str_contains($host, ':') || str_contains($host, '/')) {
            throw InvalidBaseUrlException::malformedHost(
                $source,
                $raw,
                'a host may not carry a port, a path, or a scheme',
            );
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (strlen($host) > self::MAX_HOST_LENGTH) {
            throw InvalidBaseUrlException::malformedHost($source, $raw, sprintf(
                'it is %d characters long, past the %d-character limit on a DNS name',
                strlen($host),
                self::MAX_HOST_LENGTH,
            ));
        }

        if (preg_match(self::HOST_PATTERN, $host) !== 1) {
            throw InvalidBaseUrlException::malformedHost(
                $source,
                $raw,
                'it is not a valid hostname (dot-separated labels of letters, digits and inner hyphens)',
            );
        }

        return $host;
    }

    /**
     * The same origin with $host substituted — how a tenant's verified custom domain
     * becomes a base (Req 9.3).
     *
     * Scheme, port and path prefix are inherited from the platform base rather than
     * invented: a deployment served over HTTPS on a non-standard port, or mounted
     * under a path, serves its custom domains the same way. Only the host is the
     * tenant's to choose — and even that is re-normalised here, so a row written
     * before this class existed cannot smuggle an odd spelling into a signature.
     *
     * @throws InvalidBaseUrlException when $host is not a usable hostname
     */
    public function withHost(string $host, string $source): self
    {
        return new self($this->scheme, self::host($host, $source), $this->port, $this->path);
    }

    /**
     * The same origin over HTTPS.
     *
     * An explicit `:443` inherited from the http spelling becomes implicit, so
     * upgrading a scheme cannot produce a second spelling of one origin. A
     * non-default port is kept: an operator who wrote `:8443` meant it.
     */
    public function withHttps(): self
    {
        if ($this->scheme === 'https') {
            return $this;
        }

        return new self(
            'https',
            $this->host,
            $this->port === self::DEFAULT_PORTS['https'] ? null : $this->port,
            $this->path,
        );
    }

    /**
     * Whether this origin only ever reaches the machine that requested it — the
     * shape of an `APP_URL` nobody configured.
     */
    public function isLoopback(): bool
    {
        return in_array($this->host, self::LOOPBACK_HOSTS, true)
            || str_starts_with($this->host, '127.');
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }

    /**
     * The canonical string: `scheme://host[:port][/prefix]`, never with a trailing
     * slash, so callers append `'/'.$path` without producing a double slash.
     */
    public function value(): string
    {
        return $this->scheme.'://'.$this->authority().$this->path;
    }

    /**
     * `host[:port]` — the part a signature binds (task 5.3) and the part that must be
     * compared against the presented host.
     */
    public function authority(): string
    {
        return $this->port === null ? $this->host : $this->host.':'.$this->port;
    }

    public function __toString(): string
    {
        return $this->value();
    }

    /**
     * A base URL never legitimately contains whitespace or a control character. A CR
     * or LF in particular is a response-splitting / header-injection payload, so it is
     * refused rather than stripped: silently repairing an attack turns evidence into a
     * near miss nobody sees.
     */
    private static function assertNoControlCharacters(string $value, string $source, string $raw): void
    {
        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw InvalidBaseUrlException::malformed(
                $source,
                $raw,
                'it contains whitespace or a control character (a CR/LF inside a URL is a '
                .'header-injection attempt, not a typo)',
            );
        }
    }

    private static function assertNoControlCharactersInHost(string $value, string $source, string $raw): void
    {
        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw InvalidBaseUrlException::malformedHost(
                $source,
                $raw,
                'it contains whitespace or a control character (a CR/LF inside a host is a '
                .'header-injection attempt, not a typo)',
            );
        }
    }

    /**
     * An IPv6 literal, kept bracketed so the canonical string stays a parseable URL.
     */
    private static function ipv6Host(string $host, string $source, string $raw): string
    {
        if (! str_ends_with($host, ']')) {
            throw InvalidBaseUrlException::malformedHost($source, $raw, 'its IPv6 literal is not closed');
        }

        $inner = substr($host, 1, -1);

        if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw InvalidBaseUrlException::malformedHost($source, $raw, 'it is not a valid IPv6 literal');
        }

        return '['.$inner.']';
    }

    /**
     * The explicit port, or null when the scheme already implies it.
     */
    private static function port(mixed $port, string $scheme, string $source, string $raw): ?int
    {
        if ($port === null) {
            return null;
        }

        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw InvalidBaseUrlException::malformed($source, $raw, 'its port is not a number between 1 and 65535');
        }

        return $port === self::DEFAULT_PORTS[$scheme] ? null : $port;
    }

    /**
     * The path prefix: `''`, or `/`-prefixed with no trailing slash and no empty,
     * relative, or oddly-encoded segments.
     */
    private static function path(string $path, string $source, string $raw): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.' || $segment === '..') {
                throw InvalidBaseUrlException::malformed(
                    $source,
                    $raw,
                    'its path contains a relative segment, so the origin it names depends on how '
                    .'it is resolved',
                );
            }

            if (preg_match(self::PATH_SEGMENT_PATTERN, $segment) !== 1) {
                throw InvalidBaseUrlException::malformed($source, $raw, sprintf(
                    'its path segment [%s] contains characters a mount point cannot carry',
                    $segment,
                ));
            }

            $segments[] = $segment;
        }

        return $segments === [] ? '' : '/'.implode('/', $segments);
    }
}
