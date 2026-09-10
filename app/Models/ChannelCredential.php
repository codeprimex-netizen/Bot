<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Enums\BspProvider;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ChannelCredentialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One tenant's credentials for one channel mode — non-secret config in the clear, secrets
 * envelope-encrypted and never serialised (Req 8.5, 8.6, 8.13 / A8; Req 32.3 / NFR3).
 *
 * ```php
 * $credential = ChannelCredential::activeFor(ChannelMode::CloudApi);
 * $credential?->config['waba_id'];      // readable: an identifier, not a secret
 * $credential?->secrets()['access_token'];  // the one sanctioned exit, in-request only
 * ```
 *
 * ## "NEVER returned raw to UI/logs" is four independent mechanisms
 *
 * design.md says it twice, so it is structural here rather than a convention:
 *
 * 1. **Encrypted at rest.** `secret_config` is cast through `App\Casts\EncryptedArray`, so
 *    the column holds one `FieldCipher` envelope sealed under *this row's tenant's* DEK.
 *    The bag is encrypted whole, which hides the field names too — the ciphertext does not
 *    advertise which provider a tenant uses.
 * 2. **Out of every serialisation.** `secret_config` is in `$hidden`, so `toArray()`,
 *    `toJson()`, an API resource, a Livewire payload, and `Log::info('…', ['cred' => $c])`
 *    (which serialises the model as `Arrayable`) all omit it. `secrets()` is the only way
 *    to obtain the values, and it is a method — impossible to reach by accident from a
 *    template or a `json_encode`.
 * 3. **Out of log context by key name.** `App\Support\Pii\PiiKeyRules::SECRET_PATTERN`
 *    matches `secret_config` itself and every key the bag holds (`access_token`,
 *    `verify_token`, `api_key`, `webhook_secret`, `app_secret`, `password`), so a caller who
 *    hands the *decrypted array* to a logger still gets `[redacted]` from
 *    `LogPiiScrubber` — the case `$hidden` cannot cover, because by then the values are a
 *    plain array. `ChannelCredentialSecrecyTest` pins every key.
 * 4. **Not retained after the read.** Eloquent's class-cast cache only holds *objects*, and
 *    `EncryptedArray` returns an array, so the decrypted bag is never cached on the model:
 *    it exists in the caller's variable for as long as the caller keeps it, and a second
 *    `secrets()` call decrypts again. That is what makes Req 8.5's "only within the lifetime
 *    of the request or job" true of the model rather than a promise about call sites.
 *
 * What this model does **not** do is decide anything about credentials: reading them for a
 * `(tenant, mode)` pair, writing them, and validating them with the driver before activation
 * belong to `ChannelCredentialStore` (task 6.4) and task 7.6. The scopes below are the
 * queries those services will issue, stated once.
 *
 * ## `provider_slot`, and why you never write it
 *
 * `provider` is NULL for every mode but `BSP_GATEWAY`, and NULLs are distinct in a unique
 * index — so `uniq(tenant_id, mode, provider, label)` would not constrain the modes most
 * tenants use. `provider_slot` is derived from `provider` in the `saving` hook purely so the
 * database can carry the constraint, exactly as `SigningSecret::$active_flag` is derived from
 * `accepted_until`. Set `provider`; the slot follows.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ChannelMode $mode
 * @property BspProvider|null $provider
 * @property string $provider_slot
 * @property string $label
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $secret_config
 * @property ChannelCredentialStatus $status
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CloudApiTemplate> $templates
 */
class ChannelCredential extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ChannelCredentialFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * The `provider_slot` value standing in for "this mode has no provider".
     *
     * A single character that cannot collide with a `BspProvider` value (they are all
     * upper-case alphanumerics), so the derived column is unambiguous.
     */
    public const string NO_PROVIDER = '-';

    /**
     * The label a credential set gets when the tenant does not name one.
     */
    public const string DEFAULT_LABEL = 'default';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider_slot' => self::NO_PROVIDER,
        'label' => self::DEFAULT_LABEL,
        'status' => ChannelCredentialStatus::Active->value,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'mode',
        'provider',
        'label',
        'config',
        'secret_config',
        'status',
        'verified_at',
    ];

    /**
     * The secret bag never appears in an array or a JSON document.
     *
     * Mechanism 2 of the four in the class docblock. `provider_slot` is hidden as well, for
     * a different reason: it is storage plumbing for a unique index, and exposing it in an
     * API payload would invite a client to send it back.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret_config',
        'provider_slot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Cast to the enums so a value written by a newer release surfaces as a cast
            // error at the boundary rather than as a string no router can dispatch on.
            'mode' => ChannelMode::class,
            'provider' => BspProvider::class,
            'status' => ChannelCredentialStatus::class,
            'config' => 'array',
            // Envelope-encrypted whole, under this row's tenant's DEK.
            'secret_config' => EncryptedArray::class,
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the derived slot in lockstep with what it is derived from, so
        // uniq(tenant_id, mode, provider_slot, label) really does mean "one credential set
        // per tenant per mode per provider per label" — including for the three modes whose
        // `provider` is NULL.
        static::saving(static function (self $credential): void {
            $provider = $credential->provider;

            $credential->setAttribute(
                'provider_slot',
                $provider instanceof BspProvider ? $provider->value : self::NO_PROVIDER,
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Secrets
    |--------------------------------------------------------------------------
    */

    /**
     * The decrypted secret bag — the single sanctioned exit for secret material.
     *
     * Callers must treat the result as request-lifetime only: use it, do not store it, do
     * not log it, do not put it in a job payload (a queue payload is written to a database
     * or Redis in the clear — pass the credential *id* and re-read). Task 6.4's
     * `ChannelCredentialStore::for()` is the intended caller.
     *
     * Returns `[]` rather than null when nothing is stored, so a caller reading one key does
     * not need a null check before an array access — "no secrets" and "no such key" are the
     * same answer to `$secrets['access_token'] ?? null`.
     *
     * @return array<string, mixed>
     */
    public function secrets(): array
    {
        /** @var array<string, mixed>|null $secrets */
        $secrets = $this->secret_config;

        return $secrets ?? [];
    }

    /**
     * Whether any secret material is stored at all.
     *
     * Deliberately does **not** decrypt to answer "is this mode configured?" — a screen
     * asking that question has no business unwrapping a DEK.
     */
    public function hasSecrets(): bool
    {
        // The *current* raw attribute, not the original: a row whose secrets were just
        // cleared must report false before it is saved, and `getRawOriginal()` would still
        // be holding the previous ciphertext.
        $attributes = $this->getAttributes();

        $raw = array_key_exists('secret_config', $attributes)
            ? $attributes['secret_config']
            : $this->getRawOriginal('secret_config');

        return is_string($raw) && $raw !== '';
    }

    /**
     * Which secret fields are configured, without revealing any value.
     *
     * What a credential screen renders ("access token ✓, verify token ✓") and what an audit
     * record names when credentials change. Key names only: they are provider field names,
     * not secrets, and the values never leave this method.
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        $keys = array_map(
            static fn (int|string $key): string => (string) $key,
            array_keys($this->secrets()),
        );

        sort($keys);

        return $keys;
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a send may use this row: the status permits it *and* the mode's required
     * secrets are actually present.
     *
     * Both halves, because Req 8.13 turns on the distinction: a mode whose credentials are
     * missing must be unselectable and must leave the platform on the `BAILEYS` default,
     * rather than being attempted and failing at the provider. `BAILEYS` itself needs no
     * tenant secrets (the bridge's token is platform config), so a Baileys row is usable on
     * status alone.
     */
    public function isUsable(): bool
    {
        if (! $this->status->isUsable()) {
            return false;
        }

        return ! $this->mode->requiresTenantCredentials() || $this->hasSecrets();
    }

    /**
     * Whether a driver has ever confirmed these credentials work (task 7.6).
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * One non-secret config value, or `$default`.
     *
     * `config` holds provider identifiers (`waba_id`, `phone_number_id`, `endpoint`,
     * `sender`, `api_version`) and nothing sensitive, so this accessor is deliberately
     * ordinary — the asymmetry with `secrets()` is the point.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $config = $this->config;

        return is_array($config) ? ($config[$key] ?? $default) : $default;
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * The credential row a send on `$mode` should use, or null when the tenant has none.
     *
     * Newest-verified first, then newest: a rotation (task 7.6) writes a fresh row and the
     * send picks it up without anything having to update a pointer, while an unverified row
     * never displaces a verified one.
     */
    public static function activeFor(ChannelMode $mode, ?BspProvider $provider = null): ?self
    {
        return static::query()
            ->forMode($mode, $provider)
            ->usable()
            ->orderByRaw('verified_at is null')
            ->orderByDesc('verified_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Rows for one mode — and, for `BSP_GATEWAY`, one provider.
     *
     * @param  Builder<ChannelCredential>  $query
     * @return Builder<ChannelCredential>
     */
    public function scopeForMode(Builder $query, ChannelMode $mode, ?BspProvider $provider = null): Builder
    {
        $query->where('mode', $mode);

        return $provider === null ? $query : $query->where('provider', $provider);
    }

    /**
     * Rows a send may use — status only; `isUsable()` adds the has-secrets half, which SQL
     * cannot answer without decrypting.
     *
     * @param  Builder<ChannelCredential>  $query
     * @return Builder<ChannelCredential>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', ChannelCredentialStatus::Active);
    }

    /**
     * Rows whose credentials the driver rejected — what the "needs attention" notice reads.
     *
     * @param  Builder<ChannelCredential>  $query
     * @return Builder<ChannelCredential>
     */
    public function scopeInvalid(Builder $query): Builder
    {
        return $query->where('status', ChannelCredentialStatus::Invalid);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The approved-template registry of the provider account these credentials name.
     *
     * @return HasMany<CloudApiTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(CloudApiTemplate::class, 'credential_id');
    }
}
