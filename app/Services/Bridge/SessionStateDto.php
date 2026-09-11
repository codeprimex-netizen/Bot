<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeUnreachableException;
use Carbon\CarbonImmutable;

/**
 * The bridge's live view of one session (Req 2.1–2.5 / A2).
 *
 * ## This is a *report*, not the truth
 *
 * `sessions_wa.status` is the platform's state of record, for the reason
 * `SessionStatus` spells out: the bridge is a separate process that can restart, and every
 * PHP worker has to see the same answer. This DTO is what the bridge currently believes,
 * which is the input to a reconciliation (`SessionManager::markState()`, task 9.1) — and
 * that reconciliation is allowed to *refuse* what it reads here, because
 * `SessionStatus::canTransitionTo()` gates it.
 *
 * `disconnectReason` is the bridge's raw protocol reason (`loggedOut`,
 * `connectionReplaced`, `restartRequired`, …). It is carried as an unmapped string on
 * purpose: deciding whether a reason is retryable is `ReconnectPolicy`'s (task 9.2), and a
 * DTO that pre-judged it would put that policy in two places.
 */
final readonly class SessionStateDto
{
    /**
     * @param  string  $sessionId  the opaque ULID the platform issued
     * @param  SessionStatus  $status  what the bridge believes the connection state is
     * @param  string|null  $phone  the connected number in E.164 digits, once pairing revealed it
     * @param  string|null  $pushName  the WhatsApp display name of the connected account
     * @param  string|null  $deviceId  the multi-device slot identifier, for "replaced" diagnostics
     * @param  string|null  $disconnectReason  the protocol's own reason string, unmapped
     * @param  CarbonImmutable|null  $lastSeenAt  when the bridge last had traffic on this socket
     */
    public function __construct(
        public string $sessionId,
        public SessionStatus $status,
        public ?string $phone = null,
        public ?string $pushName = null,
        public ?string $deviceId = null,
        public ?string $disconnectReason = null,
        public ?CarbonImmutable $lastSeenAt = null,
    ) {}

    /**
     * Whether the bridge holds a live socket for this session.
     */
    public function isOnline(): bool
    {
        return $this->status->isOnline();
    }

    /**
     * Read a decoded bridge response body.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BridgeUnreachableException when the body carries no state this contract knows
     */
    public static function fromPayload(string $operation, string $sessionId, array $payload): self
    {
        $status = SessionStatus::tryFromName(BridgeWire::stringOrNull($payload['status'] ?? null));

        if ($status === null) {
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'no recognised "status" in the session-state response',
            );
        }

        return new self(
            sessionId: $sessionId,
            status: $status,
            phone: BridgeWire::stringOrNull($payload['phone'] ?? null),
            pushName: BridgeWire::stringOrNull($payload['push_name'] ?? null),
            deviceId: BridgeWire::stringOrNull($payload['device_id'] ?? null),
            disconnectReason: BridgeWire::stringOrNull($payload['disconnect_reason'] ?? null),
            lastSeenAt: BridgeWire::timestampOrNull($payload['last_seen_at'] ?? null),
        );
    }
}
