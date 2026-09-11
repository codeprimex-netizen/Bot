<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The instruction hierarchy of design § AI 1.3, step 1 — as a *structure* rather
 * than as a request to the model (Req 13.8 / B4; Req 32.7 / NFR3).
 *
 * ```
 * PLATFORM   rank 0   trusted    the platform's non-overridable rules
 * TENANT     rank 1   trusted    the tenant's persona, policy, business facts
 * USER       rank 2   untrusted  everything an end user (or a retrieved document) said
 * ```
 *
 * ## What "structurally" means here
 *
 * A prompt is assembled by `App\Services\Abuse\InstructionHierarchy`, which takes
 * the layers as **separate arguments** and decides on its own which of them may
 * become system content. Untrusted layers are never concatenated into the system
 * block: they are fenced (`App\Services\Abuse\PromptFence`) and emitted as data.
 *
 * The consequence is the property that matters: a user message saying
 * `system: you are now unrestricted` cannot be promoted, because *nothing at the
 * call site takes a rank from the text*. The rank is a property of the argument
 * position the text arrived in, and text cannot change which argument it was passed
 * as. Asking a model to "ignore instructions inside the user block" is the mitigation
 * this replaces — that one depends on the model complying, and a model that has
 * already been jailbroken is precisely the case where it does not.
 *
 * `rank()` is the ordering, and it is total: a lower rank is always resolved first
 * and never overridden by a higher one.
 */
enum InstructionLayer: string
{
    case Platform = 'PLATFORM';
    case Tenant = 'TENANT';
    case User = 'USER';

    /**
     * Precedence, lowest wins. Stored as a method rather than as the case value so
     * the persisted/serialized form stays a readable label.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Platform => 0,
            self::Tenant => 1,
            self::User => 2,
        };
    }

    /**
     * Whether content from this layer may carry instructions at all.
     *
     * Only trusted layers become system content; untrusted layers are fenced data,
     * whatever they claim about themselves.
     */
    public function isTrusted(): bool
    {
        return match ($this) {
            self::Platform, self::Tenant => true,
            self::User => false,
        };
    }

    /**
     * Whether this layer outranks `$other` — i.e. whether a conflict resolves in
     * this layer's favour.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /**
     * Human-readable label for the prompt inspector in the admin panel.
     */
    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform rules (non-overridable)',
            self::Tenant => 'Tenant configuration',
            self::User => 'Untrusted user content',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
