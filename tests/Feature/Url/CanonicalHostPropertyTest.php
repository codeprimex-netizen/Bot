<?php

declare(strict_types=1);

use App\Enums\SignedUrlVerdict;
use App\Exceptions\Url\HostNotAllowedException;
use App\Http\Middleware\EnforceAllowedHost;
use App\Http\Middleware\TrustProxies;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\HostAllowlist;
use App\Services\Platform\PlatformSettings;
use App\Services\Url\BaseUrl;
use App\Services\Url\SignedUrlSigner;
use App\Services\Url\UrlBuilder;
use App\Support\Url\CanonicalBase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Url\CanonicalHostProbe;
use Tests\Fixtures\Url\UrlConfiguration;

/*
|--------------------------------------------------------------------------
| Correctness Property 27 — canonical-host URL generation (no host-header injection)
|--------------------------------------------------------------------------
| design.md: *"∀ absolute link, webhook callback, or signed URL emitted by the platform →
| its host is the configured canonical base (`BaseUrl::platform()`/`forTenant()`), **never**
| derived from the request `Host`/`X-Forwarded-Host` header; a signed URL's signature binds
| the canonical host, so a valid signature cannot be replayed against a different host, and
| requests to non-allowlisted hosts are rejected."*
|
| **Validates: Requirements 9.1, 9.4 / A9, 32.2 / NFR3**
|
| ## What this adds over the scripted tests
|
| `BaseUrlTest`, `UrlBuilderTest`, `SignedUrlTest` and `AcceptedHostTest` already assert
| every clause of Property 27 **by example**, and they are necessary: they pin the
| precedence chain, the refusals, the wiring, and the exemptions. What they cannot do is
| vary two things at once. Each of them fixes `APP_URL` in a `beforeEach` and then varies
| the host, or fixes the host and varies the configuration. The three claims this file adds
| are all products of that missing cross-product:
|
|  1. **Clause 1 over a configuration matrix, not a configuration.** The invariance of an
|     emitted URL is asserted for all 2^5 combinations of `APP_URL` vs a
|     `platform_settings['base_url']` override, apexes configured vs derived, a tenant with
|     vs without a verified custom domain, a `/app` mount vs root, and a non-default port —
|     against 24 classes of hostile `Host`, injected through all seven channels a proxy or
|     the framework could offer, and with no request bound at all. This is the axis the
|     task-5.4 defect lived on: emission read the *resolved* base while the host space was
|     derived from `APP_URL`, so exactly one cell of this matrix was broken. A scripted test
|     found it only because somebody guessed the cell.
|  2. **Clause 2 as a transplant over a generated origin set, not a fixed pair.** Every
|     drawn origin's link is presented at every other drawn origin — hosts, ports and mount
|     points varying independently — and every *equivalent spelling* of the signed host is
|     asserted to still verify. `SignedUrlTest` transplants one link onto six named hosts;
|     this asserts the whole relation, including that two links differing in nothing but
|     their host have different signatures.
|  3. **Clause 3 as an agreement between three answers, not one.** For every drawn host the
|     independent oracle in `CanonicalHostProbe::accepted()`, `HostAllowlist::accepts()`,
|     the Symfony trusted-host patterns from `HostAllowlist::patterns()`, and
|     `EnforceAllowedHost` must all say the same thing. That four-way parity is what found
|     the root-dot inconsistency recorded below.
|
| ## What it found
|
| **A layer disagreement on the root-dotted spelling of a legitimate host.** Symfony's
| `Request::getHost()` strips a trailing port and lowercases, but *keeps* a single root dot
| — `Host: bot.example.test.` arrives at the application as `bot.example.test.`.
| `HostAllowlist::accepts()` folds the root dot away and accepted it (and `AcceptedHostTest`
| asserts it should); `patterns()` emitted `^bot\.example\.test$`, which does not match it.
| In production, where `TrustHosts` installs those patterns, the request therefore died
| inside `getHost()` — a 400 for a host the platform's own allowlist considers its own, on
| a spelling clients and resolvers legitimately send. Fail-closed, but a spurious rejection
| and precisely the split this parity assertion exists to catch. Fixed in `patterns()` by
| admitting the optional root dot, which adds no host to the accepted set — only a second
| spelling of hosts already in it.
|
| ## Anti-vacuity
|
| A test asserting only "the URL did not change" passes against a builder that returns a
| constant, and one asserting only "hosts are rejected" passes against an allowlist that
| rejects everything. So every claim here is paired with its control:
|
|  - every emitted URL is compared against a string **derived from the drawn configuration
|    by `UrlConfiguration`**, not against its own earlier value alone, and is asserted to be
|    a well-formed URL whose host is the configured one;
|  - six of the 24 host classes are hosts the platform **must accept**, and the oracle — not
|    the test author — decides which;
|  - a valid link must verify at its own origin, and under every equivalent spelling of that
|    origin;
|  - a request on the canonical host must reach its route action.
|
| Proven fallible against three mutants (see the task report): `ConfiguredBaseUrl` reading
| `request()->getHost()`, `SignedUrlSigner::payload()` dropping the authority field, and
| `HostAllowlist` widening its subdomain match to any depth. Each is caught by a different
| test below.
|
| ## Reproducibility
|
| One seed fixes every draw — the order of the matrix cells, the order of the host classes,
| the concrete host inside each class, the origin set, the paths, the tamper positions. It
| is printed in every failure message and replays with
| `CANONICAL_HOST_SEED=<seed> vendor/bin/pest --filter='<test name>'`.
|
| ## A note on static state
|
| `Request::setTrustedHosts()` / `setTrustedProxies()` are **static** Symfony state and the
| suite has no fixed order, so both are cleared after every test in this file — the same
| precedent `AcceptedHostTest` sets, and for the same reason. `wa.url.hosts.enforce` is
| pinned rather than left to `HostAllowlist::enforces()`, which follows the environment and
| is off under `testing`.
*/

afterEach(function (): void {
    Request::setTrustedProxies([], Request::getTrustedHeaderSet());
    Request::setTrustedHosts([]);
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Cache::flush();
    config([
        'app.url' => 'https://'.UrlConfiguration::APP_HOST,
        'wa.url.force_https' => null,
        'wa.url.signed.ttl_seconds' => 900,
        'wa.url.hosts.enforce' => true,
        'wa.url.hosts.additional' => [UrlConfiguration::ADDITIONAL_HOST],
        'wa.url.hosts.unrestricted_paths' => ['up'],
        'wa.url.proxies.trusted' => [],
        'wa.tenancy.apexes' => [],
    ]);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
| Prefixed `hostProperty*` because Pest helper functions are global and the suite already
| defines `allowlist()`, `signer()`, `transplant()` and friends in the scripted files.
*/

function hostPropertyAllowlist(): HostAllowlist
{
    return app(HostAllowlist::class);
}

function hostPropertySigner(): SignedUrlSigner
{
    return app(SignedUrlSigner::class);
}

/**
 * A request carrying $host through $channels, on a path that is not host-exempt.
 *
 * The URI names a host the platform does not serve, so nothing here can be accepted by
 * accident: with every channel poisoned, the `empty` and `whitespace` classes leave Symfony
 * nothing to fall back to (`HTTP_HOST` and `SERVER_NAME` carry the same empty value), and a
 * single-channel injection falls back to `placeholder.invalid`.
 *
 * @param  list<string>  $channels
 */
function hostPropertyRequest(string $host, array $channels = CanonicalHostProbe::INJECTION_CHANNELS): Request
{
    $request = Request::create('http://placeholder.invalid/_probe/action', server: ['REMOTE_ADDR' => '10.0.0.9']);

    foreach ($channels as $channel) {
        match ($channel) {
            // The CGI variables Symfony falls back to, set on the server bag rather than
            // as headers — which is how a web server would actually present them.
            'HTTP_HOST', 'SERVER_NAME' => $request->server->set($channel, $host),
            // The scheme and port of the effective origin, which are as much a part of an
            // authority as the host: a poisoned port moves `host:port` too.
            'X-Forwarded-Proto' => $request->headers->set($channel, 'http'),
            'X-Forwarded-Port' => $request->headers->set($channel, '8080'),
            default => $request->headers->set($channel, $host),
        };
    }

    return $request;
}

/**
 * Bind a request carrying $host as the container's request, by every channel at once.
 *
 * @param  list<string>  $channels
 */
function hostPropertyPoison(string $host, array $channels = CanonicalHostProbe::INJECTION_CHANNELS): void
{
    app()->instance('request', hostPropertyRequest($host, $channels));
}

/**
 * Unbind the request entirely — the console, the queue worker and the scheduler, which
 * register webhooks and send links with no request in sight.
 */
function hostPropertyNoRequest(): void
{
    app()->forgetInstance('request');
}

/**
 * The host the application actually sees for a raw `Host` value, or null when Symfony
 * refuses it outright as structurally invalid.
 *
 * Asked of Symfony rather than reimplemented: its normalisation (strip a trailing port,
 * lowercase, trim, keep a root dot, reject anything outside a narrow character class) is
 * the input every host decision downstream is made on, and a test that guessed at it would
 * be asserting parity between two of its own opinions.
 */
function hostPropertyEffectiveHost(string $raw): ?string
{
    $trusted = Request::getTrustedHosts();
    Request::setTrustedHosts([]);

    try {
        return hostPropertyRequest($raw)->getHost();
    } catch (SuspiciousOperationException) {
        return null;
    } finally {
        Request::setTrustedHosts($trusted);
    }
}

/**
 * Whether $host matches the trusted-host patterns the framework installs, matched exactly
 * the way Symfony matches them: `{pattern}i` against the already-normalised host.
 */
function hostPropertyPatternsMatch(string $host): bool
{
    foreach (hostPropertyAllowlist()->patterns() as $pattern) {
        if (preg_match('{'.$pattern.'}i', $host) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Whether `EnforceAllowedHost` refuses a request carrying $host, with the framework's
 * trusted-host patterns installed as they are in production.
 *
 * Returns false only when the guarded action actually ran, so "not refused" cannot be
 * satisfied by a middleware that swallows the request.
 */
function hostPropertyRefuses(string $host, bool $throughProxy = false): bool
{
    Request::setTrustedHosts(hostPropertyAllowlist()->patterns());

    $request = $throughProxy
        ? hostPropertyRequest(UrlConfiguration::APP_HOST, ['Host'])
        : hostPropertyRequest($host);

    if ($throughProxy) {
        $request->headers->set('X-Forwarded-Host', $host);
    }

    $ran = false;
    $next = function () use (&$ran): Response {
        $ran = true;

        return new Response;
    };

    try {
        if ($throughProxy) {
            app(TrustProxies::class)->handle(
                $request,
                fn (Request $forwarded): Response => app(EnforceAllowedHost::class)->handle($forwarded, $next),
            );
        } else {
            app(EnforceAllowedHost::class)->handle($request, $next);
        }
    } catch (HostNotAllowedException) {
        return true;
    } finally {
        Request::setTrustedHosts([]);
    }

    return ! $ran;
}

/**
 * One query parameter of a signed URL.
 */
function hostPropertyParam(string $url, string $parameter): string
{
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str(is_string($query) ? $query : '', $parsed);
    $value = $parsed[$parameter] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * The same signed URL — same path, same window, same signature — presented at $origin.
 */
function hostPropertyTransplant(string $url, string $origin): string
{
    $parts = parse_url($url);
    $parts = is_array($parts) ? $parts : [];
    $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
    $query = isset($parts['query']) && is_string($parts['query']) ? '?'.$parts['query'] : '';

    return $origin.$path.$query;
}

/**
 * Apply one cell of the matrix, and return the tenant it was applied for.
 *
 * @return array{tenant: Tenant, label: string, customHost: string|null}
 */
function hostPropertyApply(UrlConfiguration $cell, CanonicalHostProbe $probe, int $index): array
{
    Cache::flush();

    // A benign request while the fixtures are written: the settings write below is audited,
    // and resolving the audit actor asks the session guard for the current request. Replaced
    // by "no request at all" before the caller starts measuring.
    app()->instance('request', Request::create('http://'.UrlConfiguration::APP_HOST.'/_probe/setup'));

    config([
        'app.url' => $cell->appUrl(),
        'wa.tenancy.apexes' => $cell->apexes(),
    ]);

    $settings = app(PlatformSettings::class);
    $override = $cell->overrideUrl();

    if ($override === null) {
        $settings->forget(PlatformSettings::BASE_URL);
    } else {
        $settings->set(PlatformSettings::BASE_URL, $override);
    }

    $label = $probe->tenantLabel($index);
    $tenant = Tenant::factory()->create(['subdomain' => $label]);
    $customHost = null;

    if ($cell->customDomain) {
        $customHost = 'chat'.$index.'.acme.example';
        TenantDomain::factory()->verified()->create(['tenant_id' => $tenant->id, 'host' => $customHost]);
    }

    // Last, not first: the settings write above is audited, and resolving the audit actor
    // asks the session guard for the current request.
    hostPropertyNoRequest();

    return ['tenant' => $tenant, 'label' => $label, 'customHost' => $customHost];
}

/*
|--------------------------------------------------------------------------
| Clause 1 — every emitted URL carries the configured canonical host
|--------------------------------------------------------------------------
*/

it('emits the configured canonical host for every URL, under every hostile Host and every configuration', function (): void {
    $probe = CanonicalHostProbe::seeded();
    $index = 0;

    foreach ($probe->shuffled(UrlConfiguration::matrix()) as $cell) {
        $index++;

        // Frozen, so a signed URL is a pure function of the base, the path and the window
        // and can be compared byte-for-byte across header injections.
        Carbon::setTestNow('2024-06-17 12:00:00');

        ['tenant' => $tenant, 'label' => $label, 'customHost' => $customHost]
            = hostPropertyApply($cell, $probe, $index);

        $path = $probe->path();
        $routeKey = strtoupper(Illuminate\Support\Str::random(12));
        $expiry = Carbon::now()->addMinutes(10);

        /** @return array<string, string> */
        $emit = function () use ($tenant, $path, $routeKey, $expiry): array {
            $base = app(BaseUrl::class);
            $builder = app(UrlBuilder::class);

            return [
                'BaseUrl::platform()' => $base->platform(),
                'BaseUrl::forTenant()' => $base->forTenant($tenant),
                'absolute()' => $builder->absolute($path),
                'absolute() for the tenant' => $builder->absolute($path, $tenant),
                'webhook()' => $builder->webhook('cloud-api', $routeKey),
                'webhook() for the tenant' => $builder->webhook('cloud-api', $routeKey, $tenant),
                'signed()' => $builder->signed($path, $expiry),
                'signed() for the tenant' => $builder->signed($path, $expiry, $tenant),
                'tenantSubdomain()' => $builder->tenantSubdomain($tenant),
            ];
        };

        $platform = $cell->platformBase();
        $tenantBase = $cell->tenantBase($customHost);

        // What the platform *must* emit, derived from the drawn cell without asking a
        // single production class. This is the anti-vacuity control for the whole test:
        // "the URL did not move" is worthless unless the URL was right to begin with.
        $required = [
            'BaseUrl::platform()' => $platform,
            'BaseUrl::forTenant()' => $tenantBase,
            'absolute()' => $platform.$path,
            'absolute() for the tenant' => $tenantBase.$path,
            'webhook()' => $platform.'/webhooks/cloud-api/'.$routeKey,
            'webhook() for the tenant' => $tenantBase.'/webhooks/cloud-api/'.$routeKey,
            // A signed URL carries a signature, so only its origin and path are predicted;
            // its stability is asserted against the baseline below.
            'signed()' => $platform.$path.'?',
            'signed() for the tenant' => $tenantBase.$path.'?',
            'tenantSubdomain()' => $cell->subdomainBase($label),
        ];

        $where = sprintf('seed %d, cell %d (%s)', $probe->seed, $index, $cell->describe());

        // ---- the baseline: no request bound at all ---------------------------------
        $baseline = $emit();

        foreach ($required as $name => $expected) {
            if (str_starts_with($name, 'signed')) {
                expect($baseline[$name])->toStartWith($expected, sprintf(
                    '%s: %s must be signed against the configured origin, got [%s].',
                    $where,
                    $name,
                    $baseline[$name],
                ));

                continue;
            }

            expect($baseline[$name])->toBe($expected, sprintf(
                '%s: %s must be built from the configured canonical base.',
                $where,
                $name,
            ));
        }

        // Well-formed, and carrying the configured host — not merely stable.
        $tenantHost = $customHost ?? $cell->platformHost();
        $expectedHosts = [
            'BaseUrl::platform()' => $cell->platformHost(),
            'BaseUrl::forTenant()' => $tenantHost,
            'absolute()' => $cell->platformHost(),
            'absolute() for the tenant' => $tenantHost,
            'webhook()' => $cell->platformHost(),
            'webhook() for the tenant' => $tenantHost,
            'signed()' => $cell->platformHost(),
            'signed() for the tenant' => $tenantHost,
            'tenantSubdomain()' => $label.'.'.$cell->apex(),
        ];

        foreach ($baseline as $name => $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $expectedHost = $expectedHosts[$name];

            expect(filter_var($url, FILTER_VALIDATE_URL))->not->toBeFalse(sprintf(
                '%s: %s emitted [%s], which is not a well-formed URL.',
                $where,
                $name,
                $url,
            ))
                ->and($host)->toBe($expectedHost, sprintf(
                    '%s: %s emitted host [%s].',
                    $where,
                    $name,
                    is_string($host) ? $host : '(none)',
                ));
        }

        // A signed link built with no request is a link that verifies.
        expect(hostPropertySigner()->verify($baseline['signed()']))->toBe(SignedUrlVerdict::Valid, $where)
            ->and(hostPropertySigner()->verify($baseline['signed() for the tenant']))
            ->toBe(SignedUrlVerdict::Valid, $where);

        // ---- every hostile host, through every channel at once ---------------------
        foreach ($probe->shuffled(CanonicalHostProbe::HOST_CLASSES) as $class) {
            $host = $probe->hostileHost($class, $cell->platformHost(), $cell->apex());

            hostPropertyPoison($host);

            $actual = $emit();

            foreach ($baseline as $name => $expected) {
                expect($actual[$name])->toBe($expected, sprintf(
                    '%s: a [%s] Host header (%s) moved %s from [%s] to [%s]. Building a URL '
                    .'from the request host is host-header injection — poisoned links, cache '
                    .'poisoning, webhook redirection (Req 9.1, Property 27).',
                    $where,
                    $class,
                    addcslashes($host, "\0..\37\177"),
                    $name,
                    $expected,
                    $actual[$name],
                ));
            }
        }

        // ---- and through each channel on its own -----------------------------------
        // All-at-once is the stronger injection, but a builder that read exactly one
        // channel while another cancelled it out would pass that and fail this.
        $isolated = $probe->pick(CanonicalHostProbe::HOST_CLASSES);
        $isolatedHost = $probe->hostileHost($isolated, $cell->platformHost(), $cell->apex());

        foreach (CanonicalHostProbe::INJECTION_CHANNELS as $channel) {
            hostPropertyPoison($isolatedHost, [$channel]);

            expect($emit())->toBe($baseline, sprintf(
                '%s: a [%s] value carried only through %s changed an emitted URL.',
                $where,
                $isolated,
                $channel,
            ));
        }

        hostPropertyNoRequest();
    }
});

/*
|--------------------------------------------------------------------------
| Clause 2 — the signature binds the canonical authority
|--------------------------------------------------------------------------
*/

it('binds the canonical authority into every signature, so no link verifies at another origin', function (): void {
    $probe = CanonicalHostProbe::seeded();

    Carbon::setTestNow('2024-06-17 12:00:00');

    foreach (range(1, 6) as $iteration) {
        $origins = $probe->origins($probe->int(3, 5));
        $path = $probe->path();
        $expiry = Carbon::now()->addSeconds($probe->int(60, SignedUrlSigner::MAX_WINDOW_SECONDS));

        /** @var array<string, CanonicalBase> $bases */
        $bases = [];
        /** @var array<string, string> $links */
        $links = [];

        foreach ($origins as $origin) {
            $base = CanonicalBase::parse($origin, 'the drawn origin');
            $bases[$origin] = $base;
            $links[$origin] = hostPropertySigner()->sign($base, $path, $expiry);
        }

        $where = sprintf('seed %d, iteration %d, path [%s]', $probe->seed, $iteration, $path);

        // ---- the control: each link verifies at the origin it was signed for -------
        foreach ($links as $origin => $url) {
            expect(hostPropertySigner()->verify($url))->toBe(SignedUrlVerdict::Valid, sprintf(
                '%s: a link signed for [%s] does not verify there — [%s].',
                $where,
                $origin,
                $url,
            ));
        }

        // ---- the transplant: every link at every other drawn origin ---------------
        foreach ($links as $origin => $url) {
            foreach ($bases as $otherOrigin => $other) {
                if ($bases[$origin]->authority() === $other->authority()) {
                    continue;
                }

                $moved = hostPropertyTransplant($url, $other->scheme.'://'.$other->authority());

                expect(hostPropertySigner()->verify($moved))->toBe(SignedUrlVerdict::SignatureMismatch, sprintf(
                    '%s: a signature issued for [%s] was accepted at [%s]. A portable '
                    .'signature is a link an attacker can replay wherever they can make a '
                    .'host resolve (Req 9.6, Property 27).',
                    $where,
                    $bases[$origin]->authority(),
                    $other->authority(),
                ));
            }
        }

        // ---- equivalent spellings of the signed host still verify -----------------
        foreach ($links as $origin => $url) {
            $base = $bases[$origin];
            $suffix = $base->port === null ? '' : ':'.$base->port;

            $spellings = [
                'uppercase' => strtoupper($base->host).$suffix,
                'random case' => $probe->respelled($base->host).$suffix,
                'root dot' => $base->host.'.'.$suffix,
            ];

            if ($base->port === null) {
                // https implies 443, so naming it is the same origin spelled differently.
                $spellings['explicit default port'] = $base->host.':443';
            }

            foreach ($spellings as $kind => $authority) {
                $respelled = hostPropertyTransplant($url, 'https://'.$authority);

                expect(hostPropertySigner()->verify($respelled))->toBe(SignedUrlVerdict::Valid, sprintf(
                    '%s: the %s spelling [%s] of the signed host [%s] was refused. A '
                    .'legitimate link must not fail because a proxy re-cased the authority.',
                    $where,
                    $kind,
                    $authority,
                    $base->authority(),
                ));
            }
        }

        // ---- two links differing in nothing but the host --------------------------
        // Same mount, same port, same path, same window: whatever separates the two
        // signatures is the host, or the host is not in the HMAC at all.
        $mount = $probe->pick(['', '/app']);
        $left = CanonicalBase::parse('https://bot.example.test'.$mount, 'the drawn origin');
        $right = CanonicalBase::parse('https://staging.bot.example.test'.$mount, 'the drawn origin');

        $leftLink = hostPropertySigner()->sign($left, $path, $expiry);
        $rightLink = hostPropertySigner()->sign($right, $path, $expiry);

        expect(parse_url($leftLink, PHP_URL_PATH))->toBe(parse_url($rightLink, PHP_URL_PATH), $where)
            ->and(hostPropertyParam($leftLink, SignedUrlSigner::ISSUED_PARAM))
            ->toBe(hostPropertyParam($rightLink, SignedUrlSigner::ISSUED_PARAM), $where)
            ->and(hostPropertyParam($leftLink, SignedUrlSigner::EXPIRES_PARAM))
            ->toBe(hostPropertyParam($rightLink, SignedUrlSigner::EXPIRES_PARAM), $where)
            ->and(hostPropertyParam($leftLink, SignedUrlSigner::SIGNATURE_PARAM))
            ->not->toBe(hostPropertyParam($rightLink, SignedUrlSigner::SIGNATURE_PARAM), sprintf(
                '%s: two links differing only in host share a signature, so the host is not '
                .'inside the HMAC (Req 9.6, Property 27).',
                $where,
            ));

        // ---- tampering with anything the signature covers -------------------------
        $target = $probe->pick(array_keys($links));
        $link = $links[$target];
        $signedPath = (string) parse_url($link, PHP_URL_PATH);
        $issued = hostPropertyParam($link, SignedUrlSigner::ISSUED_PARAM);
        $expires = hostPropertyParam($link, SignedUrlSigner::EXPIRES_PARAM);
        $signature = hostPropertyParam($link, SignedUrlSigner::SIGNATURE_PARAM);

        $tampers = [
            'a byte of the path' => str_replace($signedPath, $probe->flipPathByte($signedPath), $link),
            'the issued time' => str_replace(
                SignedUrlSigner::ISSUED_PARAM.'='.$issued,
                SignedUrlSigner::ISSUED_PARAM.'='.((int) $issued - $probe->int(1, 300)),
                $link,
            ),
            'the expiry' => str_replace(
                SignedUrlSigner::EXPIRES_PARAM.'='.$expires,
                SignedUrlSigner::EXPIRES_PARAM.'='.((int) $expires + $probe->int(1, 300)),
                $link,
            ),
            'the signature' => str_replace(
                SignedUrlSigner::SIGNATURE_PARAM.'='.$signature,
                SignedUrlSigner::SIGNATURE_PARAM.'='.$probe->flipSignature($signature),
                $link,
            ),
        ];

        foreach ($tampers as $kind => $tampered) {
            expect($tampered)->not->toBe($link, $where.': the '.$kind.' tamper was a no-op.')
                ->and(hostPropertySigner()->verify($tampered)->isValid())->toBeFalse(sprintf(
                    '%s: editing %s left the link valid — [%s].',
                    $where,
                    $kind,
                    $tampered,
                ));
        }
    }
});

/*
|--------------------------------------------------------------------------
| Clause 3 — a non-allowlisted host is rejected, and the two layers agree
|--------------------------------------------------------------------------
*/

it('accepts exactly the hosts Req 9.4 admits, with both enforcement layers agreeing on every one', function (): void {
    $probe = CanonicalHostProbe::seeded();
    $index = 0;

    /** @var list<string> $verified every verified custom domain created so far */
    $verified = [];

    foreach ($probe->shuffled(UrlConfiguration::matrix()) as $cell) {
        $index++;

        ['customHost' => $customHost] = hostPropertyApply($cell, $probe, $index);

        if ($customHost !== null) {
            $verified[] = $customHost;
        }

        // The two inputs to the oracle, assembled from the drawn cell rather than read
        // back from the allowlist.
        $apexes = $cell->configuredApexes ? [UrlConfiguration::APEX] : [$cell->platformHost()];
        $exact = $cell->exactHosts($verified);

        $where = sprintf('seed %d, cell %d (%s)', $probe->seed, $index, $cell->describe());

        // A verified custom domain is accepted exactly, and nothing under it — the half of
        // the namespace the platform does not own has to be exact (Req 9.7).
        if ($customHost !== null) {
            expect(hostPropertyAllowlist()->accepts($customHost))->toBeTrue($where)
                ->and(hostPropertyAllowlist()->accepts('sub.'.$customHost))->toBeFalse($where);
        }

        foreach ($probe->shuffled(CanonicalHostProbe::HOST_CLASSES) as $class) {
            $raw = $probe->hostileHost($class, $cell->platformHost(), $cell->apex());
            $effective = hostPropertyEffectiveHost($raw);
            $expected = $effective !== null
                && CanonicalHostProbe::accepted($effective, $apexes, $exact);

            $case = sprintf(
                '%s: a [%s] Host header (%s), which the application sees as [%s] and which is '
                .'%s one of (an apex, one label under an apex, a verified custom domain, an '
                .'operator-listed host)',
                $where,
                $class,
                addcslashes($raw, "\0..\37\177"),
                $effective === null ? '(refused by the framework)' : $effective,
                $expected ? 'genuinely' : 'not',
            );

            if ($effective !== null) {
                expect(hostPropertyAllowlist()->accepts($effective))->toBe($expected, $case)
                    // The Symfony-level guard and the middleware read one list, so they
                    // must admit one set. A host one layer accepts and the other refuses
                    // is a route that works until something calls getHost().
                    ->and(hostPropertyPatternsMatch($effective))->toBe($expected, $case
                        .' — but the trusted-host patterns disagree with accepts().');
            }

            expect(hostPropertyRefuses($raw))->toBe(! $expected, $case
                .' — EnforceAllowedHost disagrees.');
        }
    }
});

it('never lets a forwarded host from a trusted proxy be accepted or emitted', function (): void {
    // With a proxy trusted, `X-Forwarded-Host` *becomes* the effective host — which is why
    // `EnforceAllowedHost` runs after `TrustProxies`. So the accepted-host decision has to
    // hold on the forwarded value, and URL generation has to stay blind to it.
    $probe = CanonicalHostProbe::seeded();

    config(['wa.url.proxies.trusted' => ['*'], 'wa.tenancy.apexes' => [UrlConfiguration::APEX]]);

    $apexes = [UrlConfiguration::APEX];
    $exact = [UrlConfiguration::APP_HOST, UrlConfiguration::ADDITIONAL_HOST];
    $expectedBase = 'https://'.UrlConfiguration::APP_HOST;

    foreach ($probe->shuffled(CanonicalHostProbe::HOST_CLASSES) as $class) {
        $raw = $probe->hostileHost($class, UrlConfiguration::APP_HOST, UrlConfiguration::APEX);

        $forwarded = hostPropertyRequest(UrlConfiguration::APP_HOST, ['Host']);
        $forwarded->headers->set('X-Forwarded-Host', $raw);
        app(TrustProxies::class)->handle($forwarded, fn (): Response => new Response);

        try {
            $effective = $forwarded->getHost();
        } catch (SuspiciousOperationException) {
            $effective = null;
        }

        $expected = $effective !== null && CanonicalHostProbe::accepted($effective, $apexes, $exact);

        $case = sprintf(
            'seed %d: X-Forwarded-Host [%s] from a trusted proxy, seen as [%s]',
            $probe->seed,
            addcslashes($raw, "\0..\37\177"),
            $effective === null ? '(refused by the framework)' : $effective,
        );

        expect(hostPropertyRefuses($raw, throughProxy: true))->toBe(! $expected, $case);

        // And with that request bound, generation is still on the configured origin.
        app()->instance('request', $forwarded);

        expect(app(BaseUrl::class)->platform())->toBe($expectedBase, $case)
            ->and(app(UrlBuilder::class)->absolute('/inbox'))->toBe($expectedBase.'/inbox', $case);
    }

    hostPropertyNoRequest();
});

it('refuses an unrecognised host with the typed 400 before the route action runs', function (): void {
    $probe = CanonicalHostProbe::seeded();
    $index = $probe->int(1, count(UrlConfiguration::matrix()));
    $cell = $probe->pick(UrlConfiguration::matrix());

    ['customHost' => $customHost] = hostPropertyApply($cell, $probe, $index);

    $verified = $customHost === null ? [] : [$customHost];
    $apexes = $cell->configuredApexes ? [UrlConfiguration::APEX] : [$cell->platformHost()];
    $exact = $cell->exactHosts($verified);

    $ran = 0;

    Route::middleware('web')->get('/_probe/action', function () use (&$ran): JsonResponse {
        $ran++;

        return response()->json(['ok' => true]);
    });

    /**
     * The full stack — global middleware, routing, the exception renderer — driven with an
     * arbitrary `Host`. Going through the kernel rather than the test client because the
     * client builds its request from a URL, and half of these hosts cannot be spelled
     * inside one.
     *
     * @return array{status: int, ran: bool, body: string}
     */
    $serve = function (string $host) use (&$ran): array {
        $before = $ran;
        $response = app(Kernel::class)->handle(hostPropertyRequest($host));

        return [
            'status' => $response->getStatusCode(),
            'ran' => $ran > $before,
            'body' => (string) $response->getContent(),
        ];
    };

    $where = sprintf('seed %d, cell (%s)', $probe->seed, $cell->describe());

    foreach ($probe->shuffled(CanonicalHostProbe::HOST_CLASSES) as $class) {
        $raw = $probe->hostileHost($class, $cell->platformHost(), $cell->apex());
        $effective = hostPropertyEffectiveHost($raw);
        $expected = $effective !== null && CanonicalHostProbe::accepted($effective, $apexes, $exact);

        $result = $serve($raw);
        $case = sprintf(
            '%s: a [%s] Host header (%s), seen as [%s], %s',
            $where,
            $class,
            addcslashes($raw, "\0..\37\177"),
            $effective === null ? '(refused by the framework)' : $effective,
            $expected ? 'is one of ours' : 'is not ours',
        );

        if ($expected) {
            // The anti-vacuity control: an allowlist that refused everything would pass
            // every rejection assertion in this file and fail here.
            expect($result['status'])->toBe(200, $case)
                ->and($result['ran'])->toBeTrue($case.' — the route action did not run.');

            continue;
        }

        expect($result['status'])->toBe(HostNotAllowedException::STATUS, $case)
            ->and($result['ran'])->toBeFalse($case.' — the route action ran anyway, so the '
                .'refusal is not before the side effect (Req 9.5).')
            ->and($result['body'])->toContain(HostNotAllowedException::PUBLIC_MESSAGE);

        // Never the host back: reflecting an attacker-controlled header buys nothing. (The
        // empty and whitespace classes fold to '', which every string contains.)
        $folded = CanonicalHostProbe::fold($raw);

        if ($folded !== '') {
            expect($result['body'])->not->toContain($folded, $case
                .' — the refusal echoed the host back.');
        }
    }

    expect($ran)->toBeGreaterThan(0, $where.': no host in the draw ever reached the route, so '
        .'this test proved only that the platform refuses everything.');
});
