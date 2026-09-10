<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Exceptions\Tenancy\DomainProbeUnavailableException;
use App\Services\Domains\DomainProbe;
use App\Services\Domains\GuardedDomainProbe;
use App\Services\Reliability\CircuitBreaker;
use App\Services\Reliability\RetryPolicy;
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Domains\FakeDomainProbe;

/*
|--------------------------------------------------------------------------
| Guarding the domain probe (Req 9.7 / A9 composed with Req 31.1, 31.3 / NFR2)
|--------------------------------------------------------------------------
| DNS, HTTP and TLS lookups here reach hosts *tenants* supply, which makes this the
| one network dependency on the platform whose targets are attacker-chosen. So it
| gets the two Phase 2 primitives, composed the way `RetryPolicy` prescribes — retry
| loop outside, breaker inside — and this file pins the three behaviours that
| composition exists for:
|
|   1. a hanging or failing *platform* lookup is retried a bounded number of times
|      and then fails fast, so a verification screen cannot stall a worker;
|   2. a sustained failure opens the breaker, after which no lookup is attempted;
|   3. a tenant's **dead host** is not a platform failure and must never trip the
|      shared breaker — otherwise one typo fences off every other tenant.
*/

beforeEach(function (): void {
    Sleep::fake();
});

function guardedProbe(DomainProbe $inner, int $attempts = 2, string $breaker = 'domains-test'): GuardedDomainProbe
{
    return new GuardedDomainProbe(
        $inner,
        app(CircuitBreaker::class),
        app(RetryPolicy::class),
        $breaker,
        $attempts,
        250,
    );
}

it('passes a successful lookup straight through', function (): void {
    $probe = (new FakeDomainProbe)->publishTxt('_wa-challenge.acme.example', 'token');

    expect(guardedProbe($probe)->txtRecords('_wa-challenge.acme.example'))->toBe(['token']);
});

it('gives up inside a clamped budget rather than sleeping the caller out', function (): void {
    $probe = (new FakeDomainProbe)->unavailable();

    expect(fn (): array => guardedProbe($probe, attempts: 3)->txtRecords('acme.example'))
        ->toThrow(DomainProbeUnavailableException::class)
        ->and($probe->calls('txtRecords'))->toBe(3);

    // Two waits, one per retry, each clamped — a lookup on a verification screen must not
    // inherit a queue-sized backoff.
    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMilliseconds <= 250, 2);
});

it('stops attempting lookups once the breaker is open', function (): void {
    $probe = (new FakeDomainProbe)->unavailable();
    $guarded = guardedProbe($probe);
    $breaker = app(CircuitBreaker::class);

    // Three guarded operations, two attempts each: past the failures-per-window arm.
    foreach (range(1, 3) as $ignored) {
        try {
            $guarded->txtRecords('acme.example');
        } catch (DomainProbeUnavailableException) {
            // expected
        }
    }

    expect($breaker->state(CircuitScope::Provider, 'domains-test'))->toBe(CircuitState::Open);

    $reached = $probe->calls('txtRecords');

    expect(fn (): array => $guarded->txtRecords('acme.example'))
        ->toThrow(DomainProbeUnavailableException::class)
        // Property 13: while OPEN the lookup is not invoked at all. For a DNS query on a
        // verification screen that is the difference between a fast "try again" and a
        // worker blocked on a resolver timeout.
        ->and($probe->calls('txtRecords'))->toBe($reached);
});

it('never trips the shared breaker on a tenant\'s dead host', function (): void {
    // Nothing published and nothing served: every lookup answers "absent", which is
    // evidence about the *domain* and must not count against the platform.
    $probe = new FakeDomainProbe;
    $guarded = guardedProbe($probe, breaker: 'domains-dead-host');

    foreach (range(1, 10) as $ignored) {
        expect($guarded->txtRecords('_wa-challenge.dead.example'))->toBe([])
            ->and($guarded->fetch('http://dead.example/.well-known/x'))->toBeNull()
            ->and($guarded->certificate('dead.example', 443))->toBeNull();
    }

    expect(app(CircuitBreaker::class)->state(CircuitScope::Provider, 'domains-dead-host'))
        ->toBe(CircuitState::Closed);
});

it('fails inconclusive for every operation, never fabricating an answer', function (
    string $operation,
    callable $call,
): void {
    $probe = (new FakeDomainProbe)->unavailable();
    $guarded = guardedProbe($probe, attempts: 1, breaker: 'domains-'.$operation);

    try {
        $call($guarded);
        thisTest()->fail(sprintf('%s must not succeed while the platform cannot look', $operation));
    } catch (DomainProbeUnavailableException $e) {
        expect($e->getStatusCode())->toBe(503)
            ->and($e->getHeaders())->toHaveKey('Retry-After')
            ->and($e->operation)->toBe($operation);
    }
})->with([
    'dns.txt' => ['dns.txt', fn (DomainProbe $probe): array => $probe->txtRecords('acme.example')],
    'http.challenge' => ['http.challenge', fn (DomainProbe $probe) => $probe->fetch('http://acme.example/x')],
    'tls.certificate' => ['tls.certificate', fn (DomainProbe $probe) => $probe->certificate('acme.example', 443)],
]);
