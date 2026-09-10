<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;

/*
|--------------------------------------------------------------------------
| BspProvider — one driver, eight partners, not one profile (Req 8.1, 8.2 / A8)
|--------------------------------------------------------------------------
| design § Channel Mode 2.3 is explicit that the BSP_GATEWAY row is a *ceiling*: "the driver
| reports each provider's real supports() at runtime rather than assuming one profile". What
| is pinned here is the set of partners, the two per-provider facts design.md actually states,
| and the direction of refinement — a provider may narrow the ceiling and can never widen it,
| which is what keeps Property 21 true no matter what task 7.4 reads from a credential row.
*/

it('is exactly the eight partners design.md names', function (): void {
    expect(BspProvider::keys())->toBe([
        'TWILIO', '360DIALOG', 'GUPSHUP', 'VONAGE', 'MESSAGEBIRD', 'INFOBIP', 'WATI', 'KALEYRA',
    ]);
});

it('keeps each partner spelled as it spells itself', function (): void {
    expect(BspProvider::ThreeSixtyDialog->label())->toBe('360dialog')
        ->and(BspProvider::Wati->label())->toBe('WATI')
        ->and(BspProvider::MessageBird->label())->toBe('MessageBird');
});

it('gives each partner its own callback path', function (): void {
    $slugs = array_map(static fn (BspProvider $provider): string => $provider->webhookSlug(), BspProvider::cases());

    // Distinct, so a misrouted delivery is a 404 rather than a payload the wrong adapter
    // tries to parse — and shaped so `UrlBuilder::webhook()` accepts them as its provider
    // segment (`360dialog` starts with a digit, which that pattern allows).
    expect(array_unique($slugs))->toHaveCount(count(BspProvider::cases()));

    foreach ($slugs as $slug) {
        expect((bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $slug))->toBeTrue();
    }
});

it('marks the Cloud-API-equivalent partners, and only those', function (): void {
    // design § Channel Mode 2.3: "360dialog/Gupshup/WATI expose Cloud-API-equivalent
    // interactive + template-sync APIs".
    $equivalent = array_values(array_filter(
        BspProvider::cases(),
        static fn (BspProvider $provider): bool => $provider->isCloudApiEquivalent(),
    ));

    expect($equivalent)->toBe([BspProvider::ThreeSixtyDialog, BspProvider::Gupshup, BspProvider::Wati]);
});

it('marks the partners whose interactive messages go through content templates', function (): void {
    // design § Channel Mode 2.3: "Twilio & Vonage do buttons/lists via content templates".
    $viaTemplates = array_values(array_filter(
        BspProvider::cases(),
        static fn (BspProvider $provider): bool => $provider->interactiveViaContentTemplates(),
    ));

    expect($viaTemplates)->toBe([BspProvider::Twilio, BspProvider::Vonage]);
});

it('resolves interactive support per partner rather than assuming one profile', function (): void {
    expect(BspProvider::ThreeSixtyDialog->declaredSupport(ChannelCapability::Interactive))
        ->toBe(ChannelCapabilitySupport::Native)
        ->and(BspProvider::Twilio->declaredSupport(ChannelCapability::Interactive))
        ->toBe(ChannelCapabilitySupport::Conditional)
        // A partner design.md does not characterise keeps the ceiling — "attempt it subject
        // to what the provider turns out to allow", which is task 7.4's question.
        ->and(BspProvider::Kaleyra->declaredSupport(ChannelCapability::Interactive))
        ->toBe(ChannelCapabilitySupport::Conditional);
});

it('never lets a partner grant a capability the BSP route does not have', function (): void {
    foreach (BspProvider::cases() as $provider) {
        foreach (ChannelCapability::cases() as $capability) {
            $ceiling = $capability->supportOn(ChannelMode::BspGateway);

            // The floor Property 21 rests on: a refusal at the mode level is binding, no
            // matter what a per-provider profile — or task 7.4's live config — says.
            if (! $ceiling->isSupported()) {
                expect($provider->declaredSupport($capability))->toBe(ChannelCapabilitySupport::Unsupported, sprintf(
                    '%s reports %s supported, which the BSP_GATEWAY route does not.',
                    $provider->value,
                    $capability->value,
                ))
                    ->and($provider->supports($capability))->toBeFalse();
            }
        }

        // The five Baileys-only capabilities stay refused for every partner (Property 21).
        expect($provider->capabilities())->not->toContain(
            ChannelCapability::Groups,
            ChannelCapability::Extraction,
            ChannelCapability::Channels,
        );
    }
});

it('rejects an unknown provider by naming the value', function (): void {
    expect(BspProvider::tryFromKey(' twilio '))->toBe(BspProvider::Twilio)
        ->and(BspProvider::tryFromKey('SINCH'))->toBeNull()
        ->and(fn () => BspProvider::coerce('SINCH'))
        ->toThrow(InvalidArgumentException::class, 'Unknown BSP provider [SINCH]');
});
