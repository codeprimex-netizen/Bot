<?php

declare(strict_types=1);

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Support\Url\CanonicalBase;

/*
|--------------------------------------------------------------------------
| One origin, one spelling (Req 9.1, 9.3 / A9; Correctness Property 27)
|--------------------------------------------------------------------------
| Task 5.3 binds the canonical host into signed-URL signatures, so two spellings of
| one origin means a link signed against one and verified against the other fails.
| These tests pin the normalisation, and pin that an unusable value is refused rather
| than repaired into something plausible.
*/

it('canonicalises the spellings of one origin to one string', function (string $raw): void {
    expect(CanonicalBase::parse($raw, 'test')->value())->toBe('https://bot.example.com');
})->with([
    'as written' => ['https://bot.example.com'],
    'trailing slash' => ['https://bot.example.com/'],
    'mixed-case scheme and host' => ['HTTPS://Bot.Example.COM'],
    'explicit default port' => ['https://bot.example.com:443'],
    'root dot' => ['https://bot.example.com.'],
    'surrounding whitespace' => ['  https://bot.example.com  '],
    'redundant slashes' => ['https://bot.example.com///'],
]);

it('keeps a path prefix, without its trailing slash', function (): void {
    expect(CanonicalBase::parse('https://bot.example.com/app/', 'test')->value())
        ->toBe('https://bot.example.com/app')
        ->and(CanonicalBase::parse('https://bot.example.com//app//panel//', 'test')->value())
        ->toBe('https://bot.example.com/app/panel');
});

it('keeps a non-default port and drops the default one', function (): void {
    expect(CanonicalBase::parse('https://bot.example.com:8443', 'test')->value())
        ->toBe('https://bot.example.com:8443')
        ->and(CanonicalBase::parse('http://bot.example.com:80', 'test')->value())
        ->toBe('http://bot.example.com')
        ->and(CanonicalBase::parse('http://bot.example.com:8080', 'test')->authority())
        ->toBe('bot.example.com:8080');
});

it('stores an internationalised host in the punycode form DNS and TLS use', function (): void {
    expect(CanonicalBase::parse('https://пример.example', 'test')->host)
        ->toBe('xn--e1afmkfd.example')
        // Already-punycode input is left exactly as it is, so both spellings converge.
        ->and(CanonicalBase::parse('https://XN--E1AFMKFD.example', 'test')->host)
        ->toBe('xn--e1afmkfd.example');
});

it('accepts IP literals, including bracketed IPv6', function (): void {
    expect(CanonicalBase::parse('https://198.51.100.7:8443', 'test')->value())
        ->toBe('https://198.51.100.7:8443')
        ->and(CanonicalBase::parse('https://[2001:db8::1]/app', 'test')->value())
        ->toBe('https://[2001:db8::1]/app');
});

it('refuses a value that is not a usable origin', function (string $raw, string $reason): void {
    expect(fn (): CanonicalBase => CanonicalBase::parse($raw, "config('app.url')"))
        ->toThrow(InvalidBaseUrlException::class, $reason);
})->with([
    'empty' => ['', 'is empty'],
    'whitespace only' => ['   ', 'is empty'],
    'bare host' => ['bot.example.com', 'has no scheme'],
    'scheme-relative' => ['//bot.example.com', 'has no scheme'],
    'non-http scheme' => ['ftp://bot.example.com', 'is not one the platform can serve'],
    'javascript scheme' => ['javascript:alert(1)', 'is not one the platform can serve'],
    'embedded credentials' => ['https://admin:hunter2@bot.example.com', 'embedded credentials'],
    'query string' => ['https://bot.example.com/?tenant=1', 'carries a query string'],
    'fragment' => ['https://bot.example.com/#top', 'carries a fragment'],
    'inner whitespace' => ['https://bot.example.com/a b', 'header-injection attempt'],
    'CRLF injection' => ["https://bot.example.com\r\nX-Injected: 1", 'header-injection attempt'],
    'null byte' => ["https://bot.example.com\0", 'header-injection attempt'],
    'underscore in host' => ['https://bot_example.com', 'not a valid hostname'],
    'traversal in path' => ['https://bot.example.com/../etc', 'relative segment'],
]);

it('refuses a host that is not a usable hostname', function (string $raw, string $reason): void {
    expect(fn (): string => CanonicalBase::host($raw, 'tenant_domains.host'))
        ->toThrow(InvalidBaseUrlException::class, $reason);
})->with([
    'empty' => ['', 'is empty'],
    'root dot only' => ['.', 'is empty'],
    'with a port' => ['chat.acme.example:8443', 'may not carry a port'],
    'with a path' => ['chat.acme.example/hook', 'may not carry a port'],
    'with a scheme' => ['https://chat.acme.example', 'may not carry a port'],
    'CRLF injection' => ["chat.acme.example\r\nX-Injected: 1", 'header-injection attempt'],
    'unclosed IPv6' => ['[2001:db8::1', 'IPv6 literal is not closed'],
    'invalid IPv6' => ['[not-an-address]', 'not a valid IPv6 literal'],
    'too long' => [str_repeat('a.', 130).'example', 'past the 253-character limit'],
]);

it('normalises a bare host the same way it normalises one inside a URL', function (): void {
    expect(CanonicalBase::host('  CHAT.Acme.Example.  ', 'test'))->toBe('chat.acme.example')
        ->and(CanonicalBase::host('пример.example', 'test'))->toBe('xn--e1afmkfd.example');
});

it('upgrades to https without producing a second spelling of one origin', function (): void {
    $plain = CanonicalBase::parse('http://bot.example.com/app', 'test');

    expect($plain->withHttps()->value())->toBe('https://bot.example.com/app')
        ->and($plain->isSecure())->toBeFalse()
        ->and($plain->withHttps()->isSecure())->toBeTrue()
        // :443 is explicit under http and implicit under https — the upgrade must drop it,
        // or `https://x:443` and `https://x` would both be "the canonical base".
        ->and(CanonicalBase::parse('http://bot.example.com:443', 'test')->withHttps()->value())
        ->toBe('https://bot.example.com')
        // A deliberate non-standard port survives the upgrade.
        ->and(CanonicalBase::parse('http://bot.example.com:8443', 'test')->withHttps()->value())
        ->toBe('https://bot.example.com:8443')
        ->and(CanonicalBase::parse('https://bot.example.com', 'test')->withHttps()->value())
        ->toBe('https://bot.example.com');
});

it('substitutes a host while inheriting scheme, port and path prefix', function (): void {
    $platform = CanonicalBase::parse('https://bot.example.com:8443/app', 'test');

    expect($platform->withHost('Chat.Acme.Example.', 'tenant_domains.host')->value())
        ->toBe('https://chat.acme.example:8443/app');
});

it('recognises the loopback origins an unset APP_URL produces', function (string $raw, bool $loopback): void {
    expect(CanonicalBase::parse($raw, 'test')->isLoopback())->toBe($loopback);
})->with([
    'framework default' => ['http://localhost', true],
    'loopback with port' => ['http://localhost:8000', true],
    'IPv4 loopback' => ['http://127.0.0.1', true],
    'other 127/8 address' => ['http://127.1.2.3', true],
    'IPv6 loopback' => ['http://[::1]:8000', true],
    'wildcard bind' => ['http://0.0.0.0:8000', true],
    'a real origin' => ['https://bot.example.com', false],
    'a host merely containing localhost' => ['https://localhost.example.com', false],
]);

it('escapes control characters out of the message it reports them in', function (): void {
    $thrown = null;

    try {
        CanonicalBase::parse("https://bot.example.com\r\nX-Injected: 1", 'test');
    } catch (InvalidBaseUrlException $exception) {
        $thrown = $exception;
    }

    // The payload is evidence and belongs in the log — but as an escape sequence, so it
    // cannot split a line in whatever reads the log next.
    expect($thrown)->toBeInstanceOf(InvalidBaseUrlException::class)
        ->and($thrown?->getMessage())->toContain('\x0D\x0A')
        ->and($thrown?->getMessage())->not->toContain("\r");
});
