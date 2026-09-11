<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Models\ChannelCredential;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| channel_credentials — per tenant, per mode, per provider (Req 8.5, 8.6, 8.13 / A8)
|--------------------------------------------------------------------------
| The table task 6.4's `ChannelCredentialStore` reads and writes. What is pinned here is the
| shape design.md specifies — the config/secret split, the uniqueness, the tenancy — and the
| two behaviours a later task would otherwise have to re-derive: which row a send picks, and
| when a mode counts as unconfigured. Secrecy has its own file.
*/

it('scopes credentials to the acting tenant and stamps the owner on create', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $context->runFor($globex, fn (): ChannelCredential => ChannelCredential::factory()
        ->create(['tenant_id' => $globex->id]));

    // Created without naming a tenant: `BelongsToTenant` stamps the acting one.
    $own = $context->runFor($acme, fn (): ChannelCredential => ChannelCredential::create([
        'mode' => ChannelMode::CloudApi,
        'config' => ['waba_id' => '1234'],
        'secret_config' => ['access_token' => 'EAAG-token'],
    ]));

    expect($own->tenant_id)->toBe($acme->id)
        // Nothing has validated these credentials with a driver yet (task 7.6 does).
        ->and($own->isVerified())->toBeFalse();

    $context->runFor($acme, function (): void {
        // Property 23: credential resolution is disjoint by tenant.
        expect(ChannelCredential::query()->count())->toBe(1);
    });
});

it('keeps one credential set per tenant, mode, provider and label — including when there is no provider', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        ChannelCredential::factory()->forMode(ChannelMode::CloudApi)->create(['tenant_id' => $tenant->id]);

        // NULLs are distinct in a unique index, which is exactly why the constraint is
        // declared over the derived `provider_slot`: without it, the three modes that have
        // no provider — i.e. the ones most tenants use — would not be constrained at all.
        expect(fn () => ChannelCredential::factory()
            ->forMode(ChannelMode::CloudApi)
            ->create(['tenant_id' => $tenant->id]))
            ->toThrow(QueryException::class);

        // A different label is a different credential set, on purpose: "live" and "sandbox".
        $sandbox = ChannelCredential::factory()
            ->forMode(ChannelMode::CloudApi)
            ->labelled('sandbox')
            ->create(['tenant_id' => $tenant->id]);

        expect($sandbox->label)->toBe('sandbox')
            ->and(ChannelCredential::query()->count())->toBe(2);
    });
});

it('separates two BSP partners of the same tenant', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $twilio = ChannelCredential::factory()
            ->forMode(ChannelMode::BspGateway, BspProvider::Twilio)
            ->create(['tenant_id' => $tenant->id]);

        $gupshup = ChannelCredential::factory()
            ->forMode(ChannelMode::BspGateway, BspProvider::Gupshup)
            ->create(['tenant_id' => $tenant->id]);

        expect($twilio->provider)->toBe(BspProvider::Twilio)
            ->and($gupshup->provider)->toBe(BspProvider::Gupshup)
            ->and(ChannelCredential::query()->forMode(ChannelMode::BspGateway, BspProvider::Twilio)->count())->toBe(1)
            // The derived slot is what lets the unique index tell the two apart.
            ->and($twilio->getAttribute('provider_slot'))->toBe(BspProvider::Twilio->value)
            ->and($gupshup->getAttribute('provider_slot'))->toBe(BspProvider::Gupshup->value);
    });
});

it('derives the provider slot from the provider, and back to the no-provider marker', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $credential = ChannelCredential::factory()
            ->forMode(ChannelMode::BspGateway, BspProvider::Vonage)
            ->create(['tenant_id' => $tenant->id]);

        // Never written by hand: set `provider`, the slot follows — the same device
        // `signing_secrets.active_flag` uses so the database can carry the constraint.
        $credential->provider = null;
        $credential->save();

        expect($credential->fresh()?->getAttribute('provider_slot'))->toBe(ChannelCredential::NO_PROVIDER);
    });
});

it('picks the newest verified row for a mode, and never an unusable one', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        ChannelCredential::factory()->forMode(ChannelMode::CloudApi)->labelled('old')->create([
            'tenant_id' => $tenant->id,
            'verified_at' => now()->subDays(3),
        ]);
        ChannelCredential::factory()->forMode(ChannelMode::CloudApi)->labelled('revoked')->invalid()->create([
            'tenant_id' => $tenant->id,
        ]);
        $rotated = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)->labelled('rotated')->create([
            'tenant_id' => $tenant->id,
            'verified_at' => now(),
        ]);

        // A rotation writes a fresh row and the send picks it up with nothing having to
        // update a pointer; the row the driver rejected is never chosen.
        expect(ChannelCredential::activeFor(ChannelMode::CloudApi)?->id)->toBe($rotated->id)
            ->and($rotated->isVerified())->toBeTrue()
            ->and(ChannelCredential::query()->invalid()->count())->toBe(1);
    });
});

it('treats a mode with no secrets as unconfigured, except BAILEYS which needs none', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $cloud = ChannelCredential::factory()->forMode(ChannelMode::CloudApi)->withoutSecrets()->create([
            'tenant_id' => $tenant->id,
        ]);
        $baileys = ChannelCredential::factory()->forMode(ChannelMode::Baileys)->withoutSecrets()->create([
            'tenant_id' => $tenant->id,
        ]);
        $disabled = ChannelCredential::factory()->forMode(ChannelMode::OnPremise)->disabled()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Req 8.13: a mode whose credentials are missing must be unselectable, leaving the
        // platform on the BAILEYS default — which itself needs no tenant secrets, because
        // the bridge's token is platform config.
        expect($cloud->hasSecrets())->toBeFalse()
            ->and($cloud->isUsable())->toBeFalse()
            ->and($baileys->isUsable())->toBeTrue()
            ->and($disabled->hasSecrets())->toBeTrue()
            ->and($disabled->isUsable())->toBeFalse()
            ->and($disabled->status)->toBe(ChannelCredentialStatus::Disabled);
    });
});

it('reads non-secret provider identifiers in the clear', function (): void {
    $tenant = Tenant::factory()->create();

    $credential = app(TenantContext::class)->runFor($tenant, fn (): ChannelCredential => ChannelCredential::factory()
        ->create(['tenant_id' => $tenant->id, 'config' => ['waba_id' => '778899', 'api_version' => 'v20.0']]));

    // The asymmetry is the point: `config` holds identifiers the provider itself puts in
    // URLs, so a panel can show which account a session talks to.
    expect($credential->setting('waba_id'))->toBe('778899')
        ->and($credential->setting('missing', 'fallback'))->toBe('fallback')
        ->and($credential->toArray())->toHaveKey('config');
});

it('indexes the table the way design.md specifies', function (): void {
    $indexes = collect(Schema::getIndexes('channel_credentials'));

    expect($indexes->contains(fn (array $index): bool => array_slice($index['columns'], 0, 2) === ['tenant_id', 'mode']))
        ->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'mode', 'provider_slot', 'label']
            && $index['unique']))
        ->toBeTrue();
});
