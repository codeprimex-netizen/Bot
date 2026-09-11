<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;

/*
|--------------------------------------------------------------------------
| ChannelMode — one backend per session, BAILEYS by default (Req 8.1 / A8; Req 2.6 / A2)
|--------------------------------------------------------------------------
| The enum states which rules apply to a mode; every gate in the phase reads it. What is
| pinned here is the set itself, the default, and the two axes the send gate branches on —
| because "anti-ban applies iff web-protocol" is Correctness Property 24 and a wrong answer
| here would satisfy the property test while breaking the platform's safety promise.
*/

it('is exactly the four modes design.md names', function (): void {
    expect(ChannelMode::keys())->toBe(['BAILEYS', 'CLOUD_API', 'ON_PREMISE', 'BSP_GATEWAY']);
});

it('defaults to BAILEYS, the mode that needs no official onboarding', function (): void {
    expect(ChannelMode::default())->toBe(ChannelMode::Baileys)
        ->and(ChannelMode::Baileys->isDefault())->toBeTrue()
        ->and(ChannelMode::Baileys->requiresTenantCredentials())->toBeFalse();

    // Every other mode is opt-in config, and cannot be selected without credentials.
    foreach ([ChannelMode::CloudApi, ChannelMode::OnPremise, ChannelMode::BspGateway] as $mode) {
        expect($mode->isDefault())->toBeFalse()
            ->and($mode->requiresTenantCredentials())->toBeTrue();
    }
});

it('labels every mode for the panel, naming the deprecated one as such', function (): void {
    expect(ChannelMode::Baileys->label())->toContain('Baileys')
        ->and(ChannelMode::CloudApi->label())->toContain('Cloud API')
        // The panel must steer tenants off it (Req 8.9), so the label says so too.
        ->and(ChannelMode::OnPremise->label())->toContain('deprecated')
        ->and(ChannelMode::BspGateway->label())->toContain('BSP');
});

it('applies the anti-ban gate to exactly the web-protocol modes', function (): void {
    // Property 24's whole claim, as a statement about the vocabulary.
    expect(ChannelMode::Baileys->isWebProtocol())->toBeTrue()
        ->and(ChannelMode::OnPremise->isWebProtocol())->toBeTrue()
        ->and(ChannelMode::CloudApi->isWebProtocol())->toBeFalse()
        ->and(ChannelMode::BspGateway->isWebProtocol())->toBeFalse()
        ->and(ChannelMode::webProtocol())->toBe([ChannelMode::Baileys, ChannelMode::OnPremise]);
});

it('keeps "official" independent of "web protocol", because ON_PREMISE is both', function (): void {
    // design § Channel Mode 2.2 mode 3: an official route, and one the anti-ban gate
    // applies to. A caller reading `isOfficial()` must not conclude "no anti-ban".
    expect(ChannelMode::OnPremise->isOfficial())->toBeTrue()
        ->and(ChannelMode::OnPremise->isWebProtocol())->toBeTrue()
        ->and(ChannelMode::Baileys->isOfficial())->toBeFalse()
        ->and(ChannelMode::CloudApi->isOfficial())->toBeTrue()
        ->and(ChannelMode::BspGateway->isOfficial())->toBeTrue();
});

it('confines free-form content to the 24-hour window on every mode but BAILEYS', function (): void {
    // Derived from the capability matrix, so it cannot drift from the row it comes from —
    // including ON_PREMISE, whose matrix row says `⚠️ 24h window` even though Algorithm 9
    // would send it down the anti-ban branch. Task 8.1 applies both.
    expect(ChannelMode::Baileys->enforcesSessionWindow())->toBeFalse()
        ->and(ChannelMode::CloudApi->enforcesSessionWindow())->toBeTrue()
        ->and(ChannelMode::OnPremise->enforcesSessionWindow())->toBeTrue()
        ->and(ChannelMode::BspGateway->enforcesSessionWindow())->toBeTrue();
});

it('names ON_PREMISE deprecated and points it at CLOUD_API', function (): void {
    expect(ChannelMode::OnPremise->isDeprecated())->toBeTrue()
        ->and(ChannelMode::OnPremise->migrationTarget())->toBe(ChannelMode::CloudApi);

    foreach ([ChannelMode::Baileys, ChannelMode::CloudApi, ChannelMode::BspGateway] as $mode) {
        expect($mode->isDeprecated())->toBeFalse()
            ->and($mode->migrationTarget())->toBeNull();
    }
});

it('requires a BSP provider on exactly the gateway mode', function (): void {
    expect(ChannelMode::BspGateway->usesProvider())->toBeTrue()
        ->and(ChannelMode::Baileys->usesProvider())->toBeFalse()
        ->and(ChannelMode::CloudApi->usesProvider())->toBeFalse()
        ->and(ChannelMode::OnPremise->usesProvider())->toBeFalse();
});

it('gives BAILEYS the full capability set and takes five away from every official mode', function (): void {
    expect(ChannelMode::Baileys->capabilities())->toBe(ChannelCapability::cases());

    $baileysOnly = [
        ChannelCapability::Groups,
        ChannelCapability::Welcome,
        ChannelCapability::Extraction,
        ChannelCapability::Tagging,
        ChannelCapability::Channels,
    ];

    foreach ([ChannelMode::CloudApi, ChannelMode::OnPremise, ChannelMode::BspGateway] as $mode) {
        expect(ChannelCapability::unsupportedBy($mode))->toBe($baileysOnly);

        foreach ($baileysOnly as $capability) {
            expect($mode->supports($capability))->toBeFalse();
        }
    }
});

it('rejects an unknown mode by naming the value and the legal set', function (): void {
    expect(ChannelMode::tryFromKey(' CLOUD_API '))->toBe(ChannelMode::CloudApi)
        ->and(ChannelMode::tryFromKey('TELEGRAM'))->toBeNull()
        // Req 8.1: a create/update outside the set is rejected "with a validation error
        // identifying the invalid value".
        ->and(fn () => ChannelMode::coerce('TELEGRAM'))
        ->toThrow(InvalidArgumentException::class, 'Unknown channel mode [TELEGRAM]');
});

it('emits a webhook slug the URL builder can put in a callback path', function (): void {
    $slugs = array_map(static fn (ChannelMode $mode): string => $mode->webhookSlug(), ChannelMode::cases());

    expect($slugs)->toBe(['bridge', 'cloud-api', 'on-premise', 'bsp'])
        ->and(array_unique($slugs))->toHaveCount(4);

    foreach ($slugs as $slug) {
        // The provider-segment shape `CanonicalUrlBuilder` validates against; the
        // round-trip through the builder itself is asserted in ChannelWebhookRouteTest.
        expect((bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $slug))->toBeTrue();
    }
});
