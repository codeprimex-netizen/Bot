<?php

declare(strict_types=1);

use App\Exceptions\Url\UnbuildableUrlException;
use App\Support\Url\CanonicalBase;
use App\Support\Url\UrlPath;

/*
|--------------------------------------------------------------------------
| One spelling per path (Req 9.1, 9.6 / A9)
|--------------------------------------------------------------------------
| `CanonicalBase` gives one spelling per origin; this gives one spelling per path. Both
| are needed for a host-bound signature to be verifiable at all: the string signed and
| the string later presented have to be byte-identical.
|
| The refusals are the interesting half. Each one is a way a path argument could move a
| URL somewhere the caller did not mean — another origin, another resource, or a resource
| that differs from the one the signature covers once a web server has decoded it.
*/

it('emits one spelling for the same path', function (string $path): void {
    expect(UrlPath::normalize($path, 'absolute'))->toBe('/exports/42');
})->with([
    'leading slash' => ['/exports/42'],
    'no leading slash' => ['exports/42'],
    'trailing slash' => ['/exports/42/'],
    'interior double slash' => ['/exports//42'],
    'both' => ['exports//42/'],
]);

it('treats an empty path as the origin itself', function (string $path): void {
    expect(UrlPath::normalize($path, 'absolute'))->toBe('');
})->with([
    'empty' => [''],
    'root' => ['/'],
]);

it('keeps the characters a real route needs', function (string $path, string $expected): void {
    expect(UrlPath::normalize($path, 'absolute'))->toBe($expected);
})->with([
    'ULID' => ['/exports/01JABCDEFGHJKMNPQRSTVWXYZ/download', '/exports/01JABCDEFGHJKMNPQRSTVWXYZ/download'],
    'slug with hyphens' => ['/api/v1/contact-lists', '/api/v1/contact-lists'],
    'filename with a dot' => ['/exports/report.csv', '/exports/report.csv'],
    'underscore and tilde' => ['/a_b/~c', '/a_b/~c'],
    'well-formed escape' => ['/exports/a%20b', '/exports/a%20b'],
]);

it('resolves a path under the base mount point', function (): void {
    $mounted = CanonicalBase::parse('https://bot.example.com/app', 'test');
    $root = CanonicalBase::parse('https://bot.example.com', 'test');

    // The mount point is part of what a signature covers, so moving it invalidates
    // outstanding links rather than verifying them against a different resource.
    expect(UrlPath::under($mounted, '/exports/42', 'signed'))->toBe('/app/exports/42')
        ->and(UrlPath::under($root, '/exports/42', 'signed'))->toBe('/exports/42')
        ->and(UrlPath::under($mounted, '', 'signed'))->toBe('/app');
});

it('refuses a path that would move the URL somewhere else', function (string $path, string $reason): void {
    expect(fn (): string => UrlPath::normalize($path, 'signed'))
        ->toThrow(UnbuildableUrlException::class, $reason);
})->with([
    // The one form that changes the origin: appended naively, or followed as a redirect,
    // this is a link to somebody else's site emitted by us.
    'protocol-relative' => ['//attacker.example/x', 'protocol-relative'],
    // Not treated as "the origin, with extra slashes": a leading // is refused wherever it
    // appears, so no caller can get one past by writing it on its own.
    'bare double slash' => ['//', 'protocol-relative'],
    'only separators' => ['///', 'protocol-relative'],
    'traversal' => ['/exports/../../etc/passwd', 'relative segment'],
    'single dot segment' => ['/exports/./42', 'relative segment'],
    // Browsers and some proxies read a backslash as a separator; a signature would not.
    'backslash' => ['/exports\\42', 'backslash'],
    'query string' => ['/exports?tenant=other', 'query string'],
    'fragment' => ['/exports#top', 'fragment'],
    'CRLF injection' => ["/exports\r\nX-Injected: 1", 'header-injection'],
    'NUL byte' => ["/exports\x00", 'header-injection'],
    'space' => ['/exports 42', 'header-injection'],
    // The path-confusion class: the server may decode this before routing while the
    // signature covers the encoded form.
    'encoded slash' => ['/exports/a%2Fb', 'encodes a path separator'],
    'encoded slash lowercase' => ['/exports/a%2fb', 'encodes a path separator'],
    'encoded backslash' => ['/exports/a%5Cb', 'encodes a path separator'],
    'encoded NUL' => ['/exports/a%00', 'encodes a path separator'],
    'malformed escape' => ['/exports/a%zz', 'malformed percent-escape'],
    'truncated escape' => ['/exports/a%2', 'malformed percent-escape'],
    'ampersand' => ['/exports/a&b', 'may not carry unencoded'],
    'quote' => ["/exports/a'b", 'may not carry unencoded'],
]);

it('refuses a path longer than a URL can carry', function (): void {
    expect(fn (): string => UrlPath::normalize('/'.str_repeat('a', 1500), 'absolute'))
        ->toThrow(UnbuildableUrlException::class, 'character limit');
});
