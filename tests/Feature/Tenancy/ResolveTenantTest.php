<?php

declare(strict_types=1);

use App\Enums\TenantResolutionSource;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Per-request tenant resolution (Req 1.1 / A1)
|--------------------------------------------------------------------------
| Exercised end to end through the middleware rather than by calling the
| resolvers directly, so the container wiring, the `web` group placement, and
| the `resolve.tenant` alias are all covered.
*/

beforeEach(function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);

    $report = function (TenantContext $context): JsonResponse {
        return response()->json([
            'tenant' => $context->currentId(),
            'source' => $context->resolvedVia()->value,
        ]);
    };

    // The panel side: session-aware, because the web group starts the session.
    Route::middleware('web')->get('/_test/tenant', $report);

    // The API side: the alias on its own, no session.
    Route::middleware('resolve.tenant')->get('/_test/api-tenant', $report);
});

it('resolves the tenant from the panel session', function (): void {
    $acme = Tenant::factory()->create(['subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['subdomain' => 'globex']);
    $user = User::factory()->create();
    TenantUser::factory()->create(['tenant_id' => $acme->id, 'user_id' => $user->id]);
    TenantUser::factory()->create(['tenant_id' => $globex->id, 'user_id' => $user->id]);

    $response = thisTest()->actingAs($user)
        ->withSession(['active_tenant_id' => $globex->id])
        ->getJson('http://app.example.test/_test/tenant');

    $response->assertOk()->assertJson([
        'tenant' => $globex->id,
        'source' => TenantResolutionSource::Session->value,
    ]);
});

it('falls back to the sole membership when the session names no tenant', function (): void {
    $acme = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantUser::factory()->create(['tenant_id' => $acme->id, 'user_id' => $user->id]);

    thisTest()->actingAs($user)
        ->getJson('http://app.example.test/_test/tenant')
        ->assertJson(['tenant' => $acme->id, 'source' => TenantResolutionSource::Session->value]);
});

it('resolves no tenant for a member of several tenants with no active tenant chosen', function (): void {
    $user = User::factory()->create();
    TenantUser::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'user_id' => $user->id]);
    TenantUser::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'user_id' => $user->id]);

    thisTest()->actingAs($user)
        ->getJson('http://app.example.test/_test/tenant')
        ->assertJson(['tenant' => null, 'source' => TenantResolutionSource::None->value]);
});

it('ignores a session tenant the user is no longer a member of', function (): void {
    $acme = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantUser::factory()->create(['tenant_id' => $acme->id, 'user_id' => $user->id]);

    thisTest()->actingAs($user)
        ->withSession(['active_tenant_id' => $other->id])
        ->getJson('http://app.example.test/_test/tenant')
        // Falls through to the sole *actual* membership, never to $other.
        ->assertJson(['tenant' => $acme->id]);
});

it('ignores a pending invitation', function (): void {
    $acme = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantUser::factory()->pendingInvite()->create(['tenant_id' => $acme->id, 'user_id' => $user->id]);

    thisTest()->actingAs($user)
        ->withSession(['active_tenant_id' => $acme->id])
        ->getJson('http://app.example.test/_test/tenant')
        ->assertJson(['tenant' => null]);
});

it('resolves the tenant from its subdomain', function (): void {
    Tenant::factory()->create(['subdomain' => 'globex']);
    $acme = Tenant::factory()->create(['subdomain' => 'acme']);

    thisTest()->getJson('http://acme.app.example.test/_test/tenant')
        ->assertJson(['tenant' => $acme->id, 'source' => TenantResolutionSource::Subdomain->value]);
});

it('resolves a tenant that never customised its subdomain by slug', function (): void {
    $acme = Tenant::factory()->create(['slug' => 'acme-inc', 'subdomain' => null]);

    thisTest()->getJson('http://acme-inc.app.example.test/_test/tenant')
        ->assertJson(['tenant' => $acme->id, 'source' => TenantResolutionSource::Subdomain->value]);
});

it('resolves no tenant for an unknown, reserved, apex, or deeper host', function (string $host): void {
    Tenant::factory()->create(['subdomain' => 'acme']);
    Tenant::factory()->create(['subdomain' => 'admin']);

    thisTest()->getJson('http://'.$host.'/_test/tenant')
        ->assertJson(['tenant' => null, 'source' => TenantResolutionSource::None->value]);
})->with([
    'unknown label' => 'nope.app.example.test',
    'reserved label' => 'admin.app.example.test',
    'the apex itself' => 'app.example.test',
    'nested below a tenant' => 'deep.acme.app.example.test',
    'host outside every configured apex' => 'acme.evil.test',
]);

it('resolves the tenant from an API key', function (): void {
    Tenant::factory()->create(['subdomain' => 'globex']);
    $acme = Tenant::factory()->create(['subdomain' => 'acme']);
    $issued = TenantApiToken::issue($acme, 'ci pipeline');

    thisTest()->getJson('http://app.example.test/_test/api-tenant', [
        'Authorization' => 'Bearer '.$issued->plainText,
    ])->assertJson(['tenant' => $acme->id, 'source' => TenantResolutionSource::ApiToken->value]);

    expect($issued->token->fresh()?->last_used_at)->not->toBeNull();
});

it('resolves the tenant from the API key header', function (): void {
    $acme = Tenant::factory()->create();
    $issued = TenantApiToken::issue($acme, 'zapier');

    thisTest()->getJson('http://app.example.test/_test/api-tenant', [
        'X-Api-Key' => $issued->plainText,
    ])->assertJson(['tenant' => $acme->id, 'source' => TenantResolutionSource::ApiToken->value]);
});

it('resolves no tenant for an unusable API key', function (string $case): void {
    $acme = Tenant::factory()->create();
    Tenant::factory()->create();

    $plainText = match ($case) {
        'unknown' => '01hzzzzzzzzzzzzzzzzzzzzzzz|'.str_repeat('a', 48),
        'garbage' => 'not-a-token',
        'empty secret' => TenantApiToken::issue($acme, 'k')->token->id.'|',
        'revoked' => (function () use ($acme): string {
            $issued = TenantApiToken::issue($acme, 'k');
            $issued->token->revoke();

            return $issued->plainText;
        })(),
        'expired' => TenantApiToken::issue($acme, 'k', now()->subMinute())->plainText,
        'right secret, wrong id' => (function () use ($acme): string {
            $issued = TenantApiToken::issue($acme, 'k');
            [, $secret] = TenantApiToken::splitPlainText($issued->plainText);

            return TenantApiToken::issue($acme, 'other')->token->id.'|'.$secret;
        })(),
        default => throw new InvalidArgumentException("Unhandled dataset case [{$case}]."),
    };

    thisTest()->getJson('http://app.example.test/_test/api-tenant', ['Authorization' => 'Bearer '.$plainText])
        ->assertJson(['tenant' => null, 'source' => TenantResolutionSource::None->value]);
})->with(['unknown', 'garbage', 'empty secret', 'revoked', 'expired', 'right secret, wrong id']);

it('prefers the panel session over the subdomain and the API key', function (): void {
    $session = Tenant::factory()->create(['subdomain' => 'session-tenant']);
    Tenant::factory()->create(['subdomain' => 'host-tenant']);
    $keyOwner = Tenant::factory()->create();
    $user = User::factory()->create();
    TenantUser::factory()->create(['tenant_id' => $session->id, 'user_id' => $user->id]);
    $issued = TenantApiToken::issue($keyOwner, 'ci');

    thisTest()->actingAs($user)
        ->withSession(['active_tenant_id' => $session->id])
        ->getJson('http://host-tenant.app.example.test/_test/tenant', [
            'Authorization' => 'Bearer '.$issued->plainText,
        ])
        ->assertJson(['tenant' => $session->id, 'source' => TenantResolutionSource::Session->value]);
});

it('prefers the subdomain over the API key when nobody is signed in', function (): void {
    $host = Tenant::factory()->create(['subdomain' => 'host-tenant']);
    $keyOwner = Tenant::factory()->create();
    $issued = TenantApiToken::issue($keyOwner, 'ci');

    thisTest()->getJson('http://host-tenant.app.example.test/_test/tenant', [
        'Authorization' => 'Bearer '.$issued->plainText,
    ])->assertJson(['tenant' => $host->id, 'source' => TenantResolutionSource::Subdomain->value]);
});

it('falls back to the API key when the host matches no tenant', function (): void {
    $keyOwner = Tenant::factory()->create();
    $issued = TenantApiToken::issue($keyOwner, 'ci');

    thisTest()->getJson('http://nope.app.example.test/_test/api-tenant', [
        'Authorization' => 'Bearer '.$issued->plainText,
    ])->assertJson(['tenant' => $keyOwner->id, 'source' => TenantResolutionSource::ApiToken->value]);
});

it('defaults the apex to the APP_URL host when none is configured', function (): void {
    config()->set('wa.tenancy.apexes', []);
    config()->set('app.url', 'https://bot.example.test');
    $acme = Tenant::factory()->create(['subdomain' => 'acme']);

    thisTest()->getJson('http://acme.bot.example.test/_test/tenant')
        ->assertJson(['tenant' => $acme->id]);
});

it('clears the context after the response has been sent', function (): void {
    $acme = Tenant::factory()->create(['subdomain' => 'acme']);

    thisTest()->getJson('http://acme.app.example.test/_test/tenant')
        ->assertJson(['tenant' => $acme->id]);

    expect(app(TenantContext::class)->current())->toBeNull();
});
