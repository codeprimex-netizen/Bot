<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Security\ConfigMasterKeyWrapper;
use App\Services\Security\EnvelopeFieldCipher;
use App\Services\Security\FieldCipher;
use App\Services\Security\KeyWrapper;
use Illuminate\Contracts\Foundation\Application;
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
            if (is_string($id) && $id !== '' && is_string($material) && $material !== '') {
                $map[$id] = $material;
            }
        }

        return $map;
    }
}
