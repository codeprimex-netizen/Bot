<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Services\Bridge\GuardedBridgeClient;
use App\Services\Reliability\RetryPolicy;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Breakers;
use Tests\Fixtures\Bridge\FakeBridgeClient;

/*
|--------------------------------------------------------------------------
| Guarding the bridge (Req 2.9 / A2 composed with Req 31.1, 31.3 / NFR2)
|--------------------------------------------------------------------------
| The two Phase 2 primitives, composed the way `RetryPolicy` prescribes — retry loop
| outside, breaker inside — with one platform-specific twist that carries the isolation
| guarantee: the breaker is keyed **per session**. These tests pin the four behaviours
| that composition exists for:
|
|   1. a transient bridge failure is retried a bounded number of times and then fails
|      loudly, never as a plausible success;
|   2. a *terminal* refusal (a bad token, a number that is not on WhatsApp) is not
|      retried at all, because a second identical request cannot change the answer;
|   3. a sustained failure opens that session's breaker, after which nothing is attempted;
|   4. one session's breaker does not fence off another tenant's session.
*/

beforeEach(function (): void {
    Sleep::fake();
});

/**
 * Point the *bridge* family's thresholds at test-sized numbers.
 *
 * `CircuitScope::Bridge` carries its own overrides in `wa.reliability.circuit.scopes`
 * (tighter and twitchier than the platform default, because reconnecting a session is
 * cheap), so a test that only rewrote `defaults` would be overridden by them and would
 * pass or fail for the wrong reason.
 */
function configureBridgeBreaker(int $failureThreshold = 2, int $openSeconds = 60): void
{
    Breakers::configure(scopes: [CircuitScope::Bridge->value => [
        'failure_threshold' => $failureThreshold,
        'window_seconds' => 60,
        'open_seconds' => $openSeconds,
        'error_rate' => null,
    ]]);
}

function guardedBridge(FakeBridgeClient $inner, int $attempts = 2): GuardedBridgeClient
{
    return new GuardedBridgeClient(
        $inner,
        Breakers::service(),
        app(RetryPolicy::class),
        $attempts,
        250,
    );
}

it('passes a successful call straight through', function (): void {
    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());

    $receipt = guardedBridge($inner)->sendText($id, 'x@s.whatsapp.net', 'Hi');

    expect($receipt->waMessageId)->toBe(FakeBridgeClient::MESSAGE_ID_PREFIX.'1')
        ->and($inner->calls('message.text'))->toBe(1);
});

it('retries a transport failure inside a clamped budget and then fails closed', function (): void {
    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $inner->unreachable();

    expect(fn () => guardedBridge($inner, attempts: 3)->sendText($id, 'x@s.whatsapp.net', 'Hi'))
        ->toThrow(BridgeUnreachableException::class)
        ->and($inner->calls('message.text'))->toBe(3);

    // Inline waits only, never a queue-sized delay: this sleeps inside the caller.
    Sleep::assertSleptTimes(2);
});

it('retries a refusal that says this session has no socket', function (): void {
    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());

    // The sidecar is up, this session is not — and the reconnect loop may land between
    // attempts, which is exactly what makes it worth a second try.
    $inner->refuseWith(BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED, 409, times: 1);

    $receipt = guardedBridge($inner, attempts: 2)->sendText($id, 'x@s.whatsapp.net', 'Hi');

    expect($receipt->waMessageId)->not->toBe('')
        ->and($inner->calls('message.text'))->toBe(2);
});

it('does not retry a terminal refusal', function (): void {
    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $inner->refuseWith(BridgeRequestFailedException::CODE_NOT_ON_WHATSAPP, 422);

    expect(fn () => guardedBridge($inner, attempts: 5)->sendText($id, 'x@s.whatsapp.net', 'Hi'))
        ->toThrow(BridgeRequestFailedException::class)
        // One attempt: there is no account to deliver to on this attempt or any other.
        ->and($inner->calls('message.text'))->toBe(1);

    Sleep::assertNeverSlept();
});

it('does not retry a rejected bearer token', function (): void {
    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $inner->refuseWith(null, 401);

    expect(fn () => guardedBridge($inner, attempts: 5)->startSession($id))
        ->toThrow(BridgeRequestFailedException::class)
        ->and($inner->calls('session.start'))->toBe(1);
});

it('opens the breaker for a session after sustained failure and then stops calling the bridge', function (): void {
    configureBridgeBreaker(failureThreshold: 2);

    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $inner->unreachable();
    $bridge = guardedBridge($inner, attempts: 1);

    foreach (range(1, 2) as $ignored) {
        expect(fn () => $bridge->startSession($id))->toThrow(BridgeUnreachableException::class);
    }

    $callsBeforeTrip = $inner->calls('session.start');

    expect(Breakers::service()->state(CircuitScope::Bridge, $id))->toBe(CircuitState::Open)
        // A tripped breaker means the call was *not made*: a dead sidecar answers every
        // request with a full timeout, so refusing fast is the point.
        ->and(fn () => $bridge->startSession($id))->toThrow(BridgeUnreachableException::class)
        ->and($inner->calls('session.start'))->toBe($callsBeforeTrip);
});

it('never spends the retry budget on an open breaker', function (): void {
    configureBridgeBreaker(failureThreshold: 1);

    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $inner->unreachable();
    $bridge = guardedBridge($inner, attempts: 4);

    expect(fn () => $bridge->startSession($id))->toThrow(BridgeUnreachableException::class);

    $calls = $inner->calls('session.start');
    Sleep::fake();

    expect(fn () => $bridge->startSession($id))->toThrow(BridgeUnreachableException::class)
        ->and($inner->calls('session.start'))->toBe($calls);

    // A breaker's decision cannot change inside the loop, so waiting on it only delays the
    // caller's own fallback.
    Sleep::assertNeverSlept();
});

it('fences off one session without touching another tenants session', function (): void {
    configureBridgeBreaker(failureThreshold: 1);

    $inner = new FakeBridgeClient;
    $flapping = FakeBridgeClient::sessionId();
    $healthy = FakeBridgeClient::sessionId();
    $inner->connect($flapping)->connect($healthy);

    $bridge = guardedBridge($inner, attempts: 1);

    $inner->refuseWith(BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED, 409);
    expect(fn () => $bridge->startSession($flapping))->toThrow(BridgeRequestFailedException::class);
    $inner->stopRefusing();

    // A platform-wide bridge breaker would have made one tenant's bad number an outage for
    // everybody — which is the noisy-neighbour failure row-level isolation exists to prevent.
    expect(Breakers::service()->state(CircuitScope::Bridge, $flapping))->toBe(CircuitState::Open)
        ->and(Breakers::service()->state(CircuitScope::Bridge, $healthy))->toBe(CircuitState::Closed);

    $bridge->startSession($healthy);

    expect($inner->calls('session.start'))->toBe(2);
});

it('wraps an unexpected library error as a transport failure rather than letting it escape', function (): void {
    $inner = (new FakeBridgeClient)->failWith(
        new RuntimeException('curl: connecting to http://bridge.test with Bearer secret-token')
    );

    try {
        guardedBridge($inner, attempts: 1)->startSession(FakeBridgeClient::sessionId());
        $message = '';
    } catch (BridgeUnreachableException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('session.start')
        ->and($message)->not->toContain('secret-token');
});

it('reports the health probe as a boolean and never retries it', function (): void {
    $inner = new FakeBridgeClient;

    expect(guardedBridge($inner)->isReachable())->toBeTrue();

    $inner->unreachable();

    // A probe that retried would report "up" for a bridge answering one call in three, and
    // the dashboard's job is to show that as degraded.
    expect(guardedBridge($inner)->isReachable())->toBeFalse()
        ->and($inner->calls('health'))->toBe(2);
});

it('keeps the health probe on its own breaker so one session cannot suppress it', function (): void {
    configureBridgeBreaker(failureThreshold: 1);

    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $inner->unreachable();
    $bridge = guardedBridge($inner, attempts: 1);

    expect(fn () => $bridge->startSession($id))->toThrow(BridgeUnreachableException::class);

    $inner->reachable();

    expect(Breakers::service()->state(CircuitScope::Bridge, $id))->toBe(CircuitState::Open)
        ->and(Breakers::service()->state(CircuitScope::Bridge, GuardedBridgeClient::TRANSPORT_BREAKER))
        ->toBe(CircuitState::Closed)
        ->and($bridge->isReachable())->toBeTrue();
});

it('names the guarded session in a circuit-open failure without quoting its id', function (): void {
    configureBridgeBreaker(failureThreshold: 1);

    $inner = (new FakeBridgeClient)->connect($id = FakeBridgeClient::sessionId());
    $inner->unreachable();
    $bridge = guardedBridge($inner, attempts: 1);

    expect(fn () => $bridge->startSession($id))->toThrow(BridgeUnreachableException::class);

    try {
        $bridge->sendText($id, 'x@s.whatsapp.net', 'Hi');
        $message = '';
    } catch (BridgeUnreachableException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('message.text')
        ->and($message)->toContain('circuit breaker is open')
        ->and($message)->not->toContain($id);
});
