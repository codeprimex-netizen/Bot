<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Tenancy\ConfiguredTierResolver;
use App\Services\Tenancy\DatabaseTenantTokenRepository;
use App\Services\Tenancy\RequestTenantContext;
use App\Services\Tenancy\Resolvers\ChainTenantResolver;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantOwnershipGuard;
use App\Services\Tenancy\TenantResolver;
use App\Services\Tenancy\TenantTokenRepository;
use App\Services\Tenancy\TierResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the tenancy layer: the context singleton, the resolver chain, and the
 * worker boundaries that keep one tenant's work from bleeding into another's.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per unit of work. `boot()` below makes "unit of work" true
        // for queue workers as well as requests.
        $this->app->singleton(RequestTenantContext::class);
        $this->app->alias(RequestTenantContext::class, TenantContext::class);

        // The ownership guard shares the context's lifetime: its suspension frames
        // (the sanctioned read bypasses) belong to one unit of work, exactly like the
        // tenant they are compared against.
        $this->app->singleton(TenantOwnershipGuard::class);

        $this->app->singleton(TenantTokenRepository::class, DatabaseTenantTokenRepository::class);

        // Singleton so the per-instance memo actually pays off: a dispatch loop asks
        // the same handful of tenants for their lane weight thousands of times per
        // window (Req 1.6, 1.7 / A1).
        $this->app->singleton(TierResolver::class, ConfiguredTierResolver::class);

        $this->app->singleton(TenantResolver::class, function (Application $app): TenantResolver {
            return new ChainTenantResolver($this->configuredResolvers($app));
        });
    }

    public function boot(): void
    {
        $this->isolateQueuedWork();
    }

    /**
     * The resolver chain, in the precedence order declared in `config/wa.php`.
     *
     * @return list<TenantResolver>
     */
    private function configuredResolvers(Application $app): array
    {
        $configured = config('wa.tenancy.resolvers');
        $resolvers = [];

        foreach (is_array($configured) ? $configured : [] as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $resolver = $app->make($class);

            if ($resolver instanceof TenantResolver) {
                $resolvers[] = $resolver;
            }
        }

        return $resolvers;
    }

    /**
     * Give every queued job its own tenant context.
     *
     * A worker process is long-lived and the context is a singleton, so without
     * this a job that binds tenant A would hand tenant A to the next job on the
     * same worker — the worst kind of cross-tenant leak, because it is invisible
     * (Req 1.1–1.3 / A1).
     *
     * The prior context is *stashed and put back* rather than merely cleared, so
     * dispatching a job on the `sync` connection (or a job dispatched from
     * inside another job) cannot destroy the caller's own scope. Frames are keyed
     * by job identity, and restoring an unknown key is a no-op, so a job that
     * both throws and then fails cannot restore twice.
     */
    private function isolateQueuedWork(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->context()->isolate($this->frameKey($event->job->uuid(), $event->job->getJobId()));
        });

        foreach ([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class] as $finished) {
            Event::listen($finished, function (JobProcessed|JobExceptionOccurred|JobFailed $event): void {
                $this->context()->release($this->frameKey($event->job->uuid(), $event->job->getJobId()));
            });
        }

        // Between jobs a worker holds no tenant at all: anything left behind by
        // a crashed job dies here rather than being inherited.
        Event::listen(Looping::class, function (): void {
            $this->context()->forget();
        });
    }

    private function context(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    /**
     * A stable key for one job execution.
     */
    private function frameKey(?string $uuid, string|int|null $jobId): string
    {
        if (is_string($uuid) && $uuid !== '') {
            return $uuid;
        }

        return 'job:'.(is_string($jobId) || is_int($jobId) ? (string) $jobId : 'unknown');
    }
}
