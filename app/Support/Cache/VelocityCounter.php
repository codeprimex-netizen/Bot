<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Support\Carbon;

/**
 * Fixed-window counters for the anti-fraud heuristics — "how many times has this
 * address / device / identity done this lately?" (design § Abuse / anti-fraud:
 * *"device/IP heuristics, velocity limits"*; Req 32.7 / NFR3).
 *
 * ```php
 * $reading = $counter->hit('signup:ip:6f2a…', windowSeconds: 3600);
 *
 * $reading->hits;             // 4
 * $reading->resetsInSeconds;  // 812 — the whole basis of the Retry-After
 * ```
 *
 * ## Why fixed windows, and what that costs
 *
 * The window key is `floor(now / window)`, so a counter is one cache entry that expires
 * on its own and a reading needs no stored history. The known imprecision is the
 * boundary: an attacker who splits their attempts across the seam of two windows can
 * make up to `2 × limit` attempts in one window's worth of time. That is accepted here
 * deliberately, because the alternatives are worse for this job — a sliding log stores
 * one entry per attempt (an attacker-controlled amount of memory, on the *signup* path,
 * where the caller is anonymous), and a leaky bucket needs a read-modify-write per hit,
 * which loses hits under exactly the concurrency an attack produces.
 *
 * What the platform relies on instead is that these limits are **bounded blocks, not
 * bans**: doubling the throughput of a farming attempt for one window does not gain the
 * attacker a tenant, because the next window refuses again, and every refusal is
 * recorded in `abuse_events` where the pattern is visible to an operator.
 *
 * ## Counters hold no identities
 *
 * A key is composed from keyed digests (`App\Services\Abuse\IdentityDigest`), never from
 * an email, a phone number, or an IP address. A dump of the cache therefore reveals who
 * has been counted only to someone who already holds the digest key.
 */
final readonly class VelocityCounter
{
    public function __construct(private VersionedCache $cache) {}

    /**
     * Count one attempt and read the counter back.
     */
    public function hit(string $key, int $windowSeconds, int $by = 1): VelocityReading
    {
        $window = max(1, $windowSeconds);

        return new VelocityReading(
            $this->cache->increment($this->windowKey($key, $window), max(1, $by)),
            $window,
            $this->resetsIn($window),
        );
    }

    /**
     * Read the counter without counting — for a speculative check (an eligibility gate,
     * a panel display) that must not spend anybody's allowance.
     */
    public function peek(string $key, int $windowSeconds): VelocityReading
    {
        $window = max(1, $windowSeconds);
        $value = $this->cache->get($this->windowKey($key, $window));

        return new VelocityReading(is_int($value) ? $value : 0, $window, $this->resetsIn($window));
    }

    /**
     * Drop a counter — used when the thing it counted turned out to be legitimate (a
     * correct OTP clears the failed-attempt counter), so one honest success cannot leave
     * a customer locked out for the rest of the window.
     */
    public function clear(string $key, int $windowSeconds): void
    {
        $this->cache->forget($this->windowKey($key, max(1, $windowSeconds)));
    }

    /**
     * The current window's key.
     */
    private function windowKey(string $key, int $window): string
    {
        return sprintf('%s:w%d:%d', $key, $window, intdiv(Carbon::now()->getTimestamp(), $window));
    }

    /**
     * Seconds until the current window rolls — never 0, so a `Retry-After` derived from
     * it cannot invite an immediate retry.
     */
    private function resetsIn(int $window): int
    {
        return max(1, $window - (Carbon::now()->getTimestamp() % $window));
    }
}
