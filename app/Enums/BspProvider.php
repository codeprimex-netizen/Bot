<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Which Business Solution Provider fronts a `ChannelMode::BspGateway` session
 * (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4).
 *
 * The eight partners design.md names. One driver serves all of them
 * (`BspGatewayChannelDriver`, task 7.4) behind a per-provider adapter, so a ninth partner
 * is a new case plus an adapter — never a rewrite (NFR4).
 *
 * ## Providers are not interchangeable, and this enum is where that starts
 *
 * design § Channel Mode 2.3 is explicit that the `BSP_GATEWAY` row of the capability
 * matrix is a *ceiling*, not a profile: *"the driver reports each provider's real
 * `supports()` at runtime rather than assuming one profile"*. So support is resolved in
 * two layers, and this enum owns the middle one:
 *
 * | Layer | Source | Owner |
 * |---|---|---|
 * | 1. mode ceiling | `ChannelCapability::supportOn(BSP_GATEWAY)` | this phase (task 6.1) |
 * | 2. per-provider sub-matrix | `declaredSupport()` below — what design.md states about each named partner | this phase (task 6.1) |
 * | 3. runtime refinement | the provider's live config in `channel_credentials.config` (media size/type caps, template-sync availability, sender shape) | task 7.4 |
 *
 * Layer 2 can only ever **narrow** layer 1 (`ChannelCapabilitySupport::narrowedTo()`), and
 * layer 3 must narrow layer 2 the same way. That direction is the safety property: a
 * provider cannot grant group management, because the BSP route does not have it — so no
 * amount of provider config can turn a `❌` into a dispatch (Property 21).
 *
 * Only what design.md actually says is encoded here. For the partners it does not
 * characterise (MessageBird, Infobip, Kaleyra) the ceiling stands unchanged, which for
 * `Interactive` means `Conditional` — "attempt it subject to what the provider turns out
 * to allow" — and that is layer 3's question, not a guess this enum should make.
 */
enum BspProvider: string
{
    case Twilio = 'TWILIO';
    case ThreeSixtyDialog = '360DIALOG';
    case Gupshup = 'GUPSHUP';
    case Vonage = 'VONAGE';
    case MessageBird = 'MESSAGEBIRD';
    case Infobip = 'INFOBIP';
    case Wati = 'WATI';
    case Kaleyra = 'KALEYRA';

    /**
     * The partner's own spelling, for the panel's provider picker.
     */
    public function label(): string
    {
        return match ($this) {
            self::Twilio => 'Twilio',
            self::ThreeSixtyDialog => '360dialog',
            self::Gupshup => 'Gupshup',
            self::Vonage => 'Vonage',
            self::MessageBird => 'MessageBird',
            self::Infobip => 'Infobip',
            self::Wati => 'WATI',
            self::Kaleyra => 'Kaleyra',
        };
    }

    /**
     * The provider slug its callbacks are registered under:
     * `{base}/webhooks/{slug}/{routeKey}`.
     *
     * Lowercase, DNS-label-shaped, so `App\Services\Url\UrlBuilder::webhook()` accepts it
     * (Req 9.2 / A9). Per provider rather than one shared `bsp` path, so a tenant running
     * two partners has two distinct callback URLs and a misrouted delivery is a 404
     * instead of a payload the wrong adapter tries to parse.
     */
    public function webhookSlug(): string
    {
        return match ($this) {
            self::Twilio => 'twilio',
            self::ThreeSixtyDialog => '360dialog',
            self::Gupshup => 'gupshup',
            self::Vonage => 'vonage',
            self::MessageBird => 'messagebird',
            self::Infobip => 'infobip',
            self::Wati => 'wati',
            self::Kaleyra => 'kaleyra',
        };
    }

    /**
     * Whether this partner exposes a Cloud-API-equivalent surface — interactive messages
     * and a template-sync API in Meta's own shape.
     *
     * design § Channel Mode 2.3: *"360dialog/Gupshup/WATI expose Cloud-API-equivalent
     * interactive + template-sync APIs"*. Named partners only; a `false` here is "design.md
     * does not say", not "the provider cannot".
     */
    public function isCloudApiEquivalent(): bool
    {
        return match ($this) {
            self::ThreeSixtyDialog, self::Gupshup, self::Wati => true,
            self::Twilio, self::Vonage, self::MessageBird, self::Infobip, self::Kaleyra => false,
        };
    }

    /**
     * Whether interactive payloads are reachable only through the partner's own content
     * templates rather than as first-class buttons/lists.
     *
     * design § Channel Mode 2.3: *"Twilio & Vonage do buttons/lists via content
     * templates"*. Operationally this is what keeps `Interactive` `Conditional` for them:
     * the send is possible, but only via a template the tenant has registered with the
     * partner, which is a rule task 8.4 has to enforce rather than an outright refusal.
     */
    public function interactiveViaContentTemplates(): bool
    {
        return match ($this) {
            self::Twilio, self::Vonage => true,
            self::ThreeSixtyDialog, self::Gupshup, self::MessageBird, self::Infobip, self::Wati, self::Kaleyra => false,
        };
    }

    /**
     * How well this partner supports `$capability` — the mode ceiling, refined by what
     * design.md says about this partner.
     *
     * The ceiling's *refusals* are binding (`ChannelCapabilitySupport::refinedBy()`), so a
     * partner can never be reported as supporting something `ChannelMode::BspGateway` does
     * not: `Groups`, `Welcome`, `Extraction`, `Tagging`, `Channels`. Within "supported" the
     * partner's own answer wins, which is what resolving `⚠️ per provider` means. Task 7.4
     * refines the result once more from live credentials, under the same floor.
     */
    public function declaredSupport(ChannelCapability $capability): ChannelCapabilitySupport
    {
        $ceiling = $capability->supportOn(ChannelMode::BspGateway);

        if (! $ceiling->isSupported()) {
            return $ceiling;
        }

        return $ceiling->refinedBy(match ($capability) {
            // The one cell design.md distinguishes per partner: Cloud-API-equivalent
            // partners do interactive natively; Twilio/Vonage only through content
            // templates; everyone else is whatever their config turns out to say.
            ChannelCapability::Interactive => $this->isCloudApiEquivalent()
                ? ChannelCapabilitySupport::Native
                : ChannelCapabilitySupport::Conditional,

            // Template sync is Cloud-API-shaped for the equivalent partners and
            // partner-specific for the rest, but every BSP has *some* approved-template
            // route — so the ceiling (`Native`) stands and this arm changes nothing.
            ChannelCapability::Template => ChannelCapabilitySupport::Native,

            // Media caps ("size/type caps vary"), tiered bulk throughput, receipts and
            // the session-window rule are all per-provider *quantities* rather than
            // per-provider capabilities: the ceiling already carries the right verdict, and
            // the actual limits come from `channel_credentials.config` at runtime
            // (task 7.4). Returning the ceiling unchanged is a no-op refinement. Listed
            // exhaustively so a capability added later cannot land here silently.
            ChannelCapability::SendSingle,
            ChannelCapability::SendBulk,
            ChannelCapability::Media,
            ChannelCapability::FreeFormAnytime,
            ChannelCapability::InboundWebhook,
            ChannelCapability::DeliveryReceipts,
            ChannelCapability::Groups,
            ChannelCapability::Welcome,
            ChannelCapability::Extraction,
            ChannelCapability::Tagging,
            ChannelCapability::Channels => $ceiling,
        });
    }

    /**
     * Whether this partner may attempt `$capability` at all.
     */
    public function supports(ChannelCapability $capability): bool
    {
        return $this->declaredSupport($capability)->isSupported();
    }

    /**
     * Every capability this partner may attempt, in declaration order.
     *
     * @return list<ChannelCapability>
     */
    public function capabilities(): array
    {
        return array_values(array_filter(
            ChannelCapability::cases(),
            fn (ChannelCapability $capability): bool => $this->supports($capability),
        ));
    }

    /**
     * A provider from an untrusted or stored string, or `null`.
     */
    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom(strtoupper(trim($key)));
    }

    /**
     * A provider from a request, or a hard failure naming the value — the same contract
     * `ChannelMode::coerce()` documents (Req 8.1 / A8).
     *
     * @throws InvalidArgumentException on an unknown key
     */
    public static function coerce(string $key): self
    {
        $provider = self::tryFromKey($key);

        if ($provider === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown BSP provider [%s]. Known providers: %s.',
                $key,
                implode(', ', self::keys()),
            ));
        }

        return $provider;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $provider): string => $provider->value, self::cases());
    }
}
