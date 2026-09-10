<?php

declare(strict_types=1);

namespace App\Support\Url;

use App\Exceptions\Url\UnbuildableUrlException;

/**
 * The one way a path is appended to a `CanonicalBase` (Req 9.1, 9.6 / A9; design
 * § Base URL §U.3).
 *
 * ```php
 * UrlPath::normalize('/exports/42/', 'absolute');            // '/exports/42'
 * UrlPath::under($base, 'exports/42', 'signed');             // '/app/exports/42'
 * ```
 *
 * `CanonicalBase` guarantees one spelling per *origin*; this guarantees one spelling per
 * *path*, and the two together are what make a host-bound signature verifiable: the
 * string the signer signed and the string a later request presents have to be
 * byte-identical, so exactly one of `/exports/42`, `exports/42`, `/exports/42/` and
 * `//exports//42` may be emitted.
 *
 * ## What it refuses, and why each one matters
 *
 * | Input | Refused because |
 * |---|---|
 * | `//attacker.example/x` | a protocol-relative URL. Appended to an origin by a naive joiner, or handed to a browser as a redirect target, it is a link to somebody else's site emitted by us |
 * | `../../etc/passwd`, `.` | the path it names depends on who resolves it, so the signature covers one string and the server acts on another |
 * | `a\b` | browsers and some proxies read `\` as `/`, so the segment a signature covers is not the segment that is served |
 * | `%2F`, `%5C`, `%00` | an encoded separator: the web server may decode it before routing while the signature covers the encoded form. This is the path-confusion class of signed-URL bug, and the cheapest defence is to never emit one |
 * | `%zz`, a trailing `%` | a malformed escape, which different decoders repair differently |
 * | `?a=1`, `#frag` | a query or fragment is not a path. On a signed URL a query is unsigned space an attacker can write in, so the signer emits exactly its own three parameters and nothing else — see `SignedUrlSigner` |
 * | a CR, LF, NUL or space | header injection / response splitting. Refused rather than stripped, for the reason `CanonicalBase` gives: repairing an attack turns evidence into a near miss nobody sees |
 *
 * Interior empty segments (`/a//b`) are collapsed rather than refused — they are an
 * ordinary artefact of string concatenation at a call site, and collapsing cannot change
 * which resource is named. A **leading** `//` is refused instead of collapsed, because
 * there it is not an artefact: it is the one form that changes the origin.
 *
 * ## No request, no query, no framework
 *
 * Pure string handling, like everything else in this namespace: no `Request`, no
 * `route()`, no framework URL generator (Property 27, enforced by the source scan in
 * `tests/Feature/Url/BaseUrlTest.php`).
 */
final class UrlPath
{
    /**
     * Characters a path segment may contain, before percent-escapes are checked.
     *
     * Wider than `CanonicalBase`'s prefix alphabet — which is a mount point, not a route
     * — but still an allowlist: ULIDs, slugs, filenames and versioned API segments fit,
     * while `&`, `=`, `;`, `,` and quotes do not. A caller that genuinely needs one
     * percent-escapes it.
     */
    private const string SEGMENT_PATTERN = '/^[A-Za-z0-9\-._~@:+%]+$/';

    /**
     * Longest path this will build. Well under the ~2000 characters proxies and browsers
     * agree on, so a signed URL still fits after its query is appended.
     */
    private const int MAX_LENGTH = 1500;

    /**
     * The canonical form of $path: `''`, or `/`-prefixed with no trailing slash.
     *
     * @param  string  $context  the builder method being served, named in the error
     *
     * @throws UnbuildableUrlException when $path is not a usable server-side path
     */
    public static function normalize(string $path, string $context): string
    {
        if ($path === '' || $path === '/') {
            return '';
        }

        if (strlen($path) > self::MAX_LENGTH) {
            throw UnbuildableUrlException::path($context, $path, sprintf(
                'it is %d characters long, past the %d-character limit',
                strlen($path),
                self::MAX_LENGTH,
            ));
        }

        if (preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
            throw UnbuildableUrlException::path(
                $context,
                $path,
                'it contains whitespace or a control character (a CR/LF inside a URL is a '
                .'header-injection attempt, not a typo)',
            );
        }

        if (str_contains($path, '?')) {
            throw UnbuildableUrlException::path(
                $context,
                $path,
                'it carries a query string, which a signature does not cover and which the '
                .'signer therefore refuses to emit',
            );
        }

        if (str_contains($path, '#')) {
            throw UnbuildableUrlException::path(
                $context,
                $path,
                'it carries a fragment, which is a client-side concern and is never sent to '
                .'the server',
            );
        }

        if (str_contains($path, '\\')) {
            throw UnbuildableUrlException::path(
                $context,
                $path,
                'it contains a backslash, which browsers and proxies read as a path '
                .'separator while a signature would cover it literally',
            );
        }

        if (str_starts_with($path, '//')) {
            throw UnbuildableUrlException::path(
                $context,
                $path,
                'it begins with // and is therefore a protocol-relative URL naming another '
                .'origin, not a path on this one',
            );
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.' || $segment === '..') {
                throw UnbuildableUrlException::path(
                    $context,
                    $path,
                    'it contains a relative segment, so the resource it names depends on how '
                    .'it is resolved rather than on what was signed',
                );
            }

            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw UnbuildableUrlException::path($context, $path, sprintf(
                    'its segment [%s] contains characters a path may not carry unencoded',
                    $segment,
                ));
            }

            self::assertEscapes($segment, $path, $context);

            $segments[] = $segment;
        }

        return $segments === [] ? '' : '/'.implode('/', $segments);
    }

    /**
     * $path resolved under $base's mount point: the **absolute** path a request for that
     * URL carries, which is the string a signature covers.
     *
     * A deployment mounted at `/app` signs `/app/exports/42`, so moving the mount point
     * invalidates outstanding links rather than making them verify against the wrong
     * resource.
     *
     * @throws UnbuildableUrlException when $path is not a usable server-side path
     */
    public static function under(CanonicalBase $base, string $path, string $context): string
    {
        return $base->path.self::normalize($path, $context);
    }

    /**
     * Percent-escapes must be well-formed, and must not encode a separator.
     *
     * @throws UnbuildableUrlException
     */
    private static function assertEscapes(string $segment, string $path, string $context): void
    {
        if (! str_contains($segment, '%')) {
            return;
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $segment) === 1) {
            throw UnbuildableUrlException::path(
                $context,
                $path,
                'it contains a malformed percent-escape, which different decoders repair '
                .'differently',
            );
        }

        if (preg_match('/%(?:2[Ff]|5[Cc]|00)/', $segment) === 1) {
            throw UnbuildableUrlException::path(
                $context,
                $path,
                'it encodes a path separator or a NUL (%2F, %5C, %00) — the web server may '
                .'decode it before routing while a signature covers the encoded form, which '
                .'is exactly how signed-URL path confusion happens',
            );
        }
    }
}
