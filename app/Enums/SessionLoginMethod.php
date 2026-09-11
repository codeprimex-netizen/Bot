<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a WhatsApp session pairs with its device (Req 2.1 / A2).
 *
 * The engine's `BridgeClient::provisionSession()` spells this as a `string`; it is an enum
 * here for one reason that matters at the boundary it crosses: the value is sent to a
 * separate process over HTTP, and a typo in a string literal would surface as a bridge
 * `422` at pairing time rather than as a type error at the call site.
 *
 * Both methods produce the same end state (`CONNECTED`) through different intermediate
 * ones, which is why the distinction lives on the *request* and not on
 * `SessionStatus`: `QR` parks in `QR_PENDING` until the code is scanned, while
 * `PAIRING_CODE` hands the tenant an 8-character code to type into WhatsApp on the phone
 * and goes straight to `CONNECTING` once it is accepted.
 */
enum SessionLoginMethod: string
{
    /** Scan a QR code shown in the panel. The default: it needs no phone number up front. */
    case Qr = 'QR';

    /** Type a short code into WhatsApp on the phone. Requires the number in advance. */
    case PairingCode = 'PAIRING_CODE';

    /**
     * Whether this method needs the session's phone number before pairing can start.
     *
     * `PAIRING_CODE` does — the code is issued *for* a number — so a provision request
     * that names this method without a phone is invalid, and the caller should be told
     * before a request reaches the bridge.
     */
    public function requiresPhone(): bool
    {
        return $this === self::PairingCode;
    }

    /**
     * The status a freshly provisioned session lands in under this method.
     *
     * `QR` waits for a human to scan (`QR_PENDING`); a pairing code is accepted or
     * rejected by the phone without an intermediate wait state of ours, so the session is
     * already `CONNECTING`.
     */
    public function initialStatus(): SessionStatus
    {
        return match ($this) {
            self::Qr => SessionStatus::QrPending,
            self::PairingCode => SessionStatus::Connecting,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Qr => 'QR code',
            self::PairingCode => 'Pairing code',
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
