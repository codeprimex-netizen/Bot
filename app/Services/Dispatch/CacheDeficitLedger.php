<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The deficit ledger on the configured cache store, with a per-lane lock so several
 * workers stay coherent (Req 1.7 / A1; Req 30.2, 30.3 / NFR1).
 *
 * One entry per queue lane — `dispatch:fair:campaign`, `dispatch:fair:ai-reply` — holding
 * that lane's credits and rotation cursor. The store is whatever `wa.dispatch.fair.state`
 * names: the `database` store today, Redis after Req 30.3's drop-in upgrade (task 36.3),
 * with no change to this class beyond a config value.
 *
 * ## Multi-worker fairness
 *
 * The unit that must be atomic is *claim*, not *dispatch*: two workers may dispatch at the
 * same time (that is the point of having two workers), but they must not both spend the
 * same credit. So `mutate()` takes the lane's lock, reads, lets the scheduler decide,
 * writes, and releases — never holding it across the actual sending. A claim is a few
 * microseconds of arithmetic, so lock contention stays negligible even with a large worker
 * group, and the resulting behaviour is a single shared deficit round robin that happens to
 * be executed by N workers.
 *
 * ## Two documented degradations, both bounded
 *
 * 1. **No lock support.** A cache store that is not a `LockProvider` (only reachable in
 *    tests — every store the framework ships supports locks) falls back to an unlocked
 *    read-modify-write, exactly as `HashChainAuditService` does. Two racing claims can then
 *    overwrite each other's credit decrement, so a lane can be over-served by at most the
 *    credits one claim spent, i.e. **one quantum per racing worker**. That is the same order
 *    as the rounding error the scheduler already tolerates, and it is self-correcting: the
 *    next round credits from whatever was persisted.
 * 2. **Lock timeout.** If a lane's lock cannot be taken within the configured wait, the
 *    claim proceeds *unlocked* rather than raising. Refusing to dispatch would convert a
 *    contended cache into a platform-wide send outage, which is a much worse failure than a
 *    momentarily imprecise share; and unlike an audit append (where the write is the
 *    evidence and must not be silently skipped), a scheduling decision that is slightly off
 *    is corrected by the very next window. The lock TTL is set well above the wait window so
 *    a worker that dies mid-claim cannot wedge the lane.
 *
 * In both cases the *fairness property* still holds — no tenant is starved and every share
 * stays proportional within a bound — because the bound is stated per window, and the error
 * introduced is at most one quantum per concurrent claim.
 */
final readonly class CacheDeficitLedger implements DeficitLedger
{
    public function __construct(private CacheFactory $cache) {}

    public function mutate(string $queueLane, Closure $work): mixed
    {
        $lane = self::normalizeLane($queueLane);
        $lock = $this->lockFor($lane);

        try {
            $credits = $this->load($lane);

            $result = $work($credits);

            // Persisted only on a clean return: a claim that threw has dispatched nothing,
            // so its half-spent credits must not survive.
            $this->save($lane, $credits);

            return $result;
        } finally {
            $lock?->release();
        }
    }

    public function credits(string $queueLane): LaneCredits
    {
        return $this->load(self::normalizeLane($queueLane))->copy();
    }

    public function reset(string $queueLane): void
    {
        $this->store()->forget($this->stateKey(self::normalizeLane($queueLane)));
    }

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    private function load(string $lane): LaneCredits
    {
        return LaneCredits::fromArray($this->store()->get($this->stateKey($lane)));
    }

    private function save(string $lane, LaneCredits $credits): void
    {
        $key = $this->stateKey($lane);

        if ($credits->isEmpty()) {
            // Nothing worth keeping: every lane drained or was pruned. Dropping the entry
            // keeps an idle platform from holding one cache row per queue lane forever.
            $this->store()->forget($key);

            return;
        }

        $this->store()->put($key, $credits->toArray(), $this->ttlSeconds());
    }

    private function store(): CacheRepository
    {
        $store = $this->configString('store');

        return $this->cache->store($store);
    }

    private function stateKey(string $lane): string
    {
        return $this->prefix().':'.$lane;
    }

    /*
    |--------------------------------------------------------------------------
    | Locking
    |--------------------------------------------------------------------------
    */

    /**
     * The lane's claim lock, or `null` when there is none to be had — see the class
     * docblock for why neither absence nor timeout is fatal.
     */
    private function lockFor(string $lane): ?Lock
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        $lockSeconds = max(1, $this->configInt('lock_seconds', 5));
        $waitSeconds = max(0, $this->configInt('lock_wait_seconds', 3));

        // TTL comfortably above the wait window so a crashed claimant's lock expires
        // before another worker's wait does.
        $lock = $store->lock($this->stateKey($lane).':lock', max($lockSeconds * 2, 10));

        if ($waitSeconds === 0) {
            return $lock->get() === true ? $lock : null;
        }

        try {
            $lock->block($waitSeconds);
        } catch (LockTimeoutException) {
            return null;
        }

        return $lock;
    }

    /*
    |--------------------------------------------------------------------------
    | Config
    |--------------------------------------------------------------------------
    */

    /**
     * Cache keys are composed, so a lane name is constrained to what is safe in one —
     * queue lane names come from code and config, never from a tenant, but a stray space
     * or colon would still split the namespace in two.
     */
    public static function normalizeLane(string $queueLane): string
    {
        $lane = strtolower(trim($queueLane));
        $lane = (string) preg_replace('/[^a-z0-9._-]+/', '-', $lane);
        $lane = trim($lane, '-');

        return $lane === '' ? 'default' : $lane;
    }

    private function prefix(): string
    {
        return $this->configString('prefix') ?? 'dispatch:fair';
    }

    private function ttlSeconds(): int
    {
        return max(1, $this->configInt('ttl', 3600));
    }

    private function configInt(string $key, int $default): int
    {
        $value = config('wa.dispatch.fair.state.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function configString(string $key): ?string
    {
        $value = config('wa.dispatch.fair.state.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
