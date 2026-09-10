<?php

declare(strict_types=1);

namespace App\Services\Channel\Concerns;

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;

/**
 * The two `ChannelDriver` answers that are *derived* rather than chosen —
 * `requiresAntiBan()` and `supports()` — written once, so five drivers cannot disagree with
 * the enum or with each other (Req 8.3, 8.8 / A8; Properties 21, 24).
 *
 * ```php
 * final class CloudApiChannelDriver implements ChannelDriver
 * {
 *     use DerivesChannelPolicy;
 *
 *     public function mode(): ChannelMode { return ChannelMode::CloudApi; }
 *     // requiresAntiBan() and supports() now follow from that, and cannot drift.
 * }
 * ```
 *
 * The only thing a driver has to say is which mode it is. Everything here follows from
 * `mode()`, which is the same reason `App\Models\Session::supports()` and
 * `requiresAntiBan()` delegate to `channel_mode` rather than restating the matrix: the
 * capability matrix and the web-protocol rule are properties of the *backend*, and a second
 * copy of either is a second thing to keep true.
 *
 * ## `refineSupport()` — how task 7.4 resolves a `⚠️` without widening a `❌`
 *
 * design § Channel Mode 2.3 requires `BspGatewayChannelDriver` to report *"each provider's
 * real `supports()` at runtime rather than assuming one profile"*. That is a refinement of
 * the mode's cell, and refinement has exactly one rule: it may move a cell **either way
 * inside "supported"** — resolving `⚠️ per provider` to a definite `✅` for a partner known
 * to do it natively, or attaching a condition to a `✅` a partner actually restricts — and it
 * may **never** make a refused capability attemptable.
 *
 * The hook is shaped so that rule is not something an override has to remember:
 *
 * - `supportFor()` computes the mode's ceiling itself and calls `refineSupport()` with it;
 * - it then combines the two through `ChannelCapabilitySupport::refinedBy()`, whose
 *   `Unsupported` arm is absorbing.
 *
 * So an override that answers `Native` for `GROUPS` on `BSP_GATEWAY` changes nothing: the
 * ceiling is `Unsupported`, `refinedBy()` returns `Unsupported`, and `supports()` is still
 * `false`. A refinement cannot grant a capability the route does not have, and it does not
 * have to be trusted not to try. `BspProvider::declaredSupport()` is the same construction
 * one layer up, and task 7.4's live-config layer is expected to be the third.
 *
 * ## What this trait deliberately does not do
 *
 * It does not *enforce* anything on a driver that declines to use it — a class method wins
 * over a trait method in PHP, even a `final` one, so a trait cannot be a guarantee. The
 * guarantee is `App\Services\Channel\ModeGuardedChannelDriver`, which re-derives both answers
 * from `mode()` whatever the driver it wraps returned. This trait is how a driver written in
 * good faith avoids having to be careful; the decorator is why it does not matter if one is
 * not.
 *
 * The only thing it requires is `mode()`, deliberately: that keeps it usable by anything that
 * knows its own mode — a driver, or a test asserting the derivation rules on their own — and
 * `ChannelDriver` already declares the two members this fills in.
 */
trait DerivesChannelPolicy
{
    /**
     * Which backend this is — the one fact a driver using this trait must supply.
     */
    abstract public function mode(): ChannelMode;

    /**
     * Whether the anti-ban warm-up / rate / quiet-hours gate applies (Req 8.8, Property 24).
     *
     * `mode()->isWebProtocol()`, with nothing between: no config key, no per-tenant setting,
     * no constructor flag. Req 8.8 requires the gate be non-disableable, and each of those
     * would be a way to disable it for `BAILEYS` or `ON_PREMISE`.
     */
    public function requiresAntiBan(): bool
    {
        return $this->mode()->isWebProtocol();
    }

    /**
     * Whether `$capability` may be attempted on this backend — the boolean the pre-dispatch
     * gate reads (Req 8.3, Property 21).
     */
    public function supports(ChannelCapability $capability): bool
    {
        return $this->supportFor($capability)->isSupported();
    }

    /**
     * How well this backend supports `$capability`, with the `⚠️` intact.
     *
     * The three-valued answer tasks 8.1 and 8.4 need: a `Conditional` cell is what tells them
     * a 24-hour-window rule, an approved-template requirement, or a provider tier still has
     * to be enforced, and collapsing it to a boolean here would throw that away for good.
     *
     * Not on the `ChannelDriver` interface: the eight members design.md names are the
     * contract, and a caller that needs three states can read the matrix directly
     * (`ChannelMode::supportFor()`) or hold a concrete driver.
     */
    public function supportFor(ChannelCapability $capability): ChannelCapabilitySupport
    {
        $ceiling = $capability->supportOn($this->mode());

        return $ceiling->refinedBy($this->refineSupport($capability, $ceiling));
    }

    /**
     * Every capability this backend may attempt, in declaration order.
     *
     * The shape of the set task 8.2 persists as a session's authoritative capability list
     * after the one-time `supports()` handshake — read from the *driver*, so a BSP session's
     * stored set is its partner's real answer rather than the mode ceiling.
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
     * A driver's own answer for `$capability`, given the mode's ceiling — override to resolve
     * a `⚠️` cell from live provider config (task 7.4).
     *
     * Returning `$ceiling` is the no-op refinement, which is why it is the default: a driver
     * whose capabilities are exactly its mode's says nothing here.
     *
     * Whatever this returns is passed through `ChannelCapabilitySupport::refinedBy()`, so it
     * cannot widen a refusal. It also cannot perform I/O usefully — `supports()` is called on
     * every send and once per capability at session create — so read from credentials already
     * in memory, never from the network.
     */
    protected function refineSupport(
        ChannelCapability $capability,
        ChannelCapabilitySupport $ceiling,
    ): ChannelCapabilitySupport {
        return $ceiling;
    }
}
