<?php

declare(strict_types=1);

namespace App\Support\Cache;

/**
 * One velocity counter, as read: how many hits are in the current window, how long the
 * window is, and how long until it rolls (Req 32.7 / NFR3).
 *
 * Every field is safe to store in `abuse_events`, and together they are what makes an
 * anti-fraud refusal *explainable from stored data*: "5 of a permitted 3 signups from
 * this address range in 3 600 s; refused for another 812 s" can be reconstructed from
 * the row long after the counter itself has expired.
 */
final readonly class VelocityReading
{
    public function __construct(
        public int $hits,
        public int $windowSeconds,
        public int $resetsInSeconds,
    ) {}

    /**
     * Whether the counter has gone past a limit.
     *
     * Strictly greater than, because the hit being judged has already been counted: a
     * limit of 3 permits three attempts per window and refuses the fourth. A limit of 0
     * disables the check entirely rather than refusing everything — the caller reads
     * `VelocityLimit::isDisabled()` for that, and this method agrees with it.
     */
    public function exceeds(int $limit): bool
    {
        return $limit > 0 && $this->hits > $limit;
    }

    /**
     * The stored evidence shape.
     *
     * @return array{hits: int, limit: int, window_seconds: int, resets_in_seconds: int}
     */
    public function toArray(int $limit): array
    {
        return [
            'hits' => $this->hits,
            'limit' => $limit,
            'window_seconds' => $this->windowSeconds,
            'resets_in_seconds' => $this->resetsInSeconds,
        ];
    }
}
