<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Models\ChannelCredential;
use App\Models\Tenant;

/**
 * The read/write path for one tenant's per-mode channel credentials — envelope-encrypted at
 * rest, secret-redacted everywhere else, decrypted only inside the request or job that uses
 * them (Req 8.5, 8.6, 8.13 / A8; Req 32.3 / NFR3; design § Channel Mode 2.7).
 *
 * ```php
 * $store = app(ChannelCredentialStore::class);
 *
 * // What a driver is handed: decrypted, unserialisable, request-lifetime (Req 8.5).
 * $credentials = $store->for($tenant, ChannelMode::CloudApi);   // null when absent (Req 8.13)
 * $credentials?->requireConfig('phone_number_id');
 * $credentials?->requireSecret('access_token');
 *
 * // What a screen is handed: the stored row, secrets still sealed.
 * $row = $store->rowFor($tenant, ChannelMode::CloudApi);
 * $row?->isVerified();
 *
 * // Writing is an auditable act; nothing it stored is logged or returned.
 * $store->put($tenant, ChannelMode::CloudApi,
 *     secrets: ['access_token' => $token, 'verify_token' => $verify],
 *     config: ['waba_id' => '1234', 'phone_number_id' => '5678', 'api_version' => 'v20.0'],
 * );
 * ```
 *
 * ## Two shapes, because two callers need different things
 *
 * | Caller | Method | Gets |
 * |---|---|---|
 * | a `ChannelDriver` (tasks 7.1–7.5), task 7.6's `healthCheck()` | `for()` | `ChannelCredentials` — decrypted, private secrets, refuses to serialise |
 * | the mode panel, task 7.6's activate/rollback | `rowFor()`, `labelled()`, `all()` | `ChannelCredential` rows — status, `verified_at`, id; secrets still ciphertext |
 *
 * `ChannelCredentials` is the *only* decrypted shape and it is produced in exactly one place
 * (`ChannelCredentials::fromModel()`, called by `for()`), so "the secret bag is decrypted at
 * the boundary and nowhere else" is a fact about one line. There is deliberately no
 * `secretsFor()`, no `token()`, and no `decrypt()` on this contract: a caller that wants a
 * raw array has to go to `ChannelCredential::secrets()`, which is the one sanctioned exit
 * task 6.1 documents.
 *
 * ## Disjoint by `(tenant, mode, provider)` — Correctness Property 23
 *
 * Every read is scoped on three axes at once: the tenant (twice — the `BelongsToTenant`
 * global scope *and* an explicit predicate), the `ChannelMode`, and, for `BSP_GATEWAY`,
 * the `BspProvider`. A Twilio lookup cannot surface the 360dialog row, a `CLOUD_API`
 * lookup cannot surface a `BSP_GATEWAY` row, and a caller acting as another tenant is
 * refused with `CrossTenantAccessException` rather than quietly handed nothing.
 *
 * ## `null` is an ordinary answer
 *
 * Req 8.13 makes "this tenant has no credentials for this mode" a normal state: the mode
 * simply cannot be selected and the platform keeps working on the `BAILEYS` default. So
 * `for()` returns `null` instead of throwing, and the caller distinguishes the two cases
 * it actually needs:
 *
 * | Question | How |
 * |---|---|
 * | can a send use this mode right now? | `for(...) !== null` |
 * | has the tenant configured it at all? | `all(...) !== []` |
 * | is it configured but rejected/switched off? | `all(...)` non-empty while `for(...)` is null |
 *
 * Raising `ChannelCredentialException` is task 7.6's job, at the point where a mode is
 * *selected* or a send is *attempted* — not here, where a lookup is just a lookup.
 *
 * ## Boundaries
 *
 * | Concern | Owner |
 * |---|---|
 * | the encryption itself | `App\Casts\EncryptedArray` + `FieldCipher` (task 4.1) |
 * | mode → driver, capability gating | `ChannelRouter` (task 6.3) |
 * | validating credentials against a live driver, activate/rollback | task 7.6 |
 * | who may call `put()` | the route's `EnsurePermission:sessions.manage` middleware |
 * | the credential-entry screen (secret-redacted) | Phase 17+, design § 4.1 row 10 |
 */
interface ChannelCredentialStore
{
    /**
     * The decrypted credentials a driver on `$mode` should use, or `null` when the tenant has
     * none a send could use.
     *
     * Decrypted **per call**, never memoised: the store remembers which row answers the
     * lookup, so the saving is a query rather than a decryption — the decryption is precisely
     * the thing Req 8.5 says must not outlive the request or job that needs it. The returned
     * `ChannelCredentials` refuses to be serialised, so it cannot reach a queue payload even
     * by accident.
     *
     * `BAILEYS` is worth calling out: it needs no tenant credentials at all
     * (`ChannelMode::requiresTenantCredentials()` is false), so a tenant that has never
     * configured it gets `null` here. Substituting the platform bridge config is the Baileys
     * driver's business (task 7.1, via `ChannelCredentials::platform()`), not the store's —
     * this method answers about the tenant's stored rows and nothing else.
     *
     * @param  BspProvider|null  $provider  required for `BSP_GATEWAY`; ignored by every other mode
     *
     * @throws \App\Exceptions\Tenancy\CrossTenantAccessException when another tenant is bound
     * @throws \App\Exceptions\Security\CiphertextIntegrityException when the stored bag does not authenticate
     */
    public function for(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): ?ChannelCredentials;

    /**
     * The stored row `for()` would decrypt, or `null` when there is none a send could use.
     *
     * Newest-verified-first: a rotation written under a fresh label is picked up without
     * anything having to update a pointer, and an unverified row never displaces a verified
     * one. Rows that are switched off, rejected by the driver, or missing their secrets are
     * skipped — so a non-null result is always one `ChannelCredential::isUsable()` agrees
     * with, and a caller does not have to re-check.
     *
     * For callers that need the row's *state* rather than its material: the panel showing
     * "verified 3 days ago", and task 7.6 activating or rolling back. No decryption happens.
     *
     * @throws \App\Exceptions\Tenancy\CrossTenantAccessException when another tenant is bound
     */
    public function rowFor(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): ?ChannelCredential;

    /**
     * One named credential set — the exact `(tenant, mode, provider, label)` identity the
     * unique index constrains — whatever its status.
     *
     * The lookup `put()` upserts against, and the one task 7.6 needs to fetch back the
     * candidate row it asked for by name.
     *
     * @throws \App\Exceptions\Tenancy\CrossTenantAccessException when another tenant is bound
     */
    public function labelled(
        Tenant $tenant,
        ChannelMode $mode,
        string $label,
        ?BspProvider $provider = null,
    ): ?ChannelCredential;

    /**
     * Every credential set the tenant has for `$mode`, newest-verified-first, **including**
     * the rejected and switched-off ones.
     *
     * What separates "never configured" from "configured and not currently usable" — the
     * distinction design § 4.1 row 10 renders as *"disabled with setup hint"* versus
     * *"needs attention"*, and the one Req 8.13 turns on.
     *
     * @return list<ChannelCredential>
     *
     * @throws \App\Exceptions\Tenancy\CrossTenantAccessException when another tenant is bound
     */
    public function all(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): array;

    /**
     * Store credentials for `(tenant, mode, provider, label)`, encrypted at rest and
     * audited — returning the row, never what was stored in it.
     *
     * An upsert on that four-part identity: the same label is updated in place, a new label
     * creates a new set. `$secrets` is **merged** key-wise, because a credential screen can
     * never echo a stored secret back and so a submitted form legitimately carries only the
     * fields that were retyped; a `null` value removes one key. Any change to the effective
     * config or secrets clears `verified_at`, because the previous driver verification no
     * longer attests to the material now stored.
     *
     * @param  array<string, mixed>  $secrets  merged into the sealed bag; `null` removes a key, `''` is ignored
     * @param  array<string, mixed>  $config  non-secret identifiers, replaced wholesale when supplied; `[]` leaves the stored config untouched
     * @param  string|null  $label  defaults to `ChannelCredential::DEFAULT_LABEL`
     *
     * @throws \InvalidArgumentException when the mode needs tenant secrets and none would be stored
     * @throws \App\Exceptions\Tenancy\CrossTenantAccessException when another tenant is bound
     * @throws \App\Exceptions\Security\KeyUnavailableException when the key store is unavailable (fails closed)
     */
    public function put(
        Tenant $tenant,
        ChannelMode $mode,
        array $secrets = [],
        array $config = [],
        ?BspProvider $provider = null,
        ?string $label = null,
    ): ChannelCredential;

    /**
     * Drop the in-memory resolution of `(tenant, mode, provider)` so the next `for()` or
     * `labelled()` re-reads.
     *
     * The seam for a caller that changes a row without going through `put()` — task 7.6
     * stamping `verified_at` after a successful `healthCheck()`, or marking a row `INVALID`
     * after a failed one.
     */
    public function forget(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): void;

    /**
     * Drop every memoised resolution.
     */
    public function flush(): void;
}
