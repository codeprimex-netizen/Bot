<?php

declare(strict_types=1);

namespace App\Providers;

use App\Logging\PiiRedactionProcessor;
use App\Logging\RedactingLogManager;
use App\Services\Security\Pii\ConfigTenantPiiPatternSource;
use App\Services\Security\Pii\PiiRedactor;
use App\Services\Security\Pii\TenantPiiPatternSource;
use App\Services\Security\Pii\TokenizingPiiRedactor;
use App\Services\Tenancy\TenantContext;
use App\Support\Pii\LogPiiScrubber;
use App\Support\Pii\PiiScanner;
use App\Support\Pii\TenantPatternCompiler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Wires PII redaction: the reversible egress redactor, and the irreversible log
 * layer (Req 7.3 / A7; Req 32.2 / NFR3; Correctness Property 15).
 *
 * Lifetimes are the design decision here, and they are not uniform.
 *
 * ## Stateless, shared — `singleton`
 *
 * `PiiScanner`, `TenantPatternCompiler`, `LogPiiScrubber`, `PiiRedactionProcessor`.
 * None of them holds a byte of customer data between calls: the scanner answers a
 * question about a string, the scrubber returns a new string, the processor returns a
 * new record. One instance per process is right, and the log processor in particular
 * *must* be shared so the stack-channel idempotence check can recognise it.
 *
 * ## Request-lived, per unit of work — `scoped`
 *
 * `PiiRedactor` and `TenantPiiPatternSource`. The redactor holds a token map, which
 * is plaintext PII; `scoped` means the container discards it at the end of every
 * request and every queued job, which is the *only* thing keeping that map's lifetime
 * to one unit of work. This is the same boundary `SecurityServiceProvider` gives
 * `FieldCipher` for unwrapped DEKs, and for the same reason: a long-lived worker must
 * not be able to carry one tenant's plaintext into another tenant's work.
 *
 * ## The log manager
 *
 * `log` is rebound to `RedactingLogManager` so that redaction is a property of every
 * channel rather than of every call site. There is deliberately **no config switch**
 * for this: a security guarantee with an off-switch is a security guarantee somebody
 * turns off while debugging and forgets to turn back on. What *is* configurable is the
 * cost — how much of a string is scanned, how deep a payload is walked — under
 * `wa.security.pii.log`.
 */
class PiiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantPatternCompiler::class, fn (): TenantPatternCompiler => new TenantPatternCompiler(
            self::intConfig('wa.security.pii.max_pattern_length', TenantPatternCompiler::DEFAULT_MAX_LENGTH),
            self::intConfig('wa.security.pii.max_patterns_per_tenant', TenantPatternCompiler::DEFAULT_MAX_PATTERNS),
            self::intConfig('wa.security.pii.backtrack_limit', TenantPatternCompiler::DEFAULT_BACKTRACK_LIMIT),
        ));

        $this->app->singleton(PiiScanner::class, fn (): PiiScanner => new PiiScanner(
            self::intConfig('wa.security.pii.backtrack_limit', TenantPatternCompiler::DEFAULT_BACKTRACK_LIMIT),
        ));

        $this->app->singleton(LogPiiScrubber::class, fn (Application $app): LogPiiScrubber => new LogPiiScrubber(
            $app->make(PiiScanner::class),
            self::intConfig('wa.security.pii.log.max_string_length', 4000),
            self::intConfig('wa.security.pii.log.max_depth', 8),
            self::intConfig('wa.security.pii.log.max_items', 100),
        ));

        $this->app->singleton(PiiRedactionProcessor::class);

        $this->app->scoped(
            TenantPiiPatternSource::class,
            fn (Application $app): TenantPiiPatternSource => $this->patternSource($app),
        );

        $this->app->scoped(PiiRedactor::class, fn (Application $app): PiiRedactor => new TokenizingPiiRedactor(
            $app->make(PiiScanner::class),
            $app->make(TenantPiiPatternSource::class),
            $app->make(TenantContext::class),
        ));

        $this->registerLogManager();
    }

    /**
     * Replace the framework's log manager with the redacting one.
     *
     * Re-binding drops the previously built instance, and the facade's resolved
     * instance is cleared alongside it — otherwise anything that logged during
     * bootstrap would keep a reference to an unredacting manager for the rest of the
     * request.
     */
    private function registerLogManager(): void
    {
        $this->app->singleton('log', fn (Application $app): RedactingLogManager => new RedactingLogManager($app));

        Facade::clearResolvedInstance('log');
    }

    /**
     * Build the configured pattern source.
     *
     * `wa.security.pii.pattern_source` is the seam a tenant-settings-backed source
     * (a panel screen writing to the database) plugs into later. A misconfiguration is
     * a startup error rather than a platform that silently stops applying a tenant's
     * patterns.
     */
    private function patternSource(Application $app): TenantPiiPatternSource
    {
        $configured = config('wa.security.pii.pattern_source', ConfigTenantPiiPatternSource::class);

        if (is_string($configured) && $configured !== '' && $configured !== ConfigTenantPiiPatternSource::class) {
            $source = $app->make($configured);

            if (! $source instanceof TenantPiiPatternSource) {
                throw new InvalidArgumentException(sprintf(
                    'wa.security.pii.pattern_source must name a %s implementation, got [%s].',
                    TenantPiiPatternSource::class,
                    $configured,
                ));
            }

            return $source;
        }

        return new ConfigTenantPiiPatternSource(
            $app->make(TenantPatternCompiler::class),
            $app->make(LoggerInterface::class),
        );
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
