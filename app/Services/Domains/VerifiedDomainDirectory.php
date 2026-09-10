<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Models\TenantDomain;
use App\Support\Cache\VersionedCache;

/**
 * The host → tenant lookup every request on a custom domain performs, cached with
 * version-bump invalidation (Req 9.3 / A9; Req 30.4 / NFR1).
 *
 * ## Why this is cached, and why it is cached this way
 *
 * `CustomDomainTenantResolver` runs on the hot path of *every* request — it is the first
 * door in the resolver chain that reads the host — so an uncached lookup is one indexed
 * query per request on a table that changes a handful of times per tenant per year. The
 * pattern is `TierResolver`/`PlanRepository`'s and `VersionedCache`'s: entries live under
 * `domains:verified:v{n}:…` and invalidation is one atomic increment of `{n}`, so after a
 * bump every pre-bump entry is unreachable at once — including the entries this writer
 * did not know about (another host's, the verified-host list, another node's).
 *
 * The bump is fired from `TenantDomain::booted()` (`saved`/`deleted`) alongside task
 * 5.1's `BaseUrlCache` flush, so **verification, revocation and removal all take effect
 * on the next request**, with no TTL to wait out. That symmetry is the point: the same
 * write that starts a host resolving is the write that stops it.
 *
 * ## Negative answers are cached too — deliberately, and safely
 *
 * `tenantIdFor()` caches "no tenant claims this host" as the empty string. Without it,
 * every request to an unknown host would hit the database, which is precisely the
 * request an attacker can generate for free by varying the `Host` header — an unbounded
 * write-free lookup is a cheap way to make the platform work. The cached negative is
 * safe because it is invalidated by the same version bump a verification performs, so a
 * host that becomes verified is never served its old "unknown" answer.
 *
 * ## The host is a key, never an assertion
 *
 * The argument to `tenantIdFor()` comes from the request. It is normalised and looked up
 * against **verified** rows; a host with no verified row resolves to null and the
 * request carries no tenant. Nothing here is used to *build* a URL — that is
 * `BaseUrl`'s job and it never reads a request (Property 27). Both halves matter, and
 * they are opposite: generation never reads the host, resolution reads it but only as a
 * key into rows an operator's verification put there.
 */
final class VerifiedDomainDirectory
{
    /**
     * Cache namespace — the unit a bump invalidates.
     */
    public const string CACHE_NAMESPACE = 'domains:verified';

    /**
     * Cached stand-in for "no verified row claims this host". The empty string rather
     * than null because `VersionedCache::remember()` treats null as a miss by design.
     */
    private const string NO_TENANT = '';

    /**
     * Key holding the whole verified-host list, for `TrustHosts` (task 5.4).
     */
    private const string HOSTS_KEY = 'hosts';

    private readonly VersionedCache $cache;

    public function __construct(?VersionedCache $cache = null)
    {
        $this->cache = $cache ?? VersionedCache::for(
            self::CACHE_NAMESPACE,
            (int) config('wa.cache.ttl.tenant_domains', 300),
        );
    }

    /**
     * The tenant that owns $host through a **verified** custom domain, or null.
     *
     * Null for an unknown host, a host claimed but not yet verified, and a host whose
     * verification was revoked — the three cases a caller must treat identically,
     * because in all three the platform has no evidence the tenant controls the name.
     */
    public function tenantIdFor(string $host): ?string
    {
        $host = $this->normalise($host);

        if ($host === '') {
            return null;
        }

        $cached = $this->cache->remember(
            'host:'.$host,
            fn (): string => TenantDomain::query()
                ->verified()
                ->where('host', '=', $host)
                ->value('tenant_id') ?? self::NO_TENANT,
        );

        return is_string($cached) && $cached !== self::NO_TENANT ? $cached : null;
    }

    /**
     * Every verified custom-domain host on the platform, lowercase.
     *
     * This is the half of Req 9.4's accepted-host allowlist that lives in the database;
     * task 5.4 composes it with the platform apex and the verified tenant subdomains to
     * configure `TrustHosts`. Exposed here rather than assembled there so "a host is
     * accepted" and "a host resolves a tenant" can never answer differently — they read
     * the same rows through the same cache version.
     *
     * @return list<string>
     */
    public function verifiedHosts(): array
    {
        $cached = $this->cache->remember(self::HOSTS_KEY, function (): array {
            /** @var list<string> $hosts */
            $hosts = TenantDomain::query()
                ->verified()
                ->orderBy('host')
                ->pluck('host')
                ->all();

            return $hosts;
        });

        if (! is_array($cached)) {
            return [];
        }

        return array_values(array_filter($cached, 'is_string'));
    }

    /**
     * Invalidate every cached lookup at once, returning the new version.
     *
     * Called from `TenantDomain::booted()`. A write that bypasses Eloquent events
     * (`TenantDomain::query()->update()`, raw SQL) must call this itself — the same rule
     * `BaseUrlCache` states.
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

    /**
     * Lowercase, no root dot, no surrounding whitespace.
     *
     * Deliberately *not* `CanonicalBase::host()`: that throws on a malformed host, and
     * this method's input is an attacker-controlled request header, where a malformed
     * value must produce "no tenant" rather than an exception on every request. The
     * strict normaliser guards the **write** path (`TenantDomain::setHostAttribute()`),
     * so the column holds one canonical spelling and this cheap fold is enough to match
     * it. Anything that does not fold to a stored spelling simply misses.
     */
    private function normalise(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
