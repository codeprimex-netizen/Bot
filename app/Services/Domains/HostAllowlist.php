<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Exceptions\Url\InvalidBaseUrlException;
use App\Support\Url\CanonicalBase;
use Illuminate\Support\Str;

/**
 * The accepted-host allowlist: which `Host` values this deployment will answer at all
 * (Req 9.4, 9.5 / A9; design § Base URL §U.5; Correctness Property 27's third clause).
 *
 * ```php
 * $allowlist->accepts('acme.app.example.com');   // a tenant subdomain — yes
 * $allowlist->accepts('chat.acme.example');      // a *verified* custom domain — yes
 * $allowlist->accepts('evil.example');           // nothing on the platform — no
 * ```
 *
 * ## What the list contains, and what each entry costs to get wrong
 *
 * Exactly the three things Req 9.4 names, plus one operator escape hatch:
 *
 *  1. **the platform's own hosts** — every configured apex (`wa.tenancy.apexes`) and the
 *     host of the canonical base (`PlatformHosts::platformHost()`, i.e. the
 *     `platform_settings['base_url']` override or `APP_URL`). Both, because they are not
 *     always the same string and the platform serves links on both;
 *  2. **tenant subdomains** — *one* DNS label under a configured apex. This is a pattern
 *     rather than an enumeration; see the next section for why, and for what that admits;
 *  3. **verified custom domains** — exact hosts, from
 *     `VerifiedDomainDirectory::verifiedHosts()`. Exact because a custom domain is
 *     somebody else's namespace: `chat.acme.example` being verified says nothing about
 *     `anything.chat.acme.example`, and Req 9.7 requires an **unverified** claim to be
 *     excluded — a tenant could otherwise be served under a name it does not control;
 *  4. **`wa.url.hosts.additional`** — exact hosts an operator adds for an internal name a
 *     load balancer or service mesh dials the node by. Empty by default.
 *
 * The verified half comes from `VerifiedDomainDirectory` rather than from a query of this
 * class's own, and that is the point: "this host is accepted" and "this host resolves a
 * tenant" read the same rows through the same cache version, so a domain verified a second
 * ago is accepted now and a revoked one is refused now (`TenantDomain::booted()` bumps
 * that version). Two independent readers of `tenant_domains` would eventually answer
 * differently, and each direction is its own outage: a host accepted but unresolvable is a
 * request with no tenant, and a host resolvable but unaccepted is a link the platform hands
 * out and then rejects.
 *
 * ## What the subdomain pattern admits — deliberately, and no more
 *
 * For an apex `app.example.com` the accepted set is:
 *
 *  - `app.example.com` — the apex itself;
 *  - `{label}.app.example.com` — where `{label}` is **one** RFC-1035 label (letters,
 *    digits, inner hyphens, ≤ 63 characters).
 *
 * It is *not* `.*\.app\.example\.com`. Rejected, and each for a reason worth stating:
 *
 *  - `a.b.app.example.com` — two labels below the apex is not a tenant subdomain, and
 *    accepting arbitrary depth turns one wildcard certificate into an open host space;
 *  - `evil-app.example.com` — a suffix match without the dot would accept a *different*
 *    registrable domain that merely ends the same way;
 *  - `app.example.com.evil.example` — our apex as a *prefix* of somebody else's name;
 *  - `192.0.2.10`, `[::1]`, `_x.app.example.com` — not hostnames of the shape a label
 *    match can produce.
 *
 * A **reserved** label (`www`, `admin`, `api`, …) *is* accepted, because those hosts are
 * the platform's own — an operator may well serve the admin panel on `admin.{apex}`. It is
 * never accepted *as a tenant*: `PlatformHosts::tenantLabel()` returns null for it, so
 * resolution refuses it however the request arrived. "Accepted host" and "resolves a
 * tenant" are two questions, and only the second is about tenancy.
 *
 * A label under our apex that belongs to no tenant is likewise accepted, and this is a
 * decision rather than an oversight. The alternative — a `tenants` lookup on the hot path
 * of every request, ahead of routing — costs a query per request to reject a host inside
 * *our own* DNS zone (a name only we can publish), and it makes the rejection an
 * enumeration oracle for tenant slugs. Such a host resolves no tenant, so it lands on the
 * same marketing/login page the apex serves; it can never carry a tenant's data. What must
 * be exact is the part of the namespace we do not own, and that half is exact.
 *
 * ## Two consumers, one answer
 *
 * `patterns()` feeds Laravel's `TrustHosts` (and through it Symfony's trusted-host
 * regexp), so the framework refuses an unlisted host at the `Request::getHost()` level;
 * `accepts()` is what `EnforceAllowedHost` calls to produce the typed 400 of Req 9.5.
 * Both are derived from the same rules here, and `HostAllowlistTest` asserts they agree
 * host-for-host — a host one layer accepts and the other refuses is exactly the kind of
 * split that shows up as "works on one route, 400 on another".
 *
 * ## Nothing here reads the request
 *
 * Every method takes its input as an argument, like `PlatformHosts`. A host arrives from a
 * caller as a **string to check**, never as a source of truth, and a path arrives the same
 * way. There is no `Request` to reach a URL through, so Property 27 stays structural.
 */
final class HostAllowlist
{
    /**
     * Named in `InvalidBaseUrlException` messages while normalising the operator list.
     */
    public const string ADDITIONAL_SOURCE = 'wa.url.hosts.additional';

    /**
     * One RFC-1035 label — the same pattern `PlatformHosts` matches a tenant label with,
     * expressed as a regex *fragment* so `patterns()` can embed it.
     */
    private const string LABEL_FRAGMENT = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * Environments where an arbitrary host is a developer typing one, not an attack — the
     * same list, and the same posture, as `TrustHosts::shouldSpecifyTrustedHosts()`.
     *
     * @var list<string>
     */
    private const array LOCAL_ENVIRONMENTS = ['local', 'testing'];

    public function __construct(
        private readonly PlatformHosts $hosts,
        private readonly VerifiedDomainDirectory $domains,
    ) {}

    /**
     * Whether a request arriving on $host may be served.
     *
     * A malformed, empty or unknown host is false — never an exception. This runs on every
     * request with an attacker-controlled argument, so "I do not recognise that" has to be
     * a return value; an exception here would make a junk header a 500 and a log-flood
     * lever.
     */
    public function accepts(string $host): bool
    {
        $host = $this->normalise($host);

        if ($host === '') {
            return false;
        }

        $apexes = $this->hosts->apexes();

        if (in_array($host, $apexes, true)) {
            return true;
        }

        foreach ($apexes as $apex) {
            if ($this->isSingleLabelUnder($host, $apex)) {
                return true;
            }
        }

        return in_array($host, $this->exactHosts(), true);
    }

    /**
     * Regex patterns for `Request::setTrustedHosts()`, via Laravel's `TrustHosts`.
     *
     * Anchored, non-capturing, and matched case-insensitively against an already-lowercased
     * host by Symfony. Non-capturing matters: Symfony joins the patterns into one branch
     * reset group, so a capturing group here would change the meaning of its neighbours'.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        $patterns = [];

        foreach ($this->hosts->apexes() as $apex) {
            // The apex itself, or exactly one label under it.
            $patterns[] = '^(?:'.self::LABEL_FRAGMENT.'\.)?'.preg_quote($apex).'$';
        }

        foreach ($this->exactHosts() as $host) {
            $patterns[] = '^'.preg_quote($host).'$';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * The patterns to trust while serving $path — `[]`, meaning *no host restriction*, on
     * the paths that must answer on a host the platform has not accepted yet.
     *
     * `[]` is Symfony's own spelling of "unrestricted" (`setTrustedHosts([])` clears the
     * regexp), so this needs no second mechanism: the framework middleware simply installs
     * no restriction for those requests, and `EnforceAllowedHost` skips the same paths from
     * the same list.
     *
     * @return list<string>
     */
    public function patternsFor(string $path): array
    {
        return $this->isUnrestrictedPath($path) ? [] : $this->patterns();
    }

    /**
     * Whether $path is served on any host.
     *
     * Two kinds of path are, and both would otherwise fail in production and pass in a
     * test suite that never sends an unrecognised host:
     *
     *  - **the domain-ownership challenge** (`wa.tenancy.domains.http_challenge_path`).
     *    An http-01 challenge is fetched at the host being verified, *before* it is
     *    verified — which is the only state in which that host is legitimately not on the
     *    allowlist. Rejecting it would make the HTTP method permanently unsatisfiable and
     *    leave DNS as the only way to ever verify a domain. `DomainChallengeController`
     *    uses the host solely as part of the lookup key for a token the caller already
     *    presented, binds no tenant, and emits no URL, so the exemption grants nothing but
     *    the echo it exists for;
     *  - **the health check** (`wa.url.hosts.unrestricted_paths`, `up` by default).
     *    Orchestrators dial a container by pod IP or service name, so the `Host` of a
     *    liveness probe is not a name any allowlist knows. A rejected probe is a restart
     *    loop that looks like an application crash. The endpoint returns a fixed status
     *    payload and reads nothing tenant-scoped.
     *
     * The challenge path is read from the same config key `routes/web.php` registers the
     * route from, so the exemption and the route cannot drift apart.
     */
    public function isUnrestrictedPath(string $path): bool
    {
        $path = trim(trim($path), '/');

        foreach ($this->unrestrictedPrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an unrecognised host is refused on this deployment.
     *
     * `wa.url.hosts.enforce` pins the answer; unset, it follows the environment — enforced
     * everywhere except `local`/`testing`, which is exactly what Laravel's own `TrustHosts`
     * does and for the same reason: a development box is reached by `127.0.0.1`, a tunnel
     * hostname, or whatever a container published, and none of those are the configured
     * base. The value is read per request, so an operator can turn enforcement on for a
     * staging environment without a code change.
     */
    public function enforces(): bool
    {
        $configured = config('wa.url.hosts.enforce');

        // Absent means "follow the environment", and it arrives as null (config default) or
        // '' (`WA_URL_ENFORCE_HOSTS=` in an env file). Both are checked before filter_var,
        // which reads either as an explicit false — and that reading would silently disable
        // Req 9.4 on every deployment that never set the key.
        if ($configured === null || $configured === '') {
            return ! $this->isLocalEnvironment();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? ! $this->isLocalEnvironment();
    }

    /**
     * Hosts accepted by exact match: verified custom domains, the platform's own host, and
     * the operator's additions.
     *
     * @return list<string>
     */
    private function exactHosts(): array
    {
        $hosts = $this->domains->verifiedHosts();

        $platform = $this->hosts->platformHost();

        if ($platform !== null) {
            $hosts[] = $platform;
        }

        foreach ($this->configuredList(config('wa.url.hosts.additional')) as $host) {
            try {
                // The strict normaliser for the operator list, the same way an apex is
                // normalised: one spelling per host, and a malformed entry is skipped
                // rather than admitted as a literal nobody can match — an accepted-host
                // list that silently contains `Not A Host` is worse than one entry short,
                // because it reads as though the host were covered.
                $hosts[] = CanonicalBase::host($host, self::ADDITIONAL_SOURCE);
            } catch (InvalidBaseUrlException) {
                continue;
            }
        }

        return array_values(array_unique(array_map(
            fn (string $host): string => $this->normalise($host),
            $hosts,
        )));
    }

    /**
     * Whether $host is exactly one valid DNS label below $apex.
     *
     * The dot is part of the suffix check, so `evil-app.example.com` does not pass for the
     * apex `app.example.com`; the label is then matched whole, so a second dot inside it
     * fails.
     */
    private function isSingleLabelUnder(string $host, string $apex): bool
    {
        if ($host === $apex || ! str_ends_with($host, '.'.$apex)) {
            return false;
        }

        $label = substr($host, 0, -(strlen($apex) + 1));

        return preg_match('/^'.self::LABEL_FRAGMENT.'$/', $label) === 1;
    }

    /**
     * Path prefixes served on any host: the configured list plus the challenge path.
     *
     * @return list<string>
     */
    private function unrestrictedPrefixes(): array
    {
        $prefixes = [];

        foreach ($this->configuredList(config('wa.url.hosts.unrestricted_paths')) as $path) {
            $trimmed = trim($path, '/');

            if ($trimmed !== '') {
                $prefixes[] = $trimmed;
            }
        }

        $challenge = trim((string) config(
            'wa.tenancy.domains.http_challenge_path',
            DomainVerifier::DEFAULT_HTTP_PATH,
        ), '/ ');

        if ($challenge !== '') {
            $prefixes[] = $challenge;
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * Lowercase, trimmed, non-empty strings from a config list.
     *
     * @return list<string>
     */
    private function configuredList(mixed $configured): array
    {
        $values = [];

        foreach (is_array($configured) ? $configured : [] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = Str::lower(trim($value));
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Lowercase, no root dot, no surrounding whitespace — the cheap fold, deliberately not
     * `CanonicalBase::host()`, which throws.
     */
    private function normalise(string $host): string
    {
        return Str::lower(rtrim(trim($host), '.'));
    }

    private function isLocalEnvironment(): bool
    {
        return app()->environment(self::LOCAL_ENVIRONMENTS) === true;
    }
}
