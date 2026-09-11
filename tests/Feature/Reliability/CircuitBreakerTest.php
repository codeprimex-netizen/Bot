<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;
use App\Models\CircuitBreaker;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| circuit_breakers schema and model invariants (Req 31.3 / NFR2)
|--------------------------------------------------------------------------
| The persisted half of Algorithm 7: identity, casts, window/probe accounting.
| The state machine itself is task 3.2 and is asserted here only to the extent the
| row can answer it (`admitsCalls()`, `hasProbeCapacity()`).
*/

it('stores a breaker keyed by scope and name', function (): void {
    $breaker = CircuitBreaker::create([
        'scope' => CircuitScope::Provider,
        'name' => 'openai',
        'state' => CircuitState::HalfOpen,
        'failure_count' => 5,
        'success_count' => 2,
        'half_open_probes' => 1,
        'half_open_successes' => 0,
        'window_started_at' => now()->subSeconds(10),
        'opened_at' => now()->subSeconds(30),
        'last_failure_at' => now()->subSeconds(30),
        'last_success_at' => now()->subMinutes(2),
    ]);

    $fresh = CircuitBreaker::query()->findOrFail($breaker->id);

    expect($fresh->scope)->toBe(CircuitScope::Provider)
        ->and($fresh->state)->toBe(CircuitState::HalfOpen)
        ->and($fresh->failure_count)->toBe(5)
        ->and($fresh->success_count)->toBe(2)
        ->and($fresh->half_open_probes)->toBe(1)
        ->and($fresh->window_started_at)->not->toBeNull()
        ->and($fresh->opened_at)->not->toBeNull()
        ->and($fresh->key())->toBe('provider:openai');
});

it('defaults a fresh breaker to CLOSED with zeroed counters', function (): void {
    CircuitBreaker::query()->insert([
        'scope' => CircuitScope::Gateway->value,
        'name' => 'razorpay',
    ]);

    $breaker = CircuitBreaker::query()->forKey(CircuitScope::Gateway, 'razorpay')->sole();

    expect($breaker->state)->toBe(CircuitState::Closed)
        ->and($breaker->failure_count)->toBe(0)
        ->and($breaker->success_count)->toBe(0)
        ->and($breaker->half_open_probes)->toBe(0)
        ->and($breaker->half_open_successes)->toBe(0)
        ->and($breaker->window_started_at)->toBeNull()
        ->and($breaker->opened_at)->toBeNull()
        ->and($breaker->isHealthy())->toBeTrue();
});

it('round-trips every CircuitState and CircuitScope value through the database', function (): void {
    foreach (CircuitScope::cases() as $scope) {
        foreach (CircuitState::cases() as $state) {
            $breaker = CircuitBreaker::factory()->create([
                'scope' => $scope,
                'name' => $scope->value.'-'.$state->value,
                'state' => $state,
            ]);

            $stored = DB::table('circuit_breakers')->where('id', $breaker->id)->first();

            expect($stored?->scope)->toBe($scope->value)
                ->and($stored?->state)->toBe($state->value)
                ->and(CircuitBreaker::query()->findOrFail($breaker->id)->state)->toBe($state);
        }
    }
});

it('enforces uniq(scope, name)', function (): void {
    CircuitBreaker::factory()->forKey(CircuitScope::Bridge, 'session-1')->create();

    expect(fn () => CircuitBreaker::factory()->forKey(CircuitScope::Bridge, 'session-1')->create())
        ->toThrow(QueryException::class);
});

it('treats the same name in different scopes as different breakers', function (): void {
    CircuitBreaker::factory()->forKey(CircuitScope::Provider, 'shared')->create();
    CircuitBreaker::factory()->forKey(CircuitScope::Gateway, 'shared')->create();

    expect(CircuitBreaker::query()->where('name', 'shared')->count())->toBe(2)
        ->and(CircuitBreaker::query()->forKey(CircuitScope::Provider, 'shared')->count())->toBe(1);
});

it('partitions a provider breaker per tenant through its composite name', function (): void {
    // The per-tenant dimension the design requires, carried in `name` rather than a
    // tenant_id column: one tenant tripping a provider must leave the other closed.
    $acme = Tenant::factory()->create(['slug' => 'acme', 'subdomain' => 'acme']);
    $globex = Tenant::factory()->create(['slug' => 'globex', 'subdomain' => 'globex']);

    $acmeName = CircuitBreaker::compositeName('openai', $acme->id);
    $globexName = CircuitBreaker::compositeName('openai', $globex->id);

    CircuitBreaker::factory()->forKey(CircuitScope::Provider, $acmeName)->open()->create();
    CircuitBreaker::factory()->forKey(CircuitScope::Provider, $globexName)->create();

    expect($acmeName)->not->toBe($globexName)
        ->and($acmeName)->toBe('openai:'.$acme->id)
        ->and(CircuitBreaker::query()->forKey(CircuitScope::Provider, $acmeName)->sole()->state)
        ->toBe(CircuitState::Open)
        ->and(CircuitBreaker::query()->forKey(CircuitScope::Provider, $globexName)->sole()->state)
        ->toBe(CircuitState::Closed);
});

it('builds composite names without empty segments', function (): void {
    expect(CircuitBreaker::compositeName('openai'))->toBe('openai')
        ->and(CircuitBreaker::compositeName('openai', ''))->toBe('openai')
        ->and(CircuitBreaker::compositeName(' openai ', 'tenant'))->toBe('openai:tenant');
});

it('accepts a scope as either an enum or its raw value in forKey', function (): void {
    CircuitBreaker::factory()->forKey(CircuitScope::Gateway, 'stripe')->create();

    expect(CircuitBreaker::query()->forKey('gateway', 'stripe')->exists())->toBeTrue()
        ->and(CircuitBreaker::query()->inScope('gateway')->count())->toBe(1);
});

it('finds unhealthy breakers and leaves healthy ones out', function (): void {
    CircuitBreaker::factory()->forKey(CircuitScope::Provider, 'healthy')->create();
    CircuitBreaker::factory()->forKey(CircuitScope::Provider, 'tripped')->open()->create();
    CircuitBreaker::factory()->forKey(CircuitScope::Provider, 'probing')->halfOpen()->create();

    expect(CircuitBreaker::query()->unhealthy()->pluck('name')->sort()->values()->all())
        ->toBe(['probing', 'tripped']);
});

it('derives the window error rate and never divides by zero', function (): void {
    $empty = CircuitBreaker::factory()->create();
    $mixed = CircuitBreaker::factory()->withWindow(failures: 12, successes: 8)->create();
    $allBad = CircuitBreaker::factory()->withWindow(failures: 4, successes: 0)->create();

    expect($empty->windowCalls())->toBe(0)
        ->and($empty->errorRate())->toBe(0.0)
        ->and($mixed->windowCalls())->toBe(20)
        ->and($mixed->errorRate())->toBe(0.6)
        ->and($allBad->errorRate())->toBe(1.0);
});

it('reports window expiry and open-duration elapse against the stored timestamps', function (): void {
    $fresh = CircuitBreaker::factory()->withWindow(failures: 1, successes: 0, startedSecondsAgo: 5)->create();
    $stale = CircuitBreaker::factory()->withWindow(failures: 1, successes: 0, startedSecondsAgo: 45)->create();
    $never = CircuitBreaker::factory()->create();

    expect($fresh->windowHasExpired(30))->toBeFalse()
        ->and($stale->windowHasExpired(30))->toBeTrue()
        // No window means nothing to rotate, so it has not "expired".
        ->and($never->windowHasExpired(30))->toBeFalse()
        ->and($never->openDurationHasElapsed(30))->toBeFalse()
        ->and(CircuitBreaker::factory()->open(secondsAgo: 5)->create()->openDurationHasElapsed(30))->toBeFalse()
        ->and(CircuitBreaker::factory()->open(secondsAgo: 45)->create()->openDurationHasElapsed(30))->toBeTrue();
});

it('bounds the half-open probe budget', function (): void {
    $unused = CircuitBreaker::factory()->halfOpen(probes: 0)->create();
    $partly = CircuitBreaker::factory()->halfOpen(probes: 2)->create();
    $spent = CircuitBreaker::factory()->halfOpen(probes: 3)->create();
    $over = CircuitBreaker::factory()->halfOpen(probes: 9)->create();

    expect($unused->probesRemaining(3))->toBe(3)
        ->and($unused->hasProbeCapacity(3))->toBeTrue()
        ->and($partly->probesRemaining(3))->toBe(1)
        ->and($partly->hasProbeCapacity(3))->toBeTrue()
        ->and($spent->probesRemaining(3))->toBe(0)
        ->and($spent->hasProbeCapacity(3))->toBeFalse()
        // Never negative, even if a limit is lowered under a live half-open window.
        ->and($over->probesRemaining(3))->toBe(0)
        ->and($over->hasProbeCapacity(3))->toBeFalse();
});

it('reads and writes breaker state with no tenant bound', function (): void {
    // The load-bearing consequence of keeping this table platform-level: the bridge
    // reconnect loop and the gateway webhook handler run before any tenant exists, and
    // a reliability primitive must not be the thing that throws during an outage.
    app(TenantContext::class)->forget();

    $breaker = CircuitBreaker::factory()->forKey(CircuitScope::Bridge, 'session-x')->create();
    $breaker->update(['state' => CircuitState::Open, 'opened_at' => now()]);

    expect(CircuitBreaker::query()->forKey(CircuitScope::Bridge, 'session-x')->sole()->state)
        ->toBe(CircuitState::Open);
});

it('keeps the table free of a tenancy column', function (): void {
    // Guards the design decision itself: if a later change adds `tenant_id` here, the
    // tenancy guard test starts demanding BelongsToTenant, which would make every
    // tenantless breaker read fail closed. That trade-off must be made deliberately.
    expect(Schema::hasColumn('circuit_breakers', 'tenant_id'))->toBeFalse();
});
