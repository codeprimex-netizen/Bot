<?php

declare(strict_types=1);

use App\Enums\DomainChallengeMethod;
use App\Exceptions\Url\HostNotAllowedException;
use App\Http\Middleware\EnforceAllowedHost;
use App\Http\Middleware\TrustProxies;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\HostAllowlist;
use App\Services\Platform\PlatformSettings;
use App\Services\Tenancy\TenantContext;
use App\Services\Url\UrlBuilder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Accepted request hosts (Req 9.4, 9.5 / A9; Property 27, third clause)
|--------------------------------------------------------------------------
| Property 27 has two halves that must both hold and are opposite in direction:
| generation never reads the host (BaseUrlTest, UrlBuilderTest), and *acceptance* reads
| nothing but the host — against a list built from the platform's own configuration and
| from verified rows. What is pinned here, in order of what getting it wrong costs:
|
| 1. a host the platform **emits** is a host it **accepts** and **resolves**. The
|    round-trip is asserted under a plain APP_URL and under a `platform_settings`
|    override, because those two disagreed until this task: the builder hangs a tenant
|    label off the canonical base while the host space was derived from APP_URL alone, so
|    an override made every emitted subdomain link point at a host the platform refused;
| 2. an unrecognised host is refused *before the action runs*, and says so (Req 9.5);
| 3. what the subdomain pattern admits is exactly one label under an apex — not arbitrary
|    depth, not a suffix match, not somebody else's domain that ends the same way;
| 4. an unverified or revoked custom domain is not accepted, immediately (Req 9.7);
| 5. the two enforcement layers — Symfony's trusted-host patterns and the middleware —
|    answer identically, host for host;
| 6. the domain-ownership challenge and the health check survive host rejection, because
|    both are asked on hosts no allowlist can know yet.
*/

/*
| Symfony holds trusted proxies in *static* state, so a test that configures one would
| otherwise leak it into whatever runs next (the suite has no fixed order). Cleared after
| every test in this file: with no proxy trusted, no forwarded header is believed, which is
| the framework's own default.
*/
afterEach(function (): void {
    Request::setTrustedProxies([], Request::getTrustedHeaderSet());
    Request::setTrustedHosts([]);
});

beforeEach(function (): void {
    config([
        'app.url' => 'https://app.example.test',
        'wa.url.force_https' => null,
        'wa.url.hosts.enforce' => true,
        'wa.url.hosts.additional' => [],
        'wa.url.hosts.unrestricted_paths' => ['up'],
        'wa.tenancy.apexes' => ['app.example.test'],
    ]);
});

function allowlist(): HostAllowlist
{
    return app(HostAllowlist::class);
}

/**
 * Match $host the way Symfony matches a trusted-host pattern: case-insensitively, against
 * the whole set, on an already-lowercased host with no port.
 */
function matchesTrustedPatterns(string $host): bool
{
    foreach (allowlist()->patterns() as $pattern) {
        if (preg_match('{'.$pattern.'}i', strtolower($host)) === 1) {
            return true;
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| What the list admits
|--------------------------------------------------------------------------
*/

it('accepts the platform apex, its own canonical host, and one label under an apex', function (string $host): void {
    expect(allowlist()->accepts($host))->toBeTrue();
})->with([
    'the apex itself' => ['app.example.test'],
    'a tenant subdomain' => ['acme.app.example.test'],
    'a hyphenated label' => ['acme-co.app.example.test'],
    // A reserved label is the *platform's* host, not a tenant's: an operator may serve the
    // admin panel on it. It is still never resolved as a tenant — see the resolver tests.
    'a reserved platform label' => ['admin.app.example.test'],
    'trailing root dot' => ['acme.app.example.test.'],
    'mixed case' => ['ACME.App.Example.Test'],
]);

it('rejects every host outside the platform host space', function (string $host): void {
    expect(allowlist()->accepts($host))->toBeFalse();
})->with([
    'an unrelated domain' => ['evil.example'],
    // Two labels below the apex is not a tenant subdomain. Accepting arbitrary depth is
    // how one wildcard certificate becomes an open host space.
    'a deeper nesting' => ['a.b.app.example.test'],
    // A suffix match without the dot would accept a different registrable domain.
    'a domain merely ending the same way' => ['evil-app.example.test'],
    // Our apex as somebody else's prefix.
    'our apex as a prefix' => ['app.example.test.evil.example'],
    'the parent of our apex' => ['example.test'],
    'a label that is not a DNS label' => ['_acme.app.example.test'],
    'a label starting with a hyphen' => ['-acme.app.example.test'],
    'an over-long label' => [str_repeat('a', 64).'.app.example.test'],
    'an IPv4 literal' => ['192.0.2.10'],
    'an IPv6 literal' => ['[::1]'],
    'an empty host' => [''],
    'whitespace' => ['   '],
    'a host carrying a port' => ['acme.app.example.test:8080'],
]);

it('accepts a verified custom domain exactly, and nothing under it', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant, 'tenant')->verified()->create(['host' => 'chat.acme.example']);

    expect(allowlist()->accepts('chat.acme.example'))->toBeTrue()
        // A verified domain says nothing about names below it: that space belongs to the
        // tenant's DNS, and the platform has proof for one name only.
        ->and(allowlist()->accepts('sub.chat.acme.example'))->toBeFalse()
        ->and(allowlist()->accepts('acme.example'))->toBeFalse();
});

it('never accepts an unverified claim, so a host nobody proved is refused', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant, 'tenant')->create(['host' => 'pending.acme.example']);

    expect(allowlist()->accepts('pending.acme.example'))->toBeFalse();
});

it('accepts a host the moment it is verified and refuses it the moment it is revoked', function (): void {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant, 'tenant')->create(['host' => 'chat.acme.example']);

    // Warm the negative answer first: the cached "unknown" must not outlive the write.
    expect(allowlist()->accepts('chat.acme.example'))->toBeFalse();

    $domain->forceFill(['verified_at' => now()])->save();

    expect(allowlist()->accepts('chat.acme.example'))->toBeTrue();

    $domain->forceFill(['verified_at' => null])->save();

    expect(allowlist()->accepts('chat.acme.example'))->toBeFalse();
});

it('accepts the canonical platform host even when the apexes are configured elsewhere', function (): void {
    // A deployment whose panel lives on `bot.example.test` while tenant subdomains hang
    // off `app.example.test`. Both are the platform's, and links are emitted on the first.
    config(['app.url' => 'https://bot.example.test']);

    expect(allowlist()->accepts('bot.example.test'))->toBeTrue()
        // ...but the platform host is not an apex, so nothing hangs off it: the builder
        // emits `{label}.app.example.test`, not `{label}.bot.example.test`.
        ->and(allowlist()->accepts('acme.bot.example.test'))->toBeFalse();
});

it('accepts an operator-listed internal host and ignores a malformed entry', function (): void {
    config(['wa.url.hosts.additional' => ['Internal-LB.svc.cluster.local', 'not a host', '*', '']]);

    expect(allowlist()->accepts('internal-lb.svc.cluster.local'))->toBeTrue()
        ->and(allowlist()->accepts('not a host'))->toBeFalse()
        // There is no wildcard escape hatch: the operator list is exact hosts, so nothing
        // in config can switch the allowlist off by widening it.
        ->and(allowlist()->accepts('*'))->toBeFalse()
        ->and(allowlist()->accepts('anything.example'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The two layers agree
|--------------------------------------------------------------------------
*/

it('gives Symfony trusted-host patterns that admit exactly what the middleware admits', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant, 'tenant')->verified()->create(['host' => 'chat.acme.example']);
    TenantDomain::factory()->for($tenant, 'tenant')->create(['host' => 'pending.acme.example']);
    config(['wa.url.hosts.additional' => ['internal-lb.svc.cluster.local']]);

    $hosts = [
        'app.example.test', 'acme.app.example.test', 'admin.app.example.test',
        'a.b.app.example.test', 'evil-app.example.test', 'app.example.test.evil.example',
        'chat.acme.example', 'sub.chat.acme.example', 'pending.acme.example',
        'internal-lb.svc.cluster.local', 'evil.example', 'example.test',
        '_acme.app.example.test', str_repeat('a', 64).'.app.example.test', '192.0.2.10',
    ];

    $disagreements = [];

    foreach ($hosts as $host) {
        if (allowlist()->accepts($host) !== matchesTrustedPatterns($host)) {
            $disagreements[] = $host;
        }
    }

    expect($disagreements)->toBe([], sprintf(
        'TrustHosts and EnforceAllowedHost must admit the same hosts, but they disagree '
        .'about: %s. A host one layer accepts and the other refuses is a route that works '
        .'until something calls getHost().',
        implode(', ', $disagreements),
    ));
});

it('wires the framework middleware from the live allowlist, in an order that matters', function (): void {
    // The `TrustHosts` half is inert under tests by the framework's own design
    // (`shouldSpecifyTrustedHosts()`), so the wiring is pinned here instead: without this,
    // a bootstrap edit could drop the Symfony-level guard and no test would notice.
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect($global)->toContain(TrustHosts::class)
        ->and($global)->toContain(EnforceAllowedHost::class)
        // Our TrustProxies replaces the framework's, so "trust nothing by default" is not
        // silently reverted to the framework's header set.
        ->and($global)->toContain(TrustProxies::class)
        ->and($global)->not->toContain(FrameworkTrustProxies::class);

    // Order: the host check must see the *effective* host, which behind a trusted proxy is
    // the forwarded one. Checking before TrustProxies would test the internal hop's name
    // and then serve whatever the header said.
    expect(array_search(TrustProxies::class, $global, true))
        ->toBeLessThan(array_search(EnforceAllowedHost::class, $global, true));

    // And the patterns it installs are this platform's, not the framework's
    // `^(.+\.)?{APP_URL host}$` (which would admit any depth of subdomain).
    expect((new TrustHosts(app()))->hosts())->toBe(allowlist()->patterns());
});

it('emits no trusted-host restriction at all on the paths served on any host', function (): void {
    expect(allowlist()->patternsFor('.well-known/wa-domain-challenge/abc'))->toBe([])
        ->and(allowlist()->patternsFor('up'))->toBe([])
        ->and(allowlist()->patternsFor('panel/dashboard'))->toBe(allowlist()->patterns())
        ->and(allowlist()->patterns())->not->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Enforcement (Req 9.5)
|--------------------------------------------------------------------------
*/

it('refuses an unrecognised host before the requested action runs', function (): void {
    $ran = false;

    Route::middleware('web')->get('/_test/side-effect', function () use (&$ran): JsonResponse {
        $ran = true;

        return response()->json(['ok' => true]);
    });

    thisTest()->get('http://evil.example/_test/side-effect')
        ->assertStatus(HostNotAllowedException::STATUS)
        ->assertSee(HostNotAllowedException::PUBLIC_MESSAGE)
        // Never the host back: reflecting an attacker-controlled header buys nothing.
        ->assertDontSee('evil.example');

    expect($ran)->toBeFalse();

    thisTest()->get('http://app.example.test/_test/side-effect')->assertOk();

    expect($ran)->toBeTrue();
});

it('turns Symfony\'s own untrusted-host refusal into the same typed rejection', function (): void {
    // What production looks like once `TrustHosts` has installed the patterns: Symfony
    // refuses the host inside `getHost()`. Both layers must produce one response shape, or
    // which of them noticed first would be observable.
    Request::setTrustedHosts(allowlist()->patterns());

    $request = Request::create('http://evil.example/panel');

    try {
        app(EnforceAllowedHost::class)->handle($request, fn (): Response => new Response);
        $refused = null;
    } catch (HostNotAllowedException $e) {
        $refused = $e;
    }

    expect($refused)->toBeInstanceOf(HostNotAllowedException::class)
        ->and($refused?->getStatusCode())->toBe(HostNotAllowedException::STATUS)
        // Symfony returns '' from getHost() once it has refused, so the log line falls back
        // to the raw header rather than losing the evidence.
        ->and($refused?->host())->toBe('evil.example');
});

it('answers an API caller with the usual envelope', function (): void {
    Route::middleware('web')->get('/_test/side-effect', fn (): JsonResponse => response()->json(['ok' => true]));

    thisTest()->getJson('http://evil.example/_test/side-effect')
        ->assertStatus(HostNotAllowedException::STATUS)
        ->assertExactJson([
            'message' => HostNotAllowedException::PUBLIC_MESSAGE,
            'error' => HostNotAllowedException::ERROR_CODE,
        ]);
});

it('follows the environment when enforcement is not pinned', function (): void {
    config(['wa.url.hosts.enforce' => null]);

    expect(allowlist()->enforces())->toBeFalse('an arbitrary host is a developer typing one in local/testing');

    app()->detectEnvironment(static fn (): string => 'production');

    expect(allowlist()->enforces())->toBeTrue('Req 9.4 is not optional on a deployment');
});

it('keeps the refused host out of the response but available to an operator', function (): void {
    $exception = HostNotAllowedException::forHost("evil.example\r\nInjected: 1");

    expect($exception->publicMessage())->toBe(HostNotAllowedException::PUBLIC_MESSAGE)
        ->and($exception->operatorMessage())->toContain('evil.example')
        // A CR/LF in a log line is a forged second entry.
        ->and($exception->operatorMessage())->not->toContain("\r")
        ->and($exception->operatorMessage())->not->toContain("\n")
        ->and(HostNotAllowedException::forHost('')->host())->toBe(HostNotAllowedException::UNKNOWN_HOST);
});

/*
|--------------------------------------------------------------------------
| The paths that must answer before a host is recognised
|--------------------------------------------------------------------------
*/

it('still serves the ownership challenge on a host that is not yet verified', function (): void {
    // This is the interaction that breaks in production and not in tests: an http-01
    // challenge is fetched at the claimed host *before* verification, which is the one
    // state in which the host is legitimately off the allowlist. Refusing it would leave
    // DNS as the only satisfiable method, for ever.
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant, 'tenant')
        ->challenged(DomainChallengeMethod::HttpFile)
        ->create(['host' => 'chat.acme.example']);

    $token = (string) $domain->challenge_token;

    expect(allowlist()->accepts('chat.acme.example'))->toBeFalse();

    thisTest()->get('http://chat.acme.example/.well-known/wa-domain-challenge/'.$token)
        ->assertOk()
        ->assertSee($token);

    // Only that path, though: the exemption is a path, not a host.
    Route::middleware('web')->get('/_test/panel', fn (): JsonResponse => response()->json(['ok' => true]));

    thisTest()->get('http://chat.acme.example/_test/panel')
        ->assertStatus(HostNotAllowedException::STATUS);
});

it('still answers the health check on a host no allowlist can know', function (): void {
    // An orchestrator dials a container by pod IP or service name, so a liveness probe's
    // Host matches nothing. A rejected probe is a restart loop that reads as a crash.
    thisTest()->get('http://10.1.2.3/up')->assertOk();
    thisTest()->get('http://evil.example/_test/nothing')->assertStatus(HostNotAllowedException::STATUS);
});

/*
|--------------------------------------------------------------------------
| emit -> accept -> resolve
|--------------------------------------------------------------------------
*/

it('accepts and resolves the tenant subdomain it emits', function (?string $override, array $apexes): void {
    config(['wa.tenancy.apexes' => $apexes]);

    if ($override !== null) {
        app(PlatformSettings::class)->set(PlatformSettings::BASE_URL, $override);
    }

    Route::middleware('web')->get('/_test/tenant', fn (TenantContext $context): JsonResponse => response()->json([
        'tenant' => $context->currentId(),
    ]));

    $tenant = Tenant::factory()->create(['subdomain' => 'acme']);

    $emitted = app(UrlBuilder::class)->tenantSubdomain($tenant);
    $host = (string) parse_url($emitted, PHP_URL_HOST);

    expect(allowlist()->accepts($host))->toBeTrue(sprintf(
        'The platform emits %s but would refuse a request on it — every subdomain link it '
        .'hands out is a link it rejects.',
        $emitted,
    ));

    thisTest()->getJson($emitted.'/_test/tenant')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->id]);
})->with([
    // The configuration this was always right in.
    'configured apex' => [null, ['app.example.test']],
    // A single-domain install: no apex configured, the base is APP_URL.
    'apex derived from APP_URL' => [null, []],
    // The one that was broken: the base is the admin override, so the label hangs off
    // `panel.example.test` — a host the APP_URL-derived host space had never heard of.
    'apex derived from the platform_settings override' => ['https://panel.example.test', []],
    // Override *and* explicit apexes: the builder emits on the configured apex, and both
    // it and the override host are accepted.
    'override with configured apexes' => ['https://panel.example.test', ['app.example.test']],
]);

it('accepts and resolves a verified custom domain it emits for a tenant', function (): void {
    Route::middleware('web')->get('/_test/tenant', fn (TenantContext $context): JsonResponse => response()->json([
        'tenant' => $context->currentId(),
    ]));

    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant, 'tenant')->verified()->create(['host' => 'chat.acme.example']);

    $emitted = app(UrlBuilder::class)->absolute('/', $tenant);

    expect((string) parse_url($emitted, PHP_URL_HOST))->toBe('chat.acme.example');

    thisTest()->getJson('http://chat.acme.example/_test/tenant')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->id]);
});

/*
|--------------------------------------------------------------------------
| Trusted proxies — request attribution, not URL generation
|--------------------------------------------------------------------------
*/

it('ignores forwarded headers from an untrusted source', function (): void {
    $request = Request::create('http://app.example.test/x', server: ['REMOTE_ADDR' => '10.0.0.9']);
    $request->headers->set('X-Forwarded-For', '203.0.113.7');
    $request->headers->set('X-Forwarded-Proto', 'https');

    app(TrustProxies::class)->handle($request, fn (): Response => new Response);

    // A client that could pick its own IP would pick one per request: per-IP throttles,
    // the anti-fraud device/IP heuristics, and audit correlation all read this value.
    expect($request->ip())->toBe('10.0.0.9')
        ->and($request->isSecure())->toBeFalse();
});

it('believes a configured proxy, and only the four standard headers', function (): void {
    config(['wa.url.proxies.trusted' => ['10.0.0.9']]);

    $request = Request::create('http://app.example.test/x', server: ['REMOTE_ADDR' => '10.0.0.9']);
    $request->headers->set('X-Forwarded-For', '203.0.113.7');
    $request->headers->set('X-Forwarded-Proto', 'https');
    $request->headers->set('X-Forwarded-Host', 'acme.app.example.test');
    $request->headers->set('X-Forwarded-Prefix', '/injected');

    app(TrustProxies::class)->handle($request, fn (): Response => new Response);

    expect($request->ip())->toBe('203.0.113.7')
        ->and($request->isSecure())->toBeTrue()
        // The forwarded host becomes the effective one — which is why the accepted-host
        // check runs after this middleware and tests this value.
        ->and($request->getHost())->toBe('acme.app.example.test')
        // X-Forwarded-Prefix would let a header rewrite the application root.
        ->and($request->getBaseUrl())->toBe('');
});

it('unwraps a wildcard proxy list so it means what it says', function (): void {
    config(['wa.url.proxies.trusted' => ['*']]);

    $request = Request::create('http://app.example.test/x', server: ['REMOTE_ADDR' => '10.0.0.9']);
    $request->headers->set('X-Forwarded-For', '203.0.113.7');

    app(TrustProxies::class)->handle($request, fn (): Response => new Response);

    expect($request->ip())->toBe('203.0.113.7');
});
