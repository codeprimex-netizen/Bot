<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Tenancy\CrossTenantAccessException;

/**
 * The **transport contract** for talking WhatsApp — one boundary, one direction, no
 * decisions (design.md § Channel Mode 2.1: *"`BridgeClient` becomes the transport contract
 * that every channel driver implements"*; Req 8.1 / A8; Req 33.1 / NFR4; engine Req 35).
 *
 * ```php
 * // Resolved from the container, this is always the tenant-scoped, breaker-guarded chain.
 * $bridge = app(BridgeClient::class);
 *
 * $bridge->startSession($session->id);
 * $receipt = $bridge->sendText($session->id, '9198…@s.whatsapp.net', 'Hi');
 * ```
 *
 * ## What "transport" means here, and why the method list is this short
 *
 * Every method below is **one protocol operation over the wire**: it names a session, states
 * what to do, and returns what the far end said. None of them decides *whether* the operation
 * should happen. That line is what makes the contract extensible in the direction Phase 5
 * needs — `interface ChannelDriver extends BridgeClient` (task 6.2) adds precisely the things
 * this interface refuses to know about:
 *
 * | Belongs to `ChannelDriver`, not here | Why it is not transport |
 * |---|---|
 * | `mode()` | which backend a session uses is a routing fact; the wire does not vary by it |
 * | `supports(ChannelCapability)` | a *declaration* about a backend, answered without any I/O |
 * | `requiresAntiBan()` | a policy input for the send gate, not an operation |
 * | `send(Session, OutboundContent)` | the pipeline's send — quota, opt-out, anti-ban, idempotency — built *on top of* `sendText`/`sendMedia` |
 * | `sendTemplate()` | approved-template semantics that only official modes have |
 * | `parseWebhook()` | inbound *parsing*, the opposite direction, and provider-specific |
 * | `register()`, `healthCheck(ChannelCredentials)` | credential-aware onboarding; this contract has no notion of credentials |
 *
 * The single test for a method belonging here: *could the Node sidecar carry it out knowing
 * nothing but a session id and the arguments?* Everything below passes it; nothing in the
 * table does.
 *
 * ## The bridge never learns about tenants
 *
 * Sessions are keyed by an opaque ULID and the platform guarantees each one maps to exactly
 * one tenant (`sessions_wa.tenant_id`). The sidecar is therefore asked only about session ids
 * and knows nothing else — which is only a guarantee if a caller cannot name an id it does
 * not own. That check is **structural and on the way in**: the container binds
 * `TenantScopedBridgeClient` as the outermost decorator, so every session-addressed call
 * resolves its id against the acting tenant's own rows first and raises
 * `CrossTenantAccessException` (403) before a byte leaves the process. There is no code path
 * that reaches the wire without it, and none that could be fixed by a check inside the
 * sidecar.
 *
 * ## Failure is typed, never silent
 *
 * Two exceptions, and the difference between them is *who knows something*:
 *
 * - `BridgeUnreachableException` (503) — the bridge never answered. The outcome is **unknown**,
 *   so it is classified `ErrorClass::Bridge`: retryable, jittered, kept. A transport failure is
 *   never a false "sent".
 * - `BridgeRequestFailedException` — the bridge answered *no*, carrying its status and error
 *   code. `BridgeErrorClassifier` maps that pair onto an `ErrorClass`, so a `401` is not
 *   retried, a `409 session_not_connected` is, and a `422 not_on_whatsapp` fails fast.
 *
 * No method returns `null`, `false`, or an empty result to mean "it did not work". The only
 * nullable return is `qr()`, where `null` is a real answer (this session is not showing a QR).
 *
 * ## Implementations
 *
 * | Class | Role |
 * |---|---|
 * | `HttpBridgeClient` | the wire: JSON over HTTP to the Node + Baileys sidecar |
 * | `GuardedBridgeClient` | circuit breaker + bounded inline retry around any of them |
 * | `TenantScopedBridgeClient` | ownership resolution and per-tenant auth-state paths |
 * | `Tests\Fixtures\Bridge\FakeBridgeClient` | deterministic in-memory double, **test-only** |
 *
 * The fake lives under `tests/`, which only `autoload-dev` maps, so it is not autoloadable in
 * a production install at all (Property 28 / Req 36.2).
 */
interface BridgeClient
{
    /*
    |--------------------------------------------------------------------------
    | Session lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * Create the session on the bridge and begin pairing.
     *
     * Idempotent by session id: provisioning an id the bridge already holds returns its
     * current pairing state rather than resetting it, because the retry of a timed-out
     * provision must not throw away a QR the tenant is already looking at.
     *
     * `$authStateDir` is the absolute directory the bridge persists this session's Baileys
     * credentials in. **Callers should not pass it**: `TenantScopedBridgeClient` derives it
     * from the resolved owner via `TenantStorage` and overwrites whatever it was given, so a
     * caller cannot aim one tenant's session at another tenant's auth state. It is on the
     * signature only because the transport has to put it on the wire.
     *
     * @param  string|null  $phone  E.164 digits; required when $method->requiresPhone()
     * @param  string|null  $authStateDir  absolute auth-state directory; supplied by the scoped decorator
     *
     * @throws CrossTenantAccessException when $sessionId is not the acting tenant's
     * @throws BridgeRequestFailedException when the bridge refuses (e.g. a pairing method it cannot serve)
     * @throws BridgeUnreachableException when the bridge cannot be reached
     */
    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto;

    /**
     * Bring an existing session online, reusing its stored credentials.
     *
     * The post-deploy and scheduled restore paths (`SessionManager::restoreAll()`, task 9.1)
     * are built out of this, so it is idempotent: starting an already-connected session is a
     * no-op rather than a reconnect, which would drop a working socket.
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function startSession(string $sessionId): void;

    /**
     * Take a session offline.
     *
     * `$logout = false` closes the socket and **keeps** the credentials, so the session can be
     * restarted without re-pairing. `$logout = true` unlinks the device on WhatsApp's side and
     * makes the credentials void — the difference between `CLOSED` (restartable) and
     * `LOGGED_OUT` (terminal), and it is destructive, which is why it is an explicit argument
     * and not inferred from anything.
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function stopSession(string $sessionId, bool $logout = false): void;

    /**
     * What the bridge currently believes about this session.
     *
     * A *report*, not the state of record: `sessions_wa.status` is the truth, and
     * `SessionManager::markState()` decides whether to accept what this says (see
     * `SessionStatus::canTransitionTo()`).
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function sessionState(string $sessionId): SessionStateDto;

    /**
     * The current pairing QR as a base64 PNG, or null when there is none to show.
     *
     * `null` is a legitimate answer — the session is already paired, or has not reached
     * `QR_PENDING` yet — and is the one place in this contract where a null return does not
     * mean a failure.
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function qr(string $sessionId): ?string;

    /**
     * Issue a pairing code for $phone — the alternative to scanning a QR.
     *
     * Always returns a code or throws: an empty string would be displayed to a tenant as a
     * code they could type.
     *
     * @param  string  $phone  E.164 digits, no separators
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function pairingCode(string $sessionId, string $phone): string;

    /*
    |--------------------------------------------------------------------------
    | Messaging
    |--------------------------------------------------------------------------
    */

    /**
     * Put one text message on the wire.
     *
     * This is the *transport*, not "the send": plan gating, quota, opt-out, anti-ban pacing
     * and idempotency all live in the send pipeline (`tenantSendGate`, task 9.3) which calls
     * this once it has decided. Nothing here consults any of them, and nothing here may be
     * used to bypass them — the pipeline is the only intended caller.
     *
     * @param  string  $jid  the fully-qualified recipient (`…@s.whatsapp.net`, `…@g.us`)
     * @param  array<string, mixed>  $opts  protocol-level options the sidecar understands
     *                                      (quoted message, link preview, mentions, ephemeral ttl)
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto;

    /**
     * Put one media message on the wire.
     *
     * @param  array<string, mixed>  $opts  as `sendText()`
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto;

    /**
     * Publish a presence update on a chat — "typing…", "recording…", online, or a stop.
     *
     * Carries no content, is never persisted, and is not a send: it consumes no quota and
     * produces no message row. It is here because the anti-ban engine's typing simulation
     * (task 9.6) and the live-agent inbox are built out of it.
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void;

    /**
     * Ask the protocol which of $numbers have WhatsApp accounts (`onWhatsApp`).
     *
     * The result is keyed by the number **exactly as it was asked**, so a caller can correlate
     * answers with its own input without re-normalising. A number the protocol declined to
     * answer for comes back as `NumberCheck::isUnknown()` rather than as "not on WhatsApp" —
     * see that class for why collapsing the two would permanently mislabel real numbers.
     *
     * @param  list<string>  $numbers  E.164 digits
     * @return array<array-key, NumberCheck> keyed by the number as supplied. `array-key` rather
     *                                       than `string` because PHP silently coerces an
     *                                       all-digit array key to an integer — `$checks[$number]`
     *                                       resolves either way (the coercion applies to reads
     *                                       too), but `array_keys()` will show integers for
     *                                       numbers written without a `+`
     *
     * @throws CrossTenantAccessException|BridgeRequestFailedException|BridgeUnreachableException
     */
    public function checkNumbers(string $sessionId, array $numbers): array;

    /*
    |--------------------------------------------------------------------------
    | Transport health
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the bridge process itself is answering (Req 2.9 / A2).
     *
     * The one method that reports a failure as a value instead of an exception, because that
     * *is* its question: a health probe that threw when the thing was unhealthy would have to
     * be wrapped in a `try` by every caller, and the health dashboard would show an error
     * where it means to show a red light. Session-independent — it says nothing about whether
     * any particular session is connected.
     */
    public function isReachable(): bool;
}
