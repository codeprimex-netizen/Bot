<?php

declare(strict_types=1);

namespace App\Services\Url;

use App\Support\Cache\VersionedCache;
use Closure;

/**
 * The cache behind `BaseUrl`, and the seam writers invalidate it through
 * (Req 30.4 / NFR1).
 *
 * ## Why the hottest read on the platform is cached
 *
 * Resolving the canonical base is on the path of *every* absolute link, every webhook
 * registration, and every signed URL. Resolved from the database each time, it would
 * be one query for the `platform_settings` override plus one for the tenant's verified
 * domain per URL emitted — hundreds per rendered page. So the resolved string is
 * cached in a `VersionedCache` namespace, following `PlanRepository` and
 * `TierResolver`: entries live under `url:base:v{n}:...` and invalidation is one atomic
 * increment of `{n}`.
 *
 * ## How a settings edit or a domain verification takes effect
 *
 * Without a manual cache clear, in both cases, because the writers bump the version
 * rather than relying on the TTL:
 *
 *  - `PlatformSettingObserver` (`saved`/`deleted`) — an admin saving the System-settings
 *    screen (task 32.6) or `PlatformSettings::set('base_url', ...)`;
 *  - `TenantDomain::booted()` (`saved`/`deleted`) — a domain verified, unverified, or
 *    removed by task 5.5's verification machinery.
 *
 * A bump invalidates the whole namespace, so one tenant's domain change also drops the
 * platform entry and every other tenant's. That is the blunt instrument on purpose, the
 * same argument `PlanObserver` makes: these writes are rare, the resolve is two indexed
 * lookups, and a stale base URL is expensive — it is a link pointing at the wrong
 * origin, which fails remotely and looks like it worked.
 *
 * A write that bypasses Eloquent events (`PlatformSetting::query()->update()`, raw SQL,
 * a restore) must call `flush()` itself.
 */
final class BaseUrlCache
{
    /**
     * Cache namespace — the unit a bump invalidates.
     */
    public const string CACHE_NAMESPACE = 'url:base';

    private readonly VersionedCache $cache;

    public function __construct(?VersionedCache $cache = null)
    {
        $this->cache = $cache ?? VersionedCache::for(
            self::CACHE_NAMESPACE,
            (int) config('wa.cache.ttl.base_url', 300),
        );
    }

    /**
     * The cached base under $key, resolving it on a miss.
     *
     * A cached value that is not a non-empty string is treated as a miss rather than
     * returned: the entry is only ever written by this method, so a foreign value means
     * a key collision or a corrupted store, and serving it would put an arbitrary
     * string where a URL host belongs.
     *
     * @param  Closure(): string  $resolve
     */
    public function remember(string $key, Closure $resolve): string
    {
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resolved = $resolve();

        $this->cache->put($key, $resolved);

        return $resolved;
    }

    /**
     * Invalidate every cached base at once, returning the new version.
     */
    public function flush(): int
    {
        return $this->cache->bump();
    }

    /**
     * The current version — for diagnostics, and for tests asserting that a write
     * invalidated the namespace.
     */
    public function version(): int
    {
        return $this->cache->version();
    }
}
