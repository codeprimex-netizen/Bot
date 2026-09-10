<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Platform\PlatformSettings;
use App\Services\Url\BaseUrl;
use App\Services\Url\BaseUrlCache;
use App\Services\Url\CanonicalUrlBuilder;
use App\Services\Url\ConfiguredBaseUrl;
use App\Services\Url\SignedUrlSigner;
use App\Services\Url\UrlBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the canonical base URL and everything built from it (Req 9.1, 9.2, 9.3, 9.6 / A9).
 *
 * `BaseUrl` resolves to the real `ConfiguredBaseUrl` and `UrlBuilder` to the real
 * `CanonicalUrlBuilder` in every environment — there is no test double bound here
 * (Property 28). A test that needs a different origin sets `config('app.url')` or writes
 * the `platform_settings` override, which exercises the same resolution path production
 * uses.
 *
 * Nothing here binds a request-aware URL generator into the chain, and nothing may: the
 * whole point of A9 is that an emitted host comes from configuration (Property 27).
 */
class UrlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons because all three are stateless: the version-carrying cache reads its
        // version from the store on every call, so a long-lived worker cannot pin a base
        // URL resolved before an admin changed it.
        $this->app->singleton(BaseUrlCache::class);
        $this->app->singleton(PlatformSettings::class);
        $this->app->singleton(BaseUrl::class, ConfiguredBaseUrl::class);

        // The signer holds no state of its own; the secret it signs with is memoised by
        // `SigningSecretStore`, which is bound `scoped()` so a rotated-out secret stops
        // being used within one request or job.
        $this->app->singleton(SignedUrlSigner::class);

        // The builder memoises parsed bases keyed by the full canonical string, so a memo
        // cannot outlive the value it describes and a singleton is safe.
        $this->app->singleton(UrlBuilder::class, CanonicalUrlBuilder::class);
    }
}
