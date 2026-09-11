<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\ErrorClass;
use Closure;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The classifier the platform actually resolves: a chain that asks each registered
 * classifier in turn and stops at the first opinion (Req 33.2 / NFR4).
 *
 * Order, most authoritative first:
 *
 * 1. **runtime registrations** — `register()` / `map()`, newest first. What a service
 *    provider adds at boot, and what a test uses to prove the seam works.
 * 2. **`wa.reliability.retry.exceptions`** — a plain `ExceptionClass => 'CLASS'` map for
 *    the common case where a provider error needs no logic, only a name.
 * 3. **`wa.reliability.retry.classifiers`** — `ErrorClassifier` implementations, resolved
 *    from the container, for the cases that do need logic (a status code, a vendor error
 *    number, a message pattern).
 * 4. **`PlatformErrorClassifier`** — the platform's own typed exceptions and the HTTP
 *    status fallback. Appended in **code, not config**, so no deployment can configure away
 *    "a suspended tenant is not retried".
 *
 * Registrations therefore *can* override a built-in, which is deliberate: reclassifying one
 * exception during an incident should not need a release. What they cannot do is remove the
 * platform classifier from the chain, so an unrecognised platform exception always still
 * gets its documented answer.
 *
 * ## Adding a provider's errors — the three ways in
 *
 * ```php
 * // 1. config, no code: one exception, one class
 * 'exceptions' => [TwilioRateLimited::class => 'RATE_LIMIT'],
 *
 * // 2. config, with logic: a whole provider's error numbers
 * 'classifiers' => [CloudApiErrorClassifier::class],
 *
 * // 3. at runtime, from a provider's boot() — e.g. a package registering itself
 * $this->app->make(ErrorClassifier::class)->register(
 *     fn (Throwable $e): ?ErrorClass => $e instanceof BspTimeout ? ErrorClass::Timeout : null,
 * );
 * ```
 *
 * Later phases (Channel Mode drivers, LLM providers, payment gateways) use these and never
 * edit `PlatformErrorClassifier`.
 *
 * ## Nothing here may throw
 *
 * This code runs while something else is already failing, so every step is defensive: a
 * classifier that cannot be resolved, is not an `ErrorClassifier`, throws, or returns
 * something that is not an `ErrorClass` is **skipped**, and the chain continues. A broken
 * classifier must not be able to convert a handled failure into an unhandled one — that
 * would turn a retryable send into a crashed worker, which is the failure mode Req 31.1
 * cares most about. It returns `null` rather than a class of its own choosing when nothing
 * matched; `RetryPolicy` owns the default.
 */
final class CompositeErrorClassifier implements ErrorClassifier
{
    /**
     * Config key holding `ExceptionClass => 'ERROR_CLASS'`.
     */
    private const string EXCEPTIONS_KEY = 'wa.reliability.retry.exceptions';

    /**
     * Config key holding a list of `ErrorClassifier` implementations.
     */
    private const string CLASSIFIERS_KEY = 'wa.reliability.retry.classifiers';

    /**
     * Runtime-registered classifiers, newest first.
     *
     * @var list<ErrorClassifier|Closure(Throwable): ?ErrorClass>
     */
    private array $registered = [];

    /**
     * Runtime-registered `ExceptionClass => ErrorClass` entries.
     *
     * @var array<class-string, ErrorClass>
     */
    private array $mapped = [];

    public function __construct(
        private readonly Container $container,
        private readonly PlatformErrorClassifier $platform = new PlatformErrorClassifier,
    ) {}

    /**
     * Add a classifier ahead of everything already registered.
     *
     * @param  ErrorClassifier|Closure(Throwable): ?ErrorClass  $classifier
     */
    public function register(ErrorClassifier|Closure $classifier): void
    {
        array_unshift($this->registered, $classifier);
    }

    /**
     * Classify one exception class (and its subclasses) as $class.
     *
     * @param  class-string  $exceptionClass
     */
    public function map(string $exceptionClass, ErrorClass $class): void
    {
        $this->mapped = [$exceptionClass => $class] + $this->mapped;
    }

    public function classify(Throwable $e): ?ErrorClass
    {
        foreach ($this->registered as $classifier) {
            $class = $this->ask($classifier, $e);

            if ($class !== null) {
                return $class;
            }
        }

        return $this->fromMap($this->mapped, $e)
            ?? $this->fromMap($this->configuredExceptions(), $e)
            ?? $this->fromConfiguredClassifiers($e)
            ?? $this->platform->classify($e);
    }

    /**
     * Ask one classifier, swallowing its own failure.
     *
     * @param  ErrorClassifier|Closure(Throwable): ?ErrorClass  $classifier
     */
    private function ask(ErrorClassifier|Closure $classifier, Throwable $e): ?ErrorClass
    {
        try {
            $class = $classifier instanceof ErrorClassifier
                ? $classifier->classify($e)
                : $classifier($e);
        } catch (Throwable) {
            return null;
        }

        return $class instanceof ErrorClass ? $class : null;
    }

    /**
     * First entry of $map whose key $e is an instance of, most specific first.
     *
     * "Most specific" is approximated by declaration order — an exact class match is
     * checked before any `instanceof` match, so mapping a base class does not shadow a
     * subclass mapped alongside it.
     *
     * @param  array<class-string, ErrorClass>  $map
     */
    private function fromMap(array $map, Throwable $e): ?ErrorClass
    {
        $exact = $map[$e::class] ?? null;

        if ($exact instanceof ErrorClass) {
            return $exact;
        }

        foreach ($map as $exceptionClass => $class) {
            if ($e instanceof $exceptionClass) {
                return $class;
            }
        }

        return null;
    }

    /**
     * `wa.reliability.retry.exceptions`, keeping only entries that name a real exception
     * class and a real error class.
     *
     * @return array<class-string, ErrorClass>
     */
    private function configuredExceptions(): array
    {
        $configured = config(self::EXCEPTIONS_KEY);
        $map = [];

        foreach (is_array($configured) ? $configured : [] as $exceptionClass => $name) {
            if (! is_string($exceptionClass) || ! class_exists($exceptionClass)) {
                continue;
            }

            $class = $name instanceof ErrorClass
                ? $name
                : ErrorClass::tryFromName(is_string($name) ? $name : null);

            if ($class !== null) {
                $map[$exceptionClass] = $class;
            }
        }

        return $map;
    }

    /**
     * `wa.reliability.retry.classifiers`, in configured order.
     */
    private function fromConfiguredClassifiers(Throwable $e): ?ErrorClass
    {
        $configured = config(self::CLASSIFIERS_KEY);

        foreach (is_array($configured) ? $configured : [] as $entry) {
            $classifier = $this->resolve($entry);

            if ($classifier === null) {
                continue;
            }

            $class = $this->ask($classifier, $e);

            if ($class !== null) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Resolve a configured entry, or null when it is not a usable classifier.
     */
    private function resolve(mixed $entry): ?ErrorClassifier
    {
        if ($entry instanceof ErrorClassifier) {
            return $entry;
        }

        if (! is_string($entry) || ! class_exists(trim($entry))) {
            return null;
        }

        try {
            $resolved = $this->container->make(trim($entry));
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof ErrorClassifier ? $resolved : null;
    }
}
