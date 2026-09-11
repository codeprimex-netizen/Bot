<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Services\Channel\BspGatewayChannelDriver;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ModeGuardedChannelDriver;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Channel\BspCredentialFixtures;

/*
|--------------------------------------------------------------------------
| BspGatewayChannelDriver — the per-provider capability sub-matrix (Req 8.1, 8.2 / A8)
|--------------------------------------------------------------------------
| design § Channel Mode 2.3 makes the `BSP_GATEWAY` matrix row a **ceiling** and asks the driver to
| report *"each provider's real supports() at runtime rather than assuming one profile"*. This file
| is about the one property that makes that safe:
|
|   **a per-provider sub-matrix can only narrow.**
|
| So it walks **every provider × every capability** and asserts, exhaustively:
|
|   1. no partner, and no partner configuration — including a configuration that explicitly claims
|      every capability — turns a `❌` in the mode's row into a `✅`. A partner that could widen
|      `GROUPS` would dispatch a group-create to a gateway with no such API, mid-operation, after the
|      capability gate had already allowed it;
|   2. a resolved cell is never wider than the ceiling in the `Conditional → Native` direction either,
|      unless design.md says that partner does it natively;
|   3. `ModeGuardedChannelDriver` re-derives the answer, so the guarantee survives a driver that lied.
|
| It also pins the narrowings that are the *point* of the feature: media off, templates off, receipts
| off, and WATI's structural refusal of media.
|
| No HTTP is involved: the whole sub-matrix is a pure function of `(mode, provider, credentials.config)`
| with no I/O, which is what lets `supports()` be called on every send and lets task 8.2 persist the
| result. `Http::preventStrayRequests()` is what asserts that.
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * An unbound driver, straight from the container — `ChannelServiceProvider` has no registry entry for
 * this mode yet (task 7.4's consolidation adds it), so the router cannot produce one.
 */
function bspDriver(): BspGatewayChannelDriver
{
    return app(BspGatewayChannelDriver::class);
}

/**
 * A credential set for `$provider`, built in memory: this file never touches the database, because
 * the sub-matrix never reads one.
 *
 * @param  array<string, mixed>  $config  merged over the partner's complete §2.7 set
 */
function bspCredentials(BspProvider $provider, array $config = []): ChannelCredentials
{
    return new ChannelCredentials(
        tenantId: '01JBSPTENANT0000000000000',
        mode: ChannelMode::BspGateway,
        provider: $provider,
        credentialId: '01JBSPCRED00000000000000',
        config: array_merge(BspCredentialFixtures::config($provider), $config),
        secrets: BspCredentialFixtures::secrets($provider),
    );
}

/**
 * Every flag the runtime layer reads, set to the most permissive value a tenant could enter — plus
 * flags named after the capabilities the mode refuses outright.
 *
 * The adversarial input for the un-widenability assertions: if any of this could open a `❌` cell, it
 * would.
 *
 * @return array<string, mixed>
 */
function bspClaimEverythingConfig(): array
{
    return [
        'media_enabled' => true,
        'interactive_enabled' => true,
        'templates_enabled' => true,
        'delivery_receipts' => true,
        // Keys no adapter reads, in the shape a hopeful operator would write them.
        'groups_enabled' => true,
        'welcome_enabled' => true,
        'extraction_enabled' => true,
        'tagging_enabled' => true,
        'channels_enabled' => true,
        'free_form_anytime' => true,
        'GROUPS' => 'NATIVE',
        'capabilities' => ChannelCapability::keys(),
    ];
}

/*
|--------------------------------------------------------------------------
| The invariant: narrowing only
|--------------------------------------------------------------------------
*/

it('never opens a capability the BSP_GATEWAY row refuses, for any provider or configuration', function (): void {
    $driver = bspDriver();
    $refused = ChannelCapability::unsupportedBy(ChannelMode::BspGateway);

    // The five Baileys-only cells; asserted rather than assumed, so this test cannot silently become
    // vacuous if the matrix changes.
    expect($refused)->toBe([
        ChannelCapability::Groups,
        ChannelCapability::Welcome,
        ChannelCapability::Extraction,
        ChannelCapability::Tagging,
        ChannelCapability::Channels,
    ]);

    foreach (BspProvider::cases() as $provider) {
        foreach ([[], bspClaimEverythingConfig()] as $config) {
            $credentials = bspCredentials($provider, $config);
            $bound = $driver->withCredentials($credentials);

            foreach ($refused as $capability) {
                expect($bound->supports($capability))->toBeFalse()
                    ->and($bound->supportFor($capability))->toBe(ChannelCapabilitySupport::Unsupported)
                    // …and through the decorator every caller actually gets.
                    ->and((new ModeGuardedChannelDriver($bound))->supports($capability))->toBeFalse()
                    // …and in the set task 8.2 persists.
                    ->and($driver->capabilitiesFor($credentials))->not->toContain($capability);
            }
        }
    }
});

it('resolves every provider cell at or below the mode ceiling', function (): void {
    $driver = bspDriver();

    foreach (BspProvider::cases() as $provider) {
        foreach ([[], bspClaimEverythingConfig()] as $config) {
            $bound = $driver->withCredentials(bspCredentials($provider, $config));

            foreach (ChannelCapability::cases() as $capability) {
                $ceiling = $capability->supportOn(ChannelMode::BspGateway);
                $resolved = $bound->supportFor($capability);

                // "At or below" means: a supported cell implies the ceiling was supported, and a
                // `Native` resolution is only legal where design.md says the partner does it natively
                // (`BspProvider::declaredSupport()`), never merely because a tenant asked for it.
                expect($resolved->isSupported())->toBe($resolved->isSupported() && $ceiling->isSupported());

                if ($resolved === ChannelCapabilitySupport::Native && ! $ceiling->isNative()) {
                    expect($provider->declaredSupport($capability))->toBe(ChannelCapabilitySupport::Native);
                }
            }
        }
    }
});

it('answers the mode ceiling while unbound, because the partner is not yet known', function (): void {
    $driver = bspDriver();

    expect($driver->mode())->toBe(ChannelMode::BspGateway)
        ->and($driver->provider())->toBeNull()
        // Property 24: an official partner route follows the partner's rules, not the warm-up ramp.
        ->and($driver->requiresAntiBan())->toBeFalse()
        ->and($driver->mode()->isOfficial())->toBeTrue();

    foreach (ChannelCapability::cases() as $capability) {
        expect($driver->supportFor($capability))->toBe($capability->supportOn(ChannelMode::BspGateway))
            ->and($driver->supports($capability))->toBe($capability->supportedOn(ChannelMode::BspGateway));
    }
});

it('binds without mutating, so one tenant\'s partner cannot leak into another\'s answer', function (): void {
    $driver = bspDriver();

    $twilio = $driver->withCredentials(bspCredentials(BspProvider::Twilio));
    $gupshup = $driver->withCredentials(bspCredentials(BspProvider::Gupshup));

    expect($twilio)->not->toBe($gupshup)
        ->and($twilio->provider())->toBe(BspProvider::Twilio)
        ->and($gupshup->provider())->toBe(BspProvider::Gupshup)
        // The instance the container built is still unbound after both bindings.
        ->and($driver->provider())->toBeNull();
});

it('refuses to bind to another mode\'s credentials', function (): void {
    bspDriver()->withCredentials(ChannelCredentials::platform('01JBSPTENANT0000000000000', ChannelMode::CloudApi));
})->throws(InvalidArgumentException::class, 'CLOUD_API');

/*
|--------------------------------------------------------------------------
| The narrowings that are the point of the feature
|--------------------------------------------------------------------------
*/

it('resolves INTERACTIVE per partner, natively only for the Cloud-API-equivalent three', function (): void {
    $driver = bspDriver();

    foreach (BspProvider::cases() as $provider) {
        $resolved = $driver
            ->withCredentials(bspCredentials($provider, ['interactive_enabled' => true]))
            ->supportFor(ChannelCapability::Interactive);

        // design § 2.3: "360dialog/Gupshup/WATI expose Cloud-API-equivalent interactive + template-sync
        // APIs" — so those three are `✅` and the rest stay `⚠️`, which is what tells task 8.4 a content
        // template is still required.
        expect($resolved)->toBe($provider->isCloudApiEquivalent()
            ? ChannelCapabilitySupport::Native
            : ChannelCapabilitySupport::Conditional);
    }
});

it('refuses INTERACTIVE for Twilio and Vonage when no content-template route is configured', function (): void {
    $driver = bspDriver();

    // design § 2.3: "Twilio & Vonage do buttons/lists via content templates". An account with no such
    // route has no way to render one, so the cell closes rather than being attempted and refused at the
    // partner mid-send.
    foreach ([BspProvider::Twilio, BspProvider::Vonage] as $provider) {
        expect($driver->withCredentials(bspCredentials($provider))->supportFor(ChannelCapability::Interactive))
            ->toBe(ChannelCapabilitySupport::Unsupported);
    }

    // Twilio opens as soon as a Messaging Service is configured, without an explicit flag.
    expect($driver
        ->withCredentials(bspCredentials(BspProvider::Twilio, ['messaging_service_sid' => 'MG0001']))
        ->supportFor(ChannelCapability::Interactive))
        ->toBe(ChannelCapabilitySupport::Conditional);
});

it('closes MEDIA, TEMPLATE and DELIVERY_RECEIPTS from the partner account\'s own configuration', function (): void {
    $driver = bspDriver();

    $cases = [
        ['media_enabled' => false, 'capability' => ChannelCapability::Media],
        ['templates_enabled' => false, 'capability' => ChannelCapability::Template],
        ['delivery_receipts' => false, 'capability' => ChannelCapability::DeliveryReceipts],
    ];

    foreach ($cases as $case) {
        $capability = $case['capability'];
        unset($case['capability']);

        $bound = $driver->withCredentials(bspCredentials(BspProvider::ThreeSixtyDialog, $case));

        expect($bound->supportFor($capability))->toBe(ChannelCapabilitySupport::Unsupported)
            ->and($bound->supports($capability))->toBeFalse()
            // Everything else is untouched: one flag closes one cell.
            ->and($bound->supports(ChannelCapability::SendSingle))->toBeTrue();
    }
});

it('reads a flag written as a string, so "false" cannot widen a cell', function (): void {
    $driver = bspDriver();

    // A JSON config column and a panel form both produce strings. `"false"` read as truthy would
    // silently *open* a capability the tenant closed, which is the direction that matters.
    expect($driver->withCredentials(bspCredentials(BspProvider::Infobip, ['media_enabled' => 'false']))
        ->supports(ChannelCapability::Media))->toBeFalse()
        ->and($driver->withCredentials(bspCredentials(BspProvider::Infobip, ['media_enabled' => '0']))
            ->supports(ChannelCapability::Media))->toBeFalse()
        ->and($driver->withCredentials(bspCredentials(BspProvider::Infobip, ['media_enabled' => 'yes']))
            ->supports(ChannelCapability::Media))->toBeTrue()
        // An unreadable value keeps the partner's normal configuration rather than removing a working
        // capability.
        ->and($driver->withCredentials(bspCredentials(BspProvider::Infobip, ['media_enabled' => ['nonsense']]))
            ->supports(ChannelCapability::Media))->toBeTrue();
});

it('refuses MEDIA on WATI whatever the account says, because WATI has no send-by-link route', function (): void {
    $driver = bspDriver();

    foreach ([[], ['media_enabled' => true], bspClaimEverythingConfig()] as $config) {
        expect($driver->withCredentials(bspCredentials(BspProvider::Wati, $config))->supports(ChannelCapability::Media))
            ->toBeFalse();
    }

    // …and it is only WATI: the sub-matrix is per partner, not a mode-wide downgrade.
    expect($driver->withCredentials(bspCredentials(BspProvider::Gupshup))->supports(ChannelCapability::Media))
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The shape task 8.2 persists
|--------------------------------------------------------------------------
*/

it('exposes the whole resolved sub-matrix keyed for storage', function (): void {
    $credentials = bspCredentials(BspProvider::Wati);
    $matrix = bspDriver()->subMatrixFor($credentials);

    expect(array_keys($matrix))->toBe(ChannelCapability::keys())
        ->and($matrix[ChannelCapability::Media->value])->toBe(ChannelCapabilitySupport::Unsupported)
        ->and($matrix[ChannelCapability::Groups->value])->toBe(ChannelCapabilitySupport::Unsupported)
        ->and($matrix[ChannelCapability::SendSingle->value])->toBe(ChannelCapabilitySupport::Native)
        ->and($matrix[ChannelCapability::Interactive->value])->toBe(ChannelCapabilitySupport::Native);

    // The boolean view agrees with the three-valued one, capability for capability — which is what
    // makes the persisted set and the send-time gate the same answer.
    $capabilities = bspDriver()->capabilitiesFor($credentials);

    foreach (ChannelCapability::cases() as $capability) {
        expect(in_array($capability, $capabilities, true))->toBe($matrix[$capability->value]->isSupported());
    }
});

it('differs between two partners of the same tenant, which is the whole point', function (): void {
    $driver = bspDriver();

    $wati = $driver->capabilitiesFor(bspCredentials(BspProvider::Wati));
    $gupshup = $driver->capabilitiesFor(bspCredentials(BspProvider::Gupshup));

    expect($wati)->not->toBe($gupshup)
        ->and($gupshup)->toContain(ChannelCapability::Media)
        ->and($wati)->not->toContain(ChannelCapability::Media);

    // Both are subsets of the mode's own set — the ceiling holds for both.
    $ceiling = ChannelCapability::supportedBy(ChannelMode::BspGateway);

    foreach ([...$wati, ...$gupshup] as $capability) {
        expect($ceiling)->toContain($capability);
    }
});
