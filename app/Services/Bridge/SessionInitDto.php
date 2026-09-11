<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeUnreachableException;

/**
 * What the bridge hands back when a session is provisioned: the state it landed in, and
 * whichever pairing artifact that state implies (Req 2.1 / A2).
 *
 * Exactly one of `qr` / `pairingCode` is normally populated — a QR login yields an image, a
 * pairing-code login yields a code — but the DTO does not enforce that, because the bridge
 * is the authority on what it actually produced and a mismatch is information the session
 * screen should show rather than an exception that hides it.
 */
final readonly class SessionInitDto
{
    /**
     * @param  string  $sessionId  the opaque ULID the platform issued; the bridge only ever knows this
     * @param  SessionStatus  $status  the state the bridge reports the session is in now
     * @param  string|null  $qr  base64 PNG of the pairing QR, when there is one
     * @param  string|null  $pairingCode  the short code the tenant types into WhatsApp, when there is one
     */
    public function __construct(
        public string $sessionId,
        public SessionStatus $status,
        public ?string $qr = null,
        public ?string $pairingCode = null,
    ) {}

    /**
     * Read a decoded bridge response body.
     *
     * An unrecognised or absent `status` is a malformed response rather than a default:
     * defaulting it would let a bridge that failed to start a session look like one that
     * started it, and the whole point of the typed transport is that it cannot.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BridgeUnreachableException when the body is not the shape this contract requires
     */
    public static function fromPayload(string $operation, string $sessionId, array $payload): self
    {
        $status = SessionStatus::tryFromName(BridgeWire::stringOrNull($payload['status'] ?? null));

        if ($status === null) {
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'no recognised "status" in the provision response',
            );
        }

        return new self(
            sessionId: $sessionId,
            status: $status,
            qr: BridgeWire::stringOrNull($payload['qr'] ?? null),
            pairingCode: BridgeWire::stringOrNull($payload['pairing_code'] ?? null),
        );
    }
}
