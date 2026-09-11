<?php

declare(strict_types=1);

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Exceptions\Url\UnbuildableUrlException;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Tenancy\Resolvers\SubdomainTenantResolver;
use App\Services\Tenancy\TenantContext;
use App\Services\Url\UrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Absolute, webhook and subdomain URLs (Req 9.1, 9.2 / A9; Property 27)
|--------------------------------------------------------------------------
| Every URL the platform emits comes from the configured canonical base. The claims
| pinned here, in order of what getting them wrong costs:
|
| 1. the emitted host never depends on the incoming request, whatever `Host` says — a
|    poisoned header cannot produce a poisoned link or a webhook callback registered
|    against an attacker's origin (Req 9.1; task 5.7 is the dedicated property test);
| 2. a path argument cannot move the URL to another origin or another resource;
| 3. a tenant with a verified custom domain gets its own origin, and one tenant never
|    gets another's;
| 4. a subdomain URL resolves back to the tenant it was built for — or is refused. A
|    subdomain link that lands on the wrong tenant is not a broken link, it is a
|    cross-tenant one, and nothing about it looks wrong to whoever received it.
*/

beforeEach(function (): void {
    Cache::flush();
    config([
        'app.url' => 'https://bot.example.com',
        'wa.url.force_https' => null,
        'wa.tenancy.apexes' => [],
    ]);
});

/**
 * Make $host the host of the request bound in the container, by every route a framework
 * or a proxy would offer.
 */
function withPoisonedHost(string $host): void
{
    $request = Request::create('http://placeholder.invalid/exports/42');

    $request->headers->set('Host', $host);
    $request->headers->set('X-Forwarded-Host', $host);
    $request->server->set('HTTP_HOST', $host);
    $request->server->set('SERVER_NAME', $host);

    app()->instance('request', $request);
}

function urlBuilder(): UrlBuilder
{
    return app(UrlBuilder::class);
}

/*
|--------------------------------------------------------------------------
| absolute()
|--------------------------------------------------------------------------
*/

it('builds an absolute URL from the canonical base', function (): void {
    expect(urlBuilder()->absolute('/exports'))->toBe('https://bot.example.com/exports')
        ->and(urlBuilder()->absolute(''))->toBe('https://bot.example.com');
});

it('emits one spelling however the caller writes the path', function (string $path): void {
    expect(urlBuilder()->absolute($path))->toBe('https://bot.example.com/exports/42');
})->with([
    'leading slash' => ['/exports/42'],
    'no leading slash' => ['exports/42'],
    'trailing slash' => ['/exports/42/'],
    'doubled separator' => ['/exports//42'],
]);

it('keeps the base mount point in front of the path', function (): void {
    config(['app.url' => 'https://bot.example.com:8443/app']);

    expect(urlBuilder()->absolute('/exports/42'))->toBe('https://bot.example.com:8443/app/exports/42');
});

it('builds the same URL under any incoming Host header', function (string $host): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    withPoisonedHost($host);

    expect(urlBuilder()->absolute('/exports/42'))->toBe('https://bot.example.com/exports/42')
        ->and(urlBuilder()->absolute('/exports/42', $tenant))->toBe('https://chat.acme.example/exports/42')
        ->and(urlBuilder()->webhook('bridge', 'ROUTE01J', $tenant))
        ->toBe('https://chat.acme.example/webhooks/bridge/ROUTE01J');
})->with([
    'attacker origin' => ['attacker.example.net'],
    'attacker origin with a port' => ['attacker.example.net:8443'],
    'a host that looks like ours' => ['bot.example.com.attacker.example.net'],
    'header injection attempt' => ["bot.example.com\r\nX-Injected: 1"],
    'absolute URL as a host' => ['https://attacker.example.net/'],
    'empty' => [''],
]);

it('builds URLs with no request bound at all', function (): void {
    // The queue worker and the scheduler send emails and register webhooks too.
    app()->forgetInstance('request');

    expect(urlBuilder()->absolute('/exports/42'))->toBe('https://bot.example.com/exports/42');
});

it('gives a tenant with a verified domain its own origin, and never another tenant one', function (): void {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $acme->id, 'host' => 'chat.acme.example']);
    // Unverified: resolution falls *down* to the platform base, never up to a host the
    // tenant may not own (Req 9.7).
    TenantDomain::factory()->create(['tenant_id' => $globex->id, 'host' => 'victim.example']);

    expect(urlBuilder()->absolute('/inbox', $acme))->toBe('https://chat.acme.example/inbox')
        ->and(urlBuilder()->absolute('/inbox', $globex))->toBe('https://bot.example.com/inbox');
});

it('uses the ambient tenant when the caller names none', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    $context = app(TenantContext::class);
    $context->set($tenant);

    expect(urlBuilder()->absolute('/inbox'))->toBe('https://chat.acme.example/inbox');

    // Platform-level code, with nothing bound, gets the platform origin.
    $context->forget();

    expect(urlBuilder()->absolute('/inbox'))->toBe('https://bot.example.com/inbox');
});

it('refuses a path that would move the URL somewhere else', function (string $path): void {
    expect(fn (): string => urlBuilder()->absolute($path))->toThrow(UnbuildableUrlException::class);
})->with([
    'protocol-relative' => ['//attacker.example/exports'],
    'traversal' => ['/exports/../../etc/passwd'],
    'query string' => ['/exports?tenant=other'],
    'CRLF injection' => ["/exports\r\nX-Injected: 1"],
    'encoded separator' => ['/exports/a%2Fb'],
]);

it('refuses to emit anything when the deployment has no usable base', function (): void {
    config(['app.url' => '']);

    expect(fn (): string => urlBuilder()->absolute('/exports'))->toThrow(InvalidBaseUrlException::class);
});

/*
|--------------------------------------------------------------------------
| webhook() (Req 9.2)
|--------------------------------------------------------------------------
*/

it('builds a provider callback URL from the canonical base', function (): void {
    expect(urlBuilder()->webhook('cloud-api', '01JABCDEFGHJKMNPQRSTVWXYZ'))
        ->toBe('https://bot.example.com/webhooks/cloud-api/01JABCDEFGHJKMNPQRSTVWXYZ')
        // The provider slug is part of a URL a third party fetches, so it has one spelling.
        ->and(urlBuilder()->webhook('Cloud-API', 'ROUTE_KEY-1'))
        ->toBe('https://bot.example.com/webhooks/cloud-api/ROUTE_KEY-1');
});

it('refuses a provider or route key that is not one safe path segment', function (string $provider, string $routeKey): void {
    expect(fn (): string => urlBuilder()->webhook($provider, $routeKey))->toThrow(UnbuildableUrlException::class);
})->with([
    'provider with a separator' => ['cloud/api', 'ROUTE01J'],
    'provider with a traversal' => ['..', 'ROUTE01J'],
    'provider with a dot' => ['cloud.api', 'ROUTE01J'],
    'empty provider' => ['', 'ROUTE01J'],
    'route key with a separator' => ['bridge', 'a/b'],
    'route key with a query' => ['bridge', 'a?b=1'],
    'empty route key' => ['bridge', ''],
    'route key CRLF' => ['bridge', "a\r\nb"],
]);

it('refuses to register a callback over plain http outside local', function (): void {
    config(['app.url' => 'http://bot.example.com', 'wa.url.force_https' => false]);
    app()->detectEnvironment(fn (): string => 'production');

    // A callback URL is fetched from the public internet and carries a verify token or an
    // HMAC; over http it is a credential on the wire.
    expect(fn (): string => urlBuilder()->webhook('cloud-api', 'ROUTE01J'))
        ->toThrow(UnbuildableUrlException::class, 'Req 9.2 requires HTTPS')
        // An absolute panel link over the same origin is a different risk and still builds.
        ->and(urlBuilder()->absolute('/inbox'))->toBe('http://bot.example.com/inbox');
});

/*
|--------------------------------------------------------------------------
| tenantSubdomain() (Req 9.3, 9.7)
|--------------------------------------------------------------------------
*/

it('hangs a tenant subdomain off the configured apex', function (): void {
    config(['wa.tenancy.apexes' => ['app.bot.example.com']]);
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => null]);

    expect(urlBuilder()->tenantSubdomain($tenant))->toBe('https://acme.app.bot.example.com');
});

it('falls back to the platform host when no apex is configured', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => null]);

    expect(urlBuilder()->tenantSubdomain($tenant))->toBe('https://acme.bot.example.com');
});

it('inherits scheme, port and mount point from the platform base', function (): void {
    config(['app.url' => 'https://bot.example.com:8443/app']);
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => null]);

    expect(urlBuilder()->tenantSubdomain($tenant))->toBe('https://acme.bot.example.com:8443/app');
});

it('uses the platform apex even for a tenant with its own domain', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => null]);
    TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => 'chat.acme.example']);

    // A subdomain hangs off the apex by definition; a tenant with a custom domain has both.
    expect(urlBuilder()->tenantSubdomain($tenant))->toBe('https://acme.bot.example.com')
        ->and(urlBuilder()->absolute('/inbox', $tenant))->toBe('https://chat.acme.example/inbox');
});

it('prefers the tenant explicit subdomain over its slug', function (): void {
    // `SubdomainTenantResolver` matches the subdomain column first and only falls back to
    // `slug WHERE subdomain IS NULL`, so emitting the slug here would produce a URL that
    // resolves to no tenant at all.
    $tenant = Tenant::factory()->create(['slug' => 'acme-corporation', 'subdomain' => 'acme']);

    expect(urlBuilder()->tenantSubdomain($tenant))->toBe('https://acme.bot.example.com');
});

it('emits a subdomain the tenant resolver resolves back to the same tenant', function (?string $subdomain): void {
    config(['wa.tenancy.apexes' => ['app.bot.example.com']]);
    $tenant = Tenant::factory()->create(['slug' => 'acme-corporation', 'subdomain' => $subdomain]);

    $host = parse_url(urlBuilder()->tenantSubdomain($tenant), PHP_URL_HOST);
    $resolution = app(SubdomainTenantResolver::class)->resolve(
        Request::create('https://'.(is_string($host) ? $host : '').'/panel'),
    );

    expect($resolution?->tenant->getKey())->toBe($tenant->getKey());
})->with([
    'explicit subdomain' => ['acme'],
    'slug fallback' => [null],
]);

it('refuses a reserved label rather than emitting a URL that is not the tenant', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'admin', 'subdomain' => null]);

    expect(fn (): string => urlBuilder()->tenantSubdomain($tenant))
        ->toThrow(UnbuildableUrlException::class, 'reserved by the platform');
});

it('refuses a slug label another tenant answers on', function (): void {
    // `slug` and `subdomain` are each unique but not unique together, so this is reachable:
    // resolution matches the subdomain column first, so a URL built from the slug would
    // resolve to the *other* tenant.
    Tenant::factory()->create(['slug' => 'globex-inc', 'subdomain' => 'acme']);
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => null]);

    expect(fn (): string => urlBuilder()->tenantSubdomain($tenant))
        ->toThrow(UnbuildableUrlException::class, 'claimed by another tenant');
});

it('refuses a label that is not a DNS label', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'acme.corp', 'subdomain' => null]);

    expect(fn (): string => urlBuilder()->tenantSubdomain($tenant))
        ->toThrow(UnbuildableUrlException::class, 'not a single DNS label');
});

it('refuses a malformed configured apex instead of quietly using the next one', function (): void {
    config(['wa.tenancy.apexes' => ['not a host', 'app.bot.example.com']]);
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => null]);

    // Skipping the bad entry would move every tenant's URL onto a different apex.
    expect(fn (): string => urlBuilder()->tenantSubdomain($tenant))->toThrow(InvalidBaseUrlException::class);
});
