<?php

declare(strict_types=1);

use App\Enums\SignedUrlVerdict;
use App\Exceptions\Url\InvalidSignedUrlException;
use App\Exceptions\Url\UnbuildableUrlException;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Security\SigningSecretStore;
use App\Services\Url\SignedUrlSigner;
use App\Services\Url\UrlBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Host-bound signed URLs (Req 9.6 / A9; Property 27)
|--------------------------------------------------------------------------
| The attack these tests exist for: a signature over path-and-expiry alone is portable.
| An attacker who obtains one export link — a forwarded email, a shared history, a
| referer header — replays the path, expiry and signature against a host they control or
| can reach, and the platform's own signature vouches for the request.
|
| So the canonical authority is a field inside the HMAC, and the tests below transplant a
| valid link onto other hosts and assert it is refused every time. The rest pin the three
| refusals Req 9.6 names (expired, window over 3600s, host mismatch), that the window is
| refused at *generation* as well, and that verification is total — every input produces a
| verdict, and every refusal produces the same response.
*/

beforeEach(function (): void {
    Cache::flush();
    config([
        'app.url' => 'https://bot.example.com',
        'wa.url.force_https' => null,
        'wa.url.signed.ttl_seconds' => 900,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function signer(): SignedUrlSigner
{
    return app(SignedUrlSigner::class);
}

/**
 * The same signed URL, presented at $host instead of the host it was signed for.
 */
function transplant(string $url, string $host): string
{
    $original = parse_url($url, PHP_URL_HOST);

    return str_replace(
        '://'.(is_string($original) ? $original : ''),
        '://'.$host,
        $url,
    );
}

/**
 * The value of one query parameter of $url.
 */
function signedParam(string $url, string $parameter): string
{
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str(is_string($query) ? $query : '', $parsed);
    $value = $parsed[$parameter] ?? '';

    return is_string($value) ? $value : '';
}

/*
|--------------------------------------------------------------------------
| The round trip
|--------------------------------------------------------------------------
*/

it('signs a URL that verifies at the host it was signed for', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');

    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));

    expect($url)->toStartWith('https://bot.example.com/exports/01JABC/download?')
        ->and(signedParam($url, 'wa_issued'))->toBe((string) now()->getTimestamp())
        ->and(signedParam($url, 'wa_expires'))->toBe((string) now()->addMinutes(15)->getTimestamp())
        ->and(signedParam($url, 'wa_signature'))->toMatch('/^[0-9a-f]{64}$/')
        ->and(signer()->verify($url))->toBe(SignedUrlVerdict::Valid);
});

it('uses the configured default lifetime when the caller names no expiry', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');
    config(['wa.url.signed.ttl_seconds' => 60]);

    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download');

    expect(signedParam($url, 'wa_expires'))->toBe((string) now()->addSeconds(60)->getTimestamp())
        ->and(signer()->verify($url))->toBe(SignedUrlVerdict::Valid);
});

it('refuses to sign the origin itself, on any mount point', function (string $base): void {
    config(['app.url' => $base]);

    // A signed URL authorises one resource. Judged on the caller's path rather than the
    // composed one, so this refuses identically whether or not the deployment is mounted
    // under a prefix.
    expect(fn (): string => app(UrlBuilder::class)->signed(''))
        ->toThrow(UnbuildableUrlException::class, 'needs a path');
})->with([
    'root-mounted' => ['https://bot.example.com'],
    'mounted under a prefix' => ['https://bot.example.com/app'],
]);

it('signs the base mount point into the path', function (): void {
    config(['app.url' => 'https://bot.example.com/app']);

    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download');

    expect($url)->toStartWith('https://bot.example.com/app/exports/01JABC/download?')
        ->and(signer()->verify($url))->toBe(SignedUrlVerdict::Valid)
        // The same link without the mount point covers a different path.
        ->and(signer()->verify(str_replace('/app/exports', '/exports', $url)))
        ->toBe(SignedUrlVerdict::SignatureMismatch);
});

/*
|--------------------------------------------------------------------------
| The signature binds the canonical host (Req 9.6)
|--------------------------------------------------------------------------
*/

it('refuses a valid signature transplanted onto another host', function (string $host): void {
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));
    $moved = transplant($url, $host);

    expect(signer()->verify($url))->toBe(SignedUrlVerdict::Valid)
        ->and($moved)->not->toBe($url)
        ->and(signer()->verify($moved))->not->toBe(SignedUrlVerdict::Valid);
})->with([
    // The plain replay: the attacker points a host of their own at this platform, or at a
    // proxy in front of it, and presents the link there.
    'attacker origin' => ['attacker.example.net'],
    // A host that reaches the same deployment on a different port is a different origin.
    'same host, different port' => ['bot.example.com:8443'],
    // Prefix/suffix games against a naive `str_contains`-style host check.
    'subdomain of ours' => ['exports.bot.example.com'],
    'ours as a prefix' => ['bot.example.com.attacker.example.net'],
    'a shorter host' => ['example.com'],
    // Another node of this same platform, which is the case a host-blind signature makes
    // indistinguishable from the real one.
    'a sibling deployment' => ['staging.bot.example.com'],
]);

it('refuses a link signed for one of our own hosts and presented at another', function (): void {
    // Frozen, so the two links below differ in exactly one input: the host.
    Carbon::setTestNow('2024-06-17 12:00:00');
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    $tenantLink = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15), $tenant);
    $platformLink = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));

    // Both hosts are ours and both serve this path, so nothing but the bound authority
    // separates them. A tenant's export link is not a platform export link.
    expect($tenantLink)->toStartWith('https://chat.acme.example/')
        ->and(signer()->verify($tenantLink))->toBe(SignedUrlVerdict::Valid)
        ->and(signer()->verify($platformLink))->toBe(SignedUrlVerdict::Valid)
        ->and(signer()->verify(transplant($tenantLink, 'bot.example.com')))
        ->toBe(SignedUrlVerdict::SignatureMismatch)
        ->and(signer()->verify(transplant($platformLink, 'chat.acme.example')))
        ->toBe(SignedUrlVerdict::SignatureMismatch)
        // Same path, same window, different host: different signature. Nothing else in the
        // URL differs, so the signature is where the host went.
        ->and(signedParam($tenantLink, 'wa_signature'))
        ->not->toBe(signedParam($platformLink, 'wa_signature'));
});

it('accepts any spelling of the host it was signed for', function (string $host): void {
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));

    // Normalised through the one host normaliser (`CanonicalBase::host`), so a legitimate
    // link does not fail because a proxy upper-cased the authority — while a *different*
    // host still cannot be spelled into a match.
    expect(signer()->verify(transplant($url, $host)))->toBe(SignedUrlVerdict::Valid);
})->with([
    'uppercase' => ['BOT.EXAMPLE.COM'],
    'mixed case' => ['Bot.Example.com'],
    'root dot' => ['bot.example.com.'],
    'explicit default port' => ['bot.example.com:443'],
]);

/*
|--------------------------------------------------------------------------
| Tampering
|--------------------------------------------------------------------------
*/

it('refuses a URL whose signed parts were edited', function (callable $tamper): void {
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));

    expect(signer()->verify($tamper($url)))->not->toBe(SignedUrlVerdict::Valid);
})->with([
    'a different resource' => [fn (string $url): string => str_replace('01JABC', '01JXYZ', $url)],
    'a deeper path' => [fn (string $url): string => str_replace('/download', '/download/all', $url)],
    'a later expiry' => [fn (string $url): string => str_replace(
        'wa_expires='.signedParam($url, 'wa_expires'),
        'wa_expires='.((int) signedParam($url, 'wa_expires') + 60),
        $url,
    )],
    'an earlier issue time' => [fn (string $url): string => str_replace(
        'wa_issued='.signedParam($url, 'wa_issued'),
        'wa_issued='.((int) signedParam($url, 'wa_issued') - 60),
        $url,
    )],
    'a flipped signature character' => [fn (string $url): string => str_replace(
        'wa_signature='.signedParam($url, 'wa_signature'),
        'wa_signature='.strtr(substr(signedParam($url, 'wa_signature'), 0, 1), '0123456789abcdef', 'fedcba9876543210')
            .substr(signedParam($url, 'wa_signature'), 1),
        $url,
    )],
]);

it('refuses a query carrying anything the signer does not emit', function (string $query, SignedUrlVerdict $verdict): void {
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));
    $presented = explode('?', $url)[0].'?'.strtr($query, [
        '{issued}' => signedParam($url, 'wa_issued'),
        '{expires}' => signedParam($url, 'wa_expires'),
        '{signature}' => signedParam($url, 'wa_signature'),
    ]);

    expect(signer()->verify($presented))->toBe($verdict);
})->with([
    // The signature covers the path and these three values and nothing else, so an extra
    // parameter is unsigned space an attacker could write in. Refused, not ignored.
    'an extra parameter' => [
        'wa_issued={issued}&wa_expires={expires}&wa_signature={signature}&tenant=other',
        SignedUrlVerdict::Malformed,
    ],
    // "Last one wins" differs between PHP, proxies and web servers, and a difference there
    // is a difference between what was verified and what is served.
    'a repeated parameter' => [
        'wa_issued={issued}&wa_expires={expires}&wa_expires=9999999999&wa_signature={signature}',
        SignedUrlVerdict::Malformed,
    ],
    'a missing signature' => ['wa_issued={issued}&wa_expires={expires}', SignedUrlVerdict::Malformed],
    'a missing expiry' => ['wa_issued={issued}&wa_signature={signature}', SignedUrlVerdict::Malformed],
    'a non-numeric expiry' => [
        'wa_issued={issued}&wa_expires=soon&wa_signature={signature}',
        SignedUrlVerdict::Malformed,
    ],
    'a negative expiry' => [
        'wa_issued={issued}&wa_expires=-1&wa_signature={signature}',
        SignedUrlVerdict::Malformed,
    ],
    'an overlong expiry' => [
        'wa_issued={issued}&wa_expires=99999999999999999999&wa_signature={signature}',
        SignedUrlVerdict::Malformed,
    ],
    'a signature that is not hex' => [
        'wa_issued={issued}&wa_expires={expires}&wa_signature=zzzz',
        SignedUrlVerdict::Malformed,
    ],
]);

/*
|--------------------------------------------------------------------------
| Expiry and the 3600-second window (Req 9.6)
|--------------------------------------------------------------------------
*/

it('expires at its expiry second, not after it', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addSeconds(60));

    Carbon::setTestNow('2024-06-17 12:00:59');
    expect(signer()->verify($url))->toBe(SignedUrlVerdict::Valid);

    // The boundary is exclusive: a link expiring at t is refused from t onwards.
    Carbon::setTestNow('2024-06-17 12:01:00');
    expect(signer()->verify($url))->toBe(SignedUrlVerdict::Expired);

    Carbon::setTestNow('2024-06-18 12:00:00');
    expect(signer()->verify($url))->toBe(SignedUrlVerdict::Expired);
});

it('refuses a window longer than 3600 seconds at generation, not later', function (int $seconds): void {
    // A caller asking for a month-long export link is a bug in the caller. Issuing the
    // link and rejecting it an hour later is the worst of both: the recipient sees a
    // platform fault, and the caller never learns.
    expect(fn (): string => app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addSeconds($seconds)))
        ->toThrow(UnbuildableUrlException::class, 'at most 3600 seconds');
})->with([
    'one second over' => [3601],
    'a day' => [86400],
    'thirty days' => [2592000],
]);

it('signs exactly at the 3600-second boundary', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');

    expect(signer()->verify(
        app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addSeconds(3600)),
    ))->toBe(SignedUrlVerdict::Valid);
});

it('refuses an expiry that has already passed', function (int $offset): void {
    Carbon::setTestNow('2024-06-17 12:00:00');

    expect(fn (): string => app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addSeconds($offset)))
        ->toThrow(UnbuildableUrlException::class, 'cannot expire at or before');
})->with([
    'now' => [0],
    'a second ago' => [-1],
    'yesterday' => [-86400],
]);

it('refuses a validly signed link whose remaining life exceeds the cap', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addSeconds(3600));

    // A clock that moved backwards — a corrected NTP drift, a node with the wrong time —
    // leaves a genuinely signed link with more than 3600 seconds to run. Req 9.6 caps what
    // is *presented*, not only what is issued, so it is refused.
    Carbon::setTestNow('2024-06-17 11:55:00');

    expect(signer()->verify($url))->toBe(SignedUrlVerdict::WindowTooLong);
});

it('refuses a signed link that declares a window over the cap', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');
    $issued = now()->getTimestamp();
    $expires = $issued + 7200;
    $path = '/exports/01JABC/download';

    // Signed with the platform's own secret, in the payload shape `SignedUrlSigner`
    // documents — the one adversary model in which the ceiling has to be enforced at
    // verification too: a signer with a raised ceiling, or a compromised secret.
    $signature = str_replace('sha256=', '', app(SigningSecretStore::class)->sign(
        SignedUrlSigner::SECRET_SCOPE,
        implode('|', [
            'wa:signed-url:v1',
            strlen('bot.example.com').':bot.example.com',
            strlen($path).':'.$path,
            strlen((string) $issued).':'.$issued,
            strlen((string) $expires).':'.$expires,
        ]),
    ));

    $url = sprintf(
        'https://bot.example.com%s?wa_issued=%d&wa_expires=%d&wa_signature=%s',
        $path,
        $issued,
        $expires,
        $signature,
    );

    expect(signer()->verify($url))->toBe(SignedUrlVerdict::WindowTooLong);
});

it('refuses a configured default lifetime outside the range Req 9.6 allows', function (mixed $ttl): void {
    config(['wa.url.signed.ttl_seconds' => $ttl]);

    expect(fn (): string => app(UrlBuilder::class)->signed('/exports/01JABC/download'))
        ->toThrow(UnbuildableUrlException::class, 'wa.url.signed.ttl_seconds');
})->with([
    'over the ceiling' => [3601],
    'zero' => [0],
    'negative' => [-1],
    'not a number' => ['soon'],
]);

/*
|--------------------------------------------------------------------------
| Verification is total, and every refusal looks the same
|--------------------------------------------------------------------------
*/

it('returns a verdict for any input rather than raising', function (string $presented): void {
    // An attacker-controlled URL must not be able to produce a second observable outcome:
    // a 500 on malformed input and a 403 on a wrong signature is an oracle.
    expect(signer()->verify($presented))->toBeInstanceOf(SignedUrlVerdict::class)
        ->and(signer()->verify($presented)->isValid())->toBeFalse();
})->with([
    'empty' => [''],
    'not a URL' => ['not a url'],
    'scheme only' => ['https://'],
    'no host' => ['/exports/01JABC/download?wa_issued=1&wa_expires=2&wa_signature=abc'],
    'no path' => ['https://bot.example.com?wa_issued=1&wa_expires=2&wa_signature=abc'],
    'no query' => ['https://bot.example.com/exports/01JABC/download'],
    'empty query' => ['https://bot.example.com/exports/01JABC/download?'],
    'query with no pairs' => ['https://bot.example.com/exports/01JABC/download?&&'],
    'a parameter with no value' => ['https://bot.example.com/exports/01JABC/download?wa_signature'],
    'a foreign scheme' => ['javascript:alert(1)'],
    'a mailto' => ['mailto:someone@example.com'],
    'CRLF in the URL' => ["https://bot.example.com/exports\r\nX-Injected: 1?wa_issued=1"],
    'a very long string' => ['https://bot.example.com/'.str_repeat('a', 5000)],
    'the framework parameter names' => ['https://bot.example.com/exports/1?expires=9999999999&signature=abc'],
]);

it('refuses every kind of invalid link with one status and one sentence', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');
    $valid = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addSeconds(60));

    $presented = [
        'transplanted' => transplant($valid, 'attacker.example.net'),
        'tampered' => str_replace('01JABC', '01JXYZ', $valid),
        'malformed' => 'not a url',
    ];

    Carbon::setTestNow('2024-06-17 12:05:00');
    $presented['expired'] = $valid;

    // The reason is available to the operator and never to the client: the holder of a
    // signed link is unauthenticated, and telling them which half of the URL to keep
    // editing is the oracle Req 9.6 exists to prevent.
    foreach ($presented as $case => $url) {
        $refusal = null;

        try {
            signer()->assertValid($url);
        } catch (InvalidSignedUrlException $exception) {
            $refusal = $exception;
        }

        expect($refusal)->not->toBeNull()
            ->and($case)->toBeString()
            ->and($refusal?->getStatusCode())->toBe(403)
            ->and($refusal?->getMessage())->toBe(InvalidSignedUrlException::PUBLIC_MESSAGE)
            ->and($refusal?->verdict()->isValid())->toBeFalse()
            ->and($refusal?->operatorMessage())->toStartWith('Signed URL refused:');
    }
});

it('lets a valid link through assertValid', function (): void {
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(5));

    signer()->assertValid($url);

    expect(signer()->verify($url))->toBe(SignedUrlVerdict::Valid);
});

/*
|--------------------------------------------------------------------------
| The signing secret and its rotation
|--------------------------------------------------------------------------
*/

it('keeps links in the wild working across a secret rotation', function (): void {
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));
    $secrets = app(SigningSecretStore::class);

    expect(signer()->verify($url))->toBe(SignedUrlVerdict::Valid);

    // The dual-secret overlap (48h by default) is far longer than any link's life (at most
    // 3600s), so rotating the URL-signing secret never kills a link already in an inbox —
    // which a key derived from APP_KEY would.
    $rotation = $secrets->rotate(SignedUrlSigner::SECRET_SCOPE);
    $secrets->forgetSecrets();

    expect($rotation->hasOverlap())->toBeTrue()
        ->and(signer()->verify($url))->toBe(SignedUrlVerdict::Valid)
        // New links are signed with the new secret, and also verify.
        ->and(signer()->verify(app(UrlBuilder::class)->signed('/exports/01JXYZ/download')))
        ->toBe(SignedUrlVerdict::Valid);
});

it('refuses a link once its secret window has closed', function (): void {
    Carbon::setTestNow('2024-06-17 12:00:00');
    $url = app(UrlBuilder::class)->signed('/exports/01JABC/download', now()->addMinutes(15));

    app(SigningSecretStore::class)->rotate(SignedUrlSigner::SECRET_SCOPE, overlapSeconds: 60);
    app(SigningSecretStore::class)->forgetSecrets();

    Carbon::setTestNow('2024-06-17 12:05:00');

    // Past the overlap the retired secret no longer verifies. The link is inside its own
    // expiry, so this is the secret window closing, not the link's.
    expect(signer()->verify($url))->toBe(SignedUrlVerdict::SignatureMismatch);
});
