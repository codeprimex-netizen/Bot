<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a `channel_credentials` row may be used to send (Req 8.6, 8.13 / A8;
 * design § Channel Mode data model: `status(ACTIVE|INVALID|DISABLED)`).
 *
 * Three states, and the distinction between the last two is operational rather than
 * cosmetic: `INVALID` is the platform's verdict (the driver rejected the credentials),
 * `DISABLED` is the tenant's (it turned the mode off). Collapsing them would make
 * "your token stopped working" indistinguishable from "you switched this off", and only
 * the first of those needs telling anybody.
 *
 * The transitions belong to task 7.6, which validates credentials with the driver before
 * activating them and must retain the previous working row on failure. This enum states
 * which states may send and which may be selected; it writes nothing.
 */
enum ChannelCredentialStatus: string
{
    /** Validated and in use. */
    case Active = 'ACTIVE';

    /** The driver rejected these credentials — expired token, revoked key, wrong WABA. */
    case Invalid = 'INVALID';

    /** Switched off by the tenant. Retained so re-enabling does not mean re-entering secrets. */
    case Disabled = 'DISABLED';

    /**
     * The state a freshly entered credential row starts in.
     *
     * `ACTIVE` rather than a `PENDING` state design.md does not have: task 7.6 validates
     * *before* it writes, so a row that exists has already been checked, and inventing a
     * fourth state here would give the send gate a value with no defined behaviour.
     */
    public static function default(): self
    {
        return self::Active;
    }

    /**
     * Whether a send may use this row.
     */
    public function isUsable(): bool
    {
        return match ($this) {
            self::Active => true,
            self::Invalid, self::Disabled => false,
        };
    }

    /**
     * Whether the tenant should be told about this state.
     *
     * Only `INVALID`: credentials that stopped working break sends the tenant did not
     * choose to break (Req 8.13 — the mode becomes unselectable and the platform stays on
     * the `BAILEYS` default). A row the tenant disabled itself needs no notification.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::Invalid => true,
            self::Active, self::Disabled => false,
        };
    }
}
