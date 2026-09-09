<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\IdempotencyState;
use App\Enums\QuotaKind;
use App\Enums\QuotaReason;
use App\Exceptions\Billing\MalformedPlanException;
use App\Exceptions\Tenancy\QuotaExceededException;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Services\Billing\PlanRepository;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The one place plan allowances are checked and spent (Req 3.4, 3.5 / A3;
 * design.md §"Components and Interfaces → 1. Tenancy layer", Algorithm 3,
 * Correctness Property 4).
 *
 * ```php
 * // Algorithm 3, in order: ask first…
 * $verdict = $quota->verdict($tenant, QuotaKind::MessagesMonthly);
 * if ($verdict->isBlocked())  { return $gate->block($verdict); }
 * if ($verdict->isDeferred()) { return $gate->defer($verdict->secondsUntilPeriodReset()); }
 *
 * // …send… and only then spend, keyed by the message's idempotency key:
 * $quota->consume($tenant, QuotaKind::MessagesMonthly, $message->idempotency_key);
 * ```
 *
 * The order is the design's, and it is not interchangeable. `verdict()` is a cheap,
 * side-effect-free reading taken *before* the work; `consume()` runs **exactly once,
 * after the bridge confirms the send, keyed by the message idempotency key** — so a job
 * that is retried after a successful send but a failed acknowledgement cannot count the
 * same message twice (Req 3.5), and a job that never got as far as sending has spent
 * nothing at all.
 *
 * This guard sits *after* the lifecycle and plan gates, never in front of them: task 9.3
 * calls `TenantLifecycle::assertCanSendOutbound()` first (a suspended tenant is refused
 * before any allowance is examined), then `PlanGate`, then this.
 *
 * ## Where the numbers come from
 *
 * | Question | Answered by | Never by |
 * |---|---|---|
 * | what is the ceiling? | `Plan::limitFor()` via `PlanRepository` (cached) | this class, config, or the counter row |
 * | which bucket are we in? | `QuotaKind::periodKey()` in the **tenant's** timezone | a locally re-derived date format |
 * | how much is spent? | `tenant_usage.used` | anything derived or estimated |
 *
 * `null` from `limitFor()` is unlimited; a kind the plan omits grants **0**, and no plan
 * at all grants 0 — a missing plan is never a reason to skip the check
 * (`PlanRepository::forTenant()`, `PlanLimits`).
 *
 * ## Accruing counters are spent; gauges are measured
 *
 * `MESSAGES_MONTHLY`, `MESSAGES_DAILY` and `AI_CREDITS` accrue inside a period, so they
 * are **incremented** by `consume()` and reset when the period key rolls over.
 *
 * `SESSIONS`, `CONTACTS` and `CAMPAIGNS_CONCURRENT` are gauges: they measure how many
 * exist *right now* and share one standing bucket (`QuotaKind::GAUGE_PERIOD_KEY`) that
 * never rolls over. Metering them by increment/decrement would be wrong in a way that
 * never heals — a decrement lost to a crashed delete leaves the tenant permanently
 * short of capacity, with no period rollover coming to correct it. So a gauge is metered
 * by **recount**: `observe($tenant, $kind, $currentCount)` *sets* `used` from the
 * authoritative source (`$tenant->sessions()->count()`), which is idempotent, needs no
 * idempotency key, and self-heals after any missed event. `consume()` refuses a gauge
 * and `observe()` refuses an accruing kind, so the two can never be mixed up silently.
 *
 * ## The ceiling can change mid-period; usage never rewrites
 *
 * `tenant_usage.limit` is a **stamp for reporting, not the source of truth**: every
 * decision reads the plan, and any write refreshes the stamped value. An upgrade
 * therefore takes effect immediately (a tenant that buys more capacity can use it in the
 * same minute), and so does a downgrade. What never happens is a change to `used`: a
 * downgrade does not forgive spend, and an upgrade does not invent it, so a tenant that
 * drops below its own consumption is simply exhausted until the period rolls — deferred,
 * not billed backwards.
 *
 * Unlimited kinds stamp `QuotaVerdict::UNLIMITED` in that column, because it is a
 * non-null unsigned bigint: anything rendering it must ask `isUnlimited()` rather than
 * print the number.
 *
 * ## Property 4: never negative, never double-counted
 *
 * Four layers, outermost first:
 *
 * 1. **`Cache::lock("quota:{tenant}:{kind}")`** — serializes concurrent consumption of
 *    the same counter, so the ceiling check and the increment are one step. Losing the
 *    lock (a store with no lock support, or a wait timeout) degrades to the layers below
 *    rather than refusing: the send already happened, and declining to record it would
 *    lose usage. The bounded cost is that two racing consumers can each pass the ceiling
 *    check, overshooting `limit` by at most the smaller consumer's units.
 * 2. **`uniq(scope, key)` on `idempotency_keys`** — the consume-once guarantee, and the
 *    only layer that holds across processes, hosts and restarts. The ledger row and the
 *    increment are written in **one transaction**, so there is no interleaving in which
 *    usage is counted without being recorded (a retry would double it) or recorded
 *    without being counted (usage lost). A caller that loses the unique index replays the
 *    winner's receipt.
 * 3. **`used = used + n` as SQL** — the increment is never a read-modify-write in PHP, so
 *    it is atomic even with no lock at all.
 * 4. **`unsignedBigInteger used`** — the database itself refuses a negative counter, and
 *    nothing here ever decrements: an accruing counter only rises until its period rolls,
 *    and a gauge is *set* to a non-negative recount.
 *
 * Only layer 1 differs by engine. On **MySQL 8** the bucket read inside `consume()` takes
 * `FOR UPDATE`, so two workers on different hosts serialize on the row even if the cache
 * lock is unavailable; SQLite ignores the clause (it serializes writers anyway), so that
 * particular belt-and-braces is exercised only in production. Everything else — the
 * unique index, the atomic increment, the unsigned column — behaves identically on both.
 *
 * ## How this composes with `IdempotencyStore::once()` (task 3.4)
 *
 * The dedup here is deliberately built on the *primitive*, not on a private copy of the
 * store: it inserts a `COMPLETED` `idempotency_keys` row with the same shape task 3.4
 * will write and replay (`scope`, `key`, `state`, `result`, `response_hash`,
 * `completed_at`, `expires_at`). When `once()` lands, `consume()` collapses into
 *
 * ```php
 * $store->once($this->idempotencyScope($tenant, $kind), $key, fn () => $this->apply(...));
 * ```
 *
 * with no data migration and no change to the scope format — and task 3.4 must keep the
 * `result` payload replayable verbatim (see `QuotaConsumption::toLedger()`), because that
 * payload *is* the answer a duplicate consume gets. What `consume()` must **not** adopt
 * from `once()` is its `IN_FLIGHT` lease: the guarded operation here is a single atomic
 * database write, so there is no window in which a lease could be held, and introducing
 * one would add a way for a crashed worker to block a counter.
 */
final class QuotaGuard
{
    /**
     * Plan feature flag that lets a tenant spend past its ceiling — Property 4's
     * *"except explicit plan overage"*.
     *
     * Absent (the normal case) means the ceiling is hard: `consume()` records only what
     * fits and reports the rest as `QuotaConsumption::refused()`.
     */
    public const string OVERAGE_FEATURE = 'quota_overage';

    /**
     * Fallback `idempotency_keys.scope` prefix — see `idempotencyScope()`.
     */
    public const string DEFAULT_SCOPE_PREFIX = 'quota';

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly TenantContext $context,
        private readonly CacheFactory $cache,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Asking (side-effect free)
    |--------------------------------------------------------------------------
    */

    /**
     * May $tenant spend $units of $kind right now — allow, defer, or block?
     *
     * Writes nothing and reserves nothing, so it is safe to ask speculatively (the
     * dispatch eligibility gate asks about every candidate tenant, every window) and safe
     * to ask on every send.
     *
     * @throws InvalidArgumentException when $units < 1, which is a caller bug: there is
     *                                  no such thing as spending nothing
     */
    public function verdict(Tenant $tenant, QuotaKind $kind, int $units = 1): QuotaVerdict
    {
        $this->assertUnits($units);

        $periodKey = $this->periodKeyFor($tenant, $kind);
        $ceiling = $this->ceiling($tenant, $kind);

        if ($ceiling['refusal'] !== null) {
            // No plan, or a plan whose limits cannot be read: there is no allowance to be
            // part-way through, so the counter is not even consulted.
            return QuotaVerdict::for($kind, $ceiling['refusal'], $periodKey, $units, 0, 0);
        }

        $limit = $ceiling['limit'];
        $used = $this->usedIn($tenant, $kind, $periodKey);

        if ($limit === null) {
            return QuotaVerdict::for($kind, QuotaReason::Unlimited, $periodKey, $units, $used, null);
        }

        if ($limit === 0) {
            // A zero ceiling is never overageable: there is no allowance to overrun, and
            // treating one as "unlimited with billing" would let a typo'd plan send freely.
            return QuotaVerdict::for(
                $kind,
                $ceiling['declared'] ? QuotaReason::MeteredToZero : QuotaReason::NotPriced,
                $periodKey,
                $units,
                $used,
                0,
            );
        }

        if ($used + $units <= $limit) {
            return QuotaVerdict::for($kind, QuotaReason::WithinAllowance, $periodKey, $units, $used, $limit);
        }

        if ($ceiling['overage']) {
            return QuotaVerdict::for($kind, QuotaReason::Overage, $periodKey, $units, $used, $limit);
        }

        if ($units > $limit) {
            // Bigger than a whole period's allowance: no reset will ever make it fit, so
            // this is a block the tenant can act on, not a job that bounces for ever.
            return QuotaVerdict::for($kind, QuotaReason::ExceedsPeriodLimit, $periodKey, $units, $used, $limit);
        }

        if ($kind->isGauge()) {
            // The standing bucket never rolls over, so there is no period to wait for.
            return QuotaVerdict::for($kind, QuotaReason::GaugeAtCapacity, $periodKey, $units, $used, $limit);
        }

        return QuotaVerdict::for(
            $kind,
            QuotaReason::PeriodExhausted,
            $periodKey,
            $units,
            $used,
            $limit,
            $this->secondsUntilPeriodReset($tenant, $kind),
        );
    }

    /**
     * `verdict()` for callers whose failure mode is an exception rather than a branch —
     * a panel action, an API request, anything with no queued job to release.
     *
     * The send pipeline does **not** use this: Algorithm 3 needs the defer branch to
     * `release()` the job, which an exception cannot express.
     *
     * @throws QuotaExceededException when the verdict does not allow
     */
    public function authorize(Tenant $tenant, QuotaKind $kind, int $units = 1): QuotaVerdict
    {
        $verdict = $this->verdict($tenant, $kind, $units);

        if (! $verdict->isAllowed()) {
            throw QuotaExceededException::from($tenant->id, $verdict);
        }

        return $verdict;
    }

    /**
     * Allowance left for $kind in the current period — 0 when the tenant has no plan,
     * `QuotaVerdict::UNLIMITED` when the plan sets no ceiling.
     */
    public function remaining(Tenant $tenant, QuotaKind $kind): int
    {
        return $this->verdict($tenant, $kind)->remaining();
    }

    /*
    |--------------------------------------------------------------------------
    | Spending (atomic, consume-once)
    |--------------------------------------------------------------------------
    */

    /**
     * Record $units of confirmed, billable work against $kind — **once** per
     * $idempotencyKey.
     *
     * Call this *after* the work has succeeded, never before: it is an accounting entry
     * for something that happened, not a reservation. A retry of the same job passes the
     * same key and gets the original receipt back with nothing written
     * (`QuotaConsumption::isReplay()`).
     *
     * The key is a **required, positional** argument rather than a trailing optional one
     * precisely because forgetting it would silently reintroduce double counting on every
     * retry — the failure Req 3.5 exists to prevent, and the kind that only shows up under
     * load. For a message it is the message's own idempotency key (Algorithm 3); for any
     * other metered unit of work it is whatever identifies that work uniquely.
     *
     * @param  string  $idempotencyKey  non-empty; unique per unit of billable work
     *
     * @throws InvalidArgumentException when $units < 1, the key is blank, or $kind is a
     *                                  gauge (use `observe()`)
     */
    public function consume(Tenant $tenant, QuotaKind $kind, string $idempotencyKey, int $units = 1): QuotaConsumption
    {
        $this->assertUnits($units);

        $key = trim($idempotencyKey);

        if ($key === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s consume needs a non-empty idempotency key: without one a retried job would count the same work twice (Req 3.5).',
                $kind->value,
            ));
        }

        if ($kind->isGauge()) {
            throw new InvalidArgumentException(sprintf(
                '%s is a gauge: it measures a current count and is metered with observe(), not accrued with consume().',
                $kind->value,
            ));
        }

        $lock = $this->lockFor($tenant, $kind);

        try {
            return $this->context->runFor(
                $tenant,
                fn (Tenant $bound): QuotaConsumption => $this->recordConsumption($bound, $kind, $key, $units),
            );
        } finally {
            $lock?->release();
        }
    }

    /**
     * Set a gauge to its authoritative current count.
     *
     * ```php
     * $quota->observe($tenant, QuotaKind::Sessions, $tenant->sessions()->count());
     * ```
     *
     * A recount rather than an increment, for the reason in the class docblock: a gauge's
     * bucket never rolls over, so drift in it is permanent. Being idempotent, it needs no
     * idempotency key — calling it twice with the same count is the same as calling it
     * once.
     *
     * It deliberately does **not** clamp to the plan ceiling: a count is a measurement,
     * and a tenant left over its limit by a downgrade has to be able to see "12 of 10" in
     * order to know what to delete. `verdict()` then blocks further additions
     * (`GAUGE_AT_CAPACITY`) until the count comes back under the ceiling.
     *
     * @throws InvalidArgumentException when $kind is not a gauge, or $count is negative
     */
    public function observe(Tenant $tenant, QuotaKind $kind, int $count): QuotaConsumption
    {
        if (! $kind->isGauge()) {
            throw new InvalidArgumentException(sprintf(
                '%s accrues over a period: record it with consume() and its idempotency key, not by recounting.',
                $kind->value,
            ));
        }

        if ($count < 0) {
            throw new InvalidArgumentException(sprintf('A %s count cannot be negative, got %d.', $kind->value, $count));
        }

        $lock = $this->lockFor($tenant, $kind);

        try {
            return $this->context->runFor(
                $tenant,
                fn (Tenant $bound): QuotaConsumption => $this->recordMeasurement($bound, $kind, $count),
            );
        } finally {
            $lock?->release();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Buckets and periods
    |--------------------------------------------------------------------------
    */

    /**
     * The `tenant_usage.period_key` $kind is counted under for $tenant right now.
     *
     * Computed in the **tenant's** timezone, so a daily bucket rolls at the tenant's
     * local midnight rather than at UTC midnight.
     */
    public function periodKeyFor(Tenant $tenant, QuotaKind $kind, ?DateTimeInterface $at = null): string
    {
        return $kind->periodKey($at, $this->timezoneOf($tenant));
    }

    /**
     * Seconds until $kind's bucket rolls over for $tenant — the delay Algorithm 3 passes
     * to `release()`.
     *
     * `0` for a gauge: its bucket never rolls, which is why an exhausted gauge blocks
     * instead of deferring.
     */
    public function secondsUntilPeriodReset(Tenant $tenant, QuotaKind $kind, ?DateTimeInterface $at = null): int
    {
        $format = $kind->periodFormat();

        if ($format === null) {
            return 0;
        }

        $timezone = $this->timezoneOf($tenant);
        $moment = $at === null
            ? Carbon::now($timezone)
            : Carbon::instance(Carbon::parse($at))->setTimezone(new DateTimeZone($timezone));

        // Derived from the bucket format rather than from a second copy of the
        // monthly/daily rule, so `QuotaKind` stays the only place bucketing is decided.
        $next = match ($format) {
            'Y-m' => $moment->copy()->startOfMonth()->addMonth(),
            'Y-m-d' => $moment->copy()->startOfDay()->addDay(),
            default => null,
        };

        if ($next === null) {
            return 0;
        }

        return max(0, $next->getTimestamp() - $moment->getTimestamp());
    }

    /**
     * The `idempotency_keys.scope` consumption of $kind is deduplicated in.
     *
     * The tenant id is in the scope because `idempotency_keys` is deliberately not
     * tenant-scoped (see that migration), so isolation has to be explicit in the key —
     * otherwise two tenants whose upstream ids collided would share a dedup namespace.
     *
     * The **period key is deliberately absent**. A send job retried across a period
     * boundary (a daily counter, a retry after the tenant's local midnight) must still be
     * recognised as the same unit of work; if the period were part of the scope, that
     * retry would look brand new and consume a second time in the fresh bucket — exactly
     * the double count Req 3.5 forbids. Which period the original consume landed in is
     * recorded in the ledger row's `result` instead.
     */
    public function idempotencyScope(Tenant $tenant, QuotaKind $kind): string
    {
        return sprintf('%s:%s:%s', $this->scopePrefix(), $tenant->id, $kind->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — reads
    |--------------------------------------------------------------------------
    */

    /**
     * The plan's ceiling for $kind, plus the three things a decision needs alongside it.
     *
     * `refusal` non-null means the plan could not grant anything at all, and the caller
     * must not fall back to a default: absence of a plan is absence of allowance.
     *
     * @return array{limit: int|null, refusal: QuotaReason|null, declared: bool, overage: bool}
     */
    private function ceiling(Tenant $tenant, QuotaKind $kind): array
    {
        $plan = $this->plans->forTenant($tenant);

        if ($plan === null) {
            return ['limit' => 0, 'refusal' => QuotaReason::NoPlan, 'declared' => false, 'overage' => false];
        }

        try {
            return [
                'limit' => $plan->limitFor($kind),
                'refusal' => null,
                'declared' => $plan->declaresLimitFor($kind),
                'overage' => $plan->allows(self::OVERAGE_FEATURE),
            ];
        } catch (MalformedPlanException) {
            // A plan the platform cannot read grants nothing. Failing closed here is the
            // same posture as `TenantScope`: the alternative is billing a tenant for an
            // allowance nobody can state.
            return ['limit' => 0, 'refusal' => QuotaReason::PlanUnreadable, 'declared' => false, 'overage' => false];
        }
    }

    /**
     * `tenant_usage.used` for one bucket, or 0 when the bucket has never been written.
     */
    private function usedIn(Tenant $tenant, QuotaKind $kind, string $periodKey): int
    {
        $bucket = $this->context->runFor(
            $tenant,
            static fn (): ?TenantUsage => TenantUsage::query()->forBucket($kind, $periodKey)->first(),
        );

        return $bucket instanceof TenantUsage ? max(0, $bucket->used) : 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — writes (run inside runFor(), so the acting tenant *is* $tenant)
    |--------------------------------------------------------------------------
    */

    /**
     * The body of `consume()`: replay if the key has been seen, otherwise apply the
     * increment and record the key in the same transaction.
     */
    private function recordConsumption(Tenant $tenant, QuotaKind $kind, string $key, int $units): QuotaConsumption
    {
        $scope = $this->idempotencyScope($tenant, $kind);
        $periodKey = $this->periodKeyFor($tenant, $kind);

        $replay = $this->replayOf($scope, $key, $kind, $periodKey, $units);

        if ($replay !== null) {
            return $replay;
        }

        $ceiling = $this->ceiling($tenant, $kind);
        $limit = $ceiling['refusal'] === null ? $ceiling['limit'] : 0;
        $stamp = $limit ?? QuotaVerdict::UNLIMITED;
        $overage = $ceiling['overage'];

        // Created outside the transaction, so a lost create race is one retryable
        // statement rather than a rolled-back consume.
        $bucket = $this->bucketFor($kind, $periodKey, $stamp);

        try {
            return DB::transaction(function () use ($tenant, $scope, $key, $kind, $periodKey, $units, $limit, $stamp, $overage, $bucket): QuotaConsumption {
                // Re-read the counter inside the transaction: on MySQL this is
                // `SELECT ... FOR UPDATE`, which serializes two workers on the row itself
                // even when the cache lock was unavailable. SQLite ignores the clause
                // (it serializes writers anyway), so this particular layer is only truly
                // exercised in production.
                $counter = $this->lockedBucket($bucket);
                $applied = $this->grantable($counter->used, $limit, $units, $overage);

                $receipt = QuotaConsumption::applied(
                    $kind,
                    $periodKey,
                    $key,
                    $units,
                    $applied,
                    $counter->used + $applied,
                    $limit,
                );

                // The ledger row goes first: `uniq(scope, key)` is what makes this
                // consume-once, so a duplicate must lose the race *before* any counter
                // moves. Both writes are in one transaction, so neither can survive alone.
                $this->recordLedgerEntry($tenant, $scope, $key, $receipt);
                $this->writeBucket($counter, $applied, $stamp);

                return $receipt;
            });
        } catch (QueryException $exception) {
            if (! self::isUniqueViolation($exception)) {
                throw $exception;
            }

            // Another worker consumed this key between our read and our insert (possible
            // whenever the cache lock was unavailable). The transaction rolled back, so
            // nothing was counted here: replay the winner's receipt.
            return $this->replayOf($scope, $key, $kind, $periodKey, $units)
                ?? QuotaConsumption::replayed($kind, $periodKey, $key, $units, 0, $bucket->used, $limit);
        }
    }

    /**
     * The body of `observe()`: set the standing gauge bucket to $count.
     */
    private function recordMeasurement(Tenant $tenant, QuotaKind $kind, int $count): QuotaConsumption
    {
        $periodKey = $this->periodKeyFor($tenant, $kind);
        $ceiling = $this->ceiling($tenant, $kind);
        $limit = $ceiling['refusal'] === null ? $ceiling['limit'] : 0;
        $stamp = $limit ?? QuotaVerdict::UNLIMITED;

        $bucket = $this->bucketFor($kind, $periodKey, $stamp);

        if ($bucket->used !== $count || $bucket->limit !== $stamp) {
            TenantUsage::query()
                ->whereKey($bucket->getKey())
                ->update(['used' => $count, 'limit' => $stamp]);
        }

        return QuotaConsumption::measured($kind, $periodKey, $count, $limit);
    }

    /**
     * An earlier receipt for this key, or null when the key is new.
     */
    private function replayOf(string $scope, string $key, QuotaKind $kind, string $periodKey, int $units): ?QuotaConsumption
    {
        $row = IdempotencyKey::query()->forKey($scope, $key)->first();

        if (! $row instanceof IdempotencyKey) {
            return null;
        }

        if (! $row->isReplayable()) {
            // Unreachable through this class — it only ever writes COMPLETED, inside the
            // same transaction as the increment. If something else ever puts a row in this
            // namespace, the safe reading is "already spent": under-counting one unit is
            // recoverable, double-counting it is the thing Property 4 forbids.
            return QuotaConsumption::replayed($kind, $periodKey, $key, $units, 0, 0, null);
        }

        return QuotaConsumption::fromLedger($kind, $key, $units, is_array($row->result) ? $row->result : []);
    }

    /**
     * The bucket row for one period, created on first use.
     */
    private function bucketFor(QuotaKind $kind, string $periodKey, int $stamp): TenantUsage
    {
        $bucket = TenantUsage::query()->forBucket($kind, $periodKey)->first();

        if ($bucket instanceof TenantUsage) {
            return $bucket;
        }

        try {
            return TenantUsage::query()->create([
                'kind' => $kind,
                'period_key' => $periodKey,
                'used' => 0,
                'limit' => $stamp,
            ]);
        } catch (QueryException $exception) {
            if (! self::isUniqueViolation($exception)) {
                throw $exception;
            }

            // `uniq(tenant_id, kind, period_key)` did its job: another worker created the
            // bucket first, so use theirs.
            $existing = TenantUsage::query()->forBucket($kind, $periodKey)->first();

            if ($existing instanceof TenantUsage) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Re-read a bucket under a row lock (MySQL) so its counter cannot move between the
     * ceiling check and the increment.
     *
     * Falls back to the instance it was handed if the row has vanished — a tenant deleted
     * mid-consume — so a cascade cannot turn accounting into a crash.
     */
    private function lockedBucket(TenantUsage $bucket): TenantUsage
    {
        $locked = TenantUsage::query()->whereKey($bucket->getKey())->lockForUpdate()->first();

        return $locked instanceof TenantUsage ? $locked : $bucket;
    }

    /**
     * Move the counter, and refresh the stamped ceiling in the same statement.
     */
    private function writeBucket(TenantUsage $bucket, int $applied, int $stamp): void
    {
        $extra = $bucket->limit === $stamp ? [] : ['limit' => $stamp];

        if ($applied > 0) {
            // `used = used + n` as SQL, never a read-modify-write: atomic even with no
            // lock held, which is what makes the lock a fast path rather than a
            // correctness requirement.
            TenantUsage::query()->whereKey($bucket->getKey())->increment('used', $applied, $extra);

            return;
        }

        if ($extra !== []) {
            TenantUsage::query()->whereKey($bucket->getKey())->update($extra);
        }
    }

    /**
     * Write the consume-once ledger entry — a `COMPLETED` row whose `result` is the
     * receipt a duplicate caller replays.
     */
    private function recordLedgerEntry(Tenant $tenant, string $scope, string $key, QuotaConsumption $receipt): void
    {
        $result = $receipt->toLedger();

        IdempotencyKey::query()->create([
            'tenant_id' => $tenant->id,
            'scope' => $scope,
            'key' => $key,
            'state' => IdempotencyState::Completed,
            'result' => $result,
            'response_hash' => IdempotencyKey::fingerprint($result),
            'completed_at' => now(),
            // Comfortably longer than the longest bucket (a month), so a retry can never
            // outlive the entry that would recognise it.
            'expires_at' => now()->addDays($this->retentionDays()),
        ]);
    }

    /**
     * How many of $units may be recorded: everything, or only what fits under the ceiling.
     */
    private function grantable(int $used, ?int $limit, int $units, bool $overage): int
    {
        if ($limit === null) {
            return $units;
        }

        // Overage is only meaningful where an allowance exists to be overrun; a zero
        // ceiling records nothing.
        if ($overage && $limit > 0) {
            return $units;
        }

        return max(0, min($units, $limit - $used));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — locking, config, guards
    |--------------------------------------------------------------------------
    */

    /**
     * The per-counter lock the task names: `quota:{tenant}:{kind}`.
     *
     * `null` when there is none to be had — a cache store with no lock support, or a wait
     * that timed out. Neither is fatal: see the class docblock for why recording confirmed
     * usage unlocked is strictly better than losing it, and what the bounded cost is.
     */
    private function lockFor(Tenant $tenant, QuotaKind $kind): ?Lock
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        $seconds = max(1, $this->configInt('lock.seconds', 10));
        $wait = max(0, $this->configInt('lock.wait_seconds', 5));

        // TTL well above the wait window, so a worker that dies mid-consume releases the
        // counter before another worker's wait expires.
        $lock = $store->lock(sprintf('quota:%s:%s', $tenant->id, $kind->value), max($seconds * 2, 10));

        if ($wait === 0) {
            return $lock->get() === true ? $lock : null;
        }

        try {
            $lock->block($wait);
        } catch (LockTimeoutException) {
            return null;
        }

        return $lock;
    }

    private function store(): CacheRepository
    {
        return $this->cache->store($this->configString('lock.store'));
    }

    /**
     * The tenant's timezone, falling back to the platform default.
     *
     * An unusable value falls back rather than throwing: a malformed timezone is a data
     * problem in one row, and it must not be able to stop that tenant's metering — which
     * would either block every send or, worse, let sends through uncounted.
     */
    private function timezoneOf(Tenant $tenant): string
    {
        $configured = config('wa.tenancy.default_timezone');
        $fallback = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'UTC';

        $zone = $tenant->getAttribute('timezone');

        if (! is_string($zone) || trim($zone) === '') {
            return $fallback;
        }

        $zone = trim($zone);

        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return $fallback;
        }

        return $zone;
    }

    private function scopePrefix(): string
    {
        return $this->configString('scope_prefix') ?? self::DEFAULT_SCOPE_PREFIX;
    }

    private function retentionDays(): int
    {
        return max(1, $this->configInt('retention_days', 45));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertUnits(int $units): void
    {
        if ($units < 1) {
            throw new InvalidArgumentException(sprintf('Quota units must be at least 1, got %d.', $units));
        }
    }

    /**
     * Whether a failed write lost a unique index — the expected outcome of two workers
     * racing on the same bucket or the same idempotency key.
     */
    private static function isUniqueViolation(QueryException $exception): bool
    {
        // SQLSTATE 23000 on both MySQL and SQLite; 23505 is PostgreSQL's, cheap to accept.
        if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }

    private function configInt(string $key, int $default): int
    {
        $value = config('wa.tenancy.quota.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function configString(string $key): ?string
    {
        $value = config('wa.tenancy.quota.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
