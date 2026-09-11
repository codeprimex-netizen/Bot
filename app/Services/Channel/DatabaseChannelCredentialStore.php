<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\ChannelCredential;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\TenantContext;
use App\Support\Audit\AuditPayloadRedactor;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `ChannelCredentialStore` over `channel_credentials` — the whole of the store's behaviour,
 * and the four security decisions it exists to make (Req 8.5, 8.6, 8.13 / A8; Req 32.3 /
 * NFR3; Correctness Property 23).
 *
 * ## 1. Reads are disjoint by `(tenant, mode, provider)`
 *
 * Property 23 says a driver for a session only ever reads `channel_credentials` for
 * `(tenant, session.channel_mode)`. Three axes, enforced independently:
 *
 * - **Tenant, twice.** `ChannelCredential` uses `BelongsToTenant`, so `TenantScope`
 *   constrains every query to the acting tenant — and every read here *also* names
 *   `tenant_id` explicitly, so the row set is right even if a future caller reaches the
 *   query through a relation that drops the global scope. Belt and braces, because a
 *   cross-tenant credential read is a silent compromise rather than a visible bug.
 * - **The acting tenant is checked, not assumed.** Reading for tenant B while bound to
 *   tenant A is `CrossTenantAccessException` (403, Req 1.3), *not* an empty result:
 *   with only the global scope the two predicates would `AND` to nothing and the caller
 *   would read "this tenant has no credentials" from what is really a scoping bug. A
 *   caller with no tenant bound — a console command, a platform-admin sweep, a job that
 *   has not bound one — is run through `TenantContext::runFor()`, which suspends platform
 *   mode so the scope genuinely applies to the one tenant asked about.
 * - **Mode and provider.** `ChannelCredential::scopeForMode()` adds `mode`, and `provider`
 *   when one is named, so a `CLOUD_API` lookup cannot surface a `BSP_GATEWAY` row and a
 *   Twilio lookup cannot surface the 360dialog row.
 *
 * ## 2. Nothing is cached beyond the unit of work, and no plaintext is cached at all
 *
 * The store memoises the *resolution* — which row answers `(tenant, mode, provider)` — in a
 * private array, and the binding is `scoped()`, so the memo is discarded at the end of every
 * request and, because the queue worker resets scoped instances between jobs, at the end of
 * every job. What it holds is a `ChannelCredential` whose `secret_config` attribute is
 * **ciphertext**: the cast returns a plain array and Eloquent only caches cast *objects*, so
 * a decrypted bag is never retained on the model and `secrets()` decrypts afresh each call.
 *
 * The decrypted view is therefore built on **every** `for()` call and never stored:
 * `rowFor()` may answer from the memo, `ChannelCredentials::fromModel()` runs regardless. The
 * memo saves a query; it must not save a decryption, because "decrypted only within the
 * lifetime of the request or job that uses them" (Req 8.5) is a statement about how long
 * plaintext exists, and a memo of plaintext would make it a statement about a container
 * binding instead.
 *
 * The acting-tenant check runs **before** the memo, for the same reason: a remembered row must
 * not become a way for code acting as another tenant to be handed this one's credentials
 * without the refusal that an uncached read would have raised.
 *
 * There is deliberately **no `VersionedCache`** here, unlike `PlatformSettings`:
 *
 * - a shared cache entry would put the credential row — including its ciphertext envelope —
 *   into a second store (Redis, or the cache table) outside the audited column it belongs to,
 *   widening the blast radius of that store for no functional gain;
 * - and it would let a credential set that a tenant has just disabled, rotated away from, or
 *   had rejected keep being selected for the rest of the TTL. `SigningSecretStore` is bound
 *   `scoped()` for exactly this reason — a key cache must not outlive a revoked key — and
 *   credentials deserve the same answer.
 *
 * `for()` is a single indexed lookup on `idx(tenant_id, mode)`, so the memo is an
 * intra-request deduplication and never a correctness dependency. Task 7.6, which changes
 * rows without going through `put()`, has `forget()` to drop it.
 *
 * ## 3. Secrets cannot reach the audit chain, a log line, or a queue payload
 *
 * | Path | What stops it |
 * |---|---|
 * | the audit trail | `put()` records the **names** of the secret fields it stored, never a value; and non-secret `config` values only for the keys on `AUDITED_CONFIG_KEYS`, everything else `[redacted]` |
 * | a log line | the store logs nothing, and the values are matched by `PiiKeyRules::SECRET_PATTERN` if a caller logs them anyway |
 * | a queue payload | the only decrypted shape the store returns is `ChannelCredentials`, whose `__serialize()` **throws**, so `dispatch(new Job($credentials))` fails at dispatch instead of writing a token into `jobs` (and `failed_jobs`) in the clear. `rowFor()`/`put()` return the row, whose secret attribute is ciphertext. A job carries the tenant id and the mode — or the credential id — and resolves inside `handle()` |
 *
 * The audit allowlist is the `PlatformSettings::AUDITED_VALUES` precedent, applied for the
 * same reason: the chain is append-only and hash-chained, so a secret written into it cannot
 * be removed afterwards without breaking every hash after it. `config` is documented as
 * non-secret, but a tenant can put any key there and an operator can paste a token into the
 * wrong field once — so the values recorded verbatim are a short, reviewed list and
 * everything else is redacted by name. `AuditPayloadRedactor` would already redact anything
 * whose *key* looks like a secret; the allowlist is the half that catches a secret filed
 * under an innocent name.
 *
 * There is also **no `secretsFor()`** on this store, and no raw array of secrets crosses its
 * boundary: `ChannelCredential::secrets()` stays the single sanctioned exit and it is called
 * from exactly two places here — `ChannelCredentials::fromModel()` inside `for()`, and the
 * merge inside `put()`, which needs to know which keys are already stored.
 *
 * ## 4. A write is all-or-nothing, and never leaves a half-configured row
 *
 * A row whose `config` landed but whose `secret_config` did not is worse than no row:
 * `isUsable()` would call it usable on the strength of its status while the driver cannot
 * authenticate. So `put()` reads the existing row `lockForUpdate()`, merges, and saves inside
 * one transaction — which also makes two concurrent rotations serialise instead of losing one
 * side of the merge — and it refuses outright to store a row for a mode that
 * `requiresTenantCredentials()` unless the resulting secret bag is non-empty. Encryption
 * happens in the cast on assignment, so an unavailable key store raises
 * `KeyUnavailableException` inside the transaction and the whole write rolls back: failing
 * closed, never a plaintext or empty fallback.
 *
 * The audit entry is written **after** the transaction commits, not inside it. A rolled-back
 * append would leave a hole in `unique(chain_key, sequence)` and `verify()` reports a
 * `SequenceGap` as tampering — so a credential write that failed would be indistinguishable
 * from a chain someone had edited.
 *
 * ## Rotation: `put()` upserts by label; a rollback-able rotation uses a new one
 *
 * `uniq(tenant_id, mode, provider_slot, label)` means the four-part identity *is* the name of
 * a credential set, so `put()` on the same name updates in place and `put()` under a new
 * label creates a second set. Both are useful and the choice belongs to the caller:
 *
 * - **Same label** — the ordinary "fix the token" edit. Any change to config or secrets clears
 *   `verified_at`, because the previous driver verification attested to material that is no
 *   longer stored; an `INVALID` row returns to `ACTIVE` (new material deserves a fresh
 *   verdict) while a `DISABLED` one stays disabled (that was a deliberate choice, and editing
 *   config must not silently undo it).
 * - **New label** — the rollback-able rotation. The previous set stays exactly as it was, and
 *   because `activeFor()` orders verified-before-unverified the new set does **not** start
 *   serving sends until something stamps `verified_at` on it. Retention of the previous
 *   working credentials is therefore structural rather than a compensating action.
 *
 * **What task 7.6 must do because of that choice.** Validate-before-activate writes the
 * candidate under a *new label*, calls `healthCheck()`, and then either stamps `verified_at`
 * (at which point `for()` starts returning it — no pointer to update) or marks it `INVALID`
 * and raises `ChannelCredentialException`. Either way the previous row was never touched, so
 * "retain the previous working credentials" needs no rollback step. If 7.6 instead re-used the
 * live label it would have to stage the old secrets somewhere to restore them, and the only
 * place to stage a secret is a place we have just spent this class keeping secrets out of.
 * The seam it needs is exactly three calls: `put()` for the candidate, `labelled()` to read it
 * back, and `forget()` to drop the memo after it changes the row's status or `verified_at`.
 * This store never calls a driver and never sets `verified_at` from a live check.
 *
 * ## Who may call `put()`
 *
 * Authorization is the route's job, not the store's: `EnsurePermission` is the platform's one
 * RBAC enforcement point (`TenantPermission::SessionsManage` for the mode screen, design § 4.1
 * row 10). The store is also called from jobs and console commands where there is no user to
 * authorize, so a check in here would either be bypassed by those callers or block them.
 */
final class DatabaseChannelCredentialStore implements ChannelCredentialStore
{
    /**
     * `config` keys whose values are safe to record verbatim in the append-only trail —
     * the provider identifiers design § Channel Mode 2.7 lists as non-secret.
     *
     * @var list<string>
     */
    public const array AUDITED_CONFIG_KEYS = [
        'waba_id',
        'phone_number_id',
        'api_version',
        'endpoint',
        'sender',
    ];

    /**
     * What the trail shows instead of a value that is not on `AUDITED_CONFIG_KEYS`.
     */
    public const string REDACTED = AuditPayloadRedactor::REDACTED;

    /**
     * Audit actions. Distinct verbs rather than one action with a flag, so an operator can
     * ask "when were these credentials first configured?" with a `where`.
     */
    public const string CREATED_ACTION = 'channel.credentials.created';

    public const string UPDATED_ACTION = 'channel.credentials.updated';

    /**
     * Resolved rows, keyed by `{tenant}|{mode}|{provider}|{label}` — see the class docblock
     * for the lifetime and for what is deliberately not in here.
     *
     * A key present with a `null` value is a remembered *miss*, so a mode a tenant has not
     * configured does not re-query on every message of a campaign.
     *
     * @var array<string, ChannelCredential|null>
     */
    private array $resolved = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditService $audit,
    ) {}

    public function for(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): ?ChannelCredentials
    {
        $credential = $this->rowFor($tenant, $mode, $provider);

        // Decrypted here and only here, on every call, from the row the memo remembered: the
        // memo saves the query, never the decryption — see the class docblock for why that
        // asymmetry is the whole point.
        return $credential === null ? null : ChannelCredentials::fromModel($credential);
    }

    public function rowFor(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): ?ChannelCredential
    {
        // Before the memo, not after: a memoised row must not become a way for code acting as
        // another tenant to be handed this one's credentials without the refusal.
        $this->assertActingFor($tenant);

        $provider = $this->readProvider($mode, $provider);
        $key = $this->memoKey($tenant, $mode, $provider, null);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $credential = $this->scopedTo($tenant, function () use ($tenant, $mode, $provider): ?ChannelCredential {
            // `ChannelCredential::activeFor()`'s rule — newest-verified first, unverified
            // last — expressed against the explicitly-owned query rather than delegated,
            // because `all()` needs the identical order *without* the status filter and a
            // panel that listed sets in a different priority than the send path uses would
            // be lying. `ChannelCredentialStoreTest` pins the two together by asserting this
            // method returns the first usable element of `all()`.
            $credential = $this->owned($tenant)
                ->forMode($mode, $provider)
                ->usable()
                ->orderByRaw('verified_at is null')
                ->orderByDesc('verified_at')
                ->orderByDesc('created_at')
                // The tie-break, and it is load-bearing rather than tidiness: see
                // `ChannelCredential::activeFor()`.
                ->orderByDesc('id')
                ->first();

            // The has-secrets half of `isUsable()`, which SQL cannot answer. Applied to the
            // winner only, and deliberately *without* falling back to an older set: the
            // tenant's newest active credentials for a mode are the ones they intend to be
            // used, and quietly sending with the set they thought they had replaced would be
            // worse than reporting the mode as unusable.
            return $credential !== null && $credential->isUsable() ? $credential : null;
        });

        return $this->resolved[$key] = $credential;
    }

    public function labelled(
        Tenant $tenant,
        ChannelMode $mode,
        string $label,
        ?BspProvider $provider = null,
    ): ?ChannelCredential {
        $this->assertActingFor($tenant);

        $provider = $this->readProvider($mode, $provider);
        $label = $this->labelFor($label);
        $key = $this->memoKey($tenant, $mode, $provider, $label);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $credential = $this->scopedTo(
            $tenant,
            fn (): ?ChannelCredential => $this->identity($tenant, $mode, $provider, $label)->first(),
        );

        return $this->resolved[$key] = $credential;
    }

    public function all(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): array
    {
        $provider = $this->readProvider($mode, $provider);

        // Not memoised: this is the panel's and task 7.6's read, where the point is to see
        // the current state of every set — including the one just marked INVALID.
        return $this->scopedTo($tenant, function () use ($tenant, $mode, $provider): array {
            /** @var list<ChannelCredential> $credentials */
            $credentials = $this->owned($tenant)
                ->forMode($mode, $provider)
                // The same priority `for()` applies, without the status filter — so the
                // first usable element of this list is what `for()` returns, and
                // `ChannelCredentialStoreTest` asserts exactly that rather than trusting the
                // two orderings to stay in step. The `id` tie-break is part of that: a list
                // the panel ordered differently from the send path would be lying.
                ->orderByRaw('verified_at is null')
                ->orderByDesc('verified_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->all();

            return $credentials;
        });
    }

    public function put(
        Tenant $tenant,
        ChannelMode $mode,
        array $secrets = [],
        array $config = [],
        ?BspProvider $provider = null,
        ?string $label = null,
    ): ChannelCredential {
        $provider = $this->writeProvider($mode, $provider);
        $label = $this->labelFor($label);

        return $this->scopedTo($tenant, function () use ($tenant, $mode, $provider, $label, $secrets, $config): ChannelCredential {
            $write = DB::transaction(
                fn (): ChannelCredentialWrite => $this->write($tenant, $mode, $provider, $label, $secrets, $config),
            );

            // The row that answers this identity has changed, and so may the row that answers
            // the (tenant, mode, provider) lookup — a new label can outrank the old one.
            $this->forget($tenant, $mode, $provider);

            // After the commit, never inside it: a rolled-back append leaves a hole in
            // `unique(chain_key, sequence)`, and `verify()` reports that as tampering.
            $this->audit->write(
                $write->created ? self::CREATED_ACTION : self::UPDATED_ACTION,
                $this->auditPayload($write),
                $write->credential,
                tenant: $tenant,
            );

            return $write->credential;
        });
    }

    public function forget(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider = null): void
    {
        foreach ($this->invalidatedPrefixes($tenant, $mode, $this->readProvider($mode, $provider)) as $prefix) {
            foreach (array_keys($this->resolved) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->resolved[$key]);
                }
            }
        }
    }

    public function flush(): void
    {
        $this->resolved = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * The body of `put()`, inside the transaction.
     *
     * @param  array<string, mixed>  $secrets
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException when the mode needs secrets and none would be stored
     */
    private function write(
        Tenant $tenant,
        ChannelMode $mode,
        ?BspProvider $provider,
        string $label,
        array $secrets,
        array $config,
    ): ChannelCredentialWrite {
        // Locked, so a second `put()` on the same identity waits rather than merging against
        // a bag that is about to be replaced under it.
        $existing = $this->identity($tenant, $mode, $provider, $label)->lockForUpdate()->first();

        // The one decryption this write needs: merge semantics require knowing which keys are
        // already stored. It lives in this closure and is never returned, logged, or stored.
        $storedSecrets = $existing?->secrets() ?? [];
        $storedConfig = $existing === null ? [] : ($existing->config ?? []);
        ksort($storedSecrets);
        ksort($storedConfig);

        $bag = $this->mergeSecrets($storedSecrets, $secrets);
        $removed = array_values(array_diff($this->fieldNames($storedSecrets), $this->fieldNames($bag)));

        // A config bag is replaced wholesale when supplied — the screen that submits it shows
        // every key, so an absent key means "removed". An *empty* array means "not supplied":
        // a pure secret rotation must not wipe the `waba_id` it did not mention.
        $effectiveConfig = $config === [] ? $storedConfig : $config;
        ksort($effectiveConfig);

        if ($mode->requiresTenantCredentials() && $bag === []) {
            throw new InvalidArgumentException(sprintf(
                'Channel mode [%s] requires tenant credentials, so [%s] cannot be stored with an empty secret bag. '
                .'Supply at least one secret field, or delete the credential set instead.',
                $mode->value,
                $label,
            ));
        }

        // Any change to the stored material invalidates whatever a driver previously
        // confirmed, so `verified_at` is cleared and task 7.6 re-validates. An idempotent
        // re-save changes nothing and keeps a working row verified — both sides are `ksort`ed
        // first, so re-submitting the same fields in a different order is not a "change".
        $changed = $bag !== $storedSecrets || $effectiveConfig !== $storedConfig;

        $credential = $existing ?? new ChannelCredential;

        // tenant_id first: `EncryptedArray` resolves the DEK from the row's own tenant, and
        // on an insert the cast runs before `BelongsToTenant` stamps the column.
        $credential->tenant_id = $tenant->id;
        $credential->mode = $mode;
        $credential->provider = $provider;
        $credential->label = $label;
        $credential->config = $effectiveConfig === [] ? null : $effectiveConfig;
        // Assignment encrypts (`EncryptedArray::set`), so from here the attribute is
        // ciphertext and the model never holds the plaintext bag.
        $credential->secret_config = $bag === [] ? null : $bag;
        $credential->status = $this->statusFor($existing);

        if ($changed) {
            $credential->verified_at = null;
        }

        $created = ! $credential->exists;

        // `provider_slot` is deliberately absent: task 6.1's `saving` hook derives it from
        // `provider`, and writing it here would make the unique index's column have two
        // sources of truth.
        $credential->save();

        return new ChannelCredentialWrite(
            $credential,
            $created,
            // From the bag in hand, not `$credential->secretKeys()`: the model would have to
            // decrypt the envelope it has just sealed to answer, and one decryption per write
            // is one more than this needs.
            $this->fieldNames($bag),
            $removed,
            $effectiveConfig,
            $changed,
        );
    }

    /**
     * The field names of a secret bag, sorted — names only, which is all that leaves this
     * class.
     *
     * @param  array<array-key, mixed>  $bag
     * @return list<string>
     */
    private function fieldNames(array $bag): array
    {
        $names = array_map(static fn (int|string $key): string => (string) $key, array_keys($bag));

        sort($names);

        return $names;
    }

    /**
     * Merge submitted secrets over the stored bag.
     *
     * Merged rather than replaced because a credential screen can never echo a stored secret
     * back: a form the operator used to rotate `access_token` legitimately carries nothing for
     * `app_secret`, and replace-wholesale would strip it. So:
     *
     * | Submitted value | Result |
     * |---|---|
     * | a non-empty value | stored, replacing any previous value for that key |
     * | `null` | the key is removed — the only spelling of "delete this secret" |
     * | `''` (or a blank string) | ignored, the stored value is kept |
     *
     * Blank meaning "not supplied" is the important one: an untouched password field posts an
     * empty string, and treating that as a deletion would quietly disarm a working credential
     * set every time someone re-saved the screen.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function mergeSecrets(array $stored, array $submitted): array
    {
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
     * The status a written row carries.
     *
     * `INVALID` is a verdict about material that has just been replaced, so new material
     * earns a fresh chance; `DISABLED` is a tenant's deliberate choice, which editing config
     * must not silently undo.
     */
    private function statusFor(?ChannelCredential $existing): ChannelCredentialStatus
    {
        if ($existing === null || $existing->status === ChannelCredentialStatus::Invalid) {
            return ChannelCredentialStatus::Active;
        }

        return $existing->status;
    }

    /*
    |--------------------------------------------------------------------------
    | Auditing
    |--------------------------------------------------------------------------
    */

    /**
     * What the append-only trail keeps about a credential write: that it happened, to which
     * mode/provider/label, which secret fields are now set, and which were removed.
     *
     * @return array<string, mixed>
     */
    private function auditPayload(ChannelCredentialWrite $write): array
    {
        return [
            'mode' => $write->credential->mode->value,
            'provider' => $write->credential->provider?->value,
            'label' => $write->credential->label,
            'status' => $write->credential->status->value,
            // Names, never values. Spelled `sealed_fields` rather than `secret_keys` on
            // purpose: `AuditPayloadRedactor` redacts any payload key matching
            // `PiiKeyRules::SECRET_PATTERN`, and `secret_keys` matches — the list of field
            // names, which is exactly what a reviewer needs, would arrive as `[redacted]`.
            'sealed_fields' => $write->sealedFields,
            'sealed_fields_removed' => $write->removedFields,
            'config' => $this->auditableConfig($write->config),
            // Whether this write invalidated a previous driver verification, so the trail
            // shows why task 7.6 had to re-validate.
            'verification_cleared' => $write->changed,
        ];
    }

    /**
     * Non-secret config as the trail may keep it: verbatim for the reviewed keys, redacted
     * otherwise.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function auditableConfig(array $config): array
    {
        $auditable = [];

        foreach ($config as $key => $value) {
            $auditable[$key] = in_array($key, self::AUDITED_CONFIG_KEYS, true) ? $value : self::REDACTED;
        }

        ksort($auditable);

        return $auditable;
    }

    /*
    |--------------------------------------------------------------------------
    | Scoping
    |--------------------------------------------------------------------------
    */

    /**
     * Run `$query` bound to `$tenant`, or refuse.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $query
     * @return TReturn
     *
     * @throws CrossTenantAccessException when a different tenant is bound
     */
    private function scopedTo(Tenant $tenant, Closure $query): mixed
    {
        $this->assertActingFor($tenant);

        if ($this->context->currentId() === $tenant->id) {
            return $query();
        }

        /** @var TReturn $result */
        $result = $this->context->runFor($tenant, $query);

        return $result;
    }

    /**
     * Refuse to answer about `$tenant` while a *different* tenant is bound.
     *
     * Not an empty result: with only the global scope the two tenant predicates would `AND` to
     * nothing, and the caller would read a scoping bug as "this tenant has no credentials for
     * this mode" — a state Req 8.13 makes the platform act on. A caller with no tenant bound
     * (console, scheduler, platform mode, a job that has not bound one) is allowed through and
     * gets `runFor()`.
     *
     * @throws CrossTenantAccessException when a different tenant is bound
     */
    private function assertActingFor(Tenant $tenant): void
    {
        $acting = $this->context->currentId();

        if ($acting !== null && $acting !== $tenant->id) {
            throw CrossTenantAccessException::forRetrieval(ChannelCredential::class, $tenant->id, $acting);
        }
    }

    /**
     * Rows owned by `$tenant` — the explicit half of the tenant predicate, on top of the
     * `BelongsToTenant` global scope `scopedTo()` has already bound.
     *
     * @return Builder<ChannelCredential>
     */
    private function owned(Tenant $tenant): Builder
    {
        return ChannelCredential::query()->where(TenantScope::COLUMN, $tenant->id);
    }

    /**
     * The four-part identity `uniq(tenant_id, mode, provider_slot, label)` constrains.
     *
     * @return Builder<ChannelCredential>
     */
    private function identity(
        Tenant $tenant,
        ChannelMode $mode,
        ?BspProvider $provider,
        string $label,
    ): Builder {
        return $this->owned($tenant)
            ->where('mode', $mode)
            // Null becomes `provider is null` in Laravel's builder, which is what makes this
            // the identity for the three modes that name no provider.
            ->where('provider', $provider)
            ->where('label', $label);
    }

    /*
    |--------------------------------------------------------------------------
    | Arguments
    |--------------------------------------------------------------------------
    */

    /**
     * The provider a **read** should filter on.
     *
     * Lenient: a provider handed to a mode that does not use one is dropped rather than
     * refused, so a caller looping over providers can ask about `CLOUD_API` without a special
     * case, and a read cannot corrupt anything by being answered too broadly.
     *
     * A `BSP_GATEWAY` read with no provider named is answered across the tenant's partners,
     * newest-verified-first — the panel names the partner when it has one.
     */
    private function readProvider(ChannelMode $mode, ?BspProvider $provider): ?BspProvider
    {
        return $mode->usesProvider() ? $provider : null;
    }

    /**
     * The provider a **write** must name, or a hard failure.
     *
     * Strict where reads are lenient: a write with contradictory arguments would create a row
     * under an identity the caller does not think it used — a `BSP_GATEWAY` set with no
     * partner, or a `CLOUD_API` set the caller believes is Twilio's. Both are bugs worth
     * hearing about at the call site.
     *
     * @throws InvalidArgumentException when the mode and the provider disagree
     */
    private function writeProvider(ChannelMode $mode, ?BspProvider $provider): ?BspProvider
    {
        if ($mode->usesProvider() && $provider === null) {
            throw new InvalidArgumentException(sprintf(
                'Channel mode [%s] is fronted by a BSP partner, so a BspProvider must be named when storing '
                .'its credentials.',
                $mode->value,
            ));
        }

        if (! $mode->usesProvider() && $provider !== null) {
            throw new InvalidArgumentException(sprintf(
                'Channel mode [%s] names no BSP partner, so [%s] cannot be stored against it.',
                $mode->value,
                $provider->value,
            ));
        }

        return $provider;
    }

    /**
     * A blank label means the tenant did not name this set.
     */
    private function labelFor(?string $label): string
    {
        $label = trim($label ?? '');

        return $label === '' ? ChannelCredential::DEFAULT_LABEL : $label;
    }

    /*
    |--------------------------------------------------------------------------
    | Memo keys
    |--------------------------------------------------------------------------
    */

    private function memoKey(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider, ?string $label): string
    {
        return $this->memoPrefix($tenant, $mode, $provider).($label ?? '');
    }

    /**
     * Every memo scope a change to `(tenant, mode, provider)` invalidates — which for a
     * provider-using mode is **two**, and the second one is not optional.
     *
     * `rowFor($tenant, BSP_GATEWAY)` with no partner named is answered across the tenant's
     * partners, and it is memoised under the provider-less scope. So a write to *any one*
     * partner changes what that lookup answers, and a `{tenant}|{mode}|{partner}|` prefix does
     * not reach it. That lookup is not hypothetical: it is exactly the one
     * `DefaultChannelRouter::hasCredentials()` makes, so leaving the provider-less memo
     * standing meant a partner's **first** credential set was written and then ignored — the
     * remembered "this tenant has no BSP credentials" outliving the write that gave it some,
     * and routing refusing a BSP driver for the rest of that request or job. One `forget()`
     * call has to be enough; a caller cannot be expected to know it needs two.
     *
     * The reverse direction is covered as well: a provider-less `forget()` on a mode that
     * *does* use one clears every partner's scope, because the caller named no partner and
     * therefore meant the mode. Both directions err towards forgetting too much, which is free
     * — the memo is an intra-request deduplication and never a correctness dependency (see the
     * class docblock), so the only cost of an extra clear is one indexed lookup.
     *
     * @return list<string> distinct, so a mode with no provider is not walked twice
     */
    private function invalidatedPrefixes(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider): array
    {
        $prefixes = [$this->memoPrefix($tenant, $mode, $provider)];

        if (! $mode->usesProvider()) {
            return $prefixes;
        }

        if ($provider !== null) {
            // The mode-wide lookup, whose answer this partner's write may have changed.
            $prefixes[] = $this->memoPrefix($tenant, $mode, null);

            return $prefixes;
        }

        foreach (BspProvider::cases() as $case) {
            $prefixes[] = $this->memoPrefix($tenant, $mode, $case);
        }

        return $prefixes;
    }

    /**
     * Everything about one `(tenant, mode, provider)`, whatever its label — what a write to
     * any label of that triple invalidates, because a new label can outrank the old one.
     */
    private function memoPrefix(Tenant $tenant, ChannelMode $mode, ?BspProvider $provider): string
    {
        return sprintf(
            '%s|%s|%s|',
            $tenant->id,
            $mode->value,
            $provider === null ? ChannelCredential::NO_PROVIDER : $provider->value,
        );
    }
}
