<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Models\ChannelCredential;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * One tenant's credentials for one channel mode, **decrypted, for the length of this
 * request or job and no longer** — what `ChannelDriver::register()`, `healthCheck()` and
 * `parseWebhook()` are handed (Req 8.5, 8.6 / A8; Req 32.3 / NFR3; design § Channel Mode
 * 2.4, 2.7).
 *
 * ```php
 * $credentials = app(ChannelCredentialStore::class)->for($tenant, ChannelMode::CloudApi);
 *
 * $credentials->requireConfig('phone_number_id');   // an identifier: read it, log it, show it
 * $credentials->requireSecret('access_token');      // a secret: use it on the wire, nowhere else
 * ```
 *
 * `ChannelCredentialStore::for()` (task 6.4) is the intended producer; `ChannelCredential`
 * is the row it reads. This class is the *shape the driver sees*, and it exists rather than
 * the model being passed directly for one reason: a driver handed an Eloquent model can
 * lazily load relations, write to it, serialise it into a job, or re-read `secrets()` at
 * any point. Handed this, it can do none of those.
 *
 * ## Config and secrets are separated, and the asymmetry is the point
 *
 * `config` holds provider *identifiers* — `waba_id`, `phone_number_id`, `api_version`,
 * `endpoint`, `sender`. They are safe in a log, an audit payload, and a panel screen, so
 * reading one is an ordinary array access.
 *
 * `secrets` holds material that authenticates the platform to the provider. It is
 * available through `secret()` / `requireSecret()` only, and four properties of this class
 * keep it from travelling further than the call that needed it:
 *
 * 1. **Private, so no serialiser can see it.** `json_encode()` walks public properties;
 *    `secrets` is private and this class implements neither `Arrayable` nor
 *    `JsonSerializable`, so it cannot appear in an API resource, a Livewire payload, or a
 *    `Log::info('…', ['creds' => $c])` context.
 * 2. **Unserialisable, loudly.** `__serialize()` throws. A queue payload is written to the
 *    database or Redis in the clear, so a job that closes over credentials is a plaintext
 *    secret at rest; that job now fails at dispatch instead. Pass the tenant and the mode,
 *    and resolve again inside `handle()`.
 * 3. **Absent from debug output.** `__debugInfo()` reports which secret keys exist and no
 *    value, so `dd()`, `var_dump()`, and PHPUnit's failure exporter print
 *    `['access_token' => '[redacted]']`.
 * 4. **Scrubbable on the way out.** `redact()` removes any stored secret value from a
 *    string. Provider error bodies quote what they were sent — Meta and several BSPs echo
 *    an `Authorization` header fragment on a `401` — so `ChannelHealth` and
 *    `RegistrationResult` require a `ChannelCredentials` and pass their `detail` through
 *    it. That is why those two types have no public constructor.
 *
 * Residual risk, stated rather than papered over: `redact()` matches whole stored values,
 * so a provider that echoes a *truncated* token is not caught by it. The second line of
 * defence is key-name based and already in place — `PiiKeyRules::SECRET_PATTERN` matches
 * `access_token`, `verify_token`, `api_key`, `webhook_secret`, `app_secret`, `password`
 * and `signature`, so `LogPiiScrubber` redacts them wherever they are named in log context.
 *
 * ## The provider invariant
 *
 * `BSP_GATEWAY` credentials must name a `BspProvider` and the other three modes must not.
 * The database cannot say so (`channel_credentials.provider` is nullable for the three
 * modes that have no provider), so it is said here, at the boundary a driver reads —
 * because `BspGatewayChannelDriver` (task 7.4) resolves its whole per-provider capability
 * sub-matrix from that field, and a null there would silently degrade to the mode ceiling
 * for every partner.
 */
final class ChannelCredentials
{
    /**
     * The longest a `detail` string may be before `redact()` truncates it.
     *
     * A bound rather than a policy: an unbounded provider error body is how a response
     * that happens to contain a token ends up copied into an audit row in full.
     */
    public const int MAX_DETAIL_LENGTH = 500;

    /**
     * The shortest secret value `redact()` will scan for.
     *
     * A one- or two-character "secret" appears inside ordinary English, and scrubbing it
     * would turn every message into `[redacted]`-confetti while protecting nothing. Real
     * provider credentials are long; a short one is a misconfiguration, and the honest
     * answer is that this class cannot hide it.
     */
    public const int MIN_REDACTABLE_LENGTH = 6;

    /**
     * @param  array<string, mixed>  $config  provider identifiers — safe to read, log, and display
     * @param  array<string, mixed>  $secrets  authentication material — `secret()` is the only exit
     *
     * @throws InvalidArgumentException when the mode/provider pairing is impossible
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ChannelMode $mode,
        public readonly ?BspProvider $provider = null,
        public readonly ?string $credentialId = null,
        private readonly array $config = [],
        #[SensitiveParameter]
        private readonly array $secrets = [],
    ) {
        if ($this->mode->usesProvider() && $this->provider === null) {
            throw new InvalidArgumentException(sprintf(
                'Credentials for [%s] must name a BspProvider: one driver fronts %d partners and '
                .'resolves its capability sub-matrix from that field, so a missing provider would '
                .'silently report the mode ceiling for all of them.',
                $this->mode->value,
                count(BspProvider::cases()),
            ));
        }

        if (! $this->mode->usesProvider() && $this->provider !== null) {
            throw new InvalidArgumentException(sprintf(
                'Credentials for [%s] must not name a BspProvider [%s]: only BSP_GATEWAY is fronted '
                .'by a partner, and a provider here would be read by nothing.',
                $this->mode->value,
                $this->provider->value,
            ));
        }

        if (trim($this->tenantId) === '') {
            throw new InvalidArgumentException(
                'Channel credentials must name the tenant they belong to: every driver call made with '
                .'them is a call on that tenant\'s behalf, and an unattributed one cannot be audited.'
            );
        }
    }

    /**
     * The decrypted view of one stored credential row.
     *
     * The single translation from storage to driver, so "the secret bag is decrypted once,
     * at the boundary" is a fact about one method rather than a habit. Task 6.4's
     * `ChannelCredentialStore::for()` is expected to return this.
     */
    public static function fromModel(ChannelCredential $credential): self
    {
        /** @var array<string, mixed> $config */
        $config = $credential->config ?? [];

        return new self(
            tenantId: $credential->tenant_id,
            mode: $credential->mode,
            provider: $credential->provider,
            credentialId: $credential->id,
            config: $config,
            secrets: $credential->secrets(),
        );
    }

    /**
     * Credentials for a mode the *platform* configures rather than the tenant — in practice
     * `BAILEYS`, whose bridge base URL and shared token are `config('wa.bridge')` and not a
     * tenant secret at all (`ChannelMode::requiresTenantCredentials()` is false for it).
     *
     * It still takes a tenant id, because a Baileys driver call is still made on one
     * tenant's behalf and the session it addresses belongs to exactly one of them.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    public static function platform(
        string $tenantId,
        ChannelMode $mode = ChannelMode::Baileys,
        array $config = [],
        #[SensitiveParameter]
        array $secrets = [],
    ): self {
        return new self(
            tenantId: $tenantId,
            mode: $mode,
            config: $config,
            secrets: $secrets,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Non-secret config
    |--------------------------------------------------------------------------
    */

    /**
     * One provider identifier, or `$default`.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * One provider identifier that must be there, as a string.
     *
     * Named `require…` because the failure is a *configuration* error a tenant can fix, and
     * the message says which key is missing. Key names are provider vocabulary
     * (`phone_number_id`), never secrets, so naming them is safe and is the only way the
     * message is actionable.
     *
     * @throws InvalidArgumentException when the key is absent or not a non-empty scalar
     */
    public function requireConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                'The [%s] credentials for this tenant have no [%s]. Configured keys: %s.',
                $this->mode->value,
                $key,
                $this->configKeys() === [] ? '<none>' : implode(', ', $this->configKeys()),
            ));
        }

        return $value;
    }

    public function hasConfig(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    /**
     * @return list<string>
     */
    public function configKeys(): array
    {
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($this->config));
        sort($keys);

        return $keys;
    }

    /*
    |--------------------------------------------------------------------------
    | Secrets
    |--------------------------------------------------------------------------
    */

    /**
     * One secret value, or null.
     *
     * Use it on the wire and let it go. Do not assign it to a property, put it in a job
     * payload, interpolate it into an exception message, or return it from anything a
     * controller can reach.
     */
    public function secret(string $key): ?string
    {
        $value = $this->secrets[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * One secret value that must be there.
     *
     * The message names the *key* and never the value, and it lists the keys that are
     * present so a tenant who pasted a token into the wrong field can see it — which is
     * exactly what `ChannelCredential::secretKeys()` exists for, one layer down.
     *
     * @throws InvalidArgumentException when the key is absent or empty
     */
    public function requireSecret(string $key): string
    {
        $value = $this->secret($key);

        if ($value === null) {
            throw new InvalidArgumentException(sprintf(
                'The [%s] credentials for this tenant have no [%s]. Stored secret keys: %s.',
                $this->mode->value,
                $key,
                $this->secretKeys() === [] ? '<none>' : implode(', ', $this->secretKeys()),
            ));
        }

        return $value;
    }

    public function hasSecret(string $key): bool
    {
        return $this->secret($key) !== null;
    }

    /**
     * Whether any secret material is present at all.
     *
     * What Req 8.13's "a mode whose credentials are absent simply cannot be selected"
     * reduces to, for a mode that needs them.
     */
    public function hasSecrets(): bool
    {
        foreach (array_keys($this->secrets) as $key) {
            if ($this->secret((string) $key) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which secret keys are configured — names only, sorted.
     *
     * @return list<string>
     */
    public function secretKeys(): array
    {
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($this->secrets));
        sort($keys);

        return $keys;
    }

    /**
     * Whether these credentials are complete enough for a driver to use.
     *
     * Both halves of `ChannelCredential::isUsable()`'s argument, restated on the decrypted
     * view: a mode that needs tenant secrets and has none must leave the platform on the
     * `BAILEYS` default rather than be attempted and fail at the provider (Req 8.13 / A8).
     */
    public function isComplete(): bool
    {
        return ! $this->mode->requiresTenantCredentials() || $this->hasSecrets();
    }

    /*
    |--------------------------------------------------------------------------
    | Keeping secrets out of what leaves the driver
    |--------------------------------------------------------------------------
    */

    /**
     * `$text` with every stored secret value replaced by `[redacted]`, bounded in length.
     *
     * The scrubber `ChannelHealth` and `RegistrationResult` run their `detail` through, so
     * a provider response quoted into one of them cannot carry a token back out. Values
     * shorter than `MIN_REDACTABLE_LENGTH` are left alone — see that constant.
     *
     * Longest-first, so a credential that is a prefix of another (a key id inside a
     * composite token) does not leave the longer one half-scrubbed and still guessable.
     */
    public function redact(string $text): string
    {
        $values = [];

        foreach ($this->secrets as $value) {
            if (is_string($value) && mb_strlen($value) >= self::MIN_REDACTABLE_LENGTH) {
                $values[] = $value;
            }
        }

        usort($values, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $scrubbed = $values === [] ? $text : str_replace($values, '[redacted]', $text);

        return mb_strimwidth($scrubbed, 0, self::MAX_DETAIL_LENGTH, '…');
    }

    /**
     * Whether `$text` contains any redactable secret value.
     *
     * The assertion a test makes about a driver's error path. Production code should
     * `redact()` rather than branch on this: knowing a string is clean is only useful if
     * the alternative is to publish it.
     */
    public function containsSecret(string $text): bool
    {
        foreach ($this->secrets as $value) {
            if (is_string($value)
                && mb_strlen($value) >= self::MIN_REDACTABLE_LENGTH
                && str_contains($text, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What `dd()`, `var_dump()`, and a test-failure diff are allowed to see.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'tenantId' => $this->tenantId,
            'mode' => $this->mode->value,
            'provider' => $this->provider?->value,
            'credentialId' => $this->credentialId,
            'config' => $this->config,
            'secrets' => array_fill_keys($this->secretKeys(), '[redacted]'),
        ];
    }

    /**
     * Refuse to be serialised.
     *
     * Not a precaution — a job payload is stored in the clear, so `dispatch(new Sync($creds))`
     * would write a decrypted access token into `jobs` and leave it there until the worker
     * picks it up (and in `failed_jobs`, indefinitely, if it does not). Req 8.5 says
     * credentials are decrypted "only within the lifetime of the request or job", and this
     * is the mechanism that makes that true of the type rather than of the reviewer.
     *
     * Dispatch the tenant id and the mode instead, and resolve inside `handle()`.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException(sprintf(
            'Refusing to serialise [%s] credentials: a queue or cache payload is stored in the clear, '
            .'so this would write a decrypted secret to disk. Pass the tenant id and the channel mode, '
            .'and resolve the credentials again where they are used.',
            $this->mode->value,
        ));
    }
}
