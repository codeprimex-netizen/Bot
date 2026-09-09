<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Reliability\CompositeErrorClassifier;
use App\Services\Reliability\ErrorClassifier;
use App\Services\Reliability\RetryMatrix;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the retry/backoff policy: the error classifier chain, the matrix, and the policy
 * that reads both (Req 31.1 / NFR2; task 3.6).
 *
 * All three are singletons, and each for its own reason:
 *
 * - the **classifier** must be one instance per process, because runtime registrations
 *   (`ErrorClassifier::register()`) would otherwise be made against a copy and lost. That
 *   is the seam later phases add their provider errors through, so it has to be shared.
 * - the **matrix** holds nothing between calls (it reads config on every lookup, so a
 *   config change needs no cache clear), and the singleton just avoids rebuilding it.
 * - the **policy** is stateless apart from `random_int`, so one instance is safe to share
 *   across every job a worker runs.
 *
 * `ErrorClassifier` is bound to the composite rather than to `PlatformErrorClassifier`
 * directly: the platform's own classifier is the *last* link of the chain and is appended in
 * code, so a deployment can add classifications but never remove the platform's.
 */
class RetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound by its concrete name and aliased, rather than bound to the interface, so
        // that `app(ErrorClassifier::class)` and `app(CompositeErrorClassifier::class)` are
        // the *same* instance. A caller that resolved the concrete class and registered a
        // classifier on a second copy would silently register it nowhere.
        $this->app->singleton(CompositeErrorClassifier::class);
        $this->app->alias(CompositeErrorClassifier::class, ErrorClassifier::class);

        $this->app->singleton(RetryMatrix::class);
        $this->app->singleton(RetryPolicy::class);
    }
}
