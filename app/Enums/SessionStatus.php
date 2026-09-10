<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Connection state of one WhatsApp session — the **reused engine enum**, unchanged
 * (design.md § Data Models: *"Reused engine enums (`SessionStatus`, `MessageStatus`,
 * `WaStatus`) are unchanged"*; Req 2.1–2.5 / A2).
 *
 * The twelve cases and the machine below come verbatim from the single-tenant engine's
 * design (`.kiro/specs/whatsapp-auto-messenger/design.md` § SessionManager). Multi-tenancy
 * changes nothing here: a session belongs to exactly one tenant, so its connection state
 * is a per-row fact and never a per-tenant one.
 *
 * ```
 *         create()
 *            │
 *            ▼
 *      INITIALIZING ──────► QR_PENDING ──5 failed attempts──► QR_TIMEOUT
 *            │                   │
 *            │              scan success
 *            ▼                   ▼
 *        CONNECTING ────────► CONNECTED ──────► CLOSING ──► CLOSED
 *            ▲                   │
 *            │            unexpected close
 *            │                   ▼
 *            └──── RECONNECTING ─┼─ reason=loggedOut ──────► LOGGED_OUT (terminal)
 *                      │         ├─ reason=replaced  ──────► REPLACED   (terminal)
 *                      │         └─ risk threshold   ──────► THROTTLED
 *                      │
 *               backoff exhausted ──► FAILED
 * ```
 *
 * ## Why the state lives in MySQL and the machine lives here
 *
 * `sessions_wa.status` is the truth, not the bridge's memory: any PHP process — a web
 * request, a queue worker, the reconnect sweep — has to see the same answer, and the
 * bridge is a separate OS process that can restart underneath all of them.
 *
 * The consequence is that state changes arrive **out of order**. A webhook announcing
 * `CONNECTED` can be delivered after the one announcing `RECONNECTING`; a `CLOSED` can
 * arrive for a session that has already been restarted. `allowedNext()` is what turns
 * those into a loud refusal instead of a silently corrupted row, which is why the map is
 * data on the enum rather than a set of `if`s in whatever code happens to receive the
 * event.
 *
 * ## What this enum does *not* do
 *
 * It does not write anything and it does not throw. `SessionManager::markState()`
 * (task 9.1) is the single writer, and it is the thing that raises on an illegal
 * transition; `ReconnectPolicy` (task 9.2) decides *whether* a disconnect reason leads to
 * `RECONNECTING` or straight to a terminal state. Keeping the map here and the enforcement
 * there means the legality of a transition is stated once, and every writer — the webhook
 * intake, a console command, a test — is held to the same statement.
 */
enum SessionStatus: string
{
    /** A row exists and its auth-state directory is being prepared; nothing is on the wire yet. */
    case Initializing = 'INITIALIZING';

    /** A QR code is available and waiting to be scanned. */
    case QrPending = 'QR_PENDING';

    /** The QR was re-issued too many times without a scan; the pairing attempt is abandoned. */
    case QrTimeout = 'QR_TIMEOUT';

    /** Credentials exist and the socket is being established. */
    case Connecting = 'CONNECTING';

    /** Paired and online — the only state in which a send can succeed. */
    case Connected = 'CONNECTED';

    /** Dropped for a retryable reason; the backoff loop is working on it. */
    case Reconnecting = 'RECONNECTING';

    /** Online but held back by the anti-ban risk gate (task 9.6). */
    case Throttled = 'THROTTLED';

    /** A deliberate shutdown is in progress. */
    case Closing = 'CLOSING';

    /** Stopped on purpose, credentials retained — restartable. */
    case Closed = 'CLOSED';

    /** The device was unlinked on the phone. Terminal: the credentials are void. */
    case LoggedOut = 'LOGGED_OUT';

    /** Another client took over this device slot. Terminal for the same reason. */
    case Replaced = 'REPLACED';

    /** The reconnect budget was exhausted. Restartable, but only by an explicit act. */
    case Failed = 'FAILED';

    /**
     * States this state may legally move into.
     *
     * Three edges deserve their reasoning written down, because they are the ones a
     * reader is most likely to think are wrong:
     *
     * - **`QR_TIMEOUT -> QR_PENDING`.** Abandoning a pairing attempt is not abandoning the
     *   session: the tenant presses "show me a new code" and the same row pairs. Forcing a
     *   new row would orphan the auth-state directory and the session's name.
     * - **`CLOSED -> INITIALIZING` and `FAILED -> INITIALIZING`.** A restart re-prepares
     *   auth state and re-enters the machine at the top, so it is the same edge in both
     *   cases; `FAILED` is *recoverable* (the network came back) while `LOGGED_OUT` and
     *   `REPLACED` are not (the credentials are void), and that is the whole difference
     *   between them.
     * - **`CONNECTING -> LOGGED_OUT` / `REPLACED`.** A stored credential that the server
     *   rejects announces itself on the first connect, not after a disconnect, so the
     *   terminal states have to be reachable from here or a void credential would loop
     *   through `RECONNECTING` until the budget ran out.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Initializing => [self::QrPending, self::Connecting, self::Closing, self::Closed, self::Failed],
            self::QrPending => [self::QrTimeout, self::Connecting, self::Closing, self::Closed, self::Failed],
            self::QrTimeout => [self::QrPending, self::Closing, self::Closed, self::Failed],
            self::Connecting => [
                self::Connected, self::Reconnecting, self::LoggedOut,
                self::Replaced, self::Closing, self::Closed, self::Failed,
            ],
            self::Connected => [
                self::Throttled, self::Reconnecting, self::LoggedOut,
                self::Replaced, self::Closing, self::Closed,
            ],
            self::Reconnecting => [
                self::Connecting, self::Connected, self::Throttled, self::LoggedOut,
                self::Replaced, self::Closing, self::Closed, self::Failed,
            ],
            self::Throttled => [
                self::Connected, self::Reconnecting, self::LoggedOut,
                self::Replaced, self::Closing, self::Closed, self::Failed,
            ],
            self::Closing => [self::Closed, self::Failed],
            self::Closed, self::Failed => [self::Initializing],
            self::LoggedOut, self::Replaced => [],
        };
    }

    /**
     * Whether moving from this state to $to is permitted.
     *
     * A no-op transition is always permitted, so a redelivered webhook announcing the state
     * a session is already in is idempotent rather than an error — at-least-once delivery
     * makes that redelivery normal, not exceptional.
     */
    public function canTransitionTo(self $to): bool
    {
        return $this === $to || in_array($to, $this->allowedNext(), true);
    }

    /**
     * A terminal state has no outgoing transitions: the stored credentials are void and
     * the session can only be replaced, never restarted.
     */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * Whether the bridge holds a live socket for this session.
     *
     * `THROTTLED` is connected: the anti-ban gate is holding *sends* back, and a session
     * that dropped its socket every time it was throttled would re-pair constantly.
     */
    public function isOnline(): bool
    {
        return match ($this) {
            self::Connected, self::Throttled => true,
            default => false,
        };
    }

    /**
     * Whether a send may be attempted on a session in this state.
     *
     * Narrower than `isOnline()` on purpose: `THROTTLED` means "online, and deliberately
     * not sending". The anti-ban gate (task 9.6) is what clears it, so this predicate must
     * not quietly let a throttled session through.
     */
    public function canSend(): bool
    {
        return $this === self::Connected;
    }

    /**
     * Whether the session is mid-pairing and the tenant is expected to be looking at a
     * QR code or a pairing code.
     */
    public function isPairing(): bool
    {
        return match ($this) {
            self::Initializing, self::QrPending, self::QrTimeout => true,
            default => false,
        };
    }

    /**
     * Human-readable label for panels and the error dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Initializing => 'Initializing',
            self::QrPending => 'Waiting for QR scan',
            self::QrTimeout => 'QR scan timed out',
            self::Connecting => 'Connecting',
            self::Connected => 'Connected',
            self::Reconnecting => 'Reconnecting',
            self::Throttled => 'Throttled',
            self::Closing => 'Closing',
            self::Closed => 'Closed',
            self::LoggedOut => 'Logged out on device',
            self::Replaced => 'Replaced by another client',
            self::Failed => 'Failed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Every state a live socket may be in — the set the pool query and the reconnect sweep
     * select on.
     *
     * @return list<self>
     */
    public static function online(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isOnline()));
    }

    /**
     * The state named by $value, or null when it names none.
     *
     * Used when reading a status off the wire: a bridge running a newer build than this
     * PHP process must not be able to crash the intake path with a state name we do not
     * know, so the caller decides what an unrecognised value means.
     */
    public static function tryFromName(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtoupper(trim($value)));
    }
}
