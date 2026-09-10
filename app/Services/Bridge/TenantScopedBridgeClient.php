<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Exceptions\Bridge\UnknownSessionException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\Session;
use App\Services\Tenancy\TenantStorage;
use Illuminate\Support\Str;

/**
 * The decorator that makes *"the Bridge never learns about tenants"* true (design.md
 * § Bridge multi-tenancy; Req 1.2, 1.3 / A1; Req 2.6 / A2; Correctness Property 1).
 *
 * design.md's sentence is short and does a lot of work:
 *
 * > *The Bridge stays logic-free. Sessions are already keyed by an opaque `sessionId` (ULID);
 * > the platform simply guarantees every `sessionId` maps to exactly one tenant. The Bridge
 * > never learns about tenants — PHP resolves tenant → session set and only ever asks the
 * > Bridge about session IDs the tenant owns.*
 *
 * The sidecar cannot enforce any part of that: it has no tenant column, no tenant context, and
 * nothing to check an id against. So the guarantee has to be **structural on the way in**, and
 * that is this class: it is bound as the outermost `BridgeClient` in the container, so *every*
 * session-addressed call resolves its id against the acting tenant's own rows before a byte
 * leaves the process. There is no second binding, no unguarded escape hatch, and nothing a
 * caller can pass that skips it.
 *
 * ```
 *   caller ──► TenantScopedBridgeClient  (ownership + auth-state path)   ◄── the container binding
 *                └── GuardedBridgeClient (per-session breaker + retry)
 *                      └── HttpBridgeClient (HTTP to the sidecar)
 * ```
 *
 * Ownership is the **outermost** layer on purpose: a foreign session id must be refused without
 * consuming a retry attempt, without touching the breaker, and above all without a request. A
 * check placed inside the guard would still deny the call, but it would have already told the
 * breaker that this tenant's traffic is failing.
 *
 * ## The three answers a session id can get
 *
 * | The id names… | Outcome | Why |
 * |---|---|---|
 * | a session the acting tenant owns | the call proceeds | the only case with a wire request |
 * | a session **another** tenant owns | `CrossTenantAccessException` (403) | `TenantScope` misses it, then `TenantOwnershipGuard` names it for what it is rather than returning a bare 404 |
 * | no session at all | `UnknownSessionException` (404) | an ordinary typo must not look like an isolation breach in the logs an operator watches for real ones |
 *
 * All three are produced by machinery that already exists: `Session` uses `BelongsToTenant`, so
 * `Session::query()->find()` is scoped, and `TenantScopedBuilder::find()` re-probes a miss to
 * turn a foreign id into the typed 403. This class adds no isolation logic of its own — it
 * simply makes that lookup unskippable.
 *
 * With no tenant bound at all, `TenantScope` fails closed with `MissingTenantContextException`
 * before this class decides anything. Platform mode (`TenantContext::asPlatform()`) removes the
 * scope, which is the audited Req 1.5 bypass and the only way the restore sweep can walk every
 * tenant's sessions — and it is announced on the event bus, so it is visible to the audit trail.
 *
 * ## Auth state is per tenant, derived and never accepted
 *
 * `provisionSession()`'s `$authStateDir` argument is **overwritten**, not defaulted: whatever a
 * caller passes is discarded and the directory is derived from the *resolved owner* through
 * `TenantStorage` (task 0.5's `wa_auth` disk, `0700`/`0600`, namespaced
 * `tenants/{tenantId}/{sessionId}`). Accepting a caller's path would reintroduce, one layer up,
 * exactly the cross-tenant reach the id resolution just closed — a caller could name its own
 * session and another tenant's credential directory. Deriving it means the path is a function of
 * the row, and there is no argument that can move it.
 *
 * The directory is created before the bridge is asked to use it, because the sidecar opens it
 * directly and a missing directory is an error there rather than here.
 */
final readonly class TenantScopedBridgeClient implements BridgeClient
{
    public function __construct(
        private BridgeClient $inner,
        private TenantStorage $storage,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle
    |--------------------------------------------------------------------------
    */

    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        $session = $this->owned($sessionId);

        // $authStateDir is deliberately ignored: the path belongs to the resolved owner, not to
        // the caller. See the class docblock.
        $this->storage->ensureAuthStateDirectory($session->tenant_id, $session->id);

        return $this->inner->provisionSession(
            $session->id,
            $method,
            $phone ?? $session->phone,
            $this->storage->authStateFullPath($session->tenant_id, $session->id),
        );
    }

    public function startSession(string $sessionId): void
    {
        $this->inner->startSession($this->owned($sessionId)->id);
    }

    public function stopSession(string $sessionId, bool $logout = false): void
    {
        $this->inner->stopSession($this->owned($sessionId)->id, $logout);
    }

    public function sessionState(string $sessionId): SessionStateDto
    {
        return $this->inner->sessionState($this->owned($sessionId)->id);
    }

    public function qr(string $sessionId): ?string
    {
        return $this->inner->qr($this->owned($sessionId)->id);
    }

    public function pairingCode(string $sessionId, string $phone): string
    {
        return $this->inner->pairingCode($this->owned($sessionId)->id, $phone);
    }

    /*
    |--------------------------------------------------------------------------
    | Messaging
    |--------------------------------------------------------------------------
    */

    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        return $this->inner->sendText($this->owned($sessionId)->id, $jid, $text, $opts);
    }

    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        return $this->inner->sendMedia($this->owned($sessionId)->id, $jid, $media, $opts);
    }

    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        $this->inner->sendPresence($this->owned($sessionId)->id, $jid, $presence);
    }

    public function checkNumbers(string $sessionId, array $numbers): array
    {
        return $this->inner->checkNumbers($this->owned($sessionId)->id, $numbers);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport health
    |--------------------------------------------------------------------------
    */

    /**
     * Passed straight through: the health of the sidecar process names no session and therefore
     * belongs to no tenant. It reveals nothing about anyone's data — only whether the platform's
     * own dependency is up.
     */
    public function isReachable(): bool
    {
        return $this->inner->isReachable();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The session row this id names, proven to belong to the acting tenant.
     *
     * The lookup is the enforcement. `Session` uses `BelongsToTenant`, so the query carries
     * `TenantScope`; `TenantScopedBuilder::find()` re-probes a miss without the scope and raises
     * `CrossTenantAccessException` when the row exists under someone else. Nothing about that is
     * re-implemented here — this method exists so the lookup cannot be forgotten.
     *
     * Soft-deleted sessions are excluded by the model's default `SoftDeletes` scope: a deleted
     * session's credentials are being torn down, and reaching the bridge for one would recreate
     * state the platform has already promised to remove.
     *
     * @throws CrossTenantAccessException when the id belongs to another tenant
     * @throws UnknownSessionException when the id belongs to no session, or is not a ULID
     */
    private function owned(string $sessionId): Session
    {
        $id = trim($sessionId);

        if (! Str::isUlid($id)) {
            // Refused before the query: a malformed id cannot match a row, and every session id
            // the platform issues is a ULID.
            throw UnknownSessionException::malformed($id);
        }

        $session = Session::query()->find($id);

        if ($session === null) {
            throw UnknownSessionException::forId($id);
        }

        return $session;
    }
}
