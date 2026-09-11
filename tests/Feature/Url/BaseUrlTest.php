<?php

declare(strict_types=1);

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Platform\PlatformSettings;
use App\Services\Url\BaseUrl;
use App\Services\Url\BaseUrlCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| The canonical base URL (Req 9.3 / A9; Correctness Property 27)
|--------------------------------------------------------------------------
| Three claims, in order of how much damage getting them wrong does:
|
| 1. the resolved base never depends on the incoming request — an attacker-controlled
|    `Host` header cannot move the origin the platform signs links and registers
|    webhooks against (Property 27, Req 9.1). Task 5.7 is the dedicated property test;
|    the tests here pin the claim for this class;
| 2. only a *verified* custom domain counts, and resolution falls **down** the chain
|    to the platform base rather than **up** to an unverified claim (Req 9.7);
| 3. one origin has one spelling, and a change to any input takes effect without a
|    manual cache clear (Req 9.3, Req 30.4).
*/

beforeEach(function (): void {
    Cache::flush();
    config(['app.url' => 'https://bot.example.com', 'wa.url.force_https' => null]);
});

/**
 * Queries issued while $work runs.
 */
function baseUrlQueriesDuring(Closure $work): int
{
    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $work();

    return $queries;
}

/**
 * Make $host the host of the request bound in the container, by every route a
 * framework or a proxy would offer.
 */
function withRequestHost(string $host): void
{
    $request = Request::create('http://placeholder.invalid/exports/42');

    $request->headers->set('Host', $host);
    $request->headers->set('X-Forwarded-Host', $host);
    $request->headers->set('X-Original-Host', $host);
    $request->server->set('HTTP_HOST', $host);
    $request->server->set('SERVER_NAME', $host);

    app()->instance('request', $request);
}

/*
|--------------------------------------------------------------------------
| Precedence (Req 9.3)
|--------------------------------------------------------------------------
*/

it('falls back to APP_URL when nothing overrides it', function (): void {
    expect(app(BaseUrl::class)->platform())->toBe('https://bot.example.com');
});

it('prefers the platform settings override over APP_URL', function (): void {
    app(PlatformSettings::class)->set(PlatformSettings::BASE_URL, 'https://panel.example.com');

    expect(app(BaseUrl::class)->platform())->toBe('https://panel.example.com');
});

it('treats a blank override as no override', function (): void {
    PlatformSetting::factory()->baseUrl('')->create();

    expect(app(BaseUrl::class)->platform())->toBe('https://bot.example.com');
});

it('canonicalises whatever it resolves', function (): void {
    config(['app.url' => 'HTTPS://Bot.Example.COM:443/app/']);

    expect(app(BaseUrl::class)->platform())->toBe('https://bot.example.com/app');
});

it('prefers a tenant verified custom domain over the platform base', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    expect(app(BaseUrl::class)->forTenant($tenant))->toBe('https://chat.acme.example')
        // The platform base is unaffected by a tenant having its own.
        ->and(app(BaseUrl::class)->platform())->toBe('https://bot.example.com');
});

it('gives a tenant without a custom domain the platform base', function (): void {
    $tenant = Tenant::factory()->create();

    expect(app(BaseUrl::class)->forTenant($tenant))->toBe(app(BaseUrl::class)->platform());
});

it('never resolves one tenant to another tenant domain', function (): void {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();

    TenantDomain::factory()->verified()->create(['tenant_id' => $acme->id, 'host' => 'chat.acme.example']);

    expect(app(BaseUrl::class)->forTenant($acme))->toBe('https://chat.acme.example')
        ->and(app(BaseUrl::class)->forTenant($globex))->toBe('https://bot.example.com');
});

it('inherits scheme, port and path prefix from the platform base for a custom domain', function (): void {
    config(['app.url' => 'https://bot.example.com:8443/app']);

    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    expect(app(BaseUrl::class)->forTenant($tenant))->toBe('https://chat.acme.example:8443/app');
});

/*
|--------------------------------------------------------------------------
| Only a verified domain counts (Req 9.7)
|--------------------------------------------------------------------------
*/

it('ignores an unverified domain claim and falls down to the platform base', function (): void {
    $tenant = Tenant::factory()->create();

    // A tenant may claim a host it does not own; until the challenge and the TLS check
    // pass, using it would point that tenant's webhook callbacks and signed links at a
    // host somebody else controls.
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'host' => 'victim.example']);

    expect(app(BaseUrl::class)->forTenant($tenant))->toBe('https://bot.example.com');
});

it('cannot yield a host from an unverified row even when handed one directly', function (): void {
    $tenant = Tenant::factory()->create();
    $claim = TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'host' => 'victim.example']);

    expect($claim->canonicalHost())->toBeNull()
        ->and($claim->isVerified())->toBeFalse()
        ->and(TenantDomain::canonicalHostFor($tenant->id))->toBeNull();

    $claim->update(['verified_at' => now()]);

    expect($claim->fresh()?->canonicalHost())->toBe('victim.example');
});

it('picks the most recently verified domain, deterministically', function (): void {
    $tenant = Tenant::factory()->create();

    TenantDomain::factory()->verified(now()->subMonth())->create([
        'tenant_id' => $tenant->id, 'host' => 'old.acme.example',
    ]);
    TenantDomain::factory()->verified(now()->subDay())->create([
        'tenant_id' => $tenant->id, 'host' => 'new.acme.example',
    ]);
    // An unverified claim never wins, however recent.
    TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'host' => 'pending.acme.example']);

    expect(app(BaseUrl::class)->forTenant($tenant))->toBe('https://new.acme.example');
});

it('stores one spelling per host so one domain has one owner', function (): void {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();

    TenantDomain::factory()->verified()->create(['tenant_id' => $acme->id, 'host' => 'Chat.Acme.Example.']);

    expect(TenantDomain::query()->where('host', 'chat.acme.example')->count())->toBe(1)
        ->and(app(BaseUrl::class)->forTenant($acme))->toBe('https://chat.acme.example')
        // A second tenant claiming the same host in a different spelling is a hijack, and
        // the global unique(host) refuses it because both spellings normalise to one.
        ->and(fn (): TenantDomain => TenantDomain::factory()->create([
            'tenant_id' => $globex->id, 'host' => 'CHAT.acme.example',
        ]))->toThrow(UniqueConstraintViolationException::class);
});

/*
|--------------------------------------------------------------------------
| No host-header injection (Req 9.1, 9.4; Property 27)
|--------------------------------------------------------------------------
*/

it('resolves the same base under any incoming Host header', function (string $host): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    withRequestHost($host);

    expect(app(BaseUrl::class)->platform())->toBe('https://bot.example.com')
        ->and(app(BaseUrl::class)->forTenant($tenant))->toBe('https://chat.acme.example');
})->with([
    'attacker origin' => ['attacker.example.net'],
    'attacker origin with a port' => ['attacker.example.net:8443'],
    'a host that looks like ours' => ['bot.example.com.attacker.example.net'],
    'punycode homograph' => ['xn--bt-example-4o0d.com'],
    'internationalised form' => ['пример.example'],
    'header injection attempt' => ["bot.example.com\r\nX-Injected: 1"],
    'absolute URL as a host' => ['https://attacker.example.net/'],
    'loopback' => ['127.0.0.1:8000'],
    'empty' => [''],
]);

it('resolves a base with no request bound at all', function (): void {
    // The console, the queue worker and the scheduler register webhooks and build links
    // too; a resolver that needed a request would fail exactly there.
    app()->forgetInstance('request');

    expect(app(BaseUrl::class)->platform())->toBe('https://bot.example.com');
});

it('never reads the request host anywhere in the base-URL path', function (): void {
    // The structural half of Property 27: the classes that resolve an origin — and the
    // ones that build absolute, webhook and signed URLs from it (tasks 5.2, 5.3) — have no
    // request-derived input at all, so there is nothing for a poisoned header to reach.
    // Comments are stripped first — these files *discuss* the headers they must not read.
    $forbidden = [
        'Illuminate\\Http\\Request',
        'Symfony\\Component\\HttpFoundation\\Request',
        'getHost',
        'getHttpHost',
        'getSchemeAndHttpHost',
        'getRequestUri',
        'HTTP_HOST',
        'X-Forwarded-Host',
        'X-Original-Host',
        'SERVER_NAME',
        '$_SERVER',
        '$_GET',
        'request(',
        'Request::',
        'URL::',
        'url()',
        // The framework's own URL and signature helpers are request-aware: the host they
        // would emit, and sign, comes from the incoming request. Reaching for one here is
        // how Req 9.1 gets undone by a one-line convenience.
        'route(',
        'signedRoute',
        'hasValidSignature',
        'UrlGenerator',
    ];

    $files = [
        app_path('Models/TenantDomain.php'),
        app_path('Services/Platform/PlatformSettings.php'),
        // The two host-*decision* classes (task 5.4). They are not in `Services/Url`
        // because they answer "may this host be served?" rather than "what host do we
        // emit?" — but they take a host as an argument, and the moment either of them
        // reaches for the request instead, the platform has a second opinion about hosts
        // derived from the header. `PlatformHosts` in particular now feeds both the apex
        // the platform *emits* under and the allowlist that *accepts* it.
        app_path('Services/Domains/PlatformHosts.php'),
        app_path('Services/Domains/HostAllowlist.php'),
    ];

    foreach (Finder::create()->files()->name('*.php')->in([app_path('Services/Url'), app_path('Support/Url')]) as $file) {
        $files[] = $file->getRealPath();
    }

    $offences = [];

    foreach ($files as $path) {
        if (! is_string($path) || $path === '') {
            continue;
        }

        $code = php_strip_whitespace($path);

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $offences[] = basename($path).' mentions '.$needle;
            }
        }
    }

    expect($offences)->toBe([], sprintf(
        'The canonical base URL must never be derived from the incoming request (Req 9.1, '
        .'Property 27), but: %s. Building URLs from the request host is host-header '
        .'injection — poisoned links, cache poisoning, webhook redirection.',
        implode('; ', $offences),
    ));
});

/*
|--------------------------------------------------------------------------
| HTTPS and loud failure (Req 9.2; deployment errors)
|--------------------------------------------------------------------------
*/

it('forces https outside local and testing', function (): void {
    config(['app.url' => 'http://bot.example.com/app']);
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(BaseUrl::class)->platform())->toBe('https://bot.example.com/app');
});

it('keeps plain http in local development', function (): void {
    config(['app.url' => 'http://bot.example.test:8000']);
    app()->detectEnvironment(fn (): string => 'local');

    expect(app(BaseUrl::class)->platform())->toBe('http://bot.example.test:8000');
});

it('lets an environment pin the https decision either way', function (): void {
    config(['app.url' => 'http://internal.example', 'wa.url.force_https' => false]);
    app()->detectEnvironment(fn (): string => 'staging');

    expect(app(BaseUrl::class)->platform())->toBe('http://internal.example');

    Cache::flush();
    config(['wa.url.force_https' => true]);
    app()->detectEnvironment(fn (): string => 'local');

    expect(app(BaseUrl::class)->platform())->toBe('https://internal.example');
});

it('refuses a loopback origin outside local and testing', function (string $url): void {
    config(['app.url' => $url]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn (): string => app(BaseUrl::class)->platform())
        ->toThrow(InvalidBaseUrlException::class, 'loopback interface');
})->with([
    // The framework default for an APP_URL nobody set. Silently accepting it registers
    // webhook callbacks with Meta/BSP/Razorpay that point at the provider's own machine,
    // and every failure that follows looks like the provider's fault.
    'framework default' => ['http://localhost'],
    'with a port' => ['http://localhost:8000'],
    'IPv4 loopback' => ['http://127.0.0.1'],
]);

it('refuses an empty or nonsensical APP_URL rather than defaulting', function (mixed $url, string $reason): void {
    config(['app.url' => $url]);

    expect(fn (): string => app(BaseUrl::class)->platform())
        ->toThrow(InvalidBaseUrlException::class, $reason);
})->with([
    'empty' => ['', 'is empty'],
    'null' => [null, 'is empty'],
    'bare host' => ['bot.example.com', 'has no scheme'],
    'not a URL' => ['not a url', 'header-injection attempt'],
]);

it('refuses a malformed override at the edit that causes it', function (): void {
    $settings = app(PlatformSettings::class);

    expect(fn () => $settings->set(PlatformSettings::BASE_URL, 'ftp://bot.example.com'))
        ->toThrow(InvalidBaseUrlException::class)
        // Nothing was persisted, so the platform still resolves its previous base.
        ->and(PlatformSetting::query()->where('key', PlatformSettings::BASE_URL)->exists())->toBeFalse()
        ->and(app(BaseUrl::class)->platform())->toBe('https://bot.example.com');
});

/*
|--------------------------------------------------------------------------
| Caching and invalidation (Req 30.4 / NFR1)
|--------------------------------------------------------------------------
*/

it('serves repeat resolutions without querying again', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    $base = app(BaseUrl::class);
    $base->platform();
    $base->forTenant($tenant);

    expect(baseUrlQueriesDuring(function () use ($base, $tenant): void {
        for ($i = 0; $i < 5; $i++) {
            $base->platform();
            $base->forTenant($tenant);
        }
    }))->toBe(0);
});

it('takes effect immediately when the override is changed, without a cache clear', function (): void {
    $settings = app(PlatformSettings::class);
    $base = app(BaseUrl::class);

    expect($base->platform())->toBe('https://bot.example.com');

    $versionBefore = app(BaseUrlCache::class)->version();

    $settings->set(PlatformSettings::BASE_URL, 'https://panel.example.com');

    expect(app(BaseUrlCache::class)->version())->toBeGreaterThan($versionBefore)
        ->and($base->platform())->toBe('https://panel.example.com');

    // Removing the override hands the platform back to APP_URL, just as immediately.
    $settings->forget(PlatformSettings::BASE_URL);

    expect($base->platform())->toBe('https://bot.example.com');
});

it('takes effect immediately when a domain is verified or withdrawn', function (): void {
    $tenant = Tenant::factory()->create();
    $base = app(BaseUrl::class);

    $claim = TenantDomain::factory()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    expect($base->forTenant($tenant))->toBe('https://bot.example.com');

    // What task 5.5 does when the ownership challenge and the TLS check both pass.
    $claim->update(['verified_at' => now()]);

    expect($base->forTenant($tenant))->toBe('https://chat.acme.example');

    $claim->delete();

    expect($base->forTenant($tenant))->toBe('https://bot.example.com');
});

it('cannot serve a base built from an APP_URL that has since changed', function (): void {
    $base = app(BaseUrl::class);

    expect($base->platform())->toBe('https://bot.example.com');

    // A deploy-time change fires no model event, so nothing bumps a version — the config
    // inputs are fingerprinted into the cache key instead, which is why this needs no
    // cache clear either.
    config(['app.url' => 'https://panel.example.com']);

    expect($base->platform())->toBe('https://panel.example.com');
});
