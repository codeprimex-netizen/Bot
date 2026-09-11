<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;

/*
|--------------------------------------------------------------------------
| The capability matrix, cell by cell (Req 8.1, 8.2, 8.3 / A8)
|--------------------------------------------------------------------------
| design § Channel Mode 2.3 is a table, and this is that table transcribed — so a future
| edit to the matrix has to be made twice, in the enum and here, rather than being able to
| slip through as a plausible-looking `match` arm. `✅ = Native`, `⚠️ = Conditional`,
| `❌ = Unsupported`; a `❌` is what Properties 21 and 26 require be refused *before* any
| driver call.
*/

/**
 * design.md § Channel Mode 2.3, in order, as `[capability => [mode => cell]]`.
 *
 * @return array<string, array<string, ChannelCapabilitySupport>>
 */
function channelCapabilityMatrix(): array
{
    $native = ChannelCapabilitySupport::Native;
    $conditional = ChannelCapabilitySupport::Conditional;
    $unsupported = ChannelCapabilitySupport::Unsupported;

    return [
        //                            BAILEYS      CLOUD_API     ON_PREMISE    BSP_GATEWAY
        'SEND_SINGLE' => ['BAILEYS' => $native, 'CLOUD_API' => $native, 'ON_PREMISE' => $native, 'BSP_GATEWAY' => $native],
        'SEND_BULK' => ['BAILEYS' => $native, 'CLOUD_API' => $conditional, 'ON_PREMISE' => $conditional, 'BSP_GATEWAY' => $conditional],
        'MEDIA' => ['BAILEYS' => $native, 'CLOUD_API' => $native, 'ON_PREMISE' => $native, 'BSP_GATEWAY' => $conditional],
        'FREE_FORM_ANYTIME' => ['BAILEYS' => $native, 'CLOUD_API' => $conditional, 'ON_PREMISE' => $conditional, 'BSP_GATEWAY' => $conditional],
        'TEMPLATE' => ['BAILEYS' => $conditional, 'CLOUD_API' => $native, 'ON_PREMISE' => $native, 'BSP_GATEWAY' => $native],
        'INTERACTIVE' => ['BAILEYS' => $conditional, 'CLOUD_API' => $native, 'ON_PREMISE' => $native, 'BSP_GATEWAY' => $conditional],
        'GROUPS' => ['BAILEYS' => $native, 'CLOUD_API' => $unsupported, 'ON_PREMISE' => $unsupported, 'BSP_GATEWAY' => $unsupported],
        'WELCOME' => ['BAILEYS' => $native, 'CLOUD_API' => $unsupported, 'ON_PREMISE' => $unsupported, 'BSP_GATEWAY' => $unsupported],
        'EXTRACTION' => ['BAILEYS' => $native, 'CLOUD_API' => $unsupported, 'ON_PREMISE' => $unsupported, 'BSP_GATEWAY' => $unsupported],
        'TAGGING' => ['BAILEYS' => $native, 'CLOUD_API' => $unsupported, 'ON_PREMISE' => $unsupported, 'BSP_GATEWAY' => $unsupported],
        'CHANNELS' => ['BAILEYS' => $native, 'CLOUD_API' => $unsupported, 'ON_PREMISE' => $unsupported, 'BSP_GATEWAY' => $unsupported],
        'INBOUND_WEBHOOK' => ['BAILEYS' => $native, 'CLOUD_API' => $native, 'ON_PREMISE' => $native, 'BSP_GATEWAY' => $native],
        'DELIVERY_RECEIPTS' => ['BAILEYS' => $native, 'CLOUD_API' => $native, 'ON_PREMISE' => $native, 'BSP_GATEWAY' => $conditional],
    ];
}

it('is exactly the thirteen capabilities design.md names, in its order', function (): void {
    expect(ChannelCapability::keys())->toBe(array_keys(channelCapabilityMatrix()));
});

it('answers every cell of the matrix the way design.md does', function (): void {
    foreach (channelCapabilityMatrix() as $capabilityKey => $row) {
        $capability = ChannelCapability::from($capabilityKey);

        foreach ($row as $modeKey => $expected) {
            $mode = ChannelMode::from($modeKey);

            expect($capability->supportOn($mode))->toBe($expected, sprintf(
                '%s on %s should be %s.',
                $capabilityKey,
                $modeKey,
                $expected->value,
            ))
                // `supports()` is the boolean the gate reads: ⚠️ may be attempted, ❌ may not.
                ->and($capability->supportedOn($mode))->toBe($expected->isSupported());
        }
    }
});

it('covers every mode for every capability, with no default arm to fall into', function (): void {
    // The exhaustiveness claim itself: a capability added without a row would raise
    // \UnhandledMatchError here rather than defaulting to "supported", which on this matrix
    // would mean dispatching a group operation to Meta's Cloud API.
    foreach (ChannelCapability::cases() as $capability) {
        foreach (ChannelMode::cases() as $mode) {
            expect($capability->supportOn($mode))->toBeInstanceOf(ChannelCapabilitySupport::class);
        }
    }
});

it('identifies the five Baileys-only capabilities from the matrix itself', function (): void {
    $only = array_values(array_filter(
        ChannelCapability::cases(),
        static fn (ChannelCapability $capability): bool => $capability->isBaileysOnly(),
    ));

    // design § Channel Mode 2.2 "Limitations vs Baileys" and § Channels CH.4: no groups,
    // no welcome, no extraction, no tagging, no channels on any official route.
    expect($only)->toBe([
        ChannelCapability::Groups,
        ChannelCapability::Welcome,
        ChannelCapability::Extraction,
        ChannelCapability::Tagging,
        ChannelCapability::Channels,
    ]);

    // "Baileys-only" is not "the web-protocol modes": ON_PREMISE is web-protocol for
    // anti-ban purposes and still has none of these five, because it is an official
    // Business API client rather than a WhatsApp Web session.
    expect(ChannelMode::OnPremise->isWebProtocol())->toBeTrue()
        ->and(ChannelMode::OnPremise->supports(ChannelCapability::Groups))->toBeFalse();
});

it('partitions each mode into the capabilities it may attempt and the ones it must refuse', function (): void {
    foreach (ChannelMode::cases() as $mode) {
        $supported = ChannelCapability::supportedBy($mode);
        $unsupported = ChannelCapability::unsupportedBy($mode);

        $overlap = array_intersect(
            array_map(static fn (ChannelCapability $c): string => $c->value, $supported),
            array_map(static fn (ChannelCapability $c): string => $c->value, $unsupported),
        );

        expect($overlap)->toBe([])
            ->and(count($supported) + count($unsupported))->toBe(count(ChannelCapability::cases()));
    }
});

it('treats a conditional cell as attemptable and an unsupported one as not', function (): void {
    expect(ChannelCapabilitySupport::Native->isSupported())->toBeTrue()
        ->and(ChannelCapabilitySupport::Conditional->isSupported())->toBeTrue()
        ->and(ChannelCapabilitySupport::Conditional->isConditional())->toBeTrue()
        ->and(ChannelCapabilitySupport::Native->isConditional())->toBeFalse()
        ->and(ChannelCapabilitySupport::Unsupported->isSupported())->toBeFalse();
});

it('lets a refinement move a supported cell either way but never lift a refusal', function (): void {
    $native = ChannelCapabilitySupport::Native;
    $conditional = ChannelCapabilitySupport::Conditional;
    $unsupported = ChannelCapabilitySupport::Unsupported;

    // Inside "supported", the more specific layer wins: that is what resolving
    // "⚠️ per provider" to a definite verdict means.
    expect($native->refinedBy($conditional))->toBe($conditional)
        ->and($conditional->refinedBy($native))->toBe($native)
        ->and($native->refinedBy($unsupported))->toBe($unsupported)
        // ...and the one floor, which is what Property 21 rests on.
        ->and($unsupported->refinedBy($native))->toBe($unsupported)
        ->and($unsupported->refinedBy($conditional))->toBe($unsupported);
});

it('names every capability once, for the refusal and the panel to quote', function (): void {
    $labels = array_map(
        static fn (ChannelCapability $capability): string => $capability->label(),
        ChannelCapability::cases(),
    );

    // One phrase per capability, so `ModeCapabilityException` and a disabled-with-reason
    // tooltip cannot describe the same cell differently — and none of them is empty, which
    // would leave a refusal saying "  is not available on this session".
    expect($labels)->toBe(array_values(array_unique($labels)))
        ->and(ChannelCapability::Groups->label())->toBe('Group management');

    foreach ($labels as $label) {
        expect(trim($label))->not->toBe('');
    }
});
