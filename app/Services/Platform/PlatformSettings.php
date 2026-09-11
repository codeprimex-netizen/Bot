<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use App\Services\Audit\AuditService;
use App\Support\Cache\VersionedCache;

/**
 * The read/write path for platform-wide settings (Req 9.3 / A9; design § Base URL
 * §U.2), cached and audited.
 *
 * ```php
 * $settings = app(PlatformSettings::class);
 *
 * $settings->baseUrl();                                   // ?string — the Req 9.3 override
 * $settings->set(PlatformSettings::BASE_URL, 'https://bot.example.com');   // validated + audited
 * $settings->forget(PlatformSettings::BASE_URL);           // hand the base URL back to APP_URL
 * ```
 *
 * ## Cached, invalidated by version bump
 *
 * Entries live in a `VersionedCache` namespace (`platform-settings:v{n}:...`) and
 * `PlatformSettingObserver` bumps `{n}` on every write, so a reader composes its key
 * from the post-edit version and cannot name a pre-edit entry. Same pattern as
 * `PlanRepository`; see `VersionedCache` for why a version beats per-key forgetting.
 *
 * ## Audited, and secret-safe by default
 *
 * A platform setting is deployment-wide configuration, so changing one is a platform act
 * with consequences no tenant can see: pointing `base_url` at another origin redirects
 * every webhook callback and signed link the platform emits. Each write therefore lands
 * on the **platform** audit chain with the key and a `from`/`to` diff.
 *
 * Values are only recorded verbatim for keys on `AUDITED_VALUES`. `platform_settings` is
 * also where §Security & Compliance puts payment-gateway keys, and the audit table is
 * append-only and hash-chained — a secret written into it cannot be removed afterwards.
 * So the default is redaction and the exception is a short, reviewed list; task 32.6
 * extends it as it adds settings whose values are safe to keep.
 *
 * ## Validation
 *
 * `set()` does not validate: the model's observer does, on `saving`, so validation is
 * not something a caller can route around by writing the model directly. A malformed
 * `base_url` therefore raises `InvalidBaseUrlException` out of `set()` *before* the row
 * is persisted, before the cache is bumped, and before anything is audited.
 */
final class PlatformSettings
{
    /**
     * Cache namespace — the unit a version bump invalidates.
     */
    public const string CACHE_NAMESPACE = 'platform-settings';

    /**
     * The Req 9.3 runtime override of the canonical base URL.
     */
    public const string BASE_URL = 'base_url';

    /**
     * Keys whose values are safe to record verbatim in the append-only audit trail.
     *
     * @var list<string>
     */
    public const array AUDITED_VALUES = [self::BASE_URL];

    /**
     * What an audit entry shows instead of a value that is not on `AUDITED_VALUES`.
     */
    public const string REDACTED = '[redacted]';

    private readonly VersionedCache $cache;

    public function __construct(
        private readonly AuditService $audit,
        ?VersionedCache $cache = null,
    ) {
        $this->cache = $cache ?? VersionedCache::for(
            self::CACHE_NAMESPACE,
            (int) config('wa.cache.ttl.platform_settings', 300),
        );
    }

    /**
     * How a setting is named in an error message, e.g. `platform_settings['base_url']`.
     */
    public static function settingSource(string $key): string
    {
        return sprintf("platform_settings['%s']", $key);
    }

    /**
     * The value stored under $key, or null when the setting is unset.
     *
     * A missing setting is not cached as a hit (`VersionedCache::remember` does not pin
     * nulls), so configuring a setting for the first time takes effect immediately
     * rather than after the TTL of a negative lookup.
     */
    public function get(string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        return $this->cache->remember(
            'key:'.$key,
            static fn (): mixed => PlatformSetting::query()->where('key', $key)->first()?->value,
        );
    }

    /**
     * The canonical-base override of Req 9.3, or null when none is configured.
     *
     * Blank is treated as unset: an admin clearing the field means "use `APP_URL`", and
     * an empty string would otherwise fail the base-URL parse and take the panels down.
     */
    public function baseUrl(): ?string
    {
        $value = $this->get(self::BASE_URL);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Whether $key has a value.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Write $key, audit the change on the platform chain, and invalidate the caches it
     * feeds (through the observer).
     *
     * Idempotent by design: writing the value a setting already holds still records an
     * audit entry, because "an admin re-saved the settings screen" is a fact the trail
     * should carry, but the row's canonical value cannot change under it.
     *
     * @throws \App\Exceptions\Url\InvalidBaseUrlException when a validated key is given an unusable value
     */
    public function set(string $key, mixed $value): void
    {
        $previous = $this->get($key);

        // updateOrCreate rather than a raw upsert: the observer's validation and
        // invalidation hang off model events, and `unique(key)` keeps two concurrent
        // writers from creating two rows for one setting.
        $setting = PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->audit->writeForPlatform('platform.setting.changed', [
            'key' => $key,
            'from' => $this->auditableValue($key, $previous),
            // The stored value, not the argument: a canonicalising key (`base_url`) may
            // have rewritten it, and the trail should show what is in effect.
            'to' => $this->auditableValue($key, $setting->value),
        ], $setting);
    }

    /**
     * Remove $key, audited. A no-op — including no audit entry — when it was not set.
     */
    public function forget(string $key): void
    {
        $setting = PlatformSetting::query()->where('key', $key)->first();

        if ($setting === null) {
            return;
        }

        $previous = $setting->value;

        $setting->delete();

        $this->audit->writeForPlatform('platform.setting.removed', [
            'key' => $key,
            'from' => $this->auditableValue($key, $previous),
        ], $setting);
    }

    /**
     * Every setting, keyed by name — the admin System-settings screen's read (task 32.6).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $rows = $this->cache->remember('all', static function (): array {
            $settings = [];

            foreach (PlatformSetting::query()->orderBy('key')->get() as $setting) {
                $settings[$setting->key] = $setting->value;
            }

            return $settings;
        });

        if (! is_array($rows)) {
            return [];
        }

        /** @var array<string, mixed> $settings */
        $settings = $rows;

        return $settings;
    }

    /**
     * Invalidate every cached setting at once, returning the new version.
     *
     * Called by `PlatformSettingObserver` after each write; call it directly after a
     * write that bypasses Eloquent events.
     */
    public function flush(): int
    {
        return $this->cache->bump();
    }

    /**
     * The current cache version — for diagnostics and for tests asserting invalidation.
     */
    public function version(): int
    {
        return $this->cache->version();
    }

    /**
     * A value as the audit trail may keep it: verbatim for keys whose values are known
     * not to be secrets, redacted otherwise.
     */
    private function auditableValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return in_array($key, self::AUDITED_VALUES, true) ? $value : self::REDACTED;
    }
}
