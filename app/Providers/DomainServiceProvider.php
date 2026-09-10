<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Domains\DomainProbe;
use App\Services\Domains\DomainRegistrar;
use App\Services\Domains\DomainVerifier;
use App\Services\Domains\GuardedDomainProbe;
use App\Services\Domains\NetworkDomainProbe;
use App\Services\Domains\PlatformHosts;
use App\Services\Domains\VerifiedDomainDirectory;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wires custom-domain routing and verification (Req 9.3, 9.7 / A9).
 *
 * `DomainProbe` resolves to the **real** `NetworkDomainProbe`, wrapped in the platform's
 * circuit breaker and retry budget, in every environment. There is no test double bound
 * here (Property 28): `Tests\Fixtures\Domains\FakeDomainProbe` lives under `tests/`,
 * which only `autoload-dev` maps, so it is not autoloadable in a production install at
 * all — a test binds it explicitly and nothing else can.
 *
 * `wa.tenancy.domains.probe.driver` exists as the same swap point
 * `wa.security.kms.driver` is: `network` is the shipped implementation, and any other
 * value must name a `DomainProbe`. A value that resolves to something else is a startup
 * error rather than a silently unverifiable platform.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless: config is read per call, so a long-lived worker cannot pin an apex
        // list resolved before an operator changed it.
        $this->app->singleton(PlatformHosts::class);

        // Holds nothing but a `VersionedCache`, which reads its version from the store on
        // every call — so a verification on another node is visible to this one.
        $this->app->singleton(VerifiedDomainDirectory::class);

        $this->app->singleton(DomainProbe::class, fn (Application $app): DomainProbe => $this->guarded(
            $app,
            $this->driver($app),
        ));

        $this->app->singleton(DomainVerifier::class);
        $this->app->singleton(DomainRegistrar::class);
    }

    /**
     * The configured probe implementation.
     */
    private function driver(Application $app): DomainProbe
    {
        $configured = config('wa.tenancy.domains.probe.driver', 'network');
        $configured = is_string($configured) && $configured !== '' ? $configured : 'network';

        if ($configured !== 'network') {
            $probe = $app->make($configured);

            if (! $probe instanceof DomainProbe) {
                throw new InvalidArgumentException(sprintf(
                    'wa.tenancy.domains.probe.driver must be "network" or name a %s implementation, got [%s].',
                    DomainProbe::class,
                    $configured,
                ));
            }

            return $probe;
        }

        $probe = config('wa.tenancy.domains.probe');
        $probe = is_array($probe) ? $probe : [];

        return new NetworkDomainProbe(
            $app->make(HttpFactory::class),
            self::intValue($probe['connect_timeout'] ?? null, 3),
            self::intValue($probe['timeout'] ?? null, 5),
        );
    }

    /**
     * Wrap $probe in the breaker and retry budget, unless an operator has turned the
     * guard off for a deployment that fences egress some other way.
     */
    private function guarded(Application $app, DomainProbe $probe): DomainProbe
    {
        $guard = config('wa.tenancy.domains.probe.guard');
        $guard = is_array($guard) ? $guard : [];

        if (($guard['enabled'] ?? true) !== true) {
            return $probe;
        }

        return new GuardedDomainProbe(
            $probe,
            $app->make(CircuitBreaker::class),
            $app->make(RetryPolicy::class),
            self::stringValue($guard['breaker'] ?? null, 'domains'),
            self::intValue($guard['attempts'] ?? null, GuardedDomainProbe::DEFAULT_ATTEMPTS),
            self::intValue($guard['max_delay_ms'] ?? null, GuardedDomainProbe::DEFAULT_MAX_DELAY_MS),
        );
    }

    private static function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
