<?php

declare(strict_types=1);

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\VerifiedDomainDirectory;
use App\Services\Tenancy\Resolvers\ChainTenantResolver;
use App\Services\Tenancy\Resolvers\CustomDomainTenantResolver;
use App\Services\Tenancy\Resolvers\SubdomainTenantResolver;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Host-based routing: verified custom domains and tenant subdomains (Req 9.3 / A9)
|--------------------------------------------------------------------------
| Driven through the middleware rather than by calling the resolvers directly, so
| the container wiring, the configured chain order, and the `web` group placement
| are all covered.
*/

beforeEach(function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);

    Route::middleware('web')->get('/_test/tenant', function (TenantContext $context): JsonResponse {
        return response()->json([
            'tenant' => $context->currentId(),
            'source' => $context->resolvedVia()->value,
        ]);
    });
});

it('resolves a tenant from its verified custom domain', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant, 'tenant')->verified()->create(['host' => 'chat.acme.example']);

    thisTest()->getJson('http://chat.acme.example/_test/tenant')
        ->assertOk()
        ->assertJson([
            'tenant' => $tenant->id,
            'source' => TenantResolutionSource::CustomDomain->value,
        ]);
});

it('resolves nothing from an unverified claim, so a host nobody proved grants nothing', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant, 'tenant')->create(['host' => 'chat.acme.example']);

    thisTest()->getJson('http://chat.acme.example/_test/tenant')
        ->assertOk()
        ->assertJson(['tenant' => null, 'source' => TenantResolutionSource::None->value]);
});

it('stops resolving the moment a verification is revoked', function (): void {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant, 'tenant')->verified()->create([
        'host' => 'chat.acme.example',
    ]);

    thisTest()->getJson('http://chat.acme.example/_test/tenant')
        ->assertJson(['tenant' => $tenant->id]);

    $domain->forceFill(['verified_at' => null])->save();

    thisTest()->getJson('http://chat.acme.example/_test/tenant')
        ->assertJson(['tenant' => null]);
});

it('starts resolving the moment a claim is verified, with no cache to wait out', function (): void {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant, 'tenant')->create(['host' => 'chat.acme.example']);

    // Warm the negative answer first: an unverified host must not be pinned as "unknown".
    thisTest()->getJson('http://chat.acme.example/_test/tenant')->assertJson(['tenant' => null]);

    $domain->forceFill(['verified_at' => now()])->save();

    thisTest()->getJson('http://chat.acme.example/_test/tenant')->assertJson(['tenant' => $tenant->id]);
});

it('resolves nothing for a host nobody has claimed', function (): void {
    Tenant::factory()->create();

    thisTest()->getJson('http://attacker.example/_test/tenant')
        ->assertOk()
        ->assertJson(['tenant' => null]);
});

it('never lets one tenant\'s verified host resolve another tenant', function (): void {
    $owner = Tenant::factory()->create();
    Tenant::factory()->create();
    TenantDomain::factory()->for($owner, 'tenant')->verified()->create(['host' => 'chat.acme.example']);

    thisTest()->getJson('http://chat.acme.example/_test/tenant')
        ->assertJson(['tenant' => $owner->id]);
});

it('still resolves a tenant subdomain under the apex', function (): void {
    $tenant = Tenant::factory()->create(['subdomain' => 'acme']);

    thisTest()->getJson('http://acme.app.example.test/_test/tenant')
        ->assertOk()
        ->assertJson([
            'tenant' => $tenant->id,
            'source' => TenantResolutionSource::Subdomain->value,
        ]);
});

it('falls back to the slug when a tenant set no subdomain', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'globex-co', 'subdomain' => null]);

    thisTest()->getJson('http://globex-co.app.example.test/_test/tenant')
        ->assertOk()
        ->assertJson([
            'tenant' => $tenant->id,
            'source' => TenantResolutionSource::Subdomain->value,
        ]);
});

it('resolves nothing for a reserved label or the apex itself', function (string $host): void {
    Tenant::factory()->create(['slug' => 'admin', 'subdomain' => null]);

    thisTest()->getJson('http://'.$host.'/_test/tenant')
        ->assertOk()
        ->assertJson(['tenant' => null]);
})->with([
    'a reserved label' => ['admin.app.example.test'],
    'the apex' => ['app.example.test'],
]);

it('places the custom-domain resolver ahead of the subdomain one', function (): void {
    $chain = app(TenantResolver::class);

    expect($chain)->toBeInstanceOf(ChainTenantResolver::class);

    $classes = array_map(
        static fn (object $resolver): string => $resolver::class,
        $chain instanceof ChainTenantResolver ? $chain->resolvers() : [],
    );

    $custom = array_search(CustomDomainTenantResolver::class, $classes, true);
    $subdomain = array_search(SubdomainTenantResolver::class, $classes, true);

    expect($custom)->toBeInt()
        ->and($subdomain)->toBeInt()
        ->and($custom)->toBeLessThan($subdomain);
});

it('exposes the verified hosts for the accepted-host allowlist, excluding unverified ones', function (): void {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant, 'tenant')->verified()->create(['host' => 'chat.acme.example']);
    TenantDomain::factory()->for($tenant, 'tenant')->create(['host' => 'pending.acme.example']);

    expect(app(VerifiedDomainDirectory::class)->verifiedHosts())->toBe(['chat.acme.example']);
});

it('serves the HTTP challenge only for the matching host and token', function (): void {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant, 'tenant')->challenged(
        App\Enums\DomainChallengeMethod::HttpFile,
    )->create(['host' => 'chat.acme.example']);

    $token = (string) $domain->challenge_token;
    $path = '/.well-known/wa-domain-challenge/'.$token;

    thisTest()->get('http://chat.acme.example'.$path)
        ->assertOk()
        ->assertSee($token);

    // Right token, wrong host: the host is part of the lookup key, so this must 404.
    thisTest()->get('http://elsewhere.example'.$path)->assertNotFound();

    // Right host, wrong token.
    thisTest()->get('http://chat.acme.example/.well-known/wa-domain-challenge/'.str_repeat('a', 43))
        ->assertNotFound();
});

it('does not serve an HTTP challenge for a DNS-method claim', function (): void {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant, 'tenant')->challenged()->create([
        'host' => 'chat.acme.example',
    ]);

    thisTest()->get('http://chat.acme.example/.well-known/wa-domain-challenge/'.$domain->challenge_token)
        ->assertNotFound();
});
