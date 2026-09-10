<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Which messaging backend one session talks WhatsApp through — the value
 * `sessions_wa.channel_mode` holds and the router dispatches on
 * (Req 8.1 / A8; Req 2.6 / A2; design § Channel Mode 2.2).
 *
 * ```php
 * $session->channel_mode;                                  // ChannelMode::Baileys, by default
 * $session->channel_mode->supports(ChannelCapability::Groups);
 * ```
 *
 * | Mode | Driver (tasks 7.1–7.4) | Ban risk / rules | Anti-ban gate |
 * |---|---|---|---|
 * | `BAILEYS` **(default)** | `BaileysChannelDriver` — the existing Node + Baileys bridge | highest; unofficial web protocol | **mandatory** |
 * | `CLOUD_API` | `CloudApiChannelDriver` — Meta's hosted Business Platform | lowest; template + 24h-window rules | provider rules |
 * | `ON_PREMISE` | `OnPremiseChannelDriver` — legacy self-hosted client | official but **deprecated** by Meta | **applies** |
 * | `BSP_GATEWAY` | `BspGatewayChannelDriver` — one of eight `BspProvider` partners | low; partner + Meta rules | provider rules |
 *
 * ## `BAILEYS` is the default, and that is a promise rather than a convenience
 *
 * The column is `NOT NULL DEFAULT 'BAILEYS'` and `Session` declares the same default in
 * `$attributes`, so a session created by code that has never heard of Channel Mode is a
 * Baileys session — design.md § Data Models: *"existing single-tenant/Baileys behaviour
 * is preserved with zero official-API setup"*. Two consequences worth stating:
 *
 * - a tenant needs **no** Meta WABA, Cloud API token, or BSP contract to run the
 *   platform end to end (`requiresTenantCredentials()` is false for exactly this mode);
 * - a session that never opted in behaves precisely as it did before the column existed,
 *   because every predicate below answers for `Baileys` the way the pre-Channel-Mode
 *   code path behaved unconditionally: anti-ban applies, no session window, no template
 *   requirement, and the full capability set.
 *
 * The default is therefore not configurable. An operator flag that changed it would move
 * every existing session onto a backend whose credentials nobody has entered.
 *
 * ## What lives here, and what does not
 *
 * This enum *states* facts about a mode. It resolves nothing and throws nothing:
 *
 * | Concern | Owner |
 * |---|---|
 * | mode → driver instance | `ChannelRouter` (task 6.3) |
 * | the `ChannelDriver` contract, incl. `requiresAntiBan()` | task 6.2 (delegates to `isWebProtocol()`) |
 * | reading/writing per-mode credentials | `ChannelCredentialStore` (task 6.4) |
 * | the send gate, and raising `ModeCapabilityException` | tasks 6.3, 8.1 |
 * | template / 24-hour-window enforcement | task 8.4 |
 * | the mode-switch drain and its audit | task 8.6 |
 */
enum ChannelMode: string
{
    /** Existing Node + Baileys bridge: WhatsApp Web multi-device. Full features, highest ban risk. */
    case Baileys = 'BAILEYS';

    /** Meta WhatsApp Cloud API: official, Meta-hosted, template + 24-hour-window rules. */
    case CloudApi = 'CLOUD_API';

    /** WhatsApp Business / On-Premise API: official, self-hosted, deprecated by Meta. */
    case OnPremise = 'ON_PREMISE';

    /** An official BSP/gateway partner fronting Meta — see `BspProvider`. */
    case BspGateway = 'BSP_GATEWAY';

    /**
     * The mode a session gets when nobody chooses one.
     *
     * The single source of the default: the migration's column default, `Session`'s
     * `$attributes`, and any future validator all read this, so "the default is
     * `BAILEYS`" is stated once.
     */
    public static function default(): self
    {
        return self::Baileys;
    }

    public function isDefault(): bool
    {
        return $this === self::default();
    }

    /**
     * A short label for the panel's mode picker (§4.1 row 10).
     */
    public function label(): string
    {
        return match ($this) {
            self::Baileys => 'Baileys bridge (WhatsApp Web)',
            self::CloudApi => 'Meta WhatsApp Cloud API',
            self::OnPremise => 'WhatsApp On-Premise API (deprecated)',
            self::BspGateway => 'BSP gateway partner',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Which rules apply
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this mode rides the WhatsApp **web** protocol, so the anti-ban warm-up /
     * rate / quiet-hours gate applies (Req 8.8 / A8; Correctness Property 24).
     *
     * The matrix row *"Anti-ban warm-up / rate ramp needed"*: `✅` for `BAILEYS` and
     * `ON_PREMISE`, `❌` for the official Meta-hosted and partner routes, which follow the
     * provider's own limits instead. `ChannelDriver::requiresAntiBan()` (task 6.2) answers
     * by delegating here, so the driver and the enum cannot disagree — and there is no
     * configuration that flips it, because Req 8.8 says the gate must not be disableable.
     */
    public function isWebProtocol(): bool
    {
        return match ($this) {
            self::Baileys, self::OnPremise => true,
            self::CloudApi, self::BspGateway => false,
        };
    }

    /**
     * Whether this route is an **official** one (Meta's own API, a self-hosted official
     * client, or a Business Solution Provider).
     *
     * Not the negation of `isWebProtocol()`: `ON_PREMISE` is both an official route *and*
     * a mode the anti-ban gate applies to (design § Channel Mode 2.2 mode 3 — "an
     * **official** route historically used before Cloud API", capability row "Anti-ban …
     * `✅`"). Keeping the two predicates independent is what stops a caller reading
     * "official" and concluding "no anti-ban".
     */
    public function isOfficial(): bool
    {
        return match ($this) {
            self::CloudApi, self::OnPremise, self::BspGateway => true,
            self::Baileys => false,
        };
    }

    /**
     * Whether free-form content is confined to the 24-hour customer-service window, so an
     * approved template is required outside it (Req 8.12 / A8, enforced by task 8.4).
     *
     * Derived from the capability matrix — `FreeFormAnytime` is `⚠️ only in 24h window`
     * on every mode but Baileys — rather than restated, so this predicate cannot drift
     * from the row it comes from.
     *
     * **Design ambiguity, resolved conservatively.** Algorithm 9 branches *exclusively*
     * (`IF requiresAntiBan() THEN anti-ban ELSE window check`), which would skip the
     * window check for `ON_PREMISE`; the capability matrix gives `ON_PREMISE` both
     * `⚠️ 24h window` and `✅` anti-ban. The matrix is the more specific statement and the
     * safer reading — a free-form send outside the window is refused by the provider
     * anyway, so treating it as allowed would turn a typed local refusal into a remote
     * failure mid-send. The two axes are therefore reported independently here, and task
     * 8.1 can apply both to `ON_PREMISE` without contradicting either document.
     */
    public function enforcesSessionWindow(): bool
    {
        return ! ChannelCapability::FreeFormAnytime->supportOn($this)->isNative();
    }

    /*
    |--------------------------------------------------------------------------
    | Onboarding
    |--------------------------------------------------------------------------
    */

    /**
     * Whether selecting this mode requires the tenant to supply credentials of its own —
     * the matrix row *"Zero official-API onboarding"*, inverted.
     *
     * `BAILEYS` is false: the bridge's base URL and shared token are **platform**
     * configuration (`config('wa.bridge')`), not tenant secrets, and pairing is a QR
     * scan. Every other mode needs a `channel_credentials` row before it can be selected
     * — design § Channel Mode: *"when its credentials are absent that mode simply cannot
     * be selected, and the platform keeps working on Baileys"* (Req 8.13 / A8).
     */
    public function requiresTenantCredentials(): bool
    {
        return match ($this) {
            self::Baileys => false,
            self::CloudApi, self::OnPremise, self::BspGateway => true,
        };
    }

    /**
     * Whether a credential row for this mode must also name a `BspProvider`.
     *
     * True for `BSP_GATEWAY` alone, which is why `channel_credentials.provider` is
     * nullable: one driver fronts eight providers, and the other three modes have no
     * provider to name.
     */
    public function usesProvider(): bool
    {
        return match ($this) {
            self::BspGateway => true,
            self::Baileys, self::CloudApi, self::OnPremise => false,
        };
    }

    /**
     * Whether Meta has deprecated this route (Req 8.9 / A8).
     */
    public function isDeprecated(): bool
    {
        return match ($this) {
            self::OnPremise => true,
            self::Baileys, self::CloudApi, self::BspGateway => false,
        };
    }

    /**
     * The mode a deprecated one should be migrated to, or null when there is nothing to
     * migrate — the `ON_PREMISE → CLOUD_API` path Req 8.9 requires be documented and
     * task 7.3 implements.
     */
    public function migrationTarget(): ?self
    {
        return match ($this) {
            self::OnPremise => self::CloudApi,
            self::Baileys, self::CloudApi, self::BspGateway => null,
        };
    }

    /**
     * The provider slug this mode's callbacks are registered under:
     * `{base}/webhooks/{slug}/{routeKey}`.
     *
     * One lowercase DNS-label-shaped token, because that is what
     * `App\Services\Url\UrlBuilder::webhook()` accepts as its provider segment (Req 9.2 /
     * A9). For `BSP_GATEWAY` the mode-level slug is `bsp`; a route registered for a
     * specific partner uses `BspProvider::webhookSlug()` instead, so two partners of one
     * tenant do not share a callback path.
     */
    public function webhookSlug(): string
    {
        return match ($this) {
            self::Baileys => 'bridge',
            self::CloudApi => 'cloud-api',
            self::OnPremise => 'on-premise',
            self::BspGateway => 'bsp',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Capabilities
    |--------------------------------------------------------------------------
    */

    /**
     * How well this mode supports `$capability` — the matrix cell, `⚠️` intact.
     */
    public function supportFor(ChannelCapability $capability): ChannelCapabilitySupport
    {
        return $capability->supportOn($this);
    }

    /**
     * Whether `$capability` may be attempted on this mode at all.
     *
     * The pre-dispatch question of Req 8.3 / Property 21. A `false` here is what task
     * 6.3 turns into `ModeCapabilityException` *before* any driver call.
     */
    public function supports(ChannelCapability $capability): bool
    {
        return $capability->supportedOn($this);
    }

    /**
     * Every capability this mode may attempt, in declaration order.
     *
     * @return list<ChannelCapability>
     */
    public function capabilities(): array
    {
        return ChannelCapability::supportedBy($this);
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    /**
     * A mode from an untrusted string, or `null`.
     *
     * For **stored** values and optional input. Trims, and accepts the canonical
     * upper-case spelling only — a stored value written by a newer release is dropped
     * rather than guessed at, and dropping it means "no mode", which the caller must then
     * resolve explicitly rather than silently landing on a backend nobody chose.
     */
    public static function tryFromKey(string $key): ?self
    {
        return self::tryFrom(trim($key));
    }

    /**
     * A mode from a request or a route declaration, or a hard failure naming the value.
     *
     * Req 8.1 requires that a create/update whose `channel_mode` is outside the set be
     * rejected *"with a validation error identifying the invalid value"* — so the message
     * quotes the offending value and lists the legal ones. The value is a plain enum key,
     * never a secret or a subscriber identity, so quoting it is safe.
     *
     * @throws InvalidArgumentException on an unknown key
     */
    public static function coerce(string $key): self
    {
        $mode = self::tryFromKey($key);

        if ($mode === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown channel mode [%s]. Known modes: %s.',
                $key,
                implode(', ', self::keys()),
            ));
        }

        return $mode;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }

    /**
     * The modes the anti-ban gate applies to — the `whereIn` a sweep or a report needs.
     *
     * @return list<self>
     */
    public static function webProtocol(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $mode): bool => $mode->isWebProtocol()));
    }
}
