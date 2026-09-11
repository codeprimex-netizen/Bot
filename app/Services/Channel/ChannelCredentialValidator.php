<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\ChannelCredential;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Validate-before-activate for per-mode credentials: a save or a rotation becomes the set
 * that sends **only after the mode's own driver has confirmed it**, and a refusal leaves the
 * tenant sending on exactly what it was sending on a moment earlier (Req 8.6, 8.13 / A8;
 * design § Channel Mode 2.7).
 *
 * ```php
 * $validator = app(ChannelCredentialValidator::class);
 *
 * // Save or rotate. Throws instead of activating; nothing is half-applied either way.
 * $validation = $validator->save(
 *     $tenant,
 *     ChannelMode::CloudApi,
 *     secrets: ['access_token' => $token],
 *     config: ['waba_id' => '1001', 'phone_number_id' => '5678', 'api_version' => 'v21.0'],
 *     label: 'rotated-2026-05',        // a new name ⇒ the old set stays, rollback-able
 *     session: $session,               // official mode ⇒ register() runs too
 * );
 *
 * // Re-check a set that is already stored — the panel's "test connection", and the command.
 * $validator->revalidate($tenant, ChannelMode::CloudApi);
 * ```
 *
 * ## 1. The order is the requirement, not an implementation detail
 *
 * Req 8.6: *"SHALL validate the new credentials with the driver before activating them […]
 * and IF the new credentials fail validation THEN SHALL retain the previous working
 * credentials and raise a `ChannelCredentialException`."* Activate-then-verify would satisfy
 * every word of that except "before", and the gap between the two steps is a window in which
 * every send on the mode fails — for a campaign, thousands of them, on credentials the tenant
 * was told were being checked.
 *
 * So there are two paths, and which one runs depends on a single fact: whether the tenant
 * already has a usable set for this mode.
 *
 * | | A usable set already serves (**rotation / edit**) | No usable set exists (**first save**) |
 * |---|---|---|
 * | where the driver comes from | `ChannelRouter::driverForMode()`, which the incumbent set already makes resolvable | the same call — but it refuses until *something* is stored, so the candidate is written first |
 * | what is probed | the candidate, assembled **in memory** and stored nowhere | the stored candidate row |
 * | on refusal | **nothing has been written at all.** No row created, none touched, no status changed | the row is marked `INVALID` — which is the honest end state, and is not a state anything was in before |
 * | what still sends | the incumbent, byte-for-byte | nothing, exactly as before the call |
 *
 * The asymmetry exists because the router will not hand out a driver for a mode with no
 * usable credentials (`ChannelCredentialException::missing()` — deliberately, so a missing
 * set can never become a reroute), and a first save has no incumbent to satisfy it with. That
 * is the honest cost of routing having exactly one door: on a first save the candidate is
 * briefly stored-but-unverified. It costs nothing that a tenant can observe — there was no
 * working set to displace, and a session cannot already be on a mode the tenant has never
 * configured — and the alternative would be a second, ungated way to obtain a driver, which
 * is the one thing `DefaultChannelRouter` exists to prevent.
 *
 * ## 2. "Retain the previous working set" is arithmetic, not a compensating action
 *
 * There is no rollback step in this class, and that is the point: a compensating write is
 * itself something that can fail, and the thing it would have to restore is a **secret**, so
 * staging it anywhere — a variable that outlives a transaction, a cache entry, a temporary
 * row — would undo the work `DatabaseChannelCredentialStore` and `ChannelCredentials` do to
 * keep plaintext inside one call. Three properties make the retention structural instead:
 *
 * 1. **The probe happens before the write** on the only path where there is something to
 *    retain. A refused rotation is not "written and then reverted"; it is not written.
 * 2. **A rotation may be written under a new label**, in which case the previous row is not
 *    even the row being modified — `uniq(tenant_id, mode, provider_slot, label)` makes the
 *    label part of the identity, and 6.4's `put()` upserts on it. Activation is then a
 *    `verified_at` stamp on the new row, and `rowFor()`'s newest-verified-first ordering makes
 *    the cutover a single-column write rather than a swap.
 * 3. **Nothing here deletes or disables the previous row, ever.** Not on success either. It is
 *    outranked, not removed, so `ChannelCredentialValidation::$retainedLabel` can name it and a
 *    panel can offer to go back to it.
 *
 * The one write that *is* an in-place edit — the caller re-uses the live label — is still safe
 * for reason 1: the material is proved good before `put()` merges it over the stored bag.
 *
 * ## 3. Three outcomes, because "the provider said no" and "we could not ask" differ
 *
 * | Outcome | Trail | Row | Raised |
 * |---|---|---|---|
 * | the driver confirmed them | `channel.credentials.validated` | `ACTIVE`, `verified_at` stamped | — |
 * | the driver refused them | `channel.credentials.rejected` | untouched (rotation) or `INVALID` (first save) | `ChannelCredentialException::rejected()` |
 * | the probe could not be performed | `channel.credentials.unverifiable` | **untouched**, whatever it was | the transport exception, unchanged |
 *
 * The third row is `RecheckTenantDomains`' rule applied to credentials: *only conclusive
 * evidence against a credential set may take it out of service.* A driver that throws
 * (unreachable endpoint, a guard's open circuit) has established nothing, and treating that as
 * a rejection would let one provider outage mark every tenant's credentials `INVALID` — an
 * outage the platform would then have to be told, by hand, to undo. Re-raising the transport
 * exception unchanged also keeps `PlatformErrorClassifier`'s retry decision intact, which a
 * translated 422 would have destroyed.
 *
 * A mode with **no registered driver** (`ON_PREMISE` and `BSP_GATEWAY` until tasks 7.3 and
 * 7.4 land) raises `LogicException` from the router, and this class deliberately does **not**
 * convert it: it is recorded as `unverifiable` so the attempt is in the trail, and re-raised
 * as the deployment defect it is. Turning it into a tenant-facing 422 would tell a tenant its
 * token was refused when the truth is that this build cannot speak that protocol at all, and
 * would send it re-issuing a credential that is perfectly good.
 *
 * ## 4. What this cannot do: reroute
 *
 * Req 8.13's *"keep the platform operating on the `BAILEYS` default"* is a statement about the
 * platform, and `ChannelCredentialException`'s docblock settles the reading: a tenant whose
 * Cloud API set is missing or rejected keeps running its Baileys sessions normally, and the
 * Cloud API mode simply cannot be selected. It is **not** a licence to move that session's
 * traffic onto Baileys.
 *
 * Nothing here can become one, and it is worth naming the mechanism rather than the intention:
 * this class never reads or writes `sessions_wa.channel_mode`, never resolves a driver for a
 * mode other than the one it was asked about, and has no fallback arm — the mode is a
 * parameter, and every failure path either raises or returns. The single-mode `forget()` calls
 * are the closest it comes to touching routing, and their only effect is to make the *same*
 * mode re-resolve.
 *
 * ## 5. Every attempt is audited, and no secret can reach the trail
 *
 * One entry per attempt, whatever the outcome, because the interesting question after an
 * incident is *"what did the tenant try, and what did the provider say?"* — and a trail that
 * only recorded successes would answer neither. The payload carries the mode, provider, label,
 * the **names** of the secret fields (never a value — `DatabaseChannelCredentialStore`'s
 * `sealed_fields` convention, and its spelling, chosen so `AuditPayloadRedactor` does not
 * redact the very list a reviewer needs), the config **key names** without values, the
 * driver's scrubbed `detail`, the probe latency, and the label of the set that stayed in
 * service. `config` values are deliberately absent here: 6.4's `put()` already records the
 * allowlisted ones on the write itself, and repeating them would mean a second allowlist to
 * keep in step with the first.
 *
 * The append is always **after** the row is saved and never inside a transaction that might
 * roll back — `DatabaseChannelCredentialStore` explains why: a rolled-back append leaves a
 * hole in `unique(chain_key, sequence)` and `verify()` reports that as tampering.
 *
 * ## Boundaries
 *
 * | Concern | Owner |
 * |---|---|
 * | encryption, merge semantics, `verified_at`-aware ordering | `ChannelCredentialStore` (task 6.4) |
 * | mode → driver, capability gating, the memo | `ChannelRouter` (task 6.3) |
 * | what a healthy/unhealthy probe *means* per provider | each driver (tasks 7.1–7.4) |
 * | marking a session live from a pending registration | task 9.1 |
 * | who may call this | the route's `EnsurePermission:sessions.manage` middleware |
 * | draining in-flight sends on a mode switch | task 8.6 |
 */
final class ChannelCredentialValidator
{
    /**
     * Audit actions — dotted, past tense, one per outcome so an operator can ask "which
     * tenants had credentials refused this week?" with a `where` rather than a payload scan.
     */
    public const string ACCEPTED_ACTION = 'channel.credentials.validated';

    public const string REJECTED_ACTION = 'channel.credentials.rejected';

    public const string UNVERIFIABLE_ACTION = 'channel.credentials.unverifiable';

    /**
     * `refused_by` values: whether the material never reached a driver, or a driver saw it
     * and said no.
     */
    public const string REFUSED_BY_PLATFORM = 'platform';

    public const string REFUSED_BY_DRIVER = 'driver';

    public function __construct(
        private readonly ChannelCredentialStore $credentials,
        private readonly ChannelRouter $router,
        private readonly AuditService $audit,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Saving and rotating
    |--------------------------------------------------------------------------
    */

    /**
     * Store credentials for `(tenant, mode, provider, label)` **only if the driver confirms
     * them**, and activate them in the same act (Req 8.6).
     *
     * Save and rotate are one method because they are one operation with two spellings of the
     * label, and the difference belongs to the caller:
     *
     * | `$label` | Effect |
     * |---|---|
     * | omitted, or the live one | the set is edited in place once the material is proved good |
     * | a new name | a second set is created and activated; the previous one stays, rollback-able |
     *
     * Submitted secrets are **merged** over what is already stored, exactly as `put()` merges
     * them, because a credential screen can never echo a stored secret back: a form used to
     * rotate `access_token` legitimately carries nothing for `app_secret`. A `null` value
     * removes a key; a blank string means "not supplied" and keeps the stored value. Under a
     * **new** label the merge base is the set that is currently serving, so a pure token
     * rotation carries the rest of the tenant's material forward instead of writing a set that
     * would pass a health check and then fail to verify a webhook.
     *
     * The material that is probed is the material that is stored — one merged bag, computed
     * once and used for both — so a driver cannot accept one thing while a different thing is
     * written.
     *
     * @param  array<string, mixed>  $secrets  merged over the stored bag; `null` removes a key, `''` is ignored
     * @param  array<string, mixed>  $config  provider identifiers, replaced wholesale when supplied
     * @param  Session|null  $session  when named, an official mode also runs `register()`
     *
     * @throws ChannelCredentialException `missing()` when there would be nothing to authenticate with, `rejected()` when the driver refuses
     * @throws CrossTenantAccessException when acting as another tenant, or when `$session` belongs to one
     * @throws LogicException when no driver is registered for `$mode` — a deployment defect, not a credential one
     */
    public function save(
        Tenant $tenant,
        ChannelMode $mode,
        #[SensitiveParameter]
        array $secrets = [],
        array $config = [],
        ?BspProvider $provider = null,
        ?string $label = null,
        ?Session $session = null,
    ): ChannelCredentialValidation {
        $this->assertOwns($tenant, $session);

        // First read, and the ownership refusal it carries, before anything is assembled: a
        // caller acting as another tenant must be refused before a driver exists and before
        // any material is merged.
        $incumbent = $this->credentials->rowFor($tenant, $mode, $provider);

        $label = $this->labelFor($label);
        $target = $this->credentials->labelled($tenant, $mode, $label, $provider);

        // The set the submitted fields are merged over: the named set when it exists (an edit),
        // otherwise the one that is serving (a rotation carrying material forward).
        $base = $target ?? $incumbent;
        $effectiveSecrets = $this->merge($base === null ? [] : $base->secrets(), $secrets);
        $storedConfig = $base === null ? [] : ($base->config ?? []);
        // Supplied wholesale or not at all: a pure secret rotation must not wipe the `waba_id`
        // it never mentioned, which is `put()`'s rule for the same argument.
        $effectiveConfig = $config === [] ? $storedConfig : $config;

        $candidate = new ChannelCredentials(
            tenantId: $tenant->id,
            mode: $mode,
            provider: $provider,
            config: $effectiveConfig,
            secrets: $effectiveSecrets,
        );

        // Refused here rather than probed: a mode that needs tenant secrets and has none is
        // Req 8.13's *absent* state, and asking a driver to confirm an empty bag would spend a
        // provider call to be told what the platform already knows.
        if (! $candidate->isComplete()) {
            $this->auditRefusal(
                $tenant,
                $candidate,
                $label,
                null,
                $incumbent,
                self::REFUSED_BY_PLATFORM,
                sprintf(
                    'No secret material was supplied for %s, so there would be nothing to authenticate with.',
                    $mode->value,
                ),
                null,
            );

            throw ChannelCredentialException::missing($mode, $tenant->id, $provider);
        }

        // Resolvable only when a usable set already serves. Null is the first-save path — see
        // decision 1 for why the write has to come first there, and why that costs nothing.
        $driver = $this->driverOrNull($tenant, $candidate, $label);

        return $driver === null
            ? $this->saveFirstSet($tenant, $candidate, $label, $effectiveSecrets, $effectiveConfig, $session)
            : $this->saveValidatedSet(
                $tenant,
                $driver,
                $candidate,
                $label,
                $target,
                $incumbent,
                $effectiveSecrets,
                $effectiveConfig,
                $session,
            );
    }

    /**
     * Re-check a set that is already stored, and take it out of service if the driver now
     * refuses it (Req 8.13).
     *
     * The panel's "test connection" and `RevalidateChannelCredentials`' per-row check. Two
     * things separate it from `save()`:
     *
     * - it probes **stored** material, decrypted for this call only, so it proves the round
     *   trip through the envelope rather than what a form submitted;
     * - a refusal marks the row `INVALID` even when that row is the one currently serving —
     *   which is the point. Req 8.13 requires that a rejected set stop being selectable, and
     *   leaving a set `ACTIVE` after its token was revoked would keep every send failing at
     *   the provider instead of failing locally with something a tenant can act on. Whatever
     *   older verified set the tenant still has then takes over, because `rowFor()` re-reads;
     *   if there is none, the mode becomes unselectable and the platform carries on with its
     *   Baileys sessions.
     *
     * A probe that could not be performed changes nothing at all — see decision 3.
     *
     * @param  string|null  $label  a named set, or the one currently serving
     *
     * @throws ChannelCredentialException `missing()` when there is no usable set to check, `rejected()` when the driver refuses it
     * @throws CrossTenantAccessException when acting as another tenant
     * @throws LogicException when no driver is registered for `$mode`
     */
    public function revalidate(
        Tenant $tenant,
        ChannelMode $mode,
        ?BspProvider $provider = null,
        ?string $label = null,
    ): ChannelCredentialValidation {
        $row = $label === null
            ? $this->credentials->rowFor($tenant, $mode, $provider)
            : $this->credentials->labelled($tenant, $mode, $this->labelFor($label), $provider);

        if ($row === null || ! $row->isUsable()) {
            throw ChannelCredentialException::missing($mode, $tenant->id, $provider);
        }

        $credentials = ChannelCredentials::fromModel($row);
        $driver = $this->registeredDriver($tenant, $credentials, $row->label, $row);

        // The row itself is the candidate here, so a refusal invalidates it: unlike a
        // rotation, there is no newer material whose refusal could leave a working set alone.
        $probe = $this->probe($tenant, $driver, $credentials, $row->label, $row, null, null);

        $this->activate($tenant, $row, $probe->health);
        $this->auditAcceptance($tenant, $credentials, $row, $probe, null);

        return new ChannelCredentialValidation(
            credential: $row,
            health: $probe->health,
            registration: $probe->registration,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The two save paths
    |--------------------------------------------------------------------------
    */

    /**
     * The rotation/edit path: probe the candidate in memory, and write only if it passes.
     *
     * The whole of Req 8.6's failure clause lives in the ordering of these statements. Nothing
     * before the `put()` mutates anything, so a `rejected` throw out of `probe()` leaves the
     * tenant's stored rows exactly as this method found them — same active set, same secrets,
     * same ability to send.
     *
     * @param  array<string, mixed>  $secrets
     * @param  array<string, mixed>  $config
     */
    private function saveValidatedSet(
        Tenant $tenant,
        ChannelDriver $driver,
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $target,
        ?ChannelCredential $incumbent,
        #[SensitiveParameter]
        array $secrets,
        array $config,
        ?Session $session,
    ): ChannelCredentialValidation {
        $probe = $this->probe($tenant, $driver, $candidate, $label, null, $incumbent, $session);

        // Proved good, so — and only now — it is written. `put()` audits the write itself and
        // clears `verified_at` on any change, which `activate()` then stamps from this probe.
        $row = $this->credentials->put(
            $tenant,
            $candidate->mode,
            secrets: $this->writableSecrets($secrets, $target),
            config: $config,
            provider: $candidate->provider,
            label: $label,
        );

        $this->activate($tenant, $row, $probe->health);

        // Named only when it is a *different* row that still exists — an in-place edit has
        // nothing to roll back to, and saying otherwise would offer a panel a button that
        // restores the set that was just replaced.
        $retained = $incumbent !== null && $incumbent->id !== $row->id ? $incumbent->label : null;

        $this->auditAcceptance($tenant, $candidate, $row, $probe, $retained);

        return new ChannelCredentialValidation(
            credential: $row,
            health: $probe->health,
            registration: $probe->registration,
            retainedLabel: $retained,
        );
    }

    /**
     * The first-save path: store, then probe, then either stamp or invalidate.
     *
     * Reached only when the tenant has no usable set for the mode, which is exactly the case
     * where there is nothing to retain and nothing a candidate row could displace. A refusal
     * leaves an `INVALID` row rather than nothing, deliberately: Req 8.13 and design § 4.1 row
     * 10 distinguish *"you have not set this up"* from *"this needs attention"*, and only a
     * stored row can carry the second.
     *
     * @param  array<string, mixed>  $secrets
     * @param  array<string, mixed>  $config
     */
    private function saveFirstSet(
        Tenant $tenant,
        ChannelCredentials $candidate,
        string $label,
        #[SensitiveParameter]
        array $secrets,
        array $config,
        ?Session $session,
    ): ChannelCredentialValidation {
        $row = $this->credentials->put(
            $tenant,
            $candidate->mode,
            secrets: $secrets,
            config: $config,
            provider: $candidate->provider,
            label: $label,
        );

        // Before the resolution, not after it: the router asks the store whether anything usable
        // exists, and the store is still holding the remembered *miss* from the read this method
        // was reached through — `put()` drops its own scopes, but the miss belongs to a lookup
        // made before the row existed.
        $this->forget($tenant, $candidate->mode, $candidate->provider);

        // Resolvable now, because the row above is one `rowFor()` can answer with. A driver
        // registry with no entry for this mode raises `LogicException` from here — recorded and
        // re-raised, never rewritten into a credential refusal.
        $driver = $this->registeredDriver($tenant, $candidate, $label, $row);

        $probe = $this->probe($tenant, $driver, $candidate, $label, $row, null, $session);

        $this->activate($tenant, $row, $probe->health);
        $this->auditAcceptance($tenant, $candidate, $row, $probe, null);

        return new ChannelCredentialValidation(
            credential: $row,
            health: $probe->health,
            registration: $probe->registration,
            firstSet: true,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The probe
    |--------------------------------------------------------------------------
    */

    /**
     * Ask the driver, and turn its answer into one of the three outcomes.
     *
     * `healthCheck()` first and `register()` only after it passes: registration is the more
     * expensive call and the more consequential one (it can claim a webhook route at the
     * provider), so spending it on credentials that cannot authenticate would be a side effect
     * of a failure. A **pending** registration is not a refusal — the credentials are valid and
     * the provider has not finished verifying the *number*, which is `RegistrationResult`'s
     * whole reason for having two states — so it is reported and the credentials are activated.
     *
     * @param  ChannelCredential|null  $candidateRow  the stored candidate to invalidate on refusal, if any
     * @param  ChannelCredential|null  $retained  the set that keeps serving through a refusal, if any
     *
     * @throws ChannelCredentialException when the driver refuses the credentials
     * @throws Throwable the driver's own exception when the probe could not be performed
     */
    private function probe(
        Tenant $tenant,
        ChannelDriver $driver,
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $candidateRow,
        ?ChannelCredential $retained,
        ?Session $session,
    ): ChannelCredentialProbe {
        $health = $this->attempt(
            $tenant,
            $candidate,
            $label,
            $candidateRow,
            $retained,
            fn (): ChannelHealth => $driver->healthCheck($candidate),
        );

        if (! $health->isUsable()) {
            $this->refuse($tenant, $candidate, $label, $candidateRow, $retained, $health->detail, $health->latencyMs);
        }

        // Only where the provider has a number to verify, and only with a session to verify it
        // for. `register()` takes one, and a validator called from a credential screen may not
        // have one yet — that is task 9.1's call, made when the session is created.
        if ($session === null || ! $candidate->mode->isOfficial()) {
            return new ChannelCredentialProbe($health, null);
        }

        $registration = $this->attempt(
            $tenant,
            $candidate,
            $label,
            $candidateRow,
            $retained,
            fn (): RegistrationResult => $driver->register($session, $candidate),
        );

        return new ChannelCredentialProbe($health, $registration);
    }

    /**
     * Run one driver call, recording an `unverifiable` attempt if it could not be made.
     *
     * The exception is re-raised **unchanged**: its class is what `PlatformErrorClassifier`
     * reads to decide whether a caller may retry, and a transport failure translated into a
     * 422 would be classified as validation — zero attempts — which is the opposite of the
     * truth. Nothing is written on this path, so the stored state is whatever it was.
     *
     * @template TResult of ChannelHealth|RegistrationResult
     *
     * @param  callable(): TResult  $call
     * @return TResult
     *
     * @throws Throwable
     */
    private function attempt(
        Tenant $tenant,
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $candidateRow,
        ?ChannelCredential $retained,
        callable $call,
    ): ChannelHealth|RegistrationResult {
        try {
            return $call();
        } catch (Throwable $failure) {
            $this->auditUnverifiable($tenant, $candidate, $label, $candidateRow, $retained, $failure);

            throw $failure;
        }
    }

    /**
     * The driver said no: record it, take a stored candidate out of service, and raise.
     *
     * @throws ChannelCredentialException always
     */
    private function refuse(
        Tenant $tenant,
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $candidateRow,
        ?ChannelCredential $retained,
        string $detail,
        ?int $latencyMs,
    ): never {
        if ($candidateRow !== null) {
            $this->invalidate($tenant, $candidateRow);
        }

        $this->auditRefusal(
            $tenant,
            $candidate,
            $label,
            $candidateRow,
            $retained,
            self::REFUSED_BY_DRIVER,
            $detail,
            $latencyMs,
        );

        throw ChannelCredentialException::rejected(
            $candidate->mode,
            $candidate->tenantId,
            $detail,
            $candidate->provider,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Activation
    |--------------------------------------------------------------------------
    */

    /**
     * Stamp the driver's verdict onto the row — the single write that makes a set the one that
     * sends.
     *
     * `verified_at` carries the **probe's** timestamp rather than `now()`, so the column says
     * when the provider confirmed the credentials and not when the row happened to be saved.
     * `ACTIVE` is asserted as well as stamped, because this is the transition out of `INVALID`
     * for a set that has just been proved to work again; a set the *tenant* disabled is left
     * disabled, since re-enabling it is a decision this class was not asked to make.
     *
     * Both memos are dropped afterwards. That is not tidiness: the stamp changes which row
     * `rowFor()`'s newest-verified-first ordering answers with, so a memo held past this point
     * would keep serving the previous set for the rest of the request.
     */
    private function activate(Tenant $tenant, ChannelCredential $row, ChannelHealth $health): void
    {
        if ($row->status === ChannelCredentialStatus::Invalid) {
            $row->status = ChannelCredentialStatus::Active;
        }

        $row->verified_at = Carbon::instance($health->checkedAt);
        $row->save();

        $this->forget($tenant, $row->mode, $row->provider);
    }

    /**
     * Mark a stored candidate as the platform's verdict, not the tenant's choice.
     *
     * `INVALID` rather than `DISABLED`: `ChannelCredentialStatus` keeps those apart precisely
     * so *"your token stopped working"* stays distinguishable from *"you switched this off"*,
     * and only the first needs telling anybody (`needsAttention()`).
     */
    private function invalidate(Tenant $tenant, ChannelCredential $row): void
    {
        $row->status = ChannelCredentialStatus::Invalid;
        $row->verified_at = null;
        $row->save();

        $this->forget($tenant, $row->mode, $row->provider);
    }

    /**
     * Drop the resolutions this change invalidates — the credential row, and the driver the
     * router memoised for the mode.
     *
     * One call each, and that is a statement about the two contracts rather than an economy:
     * `ChannelCredentialStore::forget()` clears every memo scope a change to
     * `(tenant, mode, provider)` invalidates — including the **provider-less** scope, which for
     * `BSP_GATEWAY` is the one the router's own credential check reads — and
     * `ChannelRouter::forget()` is on the contract, so this needs no `instanceof` to reach the
     * implementation that keeps a memo.
     */
    private function forget(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider): void
    {
        $this->credentials->forget($tenant, $mode, $provider);
        $this->router->forget($tenant, $mode);
    }

    /*
    |--------------------------------------------------------------------------
    | Auditing
    |--------------------------------------------------------------------------
    */

    /**
     * Record an attempt that could not be concluded — and, deliberately, that it changed
     * nothing.
     */
    private function auditUnverifiable(
        Tenant $tenant,
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $candidateRow,
        ?ChannelCredential $retained,
        Throwable $failure,
    ): void {
        $this->audit->write(
            self::UNVERIFIABLE_ACTION,
            $this->payload($candidate, $label, $retained, [
                'outcome' => 'unverifiable',
                // The class, and the message scrubbed with the very credentials that were sent:
                // a provider refusal quotes the request, and an exception message is no safer
                // than a response body.
                'failure' => $failure::class,
                'detail' => $candidate->redact($failure->getMessage()),
                'changed' => false,
            ]),
            $candidateRow,
            tenant: $tenant,
        );
    }

    private function auditAcceptance(
        Tenant $tenant,
        ChannelCredentials $candidate,
        ChannelCredential $row,
        ChannelCredentialProbe $probe,
        ?string $retainedLabel,
    ): void {
        $this->audit->write(
            self::ACCEPTED_ACTION,
            array_merge($this->payload($candidate, $row->label, null, [
                'outcome' => 'accepted',
                'detail' => $probe->health->detail,
                'latency_ms' => $probe->health->latencyMs,
                'verified_at' => $probe->health->checkedAt->toIso8601String(),
                'changed' => true,
            ]), [
                'retained_label' => $retainedLabel,
                'registration' => $probe->registration === null
                    ? null
                    : ($probe->registration->isLive() ? 'live' : 'pending'),
                'registration_detail' => $probe->registration?->detail,
            ]),
            $row,
            tenant: $tenant,
        );
    }

    private function auditRefusal(
        Tenant $tenant,
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $candidateRow,
        ?ChannelCredential $retained,
        string $refusedBy,
        string $detail,
        ?int $latencyMs,
    ): void {
        $this->audit->write(
            self::REJECTED_ACTION,
            $this->payload($candidate, $label, $retained, [
                'outcome' => 'rejected',
                'refused_by' => $refusedBy,
                'detail' => $detail,
                'latency_ms' => $latencyMs,
                // The half of Req 8.6 an operator will be asked about: was anything lost?
                'changed' => $candidateRow !== null,
                'invalidated' => $candidateRow !== null,
            ]),
            $candidateRow,
            tenant: $tenant,
        );
    }

    /**
     * The part of every entry that is the same: which set was attempted, which fields it
     * carries, and which set kept serving.
     *
     * Names only, on both bags. `sealed_fields` follows `DatabaseChannelCredentialStore`'s
     * spelling for the reason recorded there — `secret_keys` would match
     * `PiiKeyRules::SECRET_PATTERN` and arrive as `[redacted]`, which would redact the list of
     * field names that is the entire point of recording it.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $retained,
        array $extra,
    ): array {
        return array_merge([
            'mode' => $candidate->mode->value,
            'provider' => $candidate->provider?->value,
            'label' => $label,
            'sealed_fields' => $candidate->secretKeys(),
            // Key names without values: `put()` already recorded the allowlisted values on the
            // write, and a second allowlist here would be a second thing to keep in step.
            'config_keys' => $candidate->configKeys(),
            'retained_label' => $retained?->label,
            'retained_credential_id' => $retained?->id,
        ], $extra);
    }

    /*
    |--------------------------------------------------------------------------
    | Material
    |--------------------------------------------------------------------------
    */

    /**
     * Merge submitted secrets over the stored bag — `put()`'s rules, applied before the probe
     * so that what is validated is what will be written.
     *
     * | Submitted value | Result |
     * |---|---|
     * | a non-empty value | replaces any stored value for that key |
     * | `null` | the key is dropped — the only spelling of "delete this secret" |
     * | `''` or blank | ignored; an untouched password field posts one, and treating that as a deletion would disarm a working set on every re-save |
     *
     * These rules are `DatabaseChannelCredentialStore::mergeSecrets()`'s, restated because the
     * store offers no way to *preview* a merge and the candidate has to exist in memory before
     * anything is written. The duplication is deliberate and narrow; the store's version stays
     * authoritative, and `ChannelCredentialValidatorTest` pins the two together by asserting
     * that what the driver was handed is what the row afterwards holds.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function merge(
        #[SensitiveParameter]
        array $stored,
        #[SensitiveParameter]
        array $submitted,
    ): array {
        $merged = $stored;

        foreach ($submitted as $key => $value) {
            if ($key === '') {
                continue;
            }

            if ($value === null) {
                unset($merged[$key]);

                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $merged[$key] = $value;
        }

        ksort($merged);

        return $merged;
    }

    /**
     * The bag to hand `put()`: the merged material, plus an explicit `null` for every key the
     * *named* set still holds and the merge dropped.
     *
     * Without the nulls a deletion would be silently lost, because `put()` merges rather than
     * replaces and cannot tell "absent" from "delete this" — the same asymmetry that makes a
     * blank field safe. Only the named set's keys are considered: material carried forward
     * from a *different* set was never stored under this label, so there is nothing there to
     * delete.
     *
     * @param  array<string, mixed>  $secrets
     * @return array<string, mixed>
     */
    private function writableSecrets(
        #[SensitiveParameter]
        array $secrets,
        ?ChannelCredential $target,
    ): array {
        foreach (array_keys($target?->secrets() ?? []) as $key) {
            if (! array_key_exists($key, $secrets)) {
                $secrets[$key] = null;
            }
        }

        return $secrets;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The driver for the candidate's mode, or `null` when the tenant has nothing usable stored
     * yet — the test that chooses between the two save paths.
     *
     * The `ChannelCredentialException` swallowed here is the router refusing to hand out a
     * driver for a mode with no usable credentials. That is a *state* rather than an error at
     * this one call site, because "has this tenant anything usable for this mode?" is precisely
     * the question being asked — and it is asked of the router rather than of the store so that
     * the answer is exactly "can a driver be resolved without writing first", which is what the
     * decision turns on.
     *
     * Two consequences worth stating, because they are visible in behaviour:
     *
     * - `BAILEYS` never takes the first-save path — it needs no tenant credentials, so a driver
     *   is always resolvable;
     * - a `BSP_GATEWAY` tenant that already has one partner configured takes the safer
     *   in-memory path even for a **new** partner, because the router's resolvability is
     *   mode-granular. Nothing is written when that new partner's credentials are refused —
     *   which is the better of the two outcomes, and the reason this asks the router rather
     *   than looking for a row for the exact `(mode, provider)` pair.
     */
    private function driverOrNull(Tenant $tenant, ChannelCredentials $candidate, string $label): ?ChannelDriver
    {
        try {
            return $this->registeredDriver($tenant, $candidate, $label, null);
        } catch (ChannelCredentialException) {
            return null;
        }
    }

    /**
     * The mode's registered driver, with a missing registry entry recorded before it is
     * re-raised.
     *
     * `LogicException` here means this build has no driver for the mode — `ON_PREMISE` and
     * `BSP_GATEWAY` until tasks 7.3 and 7.4 land. It is audited so the attempt is not invisible,
     * and then re-raised **unchanged**, because it is a deployment defect and not a statement
     * about the tenant's credentials: rewriting it into `ChannelCredentialException` would tell
     * a tenant its token was refused and send it re-issuing a credential that is perfectly good.
     *
     * The one failure that is *not* recorded here is `ChannelCredentialException`: it is the
     * router answering "nothing usable is stored", which `driverOrNull()` reads as a state, and
     * auditing it would put an `unverifiable` entry in front of every ordinary first save.
     *
     * @throws ChannelCredentialException when the mode has no usable credentials — the caller decides whether that is a state or an error
     * @throws Throwable when the driver cannot be resolved at all; in practice the router's `LogicException` naming an unregistered mode
     */
    private function registeredDriver(
        Tenant $tenant,
        ChannelCredentials $candidate,
        string $label,
        ?ChannelCredential $row,
    ): ChannelDriver {
        try {
            return $this->router->driverForMode($candidate->mode, $tenant);
        } catch (Throwable $failure) {
            if (! $failure instanceof ChannelCredentialException) {
                $this->auditUnverifiable($tenant, $candidate, $label, $row, null, $failure);
            }

            throw $failure;
        }
    }

    /**
     * Refuse to register another tenant's session, before any material is read.
     *
     * The store and the router both check the *acting* tenant; this checks the argument pair,
     * which they cannot see. A console command or a job binds no tenant, so without this a
     * caller could hand this method tenant A and a session of tenant B and have B's number
     * registered with A's credentials.
     *
     * @throws CrossTenantAccessException when the session belongs to another tenant
     */
    private function assertOwns(Tenant $tenant, ?Session $session): void
    {
        if ($session !== null && $session->tenant_id !== $tenant->id) {
            throw CrossTenantAccessException::forRetrieval(Session::class, $session->tenant_id, $tenant->id);
        }
    }

    private function labelFor(?string $label): string
    {
        $label = trim($label ?? '');

        return $label === '' ? ChannelCredential::DEFAULT_LABEL : $label;
    }
}
