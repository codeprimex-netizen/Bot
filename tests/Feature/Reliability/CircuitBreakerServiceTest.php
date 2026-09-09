<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Exceptions\Reliability\CircuitOpenException;
use App\Models\CircuitBreaker as CircuitBreakerRecord;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\CircuitBreakerCache;
use App\Services\Reliability\CircuitBreakerKey;
use Tests\Fixtures\Breakers;

/*
|--------------------------------------------------------------------------
| CircuitBreaker::call/state/trip — Algorithm 7 (Req 31.3 / NFR2; Req 13.13 / B4)
|--------------------------------------------------------------------------
| The behavioural half of the breaker. Correctness Property 13 has its own property test
| (`CircuitBreakerSafetyPropertyTest`); this file pins the individual clauses of
| Algorithm 7 — the two trip arms, window rotation, the cool-down, the probe budget
| (including across workers), half-open resolution, per-family config, and the snapshot
| cache.
|
| Thresholds are set per test rather than in a `beforeEach`, so each one states the
| numbers it depends on and the tests that assert the *shipped* table are not quietly
| reading somebody else's overrides.
*/

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('runs the operation and returns its value while closed', function (): void {
    $breaker = Breakers::service();
    $ran = 0;

    $result = $breaker->call(CircuitScope::Provider, 'openai', function () use (&$ran): string {
        $ran++;

        return 'hello';
    });

    $row = Breakers::requireRow(CircuitScope::Provider, 'openai');

    expect($result)->toBe('hello')
        ->and($ran)->toBe(1)
        ->and($row->state)->toBe(CircuitState::Closed)
        ->and($row->success_count)->toBe(1)
        ->and($row->failure_count)->toBe(0)
        ->and($row->last_success_at)->not->toBeNull()
        ->and($row->window_started_at)->not->toBeNull();
});

it('creates the row on first use and reports a never-exercised breaker as closed', function (): void {
    $breaker = Breakers::service();

    expect($breaker->state(CircuitScope::Provider, 'unused'))->toBe(CircuitState::Closed)
        ->and($breaker->allows(CircuitScope::Provider, 'unused'))->toBeTrue()
        // Asking must not populate the table with breakers nobody has called.
        ->and(Breakers::row(CircuitScope::Provider, 'unused'))->toBeNull();

    Breakers::succeed($breaker, CircuitScope::Provider, 'unused');

    expect(Breakers::row(CircuitScope::Provider, 'unused'))->not->toBeNull();
});

it('rethrows the operation\'s own failure and records it', function (): void {
    $breaker = Breakers::service();
    $invocations = ['count' => 0];

    $thrown = Breakers::attempt($breaker, CircuitScope::Provider, 'openai', succeeds: false, invocations: $invocations);

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        // The distinction the LLM fallback chain branches on: the operation *ran* and
        // threw, so this is deliberately not a CircuitOpenException.
        ->and($thrown)->not->toBeInstanceOf(CircuitOpenException::class)
        ->and($thrown?->getMessage())->toBe(Breakers::FAILURE_MESSAGE)
        ->and($invocations['count'])->toBe(1)
        ->and(Breakers::requireRow(CircuitScope::Provider, 'openai')->failure_count)->toBe(1)
        ->and(Breakers::requireRow(CircuitScope::Provider, 'openai')->state)->toBe(CircuitState::Closed);
});

/*
|--------------------------------------------------------------------------
| Opening: the two arms, independently
|--------------------------------------------------------------------------
*/

it('opens on the failure-count arm alone, with the rate arm disabled', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'count-arm', times: 2);
    expect(Breakers::requireRow(CircuitScope::Provider, 'count-arm')->state)->toBe(CircuitState::Closed);

    Breakers::fail($breaker, CircuitScope::Provider, 'count-arm');

    $row = Breakers::requireRow(CircuitScope::Provider, 'count-arm');

    expect($row->state)->toBe(CircuitState::Open)
        ->and($row->failure_count)->toBe(3)
        ->and($row->opened_at)->not->toBeNull();
});

it('counts non-consecutive failures towards the count arm', function (): void {
    // Req 13.13 says "at least 5 failures within a 30-second window", not five in a row: a
    // success between failures must not wipe the window's evidence.
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'interleaved');
    Breakers::succeed($breaker, CircuitScope::Provider, 'interleaved');
    Breakers::fail($breaker, CircuitScope::Provider, 'interleaved');
    Breakers::succeed($breaker, CircuitScope::Provider, 'interleaved');

    expect(Breakers::requireRow(CircuitScope::Provider, 'interleaved')->state)->toBe(CircuitState::Closed);

    Breakers::fail($breaker, CircuitScope::Provider, 'interleaved');

    expect(Breakers::requireRow(CircuitScope::Provider, 'interleaved')->state)->toBe(CircuitState::Open);
});

it('opens on the error-rate arm alone, with the count arm out of reach', function (): void {
    // A failure threshold far above anything this test produces, so the *only* thing that
    // can open the breaker is the rate arm.
    Breakers::configure([
        'failure_threshold' => 50,
        'error_rate' => 0.5,
        'error_rate_sample' => 4,
    ]);
    $breaker = Breakers::service();

    // 2 of 4 failed = 50%, which is not *above* 50%.
    Breakers::fail($breaker, CircuitScope::Provider, 'rate-arm', times: 2);
    Breakers::succeed($breaker, CircuitScope::Provider, 'rate-arm', times: 2);

    $row = Breakers::requireRow(CircuitScope::Provider, 'rate-arm');

    expect($row->windowCalls())->toBe(4)
        ->and($row->errorRate())->toBe(0.5)
        ->and($row->state)->toBe(CircuitState::Closed);

    // 3 of 5 failed = 60%.
    Breakers::fail($breaker, CircuitScope::Provider, 'rate-arm');

    expect(Breakers::requireRow(CircuitScope::Provider, 'rate-arm')->state)->toBe(CircuitState::Open);
});

it('never opens a barely-used breaker on the error-rate arm', function (): void {
    // A single failed call is a 100% error rate. Opening on that would fence off a
    // dependency nobody has shown to be broken, so the sample floor has to hold.
    Breakers::configure([
        'failure_threshold' => 50,
        'error_rate' => 0.5,
        'error_rate_sample' => 20,
    ]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'barely-used', times: 19);

    $row = Breakers::requireRow(CircuitScope::Provider, 'barely-used');

    expect($row->errorRate())->toBe(1.0)
        ->and($row->windowCalls())->toBe(19)
        ->and($row->state)->toBe(CircuitState::Closed)
        ->and($breaker->allows(CircuitScope::Provider, 'barely-used'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The window rolls
|--------------------------------------------------------------------------
*/

it('rotates the failure window instead of accumulating for ever', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'window_seconds' => 10]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'rolling', times: 2);
    expect(Breakers::requireRow(CircuitScope::Provider, 'rolling')->failure_count)->toBe(2);

    thisTest()->travel(11)->seconds();

    // The window aged out, so this failure starts a fresh one rather than being the third
    // — and it is *kept*, which is Algorithm 7's loop invariant (rotation loses nothing).
    Breakers::fail($breaker, CircuitScope::Provider, 'rolling');

    $row = Breakers::requireRow(CircuitScope::Provider, 'rolling');

    expect($row->failure_count)->toBe(1)
        ->and($row->state)->toBe(CircuitState::Closed);

    // …and two more inside the *new* window do trip it, so rotation resets rather than
    // disarms.
    Breakers::fail($breaker, CircuitScope::Provider, 'rolling', times: 2);

    expect(Breakers::requireRow(CircuitScope::Provider, 'rolling')->state)->toBe(CircuitState::Open);
});

it('rotates the success side of the window too', function (): void {
    // Otherwise the rate arm would compare fresh failures against stale successes.
    Breakers::configure(['failure_threshold' => 50, 'error_rate' => 0.5, 'error_rate_sample' => 4, 'window_seconds' => 10]);
    $breaker = Breakers::service();

    Breakers::succeed($breaker, CircuitScope::Provider, 'rolling-success', times: 6);
    thisTest()->travel(11)->seconds();
    Breakers::fail($breaker, CircuitScope::Provider, 'rolling-success', times: 3);

    $row = Breakers::requireRow(CircuitScope::Provider, 'rolling-success');

    expect($row->success_count)->toBe(0)
        ->and($row->failure_count)->toBe(3)
        // 3 in-window calls is below the sample floor of 4, so no arm has fired yet.
        ->and($row->state)->toBe(CircuitState::Closed);

    Breakers::fail($breaker, CircuitScope::Provider, 'rolling-success');

    expect(Breakers::requireRow(CircuitScope::Provider, 'rolling-success')->state)->toBe(CircuitState::Open);
});

/*
|--------------------------------------------------------------------------
| While OPEN: fail fast, do not invoke
|--------------------------------------------------------------------------
*/

it('never invokes the operation while open and fails fast with Retry-After', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'open_seconds' => 30]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'openai', times: 3);
    expect(Breakers::requireRow(CircuitScope::Provider, 'openai')->state)->toBe(CircuitState::Open);

    $invocations = ['count' => 0];
    $thrown = Breakers::attempt($breaker, CircuitScope::Provider, 'openai', succeeds: true, invocations: $invocations);

    expect($invocations['count'])->toBe(0)
        ->and($thrown)->toBeInstanceOf(CircuitOpenException::class);

    /** @var CircuitOpenException $thrown */
    expect($thrown->observedState)->toBe(CircuitState::Open)
        ->and($thrown->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($thrown->retryAfterSeconds)->toBeLessThanOrEqual(30)
        ->and($thrown->getStatusCode())->toBe(503)
        ->and($thrown->getHeaders())->toHaveKey('Retry-After')
        ->and($thrown->errorCode())->toBe('circuit_open')
        ->and($thrown->wasProbeBudgetExhausted())->toBeFalse()
        // The public body names nothing: no provider, no key, no tenant.
        ->and($thrown->publicMessage())->toBe(CircuitOpenException::PUBLIC_MESSAGE)
        ->and($breaker->allows(CircuitScope::Provider, 'openai'))->toBeFalse()
        ->and($breaker->state(CircuitScope::Provider, 'openai'))->toBe(CircuitState::Open);
});

it('does not count a fail-fast refusal as a failure of the dependency', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'openai', times: 3);
    $atTrip = Breakers::requireRow(CircuitScope::Provider, 'openai')->failure_count;

    Breakers::fail($breaker, CircuitScope::Provider, 'openai', times: 5);

    expect(Breakers::requireRow(CircuitScope::Provider, 'openai')->failure_count)->toBe($atTrip);
});

it('keeps a tenant id out of the message it logs', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 1]);
    $breaker = Breakers::service();
    $tenantId = '01JABCDEFGHJKMNPQRSTVWXYZ';
    $name = CircuitBreakerRecord::compositeName('openai', $tenantId);

    Breakers::fail($breaker, CircuitScope::Provider, $name);

    $invocations = ['count' => 0];
    $thrown = Breakers::attempt($breaker, CircuitScope::Provider, $name, succeeds: true, invocations: $invocations);

    expect($thrown?->getMessage())->toContain('openai')
        ->and($thrown?->getMessage())->not->toContain($tenantId)
        // Fingerprinted, so two lines about the same tenant's breaker still correlate.
        ->and($thrown?->getMessage())->toContain('#')
        // …while the caller can still key its own fallback state off the real name.
        ->and($thrown)->toBeInstanceOf(CircuitOpenException::class);

    /** @var CircuitOpenException $thrown */
    expect($thrown->breakerName())->toBe($name);
});

/*
|--------------------------------------------------------------------------
| OPEN -> HALF_OPEN, and the probe budget
|--------------------------------------------------------------------------
*/

it('starts probing only once the open duration has elapsed', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'open_seconds' => 30]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'openai', times: 3);

    thisTest()->travel(29)->seconds();
    expect($breaker->state(CircuitScope::Provider, 'openai'))->toBe(CircuitState::Open)
        ->and(Breakers::succeed($breaker, CircuitScope::Provider, 'openai'))->toBe(0);

    thisTest()->travel(2)->seconds();

    // `state()` reports the state the next call will find, without writing it.
    expect($breaker->state(CircuitScope::Provider, 'openai'))->toBe(CircuitState::HalfOpen)
        ->and(Breakers::requireRow(CircuitScope::Provider, 'openai')->state)->toBe(CircuitState::Open)
        ->and($breaker->allows(CircuitScope::Provider, 'openai'))->toBeTrue();
});

it('admits at most the configured probes, across workers', function (): void {
    // The failure mode the conditional UPDATE exists to prevent: several processes each
    // admitting a full budget, so a recovering dependency is hit by N × probes calls.
    // Concurrency is modelled by nesting — each attempt happens while the previous
    // worker's probe is still in flight, which is exactly when a per-process budget would
    // over-admit.
    Breakers::configure([
        'error_rate' => null,
        'failure_threshold' => 3,
        'open_seconds' => 30,
        'probes' => 2,
        'probe_successes' => 2,
    ]);

    $one = Breakers::service();
    $two = Breakers::worker();
    $three = Breakers::worker();

    Breakers::fail($one, CircuitScope::Provider, 'probed', times: 3);
    thisTest()->travel(31)->seconds();

    $invocations = ['count' => 0];
    $refused = null;

    $one->call(CircuitScope::Provider, 'probed', function () use ($two, $three, &$invocations, &$refused): string {
        $invocations['count']++;

        $two->call(CircuitScope::Provider, 'probed', function () use ($three, &$invocations, &$refused): string {
            $invocations['count']++;

            // Both probes are claimed and neither has resolved: the third worker must be
            // refused without its operation running.
            $refused = Breakers::attempt(
                $three,
                CircuitScope::Provider,
                'probed',
                succeeds: true,
                invocations: $invocations,
            );

            return 'ok';
        });

        return 'ok';
    });

    expect($invocations['count'])->toBe(2)
        ->and($refused)->toBeInstanceOf(CircuitOpenException::class);

    /** @var CircuitOpenException $refused */
    expect($refused->observedState)->toBe(CircuitState::HalfOpen)
        ->and($refused->wasProbeBudgetExhausted())->toBeTrue()
        // No cool-down to point at: somebody else's probe is in flight.
        ->and($refused->retryAfterSeconds)->toBeNull()
        // Both probes came back healthy, so the breaker is closed.
        ->and(Breakers::requireRow(CircuitScope::Provider, 'probed')->state)->toBe(CircuitState::Closed);
});

it('does not let two workers each open the breaker on their own cool-down', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 1, 'open_seconds' => 30]);

    $one = Breakers::service();
    $two = Breakers::worker();

    Breakers::fail($one, CircuitScope::Provider, 'race');
    $firstOpenedAt = Breakers::requireRow(CircuitScope::Provider, 'race')->opened_at?->toDateTimeString();

    thisTest()->travel(5)->seconds();

    // A second worker's failure while already open must not restart the cool-down, or a
    // probe would never be reached under sustained traffic.
    Breakers::fail($two, CircuitScope::Provider, 'race');

    expect(Breakers::requireRow(CircuitScope::Provider, 'race')->opened_at?->toDateTimeString())
        ->toBe($firstOpenedAt);
});

/*
|--------------------------------------------------------------------------
| Half-open resolution
|--------------------------------------------------------------------------
*/

it('closes on a successful probe, with a clean window', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'open_seconds' => 30, 'probe_successes' => 1]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'openai', times: 3);
    thisTest()->travel(31)->seconds();

    expect(Breakers::succeed($breaker, CircuitScope::Provider, 'openai'))->toBe(1);

    $row = Breakers::requireRow(CircuitScope::Provider, 'openai');

    expect($row->state)->toBe(CircuitState::Closed)
        ->and($row->failure_count)->toBe(0)
        ->and($row->success_count)->toBe(0)
        ->and($row->half_open_probes)->toBe(0)
        ->and($row->half_open_successes)->toBe(0)
        ->and($row->opened_at)->toBeNull()
        ->and($row->window_started_at)->not->toBeNull()
        ->and($row->isHealthy())->toBeTrue();
});

it('needs every configured probe success before it closes', function (): void {
    Breakers::configure([
        'error_rate' => null,
        'failure_threshold' => 3,
        'open_seconds' => 30,
        'probes' => 3,
        'probe_successes' => 2,
    ]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'cautious', times: 3);
    thisTest()->travel(31)->seconds();

    Breakers::succeed($breaker, CircuitScope::Provider, 'cautious');

    $probing = Breakers::requireRow(CircuitScope::Provider, 'cautious');

    expect($probing->state)->toBe(CircuitState::HalfOpen)
        ->and($probing->half_open_successes)->toBe(1)
        ->and($probing->half_open_probes)->toBe(1);

    Breakers::succeed($breaker, CircuitScope::Provider, 'cautious');

    expect(Breakers::requireRow(CircuitScope::Provider, 'cautious')->state)->toBe(CircuitState::Closed);
});

it('re-opens on a failed probe and starts a fresh cool-down', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'open_seconds' => 30]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'openai', times: 3);
    thisTest()->travel(31)->seconds();

    // One failed probe is enough — recovery is disproven, whatever the counters say.
    expect(Breakers::fail($breaker, CircuitScope::Provider, 'openai'))->toBe(1);

    $row = Breakers::requireRow(CircuitScope::Provider, 'openai');

    expect($row->state)->toBe(CircuitState::Open)
        ->and($row->opened_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        // The probe budget belongs to one half-open window and is reset with the transition.
        ->and($row->half_open_probes)->toBe(0)
        ->and($row->half_open_successes)->toBe(0)
        ->and(Breakers::succeed($breaker, CircuitScope::Provider, 'openai'))->toBe(0);
});

it('does not let a straggling success close a breaker another worker re-opened', function (): void {
    // `OPEN -> CLOSED` is not a legal edge: recovery must be proven by a probe, never by a
    // success that was already in flight when somebody else's probe failed.
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'open_seconds' => 30, 'probes' => 2]);

    $one = Breakers::service();
    $two = Breakers::worker();

    Breakers::fail($one, CircuitScope::Provider, 'straggler', times: 3);
    thisTest()->travel(31)->seconds();

    $slow = null;
    $invocations = ['count' => 0];

    $result = $one->call(CircuitScope::Provider, 'straggler', function () use ($two, &$slow, &$invocations): string {
        $invocations['count']++;
        $slow = Breakers::attempt($two, CircuitScope::Provider, 'straggler', succeeds: false, invocations: $invocations);

        return 'ok';
    });

    $row = Breakers::requireRow(CircuitScope::Provider, 'straggler');

    expect($result)->toBe('ok')
        ->and($slow)->toBeInstanceOf(RuntimeException::class)
        ->and($invocations['count'])->toBe(2)
        ->and($row->state)->toBe(CircuitState::Open);
});

/*
|--------------------------------------------------------------------------
| Manual control
|--------------------------------------------------------------------------
*/

it('trips a breaker manually and lets it recover on its own cool-down', function (): void {
    Breakers::configure(['open_seconds' => 30, 'probe_successes' => 1]);
    $breaker = Breakers::service();

    $breaker->trip(CircuitScope::Provider, 'kill-switch');

    $row = Breakers::requireRow(CircuitScope::Provider, 'kill-switch');

    expect($row->state)->toBe(CircuitState::Open)
        ->and($row->opened_at)->not->toBeNull()
        ->and($breaker->allows(CircuitScope::Provider, 'kill-switch'))->toBeFalse()
        ->and(Breakers::succeed($breaker, CircuitScope::Provider, 'kill-switch'))->toBe(0);

    // No "held open for ever" state to forget to undo: the cool-down applies as usual.
    thisTest()->travel(31)->seconds();

    expect($breaker->state(CircuitScope::Provider, 'kill-switch'))->toBe(CircuitState::HalfOpen)
        ->and(Breakers::succeed($breaker, CircuitScope::Provider, 'kill-switch'))->toBe(1)
        ->and(Breakers::requireRow(CircuitScope::Provider, 'kill-switch')->state)->toBe(CircuitState::Closed);
});

it('tripping an already-open breaker is a no-op on its cool-down', function (): void {
    Breakers::configure(['open_seconds' => 30]);
    $breaker = Breakers::service();

    $breaker->trip(CircuitScope::Provider, 'twice');
    $openedAt = Breakers::requireRow(CircuitScope::Provider, 'twice')->opened_at?->toDateTimeString();

    thisTest()->travel(10)->seconds();
    $breaker->trip(CircuitScope::Provider, 'twice');

    expect(Breakers::requireRow(CircuitScope::Provider, 'twice')->opened_at?->toDateTimeString())->toBe($openedAt);
});

it('resets a breaker to closed with zeroed counters', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'cleared', times: 3);
    expect(Breakers::requireRow(CircuitScope::Provider, 'cleared')->state)->toBe(CircuitState::Open);

    $breaker->reset(CircuitScope::Provider, 'cleared');

    $row = Breakers::requireRow(CircuitScope::Provider, 'cleared');

    expect($row->state)->toBe(CircuitState::Closed)
        ->and($row->failure_count)->toBe(0)
        ->and($row->success_count)->toBe(0)
        ->and($row->half_open_probes)->toBe(0)
        ->and($row->opened_at)->toBeNull()
        ->and(Breakers::succeed($breaker, CircuitScope::Provider, 'cleared'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Scoping and per-family config
|--------------------------------------------------------------------------
*/

it('keeps one tenant\'s provider breaker out of another\'s', function (): void {
    // The design's per-provider *and* per-tenant scoping (§1.5): one tenant's abuse must
    // not fence a shared provider off from everybody else.
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3]);
    $breaker = Breakers::service();

    $noisy = CircuitBreakerRecord::compositeName('openai', 'tenant-noisy');
    $quiet = CircuitBreakerRecord::compositeName('openai', 'tenant-quiet');

    Breakers::fail($breaker, CircuitScope::Provider, $noisy, times: 3);

    expect($breaker->state(CircuitScope::Provider, $noisy))->toBe(CircuitState::Open)
        ->and($breaker->state(CircuitScope::Provider, $quiet))->toBe(CircuitState::Closed)
        ->and($breaker->allows(CircuitScope::Provider, $quiet))->toBeTrue()
        ->and(Breakers::succeed($breaker, CircuitScope::Provider, $quiet))->toBe(1);
});

it('applies the shipped per-family thresholds from the design\'s table', function (): void {
    // No `configure()` here on purpose: this asserts the numbers the platform ships with.
    // Bridge = 3 fails / 30s, open 15s. Gateway = 5 fails / 60s, open 60s.
    $bridge = Breakers::service();
    $gateway = Breakers::worker();

    Breakers::fail($bridge, CircuitScope::Bridge, 'session-1', times: 3);
    Breakers::fail($gateway, CircuitScope::Gateway, 'razorpay', times: 3);

    expect(Breakers::requireRow(CircuitScope::Bridge, 'session-1')->state)->toBe(CircuitState::Open)
        ->and(Breakers::requireRow(CircuitScope::Gateway, 'razorpay')->state)->toBe(CircuitState::Closed);

    thisTest()->travel(16)->seconds();

    // The bridge's 15s cool-down has elapsed; the gateway needs two more failures.
    expect($bridge->state(CircuitScope::Bridge, 'session-1'))->toBe(CircuitState::HalfOpen);

    Breakers::fail($gateway, CircuitScope::Gateway, 'razorpay', times: 2);
    expect(Breakers::requireRow(CircuitScope::Gateway, 'razorpay')->state)->toBe(CircuitState::Open);

    // …and cools down for 60s, so another 16 seconds are nowhere near enough.
    thisTest()->travel(16)->seconds();
    expect($gateway->state(CircuitScope::Gateway, 'razorpay'))->toBe(CircuitState::Open);
});

it('lets a family override fall through to the platform default for knobs it does not set', function (): void {
    // `CircuitScope::Tenant` ships with no override, so the resolution order is visible:
    // family override for `failure_threshold`, platform default for `open_seconds`.
    Breakers::configure(
        defaults: ['failure_threshold' => 4, 'open_seconds' => 25, 'error_rate' => null],
        scopes: [CircuitScope::Tenant->value => ['failure_threshold' => 2]],
    );

    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Tenant, 'override', times: 2);
    expect(Breakers::requireRow(CircuitScope::Tenant, 'override')->state)->toBe(CircuitState::Open);

    thisTest()->travel(24)->seconds();
    expect($breaker->state(CircuitScope::Tenant, 'override'))->toBe(CircuitState::Open);

    thisTest()->travel(2)->seconds();
    expect($breaker->state(CircuitScope::Tenant, 'override'))->toBe(CircuitState::HalfOpen);
});

it('rejects a scope it does not know and a name it cannot store', function (): void {
    $breaker = Breakers::service();

    expect(fn () => $breaker->state('not-a-family', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $breaker->state(CircuitScope::Provider, '  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $breaker->state(CircuitScope::Provider, str_repeat('a', 192)))->toThrow(InvalidArgumentException::class)
        // A raw scope value is accepted, as design.md's signature has it.
        ->and($breaker->state('provider', 'raw-scope'))->toBe(CircuitState::Closed);
});

/*
|--------------------------------------------------------------------------
| The snapshot cache
|--------------------------------------------------------------------------
*/

it('serves the fail-fast path from the cache without querying', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3]);
    $breaker = Breakers::service();

    Breakers::fail($breaker, CircuitScope::Provider, 'shed', times: 3);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $invocations = ['count' => 0];
    $thrown = Breakers::attempt($breaker, CircuitScope::Provider, 'shed', succeeds: true, invocations: $invocations);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($thrown)->toBeInstanceOf(CircuitOpenException::class)
        ->and($invocations['count'])->toBe(0)
        // The case the cache exists for: a fleet hammering a dead dependency costs the
        // database nothing.
        ->and($queries)->toBeEmpty();
});

it('leaves no stale state behind after a transition', function (): void {
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'open_seconds' => 30, 'probe_successes' => 1]);
    $breaker = Breakers::service();
    $cache = app(CircuitBreakerCache::class);
    $key = CircuitBreakerKey::for(CircuitScope::Provider, 'coherent');

    Breakers::fail($breaker, CircuitScope::Provider, 'coherent', times: 3);

    expect($cache->get($key)?->state)->toBe(CircuitState::Open);

    thisTest()->travel(31)->seconds();
    Breakers::succeed($breaker, CircuitScope::Provider, 'coherent');

    // The snapshot tracks the row through every edge, so a reader never sees a state the
    // row has left.
    expect($cache->get($key)?->state)->toBe(CircuitState::Closed)
        ->and($breaker->state(CircuitScope::Provider, 'coherent'))->toBe(CircuitState::Closed)
        ->and($breaker->allows(CircuitScope::Provider, 'coherent'))->toBeTrue();

    $breaker->trip(CircuitScope::Provider, 'coherent');

    expect($cache->get($key)?->state)->toBe(CircuitState::Open)
        ->and($breaker->allows(CircuitScope::Provider, 'coherent'))->toBeFalse();
});

it('behaves identically with the cache switched off', function (): void {
    Breakers::disableCache();
    Breakers::configure([
        'error_rate' => null,
        'failure_threshold' => 3,
        'open_seconds' => 30,
        'probe_successes' => 1,
    ]);
    $breaker = Breakers::service();

    expect(app(CircuitBreakerCache::class)->isEnabled())->toBeFalse();

    Breakers::fail($breaker, CircuitScope::Provider, 'uncached', times: 3);

    expect($breaker->state(CircuitScope::Provider, 'uncached'))->toBe(CircuitState::Open)
        ->and(Breakers::succeed($breaker, CircuitScope::Provider, 'uncached'))->toBe(0);

    thisTest()->travel(31)->seconds();

    expect(Breakers::succeed($breaker, CircuitScope::Provider, 'uncached'))->toBe(1)
        ->and($breaker->state(CircuitScope::Provider, 'uncached'))->toBe(CircuitState::Closed);
});

it('ignores a snapshot that says closed when the row says open', function (): void {
    // The cache is only ever allowed to make the breaker *more* conservative. An
    // optimistic snapshot — stale after a direct SQL write, or left by an older deploy —
    // must not authorise a call, because admission is settled against the row.
    Breakers::configure(['error_rate' => null, 'failure_threshold' => 3, 'open_seconds' => 30]);
    $breaker = Breakers::service();

    Breakers::succeed($breaker, CircuitScope::Provider, 'stale');

    CircuitBreakerRecord::query()->forKey(CircuitScope::Provider, 'stale')->update([
        'state' => CircuitState::Open,
        'opened_at' => now(),
    ]);

    expect(app(CircuitBreakerCache::class)->get(CircuitBreakerKey::for(CircuitScope::Provider, 'stale'))?->state)
        ->toBe(CircuitState::Closed);

    $invocations = ['count' => 0];
    $thrown = Breakers::attempt($breaker, CircuitScope::Provider, 'stale', succeeds: true, invocations: $invocations);

    expect($invocations['count'])->toBe(0)
        ->and($thrown)->toBeInstanceOf(CircuitOpenException::class);
});

/*
|--------------------------------------------------------------------------
| What the later consumers read
|--------------------------------------------------------------------------
*/

it('surfaces tripped and probing breakers to the health dashboard query', function (): void {
    // Tasks 33.1 / 33.2 read `unhealthy()` — one indexed query for the whole fleet, no
    // per-breaker service calls.
    Breakers::configure([
        'error_rate' => null,
        'failure_threshold' => 1,
        'open_seconds' => 30,
        'probes' => 2,
        'probe_successes' => 2,
    ]);
    $breaker = Breakers::service();

    Breakers::succeed($breaker, CircuitScope::Provider, 'healthy');
    Breakers::fail($breaker, CircuitScope::Provider, 'tripped');
    Breakers::fail($breaker, CircuitScope::Provider, 'probing');

    thisTest()->travel(31)->seconds();
    // One admitted probe leaves `probing` persisted as HALF_OPEN (it needs two successes).
    Breakers::succeed($breaker, CircuitScope::Provider, 'probing');

    expect(Breakers::requireRow(CircuitScope::Provider, 'probing')->state)->toBe(CircuitState::HalfOpen)
        ->and(CircuitBreakerRecord::query()->unhealthy()->pluck('name')->sort()->values()->all())
        ->toBe(['probing', 'tripped']);
});

it('is resolved from the container as a shared instance of the interface', function (): void {
    expect(app(CircuitBreaker::class))->toBeInstanceOf(CircuitBreaker::class)
        ->and(app(CircuitBreaker::class))->toBe(app(CircuitBreaker::class));
});
