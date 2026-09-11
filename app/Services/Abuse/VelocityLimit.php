<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * One configured velocity limit: *how many* of something, *within* how long
 * (Req 32.7 / NFR3).
 *
 * Both halves are configurable, and both are validated here rather than at the call
 * site: a limit of 0 disables the check (the platform may decide it does not want a
 * device counter at all) and a non-positive window is nonsense, so it is floored at one
 * second. That keeps `HeuristicAntiFraudGuard` free of defensive arithmetic and makes
 * "every threshold configurable" true without making "every threshold trustworthy"
 * false.
 */
final readonly class VelocityLimit
{
    public function __construct(
        public int $max,
        public int $windowSeconds,
    ) {}

    /**
     * Read `{max, window_seconds}` from a config path, falling back to the defaults the
     * platform ships.
     */
    public static function fromConfig(string $path, int $defaultMax, int $defaultWindow): self
    {
        $configured = config($path, []);
        $configured = is_array($configured) ? $configured : [];

        $max = $configured['max'] ?? $defaultMax;
        $window = $configured['window_seconds'] ?? $defaultWindow;

        return new self(
            is_numeric($max) ? max(0, (int) $max) : $defaultMax,
            is_numeric($window) ? max(1, (int) $window) : $defaultWindow,
        );
    }

    /**
     * Whether this limit is switched off.
     */
    public function isDisabled(): bool
    {
        return $this->max <= 0;
    }
}
