<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way anything gets written to — or verified in — the audit trail
 * (Req 24.2, 24.5 / D1; Req 34.1 / NFR5; Correctness Property 17).
 *
 * ## Writing
 *
 * The call at a business call site is meant to be one line, with everything that can
 * be inferred inferred: the actor from the authenticated request, the tenant from the
 * subject or the acting context, the correlation ids from the current request, the
 * redaction and the hashing from the service itself.
 *
 * ```php
 * // task 21.5 — audited group settings toggle
 * $audit->write('group.settings.changed', ['field' => 'announcement', 'from' => false, 'to' => true], $group);
 *
 * // task 30.5 — impersonation: the admin is the actor, the user is the subject
 * $audit->write('user.impersonated', ['expires_at' => $expiry], $user, AuditActor::admin($admin));
 *
 * // billing, acting for a tenant nobody is currently bound to
 * $audit->write('wallet.topped_up', ['amount_micros' => $amount], $wallet, tenant: $tenant);
 *
 * // platform-wide, no tenant involved (feature flags, plans, the Req 1.5 bypass)
 * $audit->writeForPlatform('plan.limits.changed', ['plan' => $plan->key, 'limits' => $limits]);
 * ```
 *
 * Payloads are **redacted, never raw**: phone numbers masked, message bodies reduced
 * to content hashes, secret-looking keys dropped. Callers should still pass small,
 * meaningful diffs (`from`/`to`) rather than whole models — an audit row is evidence
 * of a decision, not a copy of a table.
 *
 * ## Verifying
 *
 * `verify()` walks one chain and reports the first break precisely enough to act on.
 * It never throws on a broken chain: tampering is a finding to surface (Admin audit
 * viewer, task 30.6; nightly integrity job), not an exception to swallow.
 *
 * ```php
 * $result = $audit->verify($tenant);        // this tenant's chain
 * $result = $audit->verify();               // the platform chain
 * $result = $audit->verify($tenant, $yesterdaysAnchor);   // + tail-truncation check
 * ```
 */
interface AuditService
{
    /**
     * Append one entry and return it.
     *
     * The chain is chosen in this order, first match winning: an explicit `$tenant`,
     * the subject's `tenant_id` if it is a tenant-owned row, the tenant bound to the
     * current context, and otherwise the platform chain. This is what lets an admin
     * action performed in platform mode still land on the affected tenant's chain,
     * where its history belongs.
     *
     * @param  string  $action  dotted, past tense, stable: `tenant.suspended`, `plan.limits.changed`
     * @param  array<array-key, mixed>  $payload  small and diff-shaped; redacted before storage
     * @param  Model|AuditSubject|string|null  $subject  what the action was about
     * @param  AuditActor|null  $actor  defaults to the authenticated actor, or `System`
     * @param  Tenant|string|null  $tenant  force the chain (billing/console callers that act *for* a tenant)
     *
     * @throws \App\Exceptions\Audit\AuditChainBusyException when the entry could not be appended
     * @throws \App\Exceptions\Audit\AuditPayloadException when the payload cannot be canonicalized
     */
    public function write(
        string $action,
        array $payload = [],
        Model|AuditSubject|string|null $subject = null,
        ?AuditActor $actor = null,
        Tenant|string|null $tenant = null,
    ): AuditLog;

    /**
     * Append one entry to the **platform** chain regardless of the acting context.
     *
     * For actions that are not a tenant's history: plan/flag/settings changes, admin
     * logins, and the audited `actingAsPlatform()` bypass of Req 1.5.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function writeForPlatform(
        string $action,
        array $payload = [],
        Model|AuditSubject|string|null $subject = null,
        ?AuditActor $actor = null,
    ): AuditLog;

    /**
     * Verify one chain: a tenant's, or the platform chain when `$tenant` is null
     * (mirroring `tenant_id IS NULL` in the table).
     *
     * @param  AuditChainAnchor|null  $anchor  a previously exported tip, to detect entries
     *                                         removed from the *end* of the chain
     */
    public function verify(Tenant|string|null $tenant = null, ?AuditChainAnchor $anchor = null): AuditChainVerification;

    /**
     * Verify a chain named directly by key — for callers iterating chains, where the
     * key came from the table rather than from a `Tenant`.
     */
    public function verifyChain(string $chainKey, ?AuditChainAnchor $anchor = null): AuditChainVerification;

    /**
     * Export the current tip of a chain, to be stored somewhere the database cannot
     * reach and passed back to `verify()` later. See `AuditChainAnchor` for why this
     * is the only way tail truncation becomes detectable.
     */
    public function anchor(Tenant|string|null $tenant = null): AuditChainAnchor;

    /**
     * The chain key a tenant (or its absence) maps to.
     */
    public function chainKeyFor(Tenant|string|null $tenant): string;
}
