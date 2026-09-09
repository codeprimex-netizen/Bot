<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Abuse\AbuseRecorder;
use App\Services\Abuse\AntiFraudGuard;
use App\Services\Abuse\CompositeInjectionClassifier;
use App\Services\Abuse\DatabaseAbuseRecorder;
use App\Services\Abuse\Guardrail;
use App\Services\Abuse\HeuristicAntiFraudGuard;
use App\Services\Abuse\HeuristicInjectionClassifier;
use App\Services\Abuse\IdentityDigest;
use App\Services\Abuse\InjectionClassifier;
use App\Services\Abuse\InstructionHierarchy;
use App\Services\Abuse\LayeredGuardrail;
use App\Services\Abuse\OutputValidator;
use App\Services\Abuse\PolicyOutputValidator;
use App\Services\Abuse\SessionKillSwitch;
use App\Services\Abuse\TextNormalizer;
use App\Services\Abuse\VelocityLimit;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\TenantContext;
use App\Support\Cache\VelocityCounter;
use App\Support\Cache\VersionedCache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wires the abuse-defence layer: the prompt guardrail, the abuse trail, the
 * per-session kill-switch, and the anti-fraud heuristics (Req 13.8 / B4;
 * Req 32.7 / NFR3).
 *
 * ## Lifetimes, and why they differ
 *
 * | Binding | Lifetime | Why |
 * |---|---|---|
 * | `TextNormalizer`, `IdentityDigest`, `OutputValidator`, `InstructionHierarchy`, `InjectionClassifier` | singleton | stateless; they hold compiled rules and a digest key, no tenant data |
 * | `AbuseRecorder`, `SessionKillSwitch`, `Guardrail`, `AntiFraudGuard` | scoped | they close over `TenantContext`, which is per request / per queued job — a singleton would carry one tenant's context into the next job |
 *
 * `scoped()` here is the same boundary `SecurityServiceProvider` maintains for
 * `FieldCipher` and `TenancyServiceProvider` for `TenantContext`.
 *
 * ## The one thing configuration cannot do
 *
 * `wa.security.guardrail.classifiers` adds *optional* classifiers. The deterministic
 * `HeuristicInjectionClassifier` is passed to `CompositeInjectionClassifier` as its
 * mandatory argument, so emptying that list — or getting it wrong — leaves the
 * deterministic classifier running. A configured class that is not an
 * `InjectionClassifier` is a startup error rather than a silently missing layer.
 */
class AbuseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TextNormalizer::class, static fn (): TextNormalizer => TextNormalizer::fromConfig());
        $this->app->singleton(IdentityDigest::class, static fn (): IdentityDigest => IdentityDigest::fromConfig());
        $this->app->singleton(InstructionHierarchy::class, static fn (): InstructionHierarchy => InstructionHierarchy::fromConfig());

        $this->app->singleton(OutputValidator::class, function (Application $app): OutputValidator {
            return PolicyOutputValidator::fromConfig($app->make(TextNormalizer::class));
        });

        $this->app->singleton(InjectionClassifier::class, fn (Application $app): InjectionClassifier => new CompositeInjectionClassifier(
            HeuristicInjectionClassifier::fromConfig(),
            $this->optionalClassifiers($app),
        ));

        $this->app->scoped(AbuseRecorder::class, function (Application $app): AbuseRecorder {
            return new DatabaseAbuseRecorder(
                $app->make(TenantContext::class),
                // Explicit, like the audit trail: the abuse trail lives on the default
                // connection even for a tenant on a dedicated shard (Req 1.6 / A1), so the
                // platform has one abuse feed rather than one per shard.
                $app->make('db')->connection(),
            );
        });

        $this->app->scoped(SessionKillSwitch::class, function (Application $app): SessionKillSwitch {
            return new SessionKillSwitch(
                $app->make(AbuseRecorder::class),
                $app->make(AuditService::class),
                self::cache('abuse:kill-switch'),
                attemptRecordSeconds: (int) config('wa.security.abuse.kill_switch.attempt_record_seconds', 300),
            );
        });

        $this->app->scoped(Guardrail::class, function (Application $app): Guardrail {
            return new LayeredGuardrail(
                $app->make(InjectionClassifier::class),
                $app->make(OutputValidator::class),
                $app->make(AbuseRecorder::class),
                $app->make(SessionKillSwitch::class),
                $app->make(TextNormalizer::class),
                self::cache('abuse:burst'),
                recordFlags: (bool) config('wa.security.guardrail.record_flags', true),
                burstWindowSeconds: (int) config('wa.security.guardrail.conversation.block_window_seconds', 900),
                burstBlockLimit: (int) config('wa.security.guardrail.conversation.block_limit', 5),
                autoKillSeconds: (int) config('wa.security.guardrail.conversation.auto_kill_seconds', 3600),
            );
        });

        $this->app->scoped(AntiFraudGuard::class, function (Application $app): AntiFraudGuard {
            return new HeuristicAntiFraudGuard(
                new VelocityCounter(self::cache('abuse:velocity')),
                $app->make(IdentityDigest::class),
                $app->make(AbuseRecorder::class),
                self::limits(),
                self::stringList(config('wa.security.anti_fraud.trusted_ips', [])),
                self::stringList(config('wa.security.anti_fraud.disposable_email_domains', [])),
            );
        });
    }

    /**
     * One versioned-cache namespace for the abuse layer's soft state.
     *
     * The TTL is the upper bound on a *counting window*, so it comes from
     * `wa.security.abuse.cache.ttl` and must exceed the longest anti-fraud window
     * (the device counter's day). Losing this cache costs precision, never
     * correctness: kill-switch state is re-read from `abuse_events`, and velocity
     * counters restart from zero, which is the safe direction for a legitimate user
     * and only ever a one-window gift to an attacker.
     */
    private static function cache(string $namespace): VersionedCache
    {
        $store = config('wa.security.abuse.cache.store');
        $ttl = config('wa.security.abuse.cache.ttl', 172_800);

        return new VersionedCache(
            namespace: $namespace,
            ttlSeconds: is_numeric($ttl) ? max(60, (int) $ttl) : 172_800,
            store: is_string($store) && $store !== '' ? $store : null,
        );
    }

    /**
     * The velocity limits, read per counter so a partially-configured file still yields
     * a complete set (`HeuristicAntiFraudGuard::defaultLimit()` fills the gaps).
     *
     * @return array<string, VelocityLimit>
     */
    private static function limits(): array
    {
        $limits = [];

        foreach (HeuristicAntiFraudGuard::counterNames() as $name) {
            $default = HeuristicAntiFraudGuard::defaultLimit($name);

            // A counter name is already the config path under `anti_fraud`
            // ('signup.identity' → wa.security.anti_fraud.signup.identity), which keeps the
            // two lists impossible to drift apart.
            $limits[$name] = VelocityLimit::fromConfig(
                'wa.security.anti_fraud.'.$name,
                $default->max,
                $default->windowSeconds,
            );
        }

        return $limits;
    }

    /**
     * Resolve and type-check the optional classifiers.
     *
     * @return list<InjectionClassifier>
     */
    private function optionalClassifiers(Application $app): array
    {
        $configured = config('wa.security.guardrail.classifiers', []);
        $classifiers = [];

        foreach (is_array($configured) ? $configured : [] as $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            $classifier = $app->make($class);

            if (! $classifier instanceof InjectionClassifier) {
                throw new InvalidArgumentException(sprintf(
                    'wa.security.guardrail.classifiers must name %s implementations, got [%s].',
                    InjectionClassifier::class,
                    $class,
                ));
            }

            $classifiers[] = $classifier;
        }

        return $classifiers;
    }

    /**
     * Narrow a config array to the list of non-empty strings it is meant to be.
     *
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $list = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return $list;
    }
}
