<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\Security\KeyUnavailableException;
use App\Models\Tenant;

/**
 * HMAC signing secrets with **dual-secret rotation**: exactly one secret signs, several
 * may verify (Req 32.6 / NFR3; design § Key rotation; STRIDE rows "Bridge → webhook"
 * and "Gateway → webhook").
 *
 * ## The problem this interface exists to solve
 *
 * A webhook secret is shared with a peer, and the two of you cannot swap it at the same
 * instant. Rotate naively — replace the secret, verify against the new one only — and
 * every request the peer signed with the old secret, including everything already in
 * flight and everything it will send until it picks the new one up, fails HMAC
 * verification. On an inbound webhook path that is an outage, and the pressure it
 * creates ("just skip verification for a minute") is precisely the spoofing the
 * signature exists to prevent.
 *
 * So rotation has an **overlap window**:
 *
 * ```
 *              rotate()                              accepted_until
 *                 │                                        │
 *  v1 signs ──────┼── v1 verifies only ─────────────────────┤ v1 refused
 *                 │                                        │
 *                 └── v2 signs, and verifies ──────────────┴──▶
 * ```
 *
 * - `sign()` uses the newest secret, always. Only one secret is ever handed out.
 * - `verify()` accepts **any** secret inside its window — that is the "dual" part.
 * - Past its window a secret is refused *by the clock*, whether or not the purge sweep
 *   has deleted its row yet. A window that closed only when a job ran would be a window
 *   an unrun job silently widens.
 *
 * ## Scopes
 *
 * A scope names what the secret protects and is opaque here — each subsystem owns its
 * own naming, and the string is what carries the tenant/session identity:
 *
 * | Scope | Owner |
 * |---|---|
 * | `bridge:session:{sessionId}` | per-session bridge webhook HMAC (task 7.x) |
 * | `gateway:{slug}` | payment-gateway webhook signature (task 10.x) |
 * | `tenant:{tenantId}:api` | outbound webhooks the platform signs for a tenant |
 *
 * ## Storage and fail-closed behaviour
 *
 * Secrets are sealed by `KeyWrapper` — the same KMS seam DEKs use — bound by additional
 * authenticated data to `scope|version`, so a row copied into another scope does not
 * open. Nothing here logs, returns in an exception, or serialises secret material;
 * `currentSecret()` is the single deliberate exit, for handing the secret to the peer at
 * registration time.
 *
 * `sign()` fails closed (`KeyUnavailableException`, 503) when no secret can be obtained.
 * `verify()` never throws for an unknown scope or an unreadable secret: it returns
 * `false`. An inbound signature is untrusted input, and letting it choose between "403"
 * and "503" hands an attacker a probe — and letting it raise at all would turn a
 * malformed header into an error-page.
 */
interface SigningSecretStore
{
    /**
     * Sign a payload with the scope's **newest** secret.
     *
     * Returns the signature in the configured presentation form — by default
     * `sha256=<hex>`, the shape Meta and most gateways use — so a caller can put it
     * straight into a header.
     *
     * The scope's first secret is issued on demand, for the same reason
     * `FieldCipher::encrypt` provisions a lineage on first use: refusing would make
     * signing depend on whether provisioning happened to have run, and a hard failure
     * on a write path is what grows "temporarily unsigned" branches elsewhere. Use
     * `provision()` when the tenant attribution matters.
     *
     * @throws KeyUnavailableException when no secret can be issued or opened
     */
    public function sign(string $scope, string $payload): string;

    /**
     * Whether `$signature` is a valid signature of `$payload` under **any** secret of
     * `$scope` that is still inside its window.
     *
     * Accepts the signature with or without its `sha256=` prefix, compares in constant
     * time, and never short-circuits on the first candidate — a timing difference
     * between "wrong secret" and "wrong signature" would leak which version the peer
     * used.
     *
     * Returns `false` — never throws — for an unknown scope, an empty signature, a
     * secret whose window has closed, or a secret that cannot be opened.
     */
    public function verify(string $scope, string $payload, string $signature): bool;

    /**
     * The scope's current secret in plaintext, for handing to the peer that must sign
     * with it (bridge session registration, a gateway's dashboard field).
     *
     * The one sanctioned way secret material leaves this store. Callers must not log
     * it, must not store it, and must show it exactly once.
     *
     * @throws KeyUnavailableException when no secret can be issued or opened
     */
    public function currentSecret(string $scope): string;

    /**
     * Create the scope's first secret, or return the existing signer's version
     * (idempotent).
     *
     * @param  Tenant|string|null  $tenant  attribution for the row; null for platform-owned secrets
     * @return int the signing version
     *
     * @throws KeyUnavailableException when the key store cannot seal a new secret
     */
    public function provision(string $scope, Tenant|string|null $tenant = null): int;

    /**
     * Rotate the scope: mint a new signing secret and keep the previous one acceptable
     * for `$overlapSeconds` (default `wa.security.hmac.overlap_hours`).
     *
     * Non-destructive by construction — the previous secret is given a deadline, not
     * deleted — so a peer that has not yet picked up the new secret keeps working until
     * the window closes.
     *
     * @throws KeyUnavailableException when a new secret cannot be sealed; the previous
     *                                 one is then left signing, untouched
     */
    public function rotate(string $scope, ?int $overlapSeconds = null): SigningSecretRotation;

    /**
     * Scopes whose signing secret is older than `wa.security.hmac.rotate_after_days` —
     * what the scheduled command rotates.
     *
     * @param  int  $limit  most scopes to return, so one sweep cannot rotate the world
     * @return list<string>
     */
    public function dueForRotation(int $limit): array;

    /**
     * Delete secrets whose overlap window closed (they are already refused by
     * `verify()`; this reclaims the rows).
     *
     * @return int rows deleted
     */
    public function purgeExpired(): int;

    /**
     * Drop every opened secret this instance is holding in memory.
     *
     * Implementations cache opened secrets for one request or job, so verification of a
     * batch costs one key-store round trip rather than one per candidate. This shortens
     * that window explicitly — after a rotation, or after a long-running command has
     * finished with a scope.
     */
    public function forgetSecrets(): void;
}
