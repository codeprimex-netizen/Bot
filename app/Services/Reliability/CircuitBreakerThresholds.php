<?php

declare(strict_types=1);

namespace App\Services\Reliability;

use App\Enums\CircuitScope;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use InvalidArgumentException;

/**
 * The tolerances of one breaker family, resolved from config — and the trip predicate
 * of Algorithm 7 (Req 31.3 / NFR2; Req 13.13 / B4).
 *
 * design.md § Reliability → Circuit breakers gives each family a different row:
 *
 * | Family     | Trip threshold                      | Open | Probes |
 * |------------|-------------------------------------|------|--------|
 * | `provider` | ≥5 fails / 30s **or** >50% err over 20 | 30s | 3 |
 * | `gateway`  | ≥5 fails / 60s                      | 60s  | 3      |
 * | `bridge`   | ≥3 fails / 30s                      | 15s  | 2      |
 *
 * so the numbers cannot be constants on the breaker itself. `forScope()` reads them
 * from `wa.reliability.circuit`, most specific first:
 *
 *   1. `scopes.{scope}.{knob}` — the family override
 *   2. `defaults.{knob}` — the platform default
 *   3. `self::DEFAULT_*` — the compiled-in fallback, so a key deleted from config
 *      cannot leave a safety threshold unset
 *
 * ## Why bad values are fatal
 *
 * A probe limit of 0 is a breaker that can never close; an error rate of 1.5 is an arm
 * that can never fire; a window of 0 rotates on every call, so nothing ever accumulates
 * to a threshold. Each of those *looks* like a working breaker and silently isn't — the
 * same failure shape as a silently skipped dispatch gate (`InvalidDispatchGateException`),
 * and treated the same way: the constructor throws, on the first guarded call, where a
 * smoke test sees it.
 *
 * ## The two arms are independent, and one usually shadows the other
 *
 * With the design's own provider numbers the count arm fires first in practice: 5
 * in-window failures trip it, while the rate arm needs >50% of at least 20 calls, i.e.
 * ≥11 failures. That is not redundancy — the rate arm is the arm that matters for a
 * family configured with a high count threshold (a chatty dependency where 5 failures a
 * minute is normal but half of them failing is not), and both are implemented and tested
 * separately so raising `failure_threshold` cannot quietly leave a breaker with no
 * defence.
 */
final readonly class CircuitBreakerThresholds
{
    public const int DEFAULT_FAILURE_THRESHOLD = 5;

    public const int DEFAULT_WINDOW_SECONDS = 30;

    public const float DEFAULT_ERROR_RATE = 0.5;

    public const int DEFAULT_ERROR_RATE_SAMPLE = 20;

    public const int DEFAULT_OPEN_SECONDS = 30;

    public const int DEFAULT_PROBES = 3;

    public const int DEFAULT_PROBE_SUCCESSES = 1;

    /**
     * Root of the config group these are read from.
     */
    private const string CONFIG_ROOT = 'wa.reliability.circuit';

    /**
     * @param  int  $failureThreshold  in-window failures that open the breaker
     * @param  int  $windowSeconds  length of the rolling failure window
     * @param  float|null  $errorRateThreshold  rate above which the breaker opens, or null
     *                                          to disable that arm for this family
     * @param  int  $errorRateSample  in-window calls required before the rate arm may fire
     * @param  int  $openSeconds  cool-down before a probe is admitted
     * @param  int  $probeLimit  probes admitted in HALF_OPEN, across all workers
     * @param  int  $probeSuccesses  probe successes that close the breaker
     *
     * @throws InvalidArgumentException when a value cannot describe a working breaker
     */
    public function __construct(
        public int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        public int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
        public ?float $errorRateThreshold = self::DEFAULT_ERROR_RATE,
        public int $errorRateSample = self::DEFAULT_ERROR_RATE_SAMPLE,
        public int $openSeconds = self::DEFAULT_OPEN_SECONDS,
        public int $probeLimit = self::DEFAULT_PROBES,
        public int $probeSuccesses = self::DEFAULT_PROBE_SUCCESSES,
    ) {
        $this->assertPositive('failure_threshold', $this->failureThreshold);
        $this->assertPositive('window_seconds', $this->windowSeconds);
        $this->assertPositive('error_rate_sample', $this->errorRateSample);
        $this->assertPositive('open_seconds', $this->openSeconds);
        $this->assertPositive('probes', $this->probeLimit);
        $this->assertPositive('probe_successes', $this->probeSuccesses);

        if ($this->errorRateThreshold !== null
            && ($this->errorRateThreshold < 0.0 || $this->errorRateThreshold >= 1.0)) {
            throw new InvalidArgumentException(sprintf(
                '%s error_rate must be in [0, 1) or null to disable the arm, got %s. An '
                .'error rate can never exceed 1, so 1 and above is an arm that never fires — '
                .'write null if that is what you mean.',
                self::CONFIG_ROOT,
                var_export($this->errorRateThreshold, true),
            ));
        }

        if ($this->probeSuccesses > $this->probeLimit) {
            throw new InvalidArgumentException(sprintf(
                '%s asks for %d probe successes but admits only %d probes, so the breaker '
                .'could never reach CLOSED and the dependency would stay fenced off for ever.',
                self::CONFIG_ROOT,
                $this->probeSuccesses,
                $this->probeLimit,
            ));
        }
    }

    /**
     * The tolerances for one family: family override → platform default → fallback.
     *
     * @throws InvalidArgumentException when the resolved values cannot describe a
     *                                  working breaker
     */
    public static function forScope(CircuitScope $scope): self
    {
        $defaults = self::group('defaults');
        $overrides = self::group('scopes.'.$scope->value);

        return new self(
            failureThreshold: self::int('failure_threshold', $overrides, $defaults, self::DEFAULT_FAILURE_THRESHOLD),
            windowSeconds: self::int('window_seconds', $overrides, $defaults, self::DEFAULT_WINDOW_SECONDS),
            errorRateThreshold: self::rate($overrides, $defaults),
            errorRateSample: self::int('error_rate_sample', $overrides, $defaults, self::DEFAULT_ERROR_RATE_SAMPLE),
            openSeconds: self::int('open_seconds', $overrides, $defaults, self::DEFAULT_OPEN_SECONDS),
            probeLimit: self::int('probes', $overrides, $defaults, self::DEFAULT_PROBES),
            probeSuccesses: self::int('probe_successes', $overrides, $defaults, self::DEFAULT_PROBE_SUCCESSES),
        );
    }

    /**
     * Whether the error-rate arm is live for this family.
     */
    public function errorRateArmIsLive(): bool
    {
        return $this->errorRateThreshold !== null;
    }

    /**
     * Algorithm 7's open condition, evaluated against a row whose failure has already
     * been counted.
     *
     * Three arms, exactly as the pseudocode ORs them:
     *
     *  - the row is `HALF_OPEN` — a probe failed, so recovery is disproven and the
     *    breaker re-opens on that one failure alone, whatever the counters say;
     *  - `failure_count >= failureThreshold` — the count arm, over the *current window*
     *    only, which is what makes "≥5 failures within 30 seconds" mean what it says;
     *  - the rate arm, once the window holds enough calls to be evidence.
     */
    public function tripsOn(CircuitBreakerRecord $row): bool
    {
        return $row->state->isHalfOpen()
            || $row->failure_count >= $this->failureThreshold
            || $this->errorRateExceededBy($row);
    }

    /**
     * Whether the rate arm alone would open this breaker.
     *
     * A window with fewer than `errorRateSample` calls is not evidence: a breaker whose
     * single call so far failed sits at a 100% error rate, and opening on that would
     * fence off a dependency nobody has actually shown to be broken.
     */
    public function errorRateExceededBy(CircuitBreakerRecord $row): bool
    {
        if ($this->errorRateThreshold === null) {
            return false;
        }

        if ($row->windowCalls() < $this->errorRateSample) {
            return false;
        }

        return $row->errorRate() > $this->errorRateThreshold;
    }

    /**
     * @return array<string, mixed>
     */
    private static function group(string $key): array
    {
        $value = config(self::CONFIG_ROOT.'.'.$key);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $defaults
     */
    private static function int(string $key, array $overrides, array $defaults, int $fallback): int
    {
        $value = $overrides[$key] ?? $defaults[$key] ?? $fallback;

        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * The rate arm, where **null is a meaningful value** (the arm is off for this
     * family) and so cannot be treated as "absent, fall through".
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $defaults
     */
    private static function rate(array $overrides, array $defaults): ?float
    {
        foreach ([$overrides, $defaults] as $source) {
            if (! array_key_exists('error_rate', $source)) {
                continue;
            }

            $value = $source['error_rate'];

            if ($value === null) {
                return null;
            }

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return self::DEFAULT_ERROR_RATE;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertPositive(string $key, int $value): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf(
                '%s %s must be at least 1, got %d. A circuit breaker configured with a '
                .'non-positive %s looks live but cannot work.',
                self::CONFIG_ROOT,
                $key,
                $value,
                $key,
            ));
        }
    }
}
