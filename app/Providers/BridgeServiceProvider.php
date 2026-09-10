<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Bridge\BridgeClient;
use App\Services\Bridge\GuardedBridgeClient;
use App\Services\Bridge\HttpBridgeClient;
use App\Services\Bridge\TenantScopedBridgeClient;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use App\Services\Tenancy\TenantStorage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the WA Bridge transport (Req 2.9 / A2; Req 8.1 / A8).
 *
 * `BridgeClient` resolves to **one chain, in one order**, in every environment:
 *
 * ```
 *   TenantScopedBridgeClient   ownership resolution + per-tenant auth-state paths
 *     └── GuardedBridgeClient  per-session circuit breaker + bounded inline retry
 *           └── HttpBridgeClient   JSON over HTTP to the Node + Baileys sidecar
 * ```
 *
 * The order is the design, not a preference:
 *
 * 1. **Ownership outermost.** A session id the acting tenant does not own must be refused
 *    without a request, without consuming a retry attempt, and without telling the breaker that
 *    this tenant's traffic is failing. Anywhere further in, the denial would still happen but the
 *    breaker would already have been polluted by it.
 * 2. **Breaker inside the retry loop**, as `RetryPolicy`'s docblock prescribes and as
 *    `GuardedKmsClient` and `GuardedDomainProbe` are already built.
 * 3. **The wire innermost**, knowing nothing but session ids.
 *
 * ## No test double is bound here (Property 28 / Req 36.2)
 *
 * `Tests\Fixtures\Bridge\FakeBridgeClient` lives under `tests/`, which only `autoload-dev`
 * maps, so it is not autoloadable in a production install at all — there is nothing for a
 * config key to name by mistake and nothing for the completeness scan of task 39.4 to find
 * reachable from `app/`. A test binds it explicitly (`FakeBridgeClient::bind()`), and nothing
 * else can.
 *
 * There is deliberately **no `driver` config key** here, unlike `wa.security.kms.driver` and
 * `wa.tenancy.domains.probe.driver`. Those exist because a deployment may legitimately swap the
 * implementation (a different KMS, a different resolver). The bridge has exactly one transport,
 * and the *pluggable* axis for messaging backends is Channel Mode — `sessions_wa.channel_mode`
 * selecting a `ChannelDriver` per session (task 6.1) — which is a per-session routing decision,
 * not a container-wide swap. Adding a swap key here would create a second, competing way to
 * choose a backend.
 *
 * The guard can be turned off (`wa.bridge.guard.enabled`) for a deployment that fences egress
 * some other way, exactly as the domain probe's can. Ownership scoping cannot: it is not a
 * resilience feature.
 */
class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The wire itself. A singleton because it holds nothing but the HTTP factory and reads
        // every setting per call, so a long-lived worker cannot pin a URL or a token that an
        // operator has since changed.
        $this->app->singleton(HttpBridgeClient::class, fn (Application $app): HttpBridgeClient => new HttpBridgeClient(
            $app->make(HttpFactory::class),
        ));

        $this->app->singleton(BridgeClient::class, fn (Application $app): BridgeClient => new TenantScopedBridgeClient(
            $this->guarded($app, $app->make(HttpBridgeClient::class)),
            $app->make(TenantStorage::class),
        ));
    }

    /**
     * Wrap $client in the breaker and retry budget, unless an operator has turned the guard off.
     */
    private function guarded(Application $app, BridgeClient $client): BridgeClient
    {
        $guard = config('wa.bridge.guard');
        $guard = is_array($guard) ? $guard : [];

        if (($guard['enabled'] ?? true) !== true) {
            return $client;
        }

        return new GuardedBridgeClient(
            $client,
            $app->make(CircuitBreaker::class),
            $app->make(RetryPolicy::class),
            self::intValue($guard['attempts'] ?? null, GuardedBridgeClient::DEFAULT_ATTEMPTS),
            self::intValue($guard['max_delay_ms'] ?? null, GuardedBridgeClient::DEFAULT_MAX_DELAY_MS),
        );
    }

    private static function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
