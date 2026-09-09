<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Audit\AuditService;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use App\Services\Security\ConfigMasterKeyWrapper;
use App\Services\Security\DatabaseSigningSecretStore;
use App\Services\Security\DekRotator;
use App\Services\Security\EnvelopeFieldCipher;
use App\Services\Security\FieldCipher;
use App\Services\Security\GuardedKmsClient;
use App\Services\Security\KeyWrapper;
use App\Services\Security\KmsClient;
use App\Services\Security\MasterKeyRewrapper;
use App\Services\Security\RewrapStore;
use App\Services\Security\SigningSecretStore;
use App\Services\Security\VaultTransitKmsClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wires the encryption layer: the KMS seam and the per-tenant field cipher
 * (Req 32.5 / NFR3, NFR4.2).
 *
 * Two bindings, with deliberately different lifetimes.
 *
 * ## `KeyWrapper` — singleton
 *
 * The seam a KMS plugs into. Which implementation is a **config flip**
 * (`wa.security.encryption.wrapper`): the default `ConfigMasterKeyWrapper` works out
 * of the box, and task 4.2's cloud-KMS/Vault adapter replaces it by pointing that
 * key at another class — no change to `FieldCipher`, its callers, or its tests. It
 * holds derived wrapping keys, not tenant data, so one instance per process is
 * right.
 *
 * ## `FieldCipher` — scoped, on purpose
 *
 * `scoped()` rather than `singleton()`, because the cipher memoises **unwrapped
 * DEKs**. Scoped instances are discarded at the end of every request and every
 * queued job, which gives the cache exactly the lifetime design asks for ("cached
 * in-process, never persisted in plaintext") and makes it structurally impossible
 * for a long-lived worker to carry one tenant's plaintext DEK into another tenant's
 * job — the same boundary `TenancyServiceProvider` maintains for `TenantContext`.
 *
 * ## Task 4.2's four additions
 *
 * | Binding | Lifetime | Why |
 * |---|---|---|
 * | `KmsClient` | singleton | the transport below `KeyWrapper`; holds no tenant data, and resolving it is what validates the KMS config — so it is bound lazily and costs nothing when unused |
 * | `SigningSecretStore` | **scoped** | memoises *opened* HMAC secrets, so the cache must die with the request/job rather than outlive a revoked key |
 * | `MasterKeyRewrapper` | singleton | the master-key rotation sweep; its store list is validated eagerly on resolution, because a missing store is a silently un-rotated table |
 * | `DekRotator` | singleton | decides which lineages are due and delegates the rotation itself to `FieldCipher` |
 *
 * `FakeKms` is never bound here or anywhere in `app/`: it lives under `tests/`, is only
 * autoloadable through `autoload-dev`, and is bound by the tests that use it
 * (Property 28 / Req 36).
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyWrapper::class, fn (Application $app): KeyWrapper => $this->keyWrapper($app));

        $this->app->scoped(FieldCipher::class, function (Application $app): FieldCipher {
            $cipher = config('wa.security.encryption.cipher', 'aes-256-gcm');
            $dataKeyBytes = config('wa.security.encryption.data_key_bytes', 32);

            return new EnvelopeFieldCipher(
                $app->make(KeyWrapper::class),
                is_string($cipher) && $cipher !== '' ? $cipher : 'aes-256-gcm',
                is_numeric($dataKeyBytes) ? (int) $dataKeyBytes : 32,
            );
        });

        $this->registerKms();
        $this->registerRotation();
        $this->registerSigningSecrets();
    }

    /**
     * The KMS transport (task 4.2).
     *
     * Bound **lazily and only by interface**: resolving `KmsClient` is what validates the
     * configuration, so a deployment that never uses a KMS — the default — pays nothing and
     * fails nowhere. Guarded by default, because a key store sits inside every encrypted
     * read and write and an unguarded one turns "Vault is slow" into "the platform is down".
     */
    private function registerKms(): void
    {
        $this->app->singleton(KmsClient::class, function (Application $app): KmsClient {
            $client = $this->kmsDriver($app);
            $guard = config('wa.security.kms.guard');
            $guard = is_array($guard) ? $guard : [];

            if (($guard['enabled'] ?? true) !== true) {
                return $client;
            }

            return new GuardedKmsClient(
                $client,
                $app->make(CircuitBreaker::class),
                $app->make(RetryPolicy::class),
                self::stringValue($guard['breaker'] ?? null, 'kms'),
                self::intValue($guard['attempts'] ?? null, GuardedKmsClient::DEFAULT_ATTEMPTS),
                self::intValue($guard['max_delay_ms'] ?? null, GuardedKmsClient::DEFAULT_MAX_DELAY_MS),
            );
        });
    }

    /**
     * Scheduled rotation: the master-key re-wrap sweep and the per-tenant DEK rotator.
     */
    private function registerRotation(): void
    {
        $this->app->singleton(MasterKeyRewrapper::class, fn (Application $app): MasterKeyRewrapper => new MasterKeyRewrapper(
            $app->make(KeyWrapper::class),
            $app->make(AuditService::class),
            $this->rewrapStores($app),
            self::intValue(config('wa.security.encryption.rotation.batch'), 100),
        ));

        $this->app->singleton(DekRotator::class, fn (Application $app): DekRotator => new DekRotator(
            $app->make(FieldCipher::class),
            $app->make(AuditService::class),
            self::intValue(config('wa.security.encryption.rotation.dek_after_days'), 90),
            self::intValue(config('wa.security.encryption.rotation.batch'), 100),
        ));
    }

    /**
     * HMAC signing secrets.
     *
     * `scoped()` for the same reason `FieldCipher` is: the store memoises **opened**
     * secrets, and that cache must die with the request or the job rather than outlive a
     * revoked master key inside a long-running worker.
     */
    private function registerSigningSecrets(): void
    {
        $this->app->scoped(SigningSecretStore::class, function (Application $app): SigningSecretStore {
            $hmac = config('wa.security.hmac');
            $hmac = is_array($hmac) ? $hmac : [];

            return new DatabaseSigningSecretStore(
                $app->make(KeyWrapper::class),
                self::intValue($hmac['secret_bytes'] ?? null, 32),
                self::stringValue($hmac['algorithm'] ?? null, 'sha256'),
                is_string($hmac['prefix'] ?? null) ? $hmac['prefix'] : 'sha256=',
                self::intValue($hmac['overlap_hours'] ?? null, 48) * 3600,
                self::intValue($hmac['rotate_after_days'] ?? null, 30),
            );
        });
    }

    /**
     * Build the configured `KmsClient`.
     *
     * `vault` is the shipped driver; any other value must name a `KmsClient`
     * implementation, which is how `FakeKms` is bound in the testing container and nowhere
     * else. A value that resolves to something else is a startup error — never a silently
     * unencrypted platform.
     */
    private function kmsDriver(Application $app): KmsClient
    {
        $driver = config('wa.security.kms.driver', 'vault');
        $driver = is_string($driver) && $driver !== '' ? $driver : 'vault';

        if ($driver !== 'vault') {
            $client = $app->make($driver);

            if (! $client instanceof KmsClient) {
                throw new InvalidArgumentException(sprintf(
                    'wa.security.kms.driver must be "vault" or name a %s implementation, got [%s].',
                    KmsClient::class,
                    $driver,
                ));
            }

            return $client;
        }

        $vault = config('wa.security.kms.vault');
        $vault = is_array($vault) ? $vault : [];

        return new VaultTransitKmsClient(
            $app->make(HttpFactory::class),
            self::stringValue($vault['address'] ?? null, ''),
            self::stringValue($vault['token'] ?? null, ''),
            is_string($vault['token_file'] ?? null) && $vault['token_file'] !== '' ? $vault['token_file'] : null,
            self::stringValue($vault['mount'] ?? null, 'transit'),
            self::stringValue($vault['key'] ?? null, 'wa-master'),
            is_string($vault['namespace'] ?? null) && $vault['namespace'] !== '' ? $vault['namespace'] : null,
            self::intValue($vault['timeout'] ?? null, 5),
            self::intValue($vault['connect_timeout'] ?? null, 3),
        );
    }

    /**
     * Every table a master-key rotation must re-seal.
     *
     * An entry that cannot be resolved is **fatal**, unlike a wrapper misconfiguration that
     * merely fails closed: a silently skipped store leaves rows sealed under a key an
     * operator is about to retire, and that loss is only discovered once the key is gone.
     *
     * @return list<RewrapStore>
     */
    private function rewrapStores(Application $app): array
    {
        $configured = config('wa.security.encryption.rotation.stores', []);
        $stores = [];

        foreach (is_array($configured) ? $configured : [] as $class) {
            if (! is_string($class) || $class === '') {
                throw new InvalidArgumentException(
                    'wa.security.encryption.rotation.stores must be a list of class names.'
                );
            }

            $store = $app->make($class);

            if (! $store instanceof RewrapStore) {
                throw new InvalidArgumentException(sprintf(
                    'wa.security.encryption.rotation.stores must name %s implementations, got [%s].',
                    RewrapStore::class,
                    $class,
                ));
            }

            $stores[] = $store;
        }

        return $stores;
    }

    private static function stringValue(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private static function intValue(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * Build the configured `KeyWrapper`.
     *
     * The default is constructed here (rather than through the container) because its
     * master keys come from configuration and it must never be resolvable with
     * half-built state. Any other configured class is resolved by the container and
     * type-checked — a misconfiguration is a startup error, never a silently
     * unencrypted platform.
     */
    private function keyWrapper(Application $app): KeyWrapper
    {
        $configured = config('wa.security.encryption.wrapper', ConfigMasterKeyWrapper::class);

        if (is_string($configured) && $configured !== '' && $configured !== ConfigMasterKeyWrapper::class) {
            $wrapper = $app->make($configured);

            if (! $wrapper instanceof KeyWrapper) {
                throw new InvalidArgumentException(sprintf(
                    'wa.security.encryption.wrapper must name a %s implementation, got [%s].',
                    KeyWrapper::class,
                    $configured,
                ));
            }

            return $wrapper;
        }

        $masterKeys = config('wa.security.encryption.master_keys', []);
        $activeKeyId = config('wa.security.encryption.master_key_id', 'app');
        $appKeyId = config('wa.security.encryption.app_key_id', 'app');
        $appKey = config('app.key');

        return new ConfigMasterKeyWrapper(
            self::stringMap(is_array($masterKeys) ? $masterKeys : []),
            is_string($activeKeyId) && $activeKeyId !== '' ? $activeKeyId : 'app',
            is_string($appKeyId) && $appKeyId !== '' ? $appKeyId : 'app',
            is_string($appKey) && $appKey !== '' ? $appKey : null,
        );
    }

    /**
     * Narrow a config array to the `id => material` map `ConfigMasterKeyWrapper`
     * takes, dropping anything that is not a usable pair.
     *
     * @param  array<array-key, mixed>  $keys
     * @return array<string, string>
     */
    private static function stringMap(array $keys): array
    {
        $map = [];

        foreach ($keys as $id => $material) {
            // PHP casts a numeric array key to `int`, so a perfectly reasonable master key
            // id like `2024` arrives here as an integer. Rejecting it would drop the key
            // silently and make every DEK sealed under it unopenable — so it is stringified
            // rather than discarded.
            $key = is_int($id) ? (string) $id : $id;

            if ($key !== '' && is_string($material) && $material !== '') {
                $map[$key] = $material;
            }
        }

        return $map;
    }
}
