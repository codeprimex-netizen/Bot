<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a messaging backend can be *asked to do* — the vocabulary every gate in the
 * Channel Mode phase decides against (Req 8.1, 8.2, 8.3 / A8; design § Channel Mode 2.3,
 * § Channels CH.4).
 *
 * ```php
 * // the check task 6.3's ChannelRouter::assertSupported() makes, before any driver call
 * if (! $session->channel_mode->supports(ChannelCapability::Groups)) {
 *     throw ModeCapabilityException::for($session->channel_mode, ChannelCapability::Groups);
 * }
 * ```
 *
 * The thirteen cases are design.md's list verbatim (§ Channel Mode 2.4, the
 * `ChannelCapability` sketch) and they are exactly the rows of the capability matrix in
 * § 2.3 — minus its last two rows, which are properties of the *mode* rather than
 * operations a caller can request and live on `ChannelMode` instead:
 *
 * | Matrix row | Lives on |
 * |---|---|
 * | *Anti-ban warm-up / rate ramp needed* | `ChannelMode::isWebProtocol()` (Property 24) |
 * | *Zero official-API onboarding* | `ChannelMode::requiresTenantCredentials()` |
 *
 * ## The matrix is data, and it has no default arm
 *
 * `supportOn()` is one exhaustive `match` over the capability, with an exhaustive `match`
 * over the mode inside each arm. Both halves are deliberate, for the reason
 * `TenantPermission::allowedRoles()` gives about deny-by-default: a capability added in a
 * later phase without a row here is a static-analysis error and, at runtime, an
 * `\UnhandledMatchError`. It can never *default* to supported — which on this matrix
 * would mean silently dispatching a group operation to Meta's Cloud API, whose failure
 * mode is a provider error mid-send rather than the typed pre-dispatch refusal Req 8.3
 * requires.
 *
 * Nothing in `config/` can widen the matrix, for the same reason nothing can widen the
 * permission matrix: which operations a backend really supports is a property of the
 * backend, not an operator preference. A tenant who wants groups uses a Baileys session.
 *
 * ## What this enum does *not* do
 *
 * It states what is supported. It neither throws nor dispatches:
 *
 * | Concern | Owner |
 * |---|---|
 * | `supports()` on a live driver, and the per-provider sub-matrix | tasks 6.2, 7.4 |
 * | routing, and raising `ModeCapabilityException` | task 6.3 (`ChannelRouter`) |
 * | persisting the handshake's authoritative capability set (Req 8.2) | task 8.2 |
 * | the 24-hour-window / approved-template rule a `Conditional` cell implies | task 8.4 |
 * | rendering an unsupported feature disabled-with-reason | the panel tasks (§4.1 row 10) |
 *
 * A more specific layer may **resolve** a `⚠️` cell in either direction — that is what
 * `BspProvider::declaredSupport()` does with "per provider" — but it can never make a `❌`
 * attemptable: the refusals in this matrix are the ceiling every layer inherits
 * (`ChannelCapabilitySupport::refinedBy()`).
 */
enum ChannelCapability: string
{
    /** One text message to one recipient. */
    case SendSingle = 'SEND_SINGLE';

    /** A campaign: the same content to many recipients. */
    case SendBulk = 'SEND_BULK';

    /** Image, document, audio, video, sticker. */
    case Media = 'MEDIA';

    /** Free-form (non-template) content at any time, with no session-window rule. */
    case FreeFormAnytime = 'FREE_FORM_ANYTIME';

    /** Pre-approved template messages (`cloud_api_templates`). */
    case Template = 'TEMPLATE';

    /** Buttons, lists, and the other interactive payloads. */
    case Interactive = 'INTERACTIVE';

    /** Group management: create, admin, settings, participants. */
    case Groups = 'GROUPS';

    /** Auto-welcome a participant who joins a group. */
    case Welcome = 'WELCOME';

    /** Group member extraction and active-number filtering. */
    case Extraction = 'EXTRACTION';

    /** Tag-all / selective tagging inside a group. */
    case Tagging = 'TAGGING';

    /** Channel / newsletter management and posting. */
    case Channels = 'CHANNELS';

    /** Inbound customer messages delivered to the platform by webhook. */
    case InboundWebhook = 'INBOUND_WEBHOOK';

    /** Delivery and read receipts for outbound messages. */
    case DeliveryReceipts = 'DELIVERY_RECEIPTS';

    /**
     * How well `$mode` supports this capability — design § Channel Mode 2.3, cell by cell.
     *
     * The `⚠️` cells keep the design's own condition in a comment beside them, because
     * the condition is what a later gate has to enforce and the reader of this arm is
     * the person writing that gate.
     */
    public function supportOn(ChannelMode $mode): ChannelCapabilitySupport
    {
        return match ($this) {
            // Single send (text): ✅ everywhere. The one operation every backend has.
            self::SendSingle => match ($mode) {
                ChannelMode::Baileys,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Native,
            },

            // Bulk send: ✅ on Baileys (anti-ban paced); ⚠️ on the official modes —
            // template + quality tier (Cloud API / On-Premise), per-provider tier (BSP).
            self::SendBulk => match ($mode) {
                ChannelMode::Baileys => ChannelCapabilitySupport::Native,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Conditional,
            },

            // Media: ✅ on the web-protocol and Meta-hosted modes; ⚠️ per provider on
            // BSP, where size and type caps vary (task 7.4 resolves the real limits).
            self::Media => match ($mode) {
                ChannelMode::Baileys,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise => ChannelCapabilitySupport::Native,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Conditional,
            },

            // Free-form anytime: ✅ only on Baileys. Every official mode is ⚠️ "only
            // inside the 24-hour session window" — including ON_PREMISE, whose matrix
            // row says so even though Algorithm 9 sends it down the anti-ban branch.
            // See the class docblock of ChannelMode::enforcesSessionWindow().
            self::FreeFormAnytime => match ($mode) {
                ChannelMode::Baileys => ChannelCapabilitySupport::Native,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Conditional,
            },

            // Approved templates: ⚠️ on Baileys, which can only render a template as
            // text (there is no approval registry on the web protocol); ✅ on the
            // official modes, BSP via the provider's own template-sync API.
            self::Template => match ($mode) {
                ChannelMode::Baileys => ChannelCapabilitySupport::Conditional,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Native,
            },

            // Interactive: ⚠️ on Baileys (only if the WhatsApp version supports it);
            // ✅ on Cloud API / On-Premise; ⚠️ per provider on BSP.
            self::Interactive => match ($mode) {
                ChannelMode::Baileys => ChannelCapabilitySupport::Conditional,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise => ChannelCapabilitySupport::Native,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Conditional,
            },

            /*
             * The five Baileys-only capabilities: group management, auto-welcome, member
             * extraction, tagging, and channels/newsletters. ❌ on every official mode —
             * Meta's Business Platform exposes none of them, and neither does any BSP
             * fronting it (design § Channel Mode 2.2 "Limitations vs Baileys", § Channels
             * CH.4). These are the cells Properties 21 and 26 are about.
             */
            self::Groups,
            self::Welcome,
            self::Extraction,
            self::Tagging,
            self::Channels => match ($mode) {
                ChannelMode::Baileys => ChannelCapabilitySupport::Native,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Unsupported,
            },

            // Inbound webhooks: ✅ everywhere, by four different mechanisms — bridge
            // HMAC, Meta verify-token + signature, on-prem callback, BSP signature.
            // Which one is used is the driver's business (task 8.3); that there *is* one
            // is what this row says.
            self::InboundWebhook => match ($mode) {
                ChannelMode::Baileys,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Native,
            },

            // Delivery / read receipts: ✅ except on BSP, where ⚠️ per provider.
            self::DeliveryReceipts => match ($mode) {
                ChannelMode::Baileys,
                ChannelMode::CloudApi,
                ChannelMode::OnPremise => ChannelCapabilitySupport::Native,
                ChannelMode::BspGateway => ChannelCapabilitySupport::Conditional,
            },
        };
    }

    /**
     * Whether `$mode` may attempt this capability at all — the boolean form of the cell.
     */
    public function supportedOn(ChannelMode $mode): bool
    {
        return $this->supportOn($mode)->isSupported();
    }

    /**
     * Whether this capability exists on the Baileys bridge and on no other backend —
     * design.md's own phrase, *"Channels/newsletters = Baileys-only"* (§ Channels CH.4).
     *
     * Derived from the matrix rather than listed again, so the "Baileys-only" claim in the
     * panel and in `ModeCapabilityException`'s message cannot drift from the rows above.
     *
     * Note that this is **not** the same as "the web-protocol modes": `ON_PREMISE` is a
     * web-protocol mode for anti-ban purposes (`ChannelMode::isWebProtocol()`) and still
     * has no group, welcome, extraction, tagging or channel management, because it is an
     * official Business API client rather than a WhatsApp Web session. The two axes —
     * *which pacing rules apply* and *which features exist* — genuinely do not coincide,
     * and conflating them would grant `ON_PREMISE` five capabilities design.md refuses it.
     */
    public function isBaileysOnly(): bool
    {
        foreach (ChannelMode::cases() as $mode) {
            if ($this->supportedOn($mode) !== ($mode === ChannelMode::Baileys)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every capability `$mode` may attempt, in declaration order.
     *
     * This is the shape of the set task 8.2 persists as a session's authoritative
     * capability list after the `supports()` handshake.
     *
     * @return list<ChannelCapability>
     */
    public static function supportedBy(ChannelMode $mode): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $capability): bool => $capability->supportedOn($mode),
        ));
    }

    /**
     * Every capability `$mode` must refuse before dispatch, in declaration order.
     *
     * @return list<ChannelCapability>
     */
    public static function unsupportedBy(ChannelMode $mode): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $capability): bool => ! $capability->supportedOn($mode),
        ));
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }
}
