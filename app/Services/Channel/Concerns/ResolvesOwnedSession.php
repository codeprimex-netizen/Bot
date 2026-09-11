<?php

declare(strict_types=1);

namespace App\Services\Channel\Concerns;

use App\Exceptions\Bridge\UnknownSessionException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\Session;
use Illuminate\Support\Str;

/**
 * Turn a bare session id into the row it names, proven to belong to the acting tenant — what
 * the **transport** half of `ChannelDriver` needs on a mode whose wire is not the Baileys
 * sidecar (Req 1.2, 1.3 / A1; Req 8.5 / A8; Correctness Properties 1, 23).
 *
 * ```php
 * public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
 * {
 *     $session = $this->ownedSession($sessionId);       // ownership first, before any request
 *     $credentials = $this->credentialsFor($session);   // …and before any decryption
 *     // …
 * }
 * ```
 *
 * ## Why the official drivers need this and `BaileysChannelDriver` does not
 *
 * `BridgeClient`'s eleven transport methods take a **session id**, not a `Session`, because
 * the sidecar knows nothing else — *"the Bridge never learns about tenants"*. Ownership for
 * that path is enforced one layer down by `TenantScopedBridgeClient`, the outermost decorator
 * of the composed `BridgeClient` chain, so `BaileysChannelDriver` inherits it by delegating
 * and adds nothing.
 *
 * An official driver has no such chain: it *is* the wire. So the same resolution has to happen
 * here, and it has to happen **first** — before credentials are decrypted and before a request
 * is made. Two things go wrong otherwise, and neither produces an error anywhere:
 *
 * 1. a foreign session id would resolve *this* caller's credentials and send from *this*
 *    tenant's number on behalf of a session it does not own;
 * 2. `ChannelCredentialStore::for()` is the layer that raises `CrossTenantAccessException`,
 *    and reaching it with the wrong tenant already in hand is too late — the tenant it is
 *    asked about would be the acting one, and the check would pass.
 *
 * ## The lookup *is* the enforcement
 *
 * Nothing is re-implemented here. `Session` uses `BelongsToTenant`, so `Session::query()` is
 * scoped by `TenantScope`, and `TenantScopedBuilder::find()` re-probes a miss without the
 * scope to turn a foreign id into a typed 403 rather than a bare 404 — which is what keeps an
 * ordinary typo (`UnknownSessionException`, 404) distinguishable in the logs an operator
 * watches for real isolation breaches. This trait exists so that lookup cannot be forgotten,
 * exactly as `TenantScopedBridgeClient::owned()` does one layer down for the bridge.
 *
 * Soft-deleted sessions are excluded by the model's own default scope: a deleted session's
 * credentials are being torn down, and sending from one would recreate state the platform has
 * already promised to remove.
 */
trait ResolvesOwnedSession
{
    /**
     * The session `$sessionId` names, proven to belong to the acting tenant.
     *
     * @throws UnknownSessionException when the id is not a ULID, or names no session (404)
     * @throws CrossTenantAccessException when the id names another tenant's session (403)
     */
    protected function ownedSession(string $sessionId): Session
    {
        $id = trim($sessionId);

        if (! Str::isUlid($id)) {
            // Refused before the query: every session id the platform issues is a ULID, so a
            // malformed one cannot match a row and does not deserve a round trip.
            throw UnknownSessionException::malformed($id);
        }

        $session = Session::query()->find($id);

        if ($session === null) {
            throw UnknownSessionException::forId($id);
        }

        return $session;
    }
}
