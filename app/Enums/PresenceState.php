<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A presence update the platform may publish on a chat — the closed set behind
 * `BridgeClient::sendPresence()`.
 *
 * Presence is a *transport* concern rather than a messaging one: it carries no content, is
 * never persisted, and is not a send (it consumes no quota and produces no message row).
 * It exists on the bridge contract because the anti-ban engine's typing simulation
 * (task 9.6) is built out of it — a human types before a message arrives — and because the
 * live-agent inbox marks a conversation as being read.
 *
 * The values are the WhatsApp protocol's own. They are an enum rather than strings so the
 * anti-ban engine and a channel driver cannot disagree about the spelling of `composing`,
 * which would be a silently ineffective typing simulation rather than an error.
 */
enum PresenceState: string
{
    /** Online, not typing. */
    case Available = 'available';

    /** Offline / last-seen hidden. */
    case Unavailable = 'unavailable';

    /** The "typing…" indicator. */
    case Composing = 'composing';

    /** The "recording audio…" indicator. */
    case Recording = 'recording';

    /** Stop whatever indicator is showing, without going offline. */
    case Paused = 'paused';

    /**
     * Whether this state shows an activity indicator that WhatsApp expires on its own.
     *
     * The typing simulation has to re-publish these to keep the indicator alive across a
     * long delay, which is the one behavioural difference between them and the plain
     * online/offline pair.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::Composing, self::Recording => true,
            self::Available, self::Unavailable, self::Paused => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Online',
            self::Unavailable => 'Offline',
            self::Composing => 'Typing',
            self::Recording => 'Recording audio',
            self::Paused => 'Stopped typing',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
