<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Exceptions\Security\PermissionDeniedException;
use App\Http\Middleware\EnsurePermission;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| RBAC at the boundary: `tenant.permission:{key}` (Req 32.1 / NFR3)
|--------------------------------------------------------------------------
| `RbacService` answers the question; this middleware is what makes a route *ask*
| it. A guard that every screen has to remember to call is a guard some screen will
| not call, so the check is declared on the route and these tests drive it over real
| HTTP — the same way task 2.2's plan gate is tested.
*/

beforeEach(function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);
});

/**
 * A signed-in user whose sole membership resolves the tenant on every request.
 *
 * @return array{0: User, 1: Tenant}
 */
function permissionPanelUser(TenantRole $role): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return [$user, $tenant];
}

function permissionRoute(string $declaration, string $uri = '/_test/guarded'): void
{
    Route::middleware(['resolve.tenant', 'tenant.permission:'.$declaration])
        ->get($uri, fn () => response()->json(['ok' => true]));
}

it('serves a route to a role that holds the permission', function (): void {
    permissionRoute('campaigns.manage');
    [$user] = permissionPanelUser(TenantRole::Operator);

    thisTest()->actingAs($user)
        ->getJson('http://app.example.test/_test/guarded')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('refuses a role that does not hold the permission with a 403 envelope', function (): void {
    permissionRoute('tenant.billing.manage');
    [$user] = permissionPanelUser(TenantRole::Operator);

    thisTest()->actingAs($user)
        ->getJson('http://app.example.test/_test/guarded')
        ->assertForbidden()
        ->assertExactJson([
            'message' => PermissionDeniedException::PUBLIC_MESSAGE,
            'error' => PermissionDeniedException::ERROR_CODE,
            // The permission key is public: the caller just exercised the route, and
            // without it a panel cannot say which power is missing.
            'permission' => 'tenant.billing.manage',
        ]);
});

it('gives the same refusal to a stranger as to an under-privileged member', function (): void {
    permissionRoute('tenant.billing.manage');
    [$member] = permissionPanelUser(TenantRole::Operator);

    // A user with a membership somewhere else entirely: their request resolves that
    // tenant, and they hold nothing in it either way.
    [$stranger] = permissionPanelUser(TenantRole::Viewer);

    $memberResponse = thisTest()->actingAs($member)->getJson('http://app.example.test/_test/guarded');
    $strangerResponse = thisTest()->actingAs($stranger)->getJson('http://app.example.test/_test/guarded');

    // Indistinguishable from outside, or the 403 becomes a membership oracle.
    expect($memberResponse->getStatusCode())->toBe(403)
        ->and($strangerResponse->getStatusCode())->toBe(403)
        ->and($strangerResponse->getContent())->toBe($memberResponse->getContent());
});

it('refuses an unauthenticated request even when a tenant resolves from the host', function (): void {
    permissionRoute('messages.read');
    Tenant::factory()->create(['subdomain' => 'acme']);

    // The subdomain identifies a tenant; it does not identify a *caller*. Nothing to
    // check a role against means deny.
    thisTest()->getJson('http://acme.app.example.test/_test/guarded')->assertForbidden();
});

it('refuses when no tenant is bound at all', function (): void {
    permissionRoute('messages.read');
    $user = User::factory()->create();

    // Signed in, but a member of nothing: no tenant resolves, so there is no membership
    // to read and the answer is no rather than "which tenant did you mean?".
    thisTest()->actingAs($user)->getJson('http://app.example.test/_test/guarded')->assertForbidden();
});

it('requires every permission when a route names several', function (): void {
    permissionRoute('contacts.manage,messages.send', '/_test/both');
    [$operator] = permissionPanelUser(TenantRole::Operator);
    [$agent] = permissionPanelUser(TenantRole::Agent);

    thisTest()->actingAs($operator)->getJson('http://app.example.test/_test/both')->assertOk();

    // An agent may send but may not manage contacts: all-of, never any-of.
    thisTest()->actingAs($agent)->getJson('http://app.example.test/_test/both')->assertForbidden();
});

it('holds for the panel surface too, through the exception\'s own status', function (): void {
    permissionRoute('tenant.billing.manage');
    [$user, $tenant] = permissionPanelUser(TenantRole::Viewer);

    $response = thisTest()->actingAs($user)->get('http://app.example.test/_test/guarded');

    // An HTML request gets the framework's 403 page rather than the JSON envelope — the
    // exception carries its own status, so no renderer has to agree about it.
    $response->assertForbidden();

    // Whatever the page renders (it includes the internal message when APP_DEBUG is on),
    // it never carries a tenant id: the SecurityException family fingerprints them.
    expect((string) $response->getContent())->not->toContain($tenant->id);
});

/*
|--------------------------------------------------------------------------
| Misconfiguration fails loudly, and identically on every request
|--------------------------------------------------------------------------
*/

it('raises on an unknown permission key instead of denying', function (): void {
    $middleware = app(EnsurePermission::class);
    $next = fn (Request $request): Response => new Response('ok');

    // Denying would lock a working screen for every tenant and look exactly like a
    // permissions bug; a 500 on the first request is loud.
    expect(fn (): Response => $middleware->handle(Request::create('/'), $next, 'campaigns.manages'))
        ->toThrow(InvalidArgumentException::class, 'Unknown tenant permission')
        // ...and it raises with no tenant bound too, so a typo cannot hide behind a
        // request that would have been refused anyway.
        ->and(fn (): Response => $middleware->handle(Request::create('/'), $next))
        ->toThrow(InvalidArgumentException::class, 'at least one permission key');
});

it('validates the whole list before consulting anything', function (): void {
    $middleware = app(EnsurePermission::class);
    $next = fn (Request $request): Response => new Response('ok');

    // The second key is the typo. If validation were lazy the first key would deny
    // first, and the typo would only surface for a caller who *did* hold the first.
    expect(fn (): Response => $middleware->handle(Request::create('/'), $next, 'messages.read', 'nope'))
        ->toThrow(InvalidArgumentException::class, 'Unknown tenant permission');
});
