<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reliability;

use App\Models\OutboxMessage;
use App\Services\Reliability\Outbox;
use App\Services\Reliability\OutboxDelivery;
use App\Services\Reliability\OutboxEnvelope;
use App\Services\Reliability\OutboxRelayReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The input generator for the Correctness Property 16 property test: random outbox
 * workloads, random per-attempt faults, and a dead worker — all replayable from one seed
 * (Req 31.4 / NFR2; Req 25.2 / D2).
 *
 * It generates and **records**; it judges nothing. The oracle lives in
 * `tests/Feature/Reliability/OutboxExactlyOncePropertyTest.php`, where it can be read next
 * to the invariants it licenses, and it is derived from what this class says it drew —
 * never from what the relay did.
 *
 * ## What is drawn
 *
 * - **the workload** — N effects across several tenants and the tenant-less platform (a
 *   legal value on `outbox`, not a missing one), with random `available_at` schedules, so a
 *   pass has to respect the intent clock as well as the retry gate;
 * - **the fault menu**, per attempt: an ack, a crash *after* the ack but before the `SENT`
 *   write (the interleaving exactly-once exists for), a crash *before* the ack, a network
 *   failure, a 5xx, a 429 carrying a `Retry-After`, and a breaker shedding the call;
 * - **a dead worker** (`ghostClaim()`) — one that claims a batch, spends the attempt, takes
 *   the lease, and then dies without delivering anything.
 *
 * ## Why the menu opens with every kind, shuffled
 *
 * `faultFor()` serves a shuffled copy of the whole menu before it starts sampling, for the
 * reason `Tests\Fixtures\AuditChainProbe` enumerates its tamper kinds: the *kind* is the
 * one dimension small enough to cover exhaustively, and a sampled menu would leave roughly
 * one kind in eight unexercised on any given run — while a coverage assertion over sampled
 * kinds is a flake waiting for a slow afternoon. Every other dimension (which row gets
 * which fault, how long a `Retry-After` is, how big a batch is, how far the clock moves) is
 * sampled.
 *
 * `stopFaults()` therefore switches the *sampling* off and lets the opening schedule finish,
 * so "faults are switched off after a random pass" cannot silently cost coverage;
 * `ackEverything()` is the hard stop the drain phase uses once coverage has been asserted.
 *
 * ## Reproducibility
 *
 * Every draw comes from one seeded engine, so a failure replays exactly:
 * `OUTBOX_EXACTLY_ONCE_SEED=<seed> vendor/bin/pest --filter='<test name>'`. The seed is
 * printed in every failure message, together with the pass and the fault that was drawn.
 * Tenant *names* still come from the unseeded factory faker, so a replay is identical in
 * every respect the property depends on and not byte-identical in the ones it does not.
 */
final class OutboxProbe
{
    /**
     * Set this to replay a failed run.
     */
    public const string SEED_ENV = 'OUTBOX_EXACTLY_ONCE_SEED';

    /**
     * The receiver acked and applied the effect; the relay records `SENT`.
     */
    public const string ACK = 'ack';

    /**
     * The receiver acked and applied the effect, and *then* the worker died before the
     * relay could write `SENT` — the crash Property 16 exists for. Delivery is at least
     * once, so the row is redelivered; the effect must still be applied once.
     */
    public const string CRASH_AFTER_ACK = 'crash_after_ack';

    /**
     * The worker died before the receiver applied anything. Nothing was applied, and the
     * row must come back.
     */
    public const string CRASH_BEFORE_ACK = 'crash_before_ack';

    /**
     * The transport could not reach the receiver (`NETWORK`, retryable).
     */
    public const string NETWORK = 'network';

    /**
     * The receiver answered `503` (`NETWORK`, retryable).
     */
    public const string SERVER_ERROR = 'server_error';

    /**
     * The receiver answered `429` with a `Retry-After` (`RATE_LIMIT`, deferred, and the
     * wait is honoured verbatim rather than jittered).
     */
    public const string RATE_LIMITED = 'rate_limited';

    /**
     * A circuit breaker in the transport refused the call, so no attempt was made — the
     * one outcome that must **not** consume the row's attempt budget.
     */
    public const string CIRCUIT_OPEN = 'circuit_open';

    /**
     * Every fault kind, each of which the opening schedule guarantees at least once.
     *
     * @var non-empty-list<string>
     */
    public const array FAULTS = [
        self::ACK,
        self::CRASH_AFTER_ACK,
        self::CRASH_BEFORE_ACK,
        self::NETWORK,
        self::SERVER_ERROR,
        self::RATE_LIMITED,
        self::CIRCUIT_OPEN,
    ];

    /**
     * What sampling draws from once the opening schedule is spent. `ack` twice, so a long
     * run makes progress as well as trouble — a menu that failed six attempts in seven
     * would park most of the queue before the interesting redeliveries happened.
     *
     * @var non-empty-list<string>
     */
    private const array MENU = [
        self::ACK,
        self::ACK,
        self::CRASH_AFTER_ACK,
        self::CRASH_BEFORE_ACK,
        self::NETWORK,
        self::SERVER_ERROR,
        self::RATE_LIMITED,
        self::CIRCUIT_OPEN,
    ];

    /**
     * Longest `Retry-After`/cool-down a fault names, in seconds. Bounded so a drain pass
     * can advance past every gate it could have set, and far inside the dedup horizon —
     * this test is about crash interleavings, and the horizon has its own scripted tests.
     */
    private const int MAX_NAMED_WAIT_SECONDS = 120;

    private readonly Randomizer $rng;

    /**
     * The opening schedule: one of every fault kind, shuffled, served before sampling
     * starts and drained even after `stopFaults()`.
     *
     * @var list<string>
     */
    private array $schedule;

    /**
     * Whether sampling is still on. `false` means "ack unless the opening schedule still
     * has something to say".
     */
    private bool $sampling = true;

    /**
     * Every attempt drawn so far, oldest first.
     *
     * @var list<array{id: int, key: string, attempt: int, fault: string, wait: int}>
     */
    private array $journal = [];

    /**
     * The same, for the pass currently being observed.
     *
     * @var list<array{id: int, key: string, attempt: int, fault: string, wait: int}>
     */
    private array $pass = [];

    /**
     * How often each kind has been drawn, for the coverage assertion.
     *
     * @var array<string, int>
     */
    private array $drawn = [];

    /**
     * Whether a second worker should run *during* the next delivery. Armed by the test,
     * consumed by the transport double, and sticky until it fires — a pass whose rows were
     * all swallowed by a dead worker gives it no window, and the concurrent-claim
     * interleaving must not be lost because of that.
     */
    private bool $concurrentArmed = false;

    /**
     * What the concurrent passes of the pass being observed reported.
     *
     * @var list<OutboxRelayReport>
     */
    private array $concurrentReports = [];

    private int $concurrentPasses = 0;

    private function __construct(public readonly int $seed)
    {
        $this->rng = new Randomizer(new Mt19937($seed));

        /** @var list<string> $schedule */
        $schedule = $this->rng->shuffleArray(self::FAULTS);

        $this->schedule = $schedule;
    }

    /**
     * A fresh seed, or the one named by `OUTBOX_EXACTLY_ONCE_SEED` for a replay.
     */
    public static function seeded(): self
    {
        $configured = getenv(self::SEED_ENV);

        return new self(
            is_string($configured) && $configured !== '' && ctype_digit($configured)
                ? (int) $configured
                : random_int(1, PHP_INT_MAX),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Draws
    |--------------------------------------------------------------------------
    */

    public function int(int $min, int $max): int
    {
        return $max <= $min ? $min : $this->rng->getInt($min, $max);
    }

    public function chance(int $percent): bool
    {
        return $this->rng->getInt(1, 100) <= $percent;
    }

    /**
     * @template TValue
     *
     * @param  non-empty-list<TValue>  $items
     * @return TValue
     */
    public function pick(array $items): mixed
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    /*
    |--------------------------------------------------------------------------
    | The workload
    |--------------------------------------------------------------------------
    */

    /**
     * Enqueue `$count` effects and hand back what was enqueued, in insertion order.
     *
     * The first two are available immediately, by construction rather than by draw, so the
     * first pass — and the dead worker that claims just before it — always has something to
     * hold. The rest are scheduled at random, which is what makes the relay prove it honours
     * `available_at` (the immutable intent) and not only `next_attempt_at` (the retry gate).
     *
     * @param  non-empty-list<string|null>  $tenantIds  tenants to spread the effects over; `null` is a
     *                                                  platform-level effect, which this table allows
     * @return list<array{id: int, key: string, tenant: string|null}>
     */
    public function workload(Outbox $outbox, int $count, array $tenantIds): array
    {
        $enqueued = [];

        for ($n = 1; $n <= $count; $n++) {
            $key = sprintf('outbox.prop:%d:%d', $this->seed, $n);
            $tenant = $this->pick($tenantIds);
            $delay = $n <= 2 ? 0 : $this->pick([0, 0, 0, 5, 30, 90]);

            $row = $outbox->record(
                OutboxEnvelope::for(
                    'order',
                    'ord-'.$n,
                    $this->pick(['order.paid', 'order.refunded', 'gateway.acked']),
                    ['n' => $n, 'nonce' => $this->int(1, 1_000_000)],
                    $key,
                )
                    ->to('https://receiver.test/hooks/'.$n)
                    ->forTenant($tenant)
                    ->delayedBy($delay),
            );

            $enqueued[] = ['id' => (int) $row->getKey(), 'key' => $key, 'tenant' => $tenant];
        }

        return $enqueued;
    }

    /*
    |--------------------------------------------------------------------------
    | Faults
    |--------------------------------------------------------------------------
    */

    /**
     * The fault this attempt suffers, recorded so the oracle can say what must follow.
     *
     * `wait` is the seconds a `429` or a shedding breaker names; it is drawn for every
     * attempt so the value is in the journal whether or not the drawn fault uses it.
     *
     * @return array{fault: string, wait: int}
     */
    public function faultFor(OutboxDelivery $delivery): array
    {
        $fault = $this->nextFault();
        $wait = $this->int(1, self::MAX_NAMED_WAIT_SECONDS);

        $entry = [
            'id' => $delivery->id,
            'key' => $delivery->dedupKey,
            'attempt' => $delivery->attempt,
            'fault' => $fault,
            'wait' => $wait,
        ];

        $this->journal[] = $entry;
        $this->pass[] = $entry;
        $this->drawn[$fault] = ($this->drawn[$fault] ?? 0) + 1;

        return ['fault' => $fault, 'wait' => $wait];
    }

    /**
     * Stop sampling faults — the "faults switched off after a random pass" switch.
     *
     * The opening schedule is deliberately *not* discarded: coverage of the menu must not
     * depend on which pass the draw happened to switch faults off at.
     */
    public function stopFaults(): void
    {
        $this->sampling = false;
    }

    /**
     * The hard stop: from here the receiver acks everything, whatever is left in the
     * schedule. For the drain phase, once the menu's coverage has been asserted.
     */
    public function ackEverything(): void
    {
        $this->sampling = false;
        $this->schedule = [];
    }

    /**
     * How often `$fault` has been drawn.
     */
    public function drew(string $fault): int
    {
        return $this->drawn[$fault] ?? 0;
    }

    /**
     * Start observing a pass — clears the per-pass journal, not the run's.
     */
    public function openPass(): void
    {
        $this->pass = [];
        $this->concurrentReports = [];
    }

    /*
    |--------------------------------------------------------------------------
    | A second worker, mid-delivery
    |--------------------------------------------------------------------------
    */

    /**
     * Ask for a concurrent relay pass to run from inside the next delivery — the window in
     * which a second worker would claim, and where only the *lease* keeps it off the row
     * already in flight (`FOR UPDATE SKIP LOCKED` has been committed away by then, and on
     * SQLite it never existed).
     */
    public function armConcurrentWorker(): void
    {
        $this->concurrentArmed = true;
    }

    /**
     * Whether this delivery is the one that should have a concurrent pass run inside it.
     * Answers `true` at most once per arming.
     */
    public function takeConcurrentWorker(): bool
    {
        $armed = $this->concurrentArmed;
        $this->concurrentArmed = false;

        return $armed;
    }

    public function recordConcurrentPass(OutboxRelayReport $report): void
    {
        $this->concurrentReports[] = $report;
        $this->concurrentPasses++;
    }

    /**
     * What the concurrent passes inside the pass being observed claimed.
     *
     * @return list<OutboxRelayReport>
     */
    public function passConcurrentReports(): array
    {
        return $this->concurrentReports;
    }

    /**
     * How many concurrent passes have run in the whole run.
     */
    public function concurrentPasses(): int
    {
        return $this->concurrentPasses;
    }

    /**
     * The attempts made since `openPass()`, oldest first.
     *
     * @return list<array{id: int, key: string, attempt: int, fault: string, wait: int}>
     */
    public function passJournal(): array
    {
        return $this->pass;
    }

    /**
     * Every attempt of the whole run, oldest first.
     *
     * @return list<array{id: int, key: string, attempt: int, fault: string, wait: int}>
     */
    public function journal(): array
    {
        return $this->journal;
    }

    /**
     * The dedup keys whose effect the receiver has ever acked — i.e. exactly the keys whose
     * `applied()` count must be 1 rather than 0.
     *
     * @return array<string, true>
     */
    public function ackedKeys(): array
    {
        $acked = [];

        foreach ($this->journal as $entry) {
            if ($entry['fault'] === self::ACK || $entry['fault'] === self::CRASH_AFTER_ACK) {
                $acked[$entry['key']] = true;
            }
        }

        return $acked;
    }

    /*
    |--------------------------------------------------------------------------
    | A worker that dies mid-batch
    |--------------------------------------------------------------------------
    */

    /**
     * Claim a batch the way a relay worker does — and then die without delivering it.
     *
     * This is the one interleaving no transport double can produce, because the relay
     * catches everything a transport throws: a worker that is killed *between* the claim
     * and the delivery. So it is staged directly, with the model's own claim predicate
     * (`scopeClaimable()` plus the intent clock and the parking predicate) and the relay's
     * own lease statement — `attempts` counted **before** the attempt is made, and
     * `next_attempt_at` pushed out by the lease.
     *
     * What the test then asserts is the whole point of that ordering: the rows are held out
     * of the next pass by the lease (the exclusivity mechanism that works on SQLite too),
     * their budget is spent even though nothing was delivered, and they come back when the
     * lease expires — never lost, and never retried for ever.
     *
     * @return list<int> the ids the dead worker was holding
     */
    public function ghostClaim(int $batch, int $leaseSeconds, int $maxAttempts): array
    {
        /** @var list<int> $ids */
        $ids = DB::transaction(function () use ($batch, $leaseSeconds, $maxAttempts): array {
            $rows = OutboxMessage::query()
                ->claimable()
                ->where('available_at', '<=', Carbon::now())
                ->where('attempts', '<', $maxAttempts)
                ->lockForClaim()
                ->limit(max(1, $batch))
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            OutboxMessage::query()
                ->whereKey($rows->modelKeys())
                ->increment('attempts', 1, ['next_attempt_at' => Carbon::now()->addSeconds($leaseSeconds)]);

            return array_map(
                static fn (OutboxMessage $row): int => (int) $row->getKey(),
                array_values($rows->all()),
            );
        });

        return $ids;
    }

    /**
     * The next fault: the opening schedule first, then the sampled menu, then acks.
     */
    private function nextFault(): string
    {
        if ($this->schedule !== []) {
            return (string) array_shift($this->schedule);
        }

        return $this->sampling ? $this->pick(self::MENU) : self::ACK;
    }
}
