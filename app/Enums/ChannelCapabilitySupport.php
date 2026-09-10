<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How well one `ChannelCapability` works on one `ChannelMode` — the three values
 * design.md's capability matrix actually uses (Req 8.1, 8.2 / A8; design § Channel Mode
 * 2.3: *"`✅ native · ⚠️ degraded/conditional · ❌ unsupported → ModeCapabilityException`"*).
 *
 * ## Why the matrix is not a boolean
 *
 * `ChannelDriver::supports()` returns a `bool`, and that is the right shape for the
 * gate: a capability either may be attempted or must be refused before dispatch
 * (Property 21). But the matrix distinguishes **three** states, and collapsing the
 * middle one on the way in would throw away the only information the *later* gates
 * need:
 *
 * | Matrix cell | This enum | What the pipeline does with it |
 * |---|---|---|
 * | `✅` | `Native` | attempt it; no extra rule |
 * | `⚠️` | `Conditional` | attempt it **subject to a mode rule** — the 24-hour session window and the approved-template requirement (task 8.4), the provider's quality tier (task 8.1), a per-provider sub-matrix (task 7.4) |
 * | `❌` | `Unsupported` | `ModeCapabilityException` before any driver call (tasks 6.3, 7.2) |
 *
 * Read as a boolean via `isSupported()`, both `Native` and `Conditional` are "may be
 * attempted", which is exactly what `supports()` means. Keeping `Conditional` visible
 * is what lets `ON_PREMISE`'s `⚠️ 24h window` survive into task 8.4 instead of being
 * indistinguishable from `BAILEYS`'s unconditional `✅`.
 *
 * There is deliberately no fourth value and no "unknown": a capability whose support a
 * driver cannot determine until it reads credentials is `Conditional` — the condition
 * being "whatever the provider turns out to allow" — because an unknown that gated as
 * *supported* would be a silent grant, and one that gated as *unsupported* would break
 * a provider that in fact supports it.
 */
enum ChannelCapabilitySupport: string
{
    /** Works, with no rule beyond the ordinary send pipeline. */
    case Native = 'NATIVE';

    /** Works only under a mode/provider rule the pipeline must still enforce. */
    case Conditional = 'CONDITIONAL';

    /** Refused before dispatch, with `ModeCapabilityException`. */
    case Unsupported = 'UNSUPPORTED';

    /**
     * Whether the operation may be attempted at all — what `supports()` answers.
     */
    public function isSupported(): bool
    {
        return match ($this) {
            self::Native, self::Conditional => true,
            self::Unsupported => false,
        };
    }

    /**
     * Whether a further rule must be evaluated before the send is allowed.
     */
    public function isConditional(): bool
    {
        return $this === self::Conditional;
    }

    public function isNative(): bool
    {
        return $this === self::Native;
    }

    /**
     * This verdict as refined by a more specific layer — with one floor: an
     * `Unsupported` cell stays `Unsupported`, whatever the refinement says.
     *
     * Support is resolved in layers: the mode's matrix cell (§ 2.3), then a per-provider
     * sub-matrix (`BspProvider::declaredSupport()`), then the provider's live config at
     * runtime (task 7.4). A refinement is allowed to move a cell **either** way inside
     * "supported" — resolving `⚠️ per provider` to a definite `✅` for a partner known to
     * do it natively, or attaching a condition to a `✅` a provider actually restricts —
     * because that is precisely the "the driver reports each provider's real `supports()`"
     * the design asks for.
     *
     * What it may never do is make a refused capability attemptable. That single boundary
     * is what Property 21 rests on — no per-provider config can turn `❌ Groups` into a
     * dispatch — so it is enforced here, once, rather than re-argued by each refinement
     * layer.
     */
    public function refinedBy(self $refined): self
    {
        return match ($this) {
            self::Unsupported => self::Unsupported,
            self::Native, self::Conditional => $refined,
        };
    }
}
