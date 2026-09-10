<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Models\ChannelWebhookRoute;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Url\UrlBuilder;
use Database\Factories\ChannelWebhookRouteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| channel_webhook_routes — the key that resolves the tenant (Req 8.4 / A8; Req 9.2 / A9)
|--------------------------------------------------------------------------
| An inbound provider callback carries no session, no subdomain and no API key: the `route_key`
| in its URL is the only tenant-bearing thing about it. So this table is read *before* a tenant
| is bound, its uniqueness is global, and the model is deliberately not `BelongsToTenant` — the
| reviewed exemption `TenantOwnedModelsGuardTest` records. What is pinned here is that the
| lookup works with nothing bound, that the key's shape agrees with the URL builder that has to
| put it in a callback URL, and that a retired route stops resolving.
*/

it('resolves a route with no tenant bound at all', function (): void {
    $context = app(TenantContext::class);
    $tenant = Tenant::factory()->create();

    $route = $context->runFor($tenant, function () use ($tenant): ChannelWebhookRoute {
        $session = Session::factory()->create(['tenant_id' => $tenant->id]);

        return ChannelWebhookRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'session_id' => $session->id,
        ]);
    });

    $context->forget();

    // The read that establishes the context, performed the way the webhook controller
    // (task 8.3) will: by key, with nothing bound.
    $resolved = ChannelWebhookRoute::resolve($route->route_key);

    expect($resolved?->id)->toBe($route->id)
        ->and($resolved?->tenant_id)->toBe($tenant->id)
        ->and($resolved?->session_id)->toBe($route->session_id)
        ->and($resolved?->mode)->toBe(ChannelMode::CloudApi);
});

it('refuses two tenants the same route key, because the key is what names the tenant', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $key = ChannelWebhookRouteFactory::routeKey();

    $context->runFor($acme, function () use ($acme, $key): void {
        ChannelWebhookRoute::factory()->create([
            'tenant_id' => $acme->id,
            'session_id' => Session::factory()->create(['tenant_id' => $acme->id])->id,
            'route_key' => $key,
        ]);
    });

    // Global uniqueness, exactly like `tenant_domains.host`: two claims on one key would be
    // an ambiguity resolvable only by guessing which tenant a callback meant.
    $context->runFor($globex, function () use ($globex, $key): void {
        expect(fn () => ChannelWebhookRoute::factory()->create([
            'tenant_id' => $globex->id,
            'session_id' => Session::factory()->create(['tenant_id' => $globex->id])->id,
            'route_key' => $key,
        ]))->toThrow(QueryException::class);
    });
});

it('stores only keys the URL builder can put in a callback URL', function (): void {
    $tenant = Tenant::factory()->create();
    $urls = app(UrlBuilder::class);

    $route = app(TenantContext::class)->runFor($tenant, fn (): ChannelWebhookRoute => ChannelWebhookRoute::factory()
        ->create([
            'tenant_id' => $tenant->id,
            'session_id' => Session::factory()->create(['tenant_id' => $tenant->id])->id,
        ]));

    // The agreement that matters: a key this model accepted is a key `UrlBuilder::webhook()`
    // will build a URL from — asserted by round-tripping it rather than by trusting two
    // patterns to stay equal by eye.
    $url = $urls->webhook($route->mode->webhookSlug(), $route->route_key, $tenant);

    expect($url)->toContain('/webhooks/cloud-api/'.$route->route_key);

    // ...and a key it would reject never reaches the column, so no session can end up with a
    // callback URL no provider can call.
    foreach (['has spaces', 'slash/es', 'question?mark', '', str_repeat('k', 129)] as $bad) {
        expect(fn () => ChannelWebhookRoute::factory()->make(['route_key' => $bad]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('emits a distinct callback URL per BSP partner', function (): void {
    $tenant = Tenant::factory()->create();
    $urls = app(UrlBuilder::class);

    // Every mode slug and every provider slug must survive the builder's provider-segment
    // pattern, or a session on that backend could never be registered (Req 9.2).
    foreach (ChannelMode::cases() as $mode) {
        $url = $urls->webhook($mode->webhookSlug(), ChannelWebhookRouteFactory::routeKey(), $tenant);

        expect($url)->toContain('/webhooks/'.$mode->webhookSlug().'/');
    }

    foreach (BspProvider::cases() as $provider) {
        $url = $urls->webhook($provider->webhookSlug(), ChannelWebhookRouteFactory::routeKey(), $tenant);

        expect($url)->toContain('/webhooks/'.$provider->webhookSlug().'/');
    }
});

it('stops resolving a retired route, so rotating a key is a revocation', function (): void {
    $tenant = Tenant::factory()->create();

    [$old, $new] = app(TenantContext::class)->runFor($tenant, function () use ($tenant): array {
        $session = Session::factory()->create(['tenant_id' => $tenant->id]);

        return [
            ChannelWebhookRoute::factory()->retired()->create([
                'tenant_id' => $tenant->id,
                'session_id' => $session->id,
            ]),
            ChannelWebhookRoute::factory()->create([
                'tenant_id' => $tenant->id,
                'session_id' => $session->id,
            ]),
        ];
    });

    // Both rows coexist for one session and one mode on purpose: a rotation issues the new
    // route before retiring the old one, so there is no window in which the provider's
    // registered URL resolves to nothing.
    expect(ChannelWebhookRoute::resolve($old->route_key))->toBeNull()
        ->and(ChannelWebhookRoute::resolve($new->route_key)?->id)->toBe($new->id)
        ->and(ChannelWebhookRoute::query()->forSession($new->session_id)->count())->toBe(2)
        ->and(ChannelWebhookRoute::query()->forSession($new->session_id)->active()->count())->toBe(1);
});

it('rejects a malformed key without querying, and never resolves an unknown one', function (): void {
    expect(ChannelWebhookRoute::resolve('not a key'))->toBeNull()
        ->and(ChannelWebhookRoute::resolve(''))->toBeNull()
        ->and(ChannelWebhookRoute::resolve(ChannelWebhookRouteFactory::routeKey()))->toBeNull();
});

it('compares the verify token as a digest, and never stores or serialises the token', function (): void {
    $tenant = Tenant::factory()->create();
    $token = Str::random(32);

    $route = app(TenantContext::class)->runFor($tenant, fn (): ChannelWebhookRoute => ChannelWebhookRoute::factory()
        ->withVerifyToken($token)
        ->create([
            'tenant_id' => $tenant->id,
            'session_id' => Session::factory()->create(['tenant_id' => $tenant->id])->id,
        ]));

    expect($route->matchesVerifyToken($token))->toBeTrue()
        ->and($route->matchesVerifyToken('wrong'))->toBeFalse()
        ->and($route->matchesVerifyToken(''))->toBeFalse()
        ->and($route->verify_token_hash)->not->toBe($token)
        // A digest in an API response is a digest an attacker can grind offline, and nothing
        // outside the model needs it.
        ->and($route->toArray())->not->toHaveKey('verify_token_hash')
        ->and((string) json_encode($route))->not->toContain($token);

    // A route with no handshake cannot complete one.
    $route->verify_token_hash = null;

    expect($route->matchesVerifyToken($token))->toBeFalse();
});

it('indexes the table the way design.md specifies', function (): void {
    $indexes = collect(Schema::getIndexes('channel_webhook_routes'));

    expect($indexes->contains(fn (array $i): bool => array_slice($i['columns'], 0, 2) === ['tenant_id', 'mode']))
        ->toBeTrue()
        ->and($indexes->contains(fn (array $i): bool => $i['columns'] === ['route_key'] && $i['unique']))
        ->toBeTrue()
        ->and($indexes->contains(fn (array $i): bool => $i['columns'] === ['session_id', 'active']))
        ->toBeTrue();
});
