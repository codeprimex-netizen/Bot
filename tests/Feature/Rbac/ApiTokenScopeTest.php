<?php

declare(strict_types=1);

use App\Enums\TenantPermission;
use App\Enums\TenantResolutionSource;
use App\Enums\TenantRole;
use App\Exceptions\Security\PermissionDeniedException;
use App\Http\Middleware\EnsurePermission;
use App\Models\Tenant;
use App\Models\TenantApiToken;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Rbac\RbacService;
use App\Services\Tenancy\ApiTokenIdentity;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantTokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Public API: a key does only what it was issued for (Req 32.1 / NFR3)
|--------------------------------------------------------------------------
| Before task 4.6 an API key was pure identity: it named a tenant, and that was the
| whole of its authority — every key could do everything the tenant could. These
| tests pin the second half. The important cases are the ones where nothing was
| granted, because "no scopes" is the state every key issued before this task is in
| and the state a leaked-but-narrow key relies on.
*/

beforeEach(function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);
});

function scopedRoute(string $permission, string $uri = '/_test/api'): void
{
    Route::middleware(['resolve.tenant', 'tenant.permission:'.$permission])
        ->get($uri, fn () => response()->json(['ok' => true]));
}

/**
 * @param  list<TenantPermission>  $scopes
 * @return array{0: Tenant, 1: array<string, string>}
 */
function scopedKey(array $scopes): array
{
    $tenant = Tenant::factory()->create();
    $issued = TenantApiToken::issue($tenant, 'ci pipeline', null, $scopes);

    return [$tenant, ['Authorization' => 'Bearer '.$issued->plainText]];
}

it('serves a request whose key carries the scope', function (): void {
    scopedRoute('messages.send');
    [, $headers] = scopedKey([TenantPermission::MessagesSend]);

    thisTest()->getJson('http://app.example.test/_test/api', $headers)
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('refuses a key that carries a different scope', function (): void {
    scopedRoute('messages.send');
    [, $headers] = scopedKey([TenantPermission::MessagesRead]);

    thisTest()->getJson('http://app.example.test/_test/api', $headers)
        ->assertForbidden()
        ->assertJson([
            'error' => PermissionDeniedException::ERROR_CODE,
            'permission' => 'messages.send',
        ]);
});

it('refuses a key with no scopes at all — the state every pre-4.6 key is in', function (): void {
    scopedRoute('messages.read');
    [$tenant, $headers] = scopedKey([]);

    // The key still *identifies* its tenant: resolution is identification, not
    // authorization. It just may not do anything.
    thisTest()->getJson('http://app.example.test/_test/api', $headers)->assertForbidden();

    expect(TenantApiToken::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail()->grantedScopes())
        ->toBe([]);
});

it('treats a key whose scope column is NULL as carrying nothing', function (): void {
    scopedRoute('messages.read');
    [$tenant, $headers] = scopedKey([TenantPermission::MessagesRead]);

    // Exactly what the migration leaves behind for keys issued before scopes existed.
    TenantApiToken::withoutTenantScope()->where('tenant_id', $tenant->id)->update(['scopes' => null]);

    thisTest()->getJson('http://app.example.test/_test/api', $headers)->assertForbidden();
});

it('drops a stored scope it does not recognise instead of refusing to read the key', function (): void {
    scopedRoute('messages.read');
    [$tenant, $headers] = scopedKey([TenantPermission::MessagesRead]);

    TenantApiToken::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->update(['scopes' => json_encode(['messages.read', 'messages.teleport', '*'])]);

    // A scope written by a newer release must not make the credential unusable when read
    // by an older one — and the wildcard is not a wildcard.
    thisTest()->getJson('http://app.example.test/_test/api', $headers)->assertOk();

    $token = TenantApiToken::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($token->grantedScopes())->toBe([TenantPermission::MessagesRead])
        ->and($token->allows(TenantPermission::MessagesSend))->toBeFalse();
});

it('refuses an api-token request whose credential is missing, rather than using the session user', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    TenantUser::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'role' => TenantRole::Owner,
    ]);

    app(TenantContext::class)->set($tenant, TenantResolutionSource::ApiToken);
    app()->forgetInstance(RbacService::class);

    $request = Request::create('/');
    $request->setUserResolver(fn (): User => $owner);

    // The context says the caller is a key, and no verified key is on the request. The
    // owner sitting in the session is *not* the caller: authorizing them would let a
    // request that presented a credential borrow whoever happens to be logged in.
    expect(fn (): Response => app(EnsurePermission::class)
        ->handle($request, fn (): Response => new Response('ok'), 'tenant.billing.manage'))
        ->toThrow(PermissionDeniedException::class, 'no verified API key');
});

it('refuses a verified key whose tenant is not the bound one', function (): void {
    $keyOwner = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $issued = TenantApiToken::issue($keyOwner, 'ci', null, [TenantPermission::MessagesRead]);

    $identity = app(TenantTokenRepository::class)->resolve($issued->plainText);
    expect($identity)->toBeInstanceOf(ApiTokenIdentity::class);

    app(TenantContext::class)->set($other);
    app()->forgetInstance(RbacService::class);

    // A key is bound to exactly one tenant, so this state means something upstream
    // rebound the context: refuse rather than reconcile.
    expect(app(RbacService::class)->tokenAllows($identity, TenantPermission::MessagesRead))->toBeFalse()
        ->and(fn () => app(RbacService::class)->authorizeToken($identity, TenantPermission::MessagesRead))
        ->toThrow(PermissionDeniedException::class, 'does not act as tenant');
});

it('treats a missing credential as no authority rather than unrestricted', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);
    app()->forgetInstance(RbacService::class);

    expect(app(RbacService::class)->tokenAllows(null, TenantPermission::MessagesRead))->toBeFalse()
        ->and(fn () => app(RbacService::class)->authorizeToken(null, TenantPermission::MessagesRead))
        ->toThrow(PermissionDeniedException::class, 'no verified API key');
});

/*
|--------------------------------------------------------------------------
| The credential itself
|--------------------------------------------------------------------------
*/

it('verifies the key once and carries its scopes on the identity', function (): void {
    $tenant = Tenant::factory()->create();
    $issued = TenantApiToken::issue($tenant, 'zapier', null, [
        TenantPermission::MessagesSend,
        TenantPermission::ContactsRead,
    ]);

    $identity = app(TenantTokenRepository::class)->resolve($issued->plainText);

    expect($identity?->tenant->id)->toBe($tenant->id)
        ->and($identity?->tokenId)->toBe($issued->token->id)
        ->and($identity?->name)->toBe('zapier')
        ->and($identity?->scopeKeys())->toBe(['messages.send', 'contacts.read'])
        ->and($identity?->allows(TenantPermission::MessagesSend))->toBeTrue()
        ->and($identity?->allows(TenantPermission::CampaignsManage))->toBeFalse()
        ->and($identity?->actsAs($tenant->id))->toBeTrue()
        ->and($identity?->actsAs('01hzzzzzzzzzzzzzzzzzzzzzzz'))->toBeFalse();
});

it('carries no credential material on the identity it publishes on the request', function (): void {
    $tenant = Tenant::factory()->create();
    $issued = TenantApiToken::issue($tenant, 'ci', null, [TenantPermission::MessagesRead]);
    [, $secret] = TenantApiToken::splitPlainText($issued->plainText);

    $identity = app(TenantTokenRepository::class)->resolve($issued->plainText);
    $serialized = (string) json_encode($identity);

    // The identity is put on the request where a logger or an exception renderer can
    // reach it, so it must carry nothing that would be a credential if it leaked.
    expect($serialized)->not->toContain($secret)
        ->and($serialized)->not->toContain(TenantApiToken::hashSecret($secret));
});

it('refuses to issue a key with a scope key that does not exist', function (): void {
    $tenant = Tenant::factory()->create();

    // Silently dropping it here would issue a key that cannot do the thing it was asked
    // for — a support ticket, not a security event, but the same class of silence.
    expect(fn (): mixed => TenantApiToken::issue($tenant, 'typo', null, ['messages.snd']))
        ->toThrow(InvalidArgumentException::class, 'Unknown tenant permission');
});

it('stores a distinct, ordered scope list', function (): void {
    $tenant = Tenant::factory()->create();
    $issued = TenantApiToken::issue($tenant, 'dupes', null, [
        TenantPermission::MessagesRead,
        'messages.read',
        TenantPermission::ContactsRead,
    ]);

    expect($issued->token->scopes)->toBe(['messages.read', 'contacts.read']);
});
