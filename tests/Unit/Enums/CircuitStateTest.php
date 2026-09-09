<?php

declare(strict_types=1);

use App\Enums\CircuitScope;
use App\Enums\CircuitState;

/*
|--------------------------------------------------------------------------
| CircuitState / CircuitScope (Req 31.3 / NFR2)
|--------------------------------------------------------------------------
| The declarative half of Algorithm 7: which states admit a call at all, and which
| transitions are legal. Correctness Property 13 rests on the first of those.
*/

it('never admits a call while OPEN', function (): void {
    expect(CircuitState::Open->admitsCalls())->toBeFalse()
        ->and(CircuitState::Closed->admitsCalls())->toBeTrue()
        ->and(CircuitState::HalfOpen->admitsCalls())->toBeTrue();
});

it('rations calls only while HALF_OPEN', function (): void {
    expect(CircuitState::HalfOpen->rationsCalls())->toBeTrue()
        ->and(CircuitState::Closed->rationsCalls())->toBeFalse()
        ->and(CircuitState::Open->rationsCalls())->toBeFalse();
});

it('answers which state it is', function (): void {
    expect(CircuitState::Closed->isClosed())->toBeTrue()
        ->and(CircuitState::Closed->isOpen())->toBeFalse()
        ->and(CircuitState::Open->isOpen())->toBeTrue()
        ->and(CircuitState::HalfOpen->isHalfOpen())->toBeTrue();
});

it('allows only the four transitions of Algorithm 7', function (): void {
    expect(CircuitState::Closed->allowedNext())->toBe([CircuitState::Open])
        ->and(CircuitState::Open->allowedNext())->toBe([CircuitState::HalfOpen])
        ->and(CircuitState::HalfOpen->allowedNext())->toBe([CircuitState::Closed, CircuitState::Open]);
});

it('refuses recovery that was never proven by a probe', function (): void {
    // OPEN -> CLOSED would mean trusting the clock instead of a probe; CLOSED ->
    // HALF_OPEN would mean a healthy breaker starting to ration calls unprompted.
    expect(CircuitState::Open->canTransitionTo(CircuitState::Closed))->toBeFalse()
        ->and(CircuitState::Closed->canTransitionTo(CircuitState::HalfOpen))->toBeFalse()
        ->and(CircuitState::Open->canTransitionTo(CircuitState::HalfOpen))->toBeTrue()
        ->and(CircuitState::HalfOpen->canTransitionTo(CircuitState::Closed))->toBeTrue()
        ->and(CircuitState::HalfOpen->canTransitionTo(CircuitState::Open))->toBeTrue();
});

it('treats a no-op transition as legal in every state', function (): void {
    foreach (CircuitState::cases() as $state) {
        expect($state->canTransitionTo($state))->toBeTrue();
    }
});

it('exposes the stored values and a label for every case', function (): void {
    expect(CircuitState::values())->toBe(['CLOSED', 'OPEN', 'HALF_OPEN'])
        ->and(CircuitScope::values())->toBe(['provider', 'tenant', 'gateway', 'bridge']);

    foreach ([...CircuitState::cases(), ...CircuitScope::cases()] as $case) {
        expect($case->label())->not->toBe('');
    }
});

it('marks only the provider family as tenant-partitioned', function (): void {
    // The design scopes LLM breakers per provider *and* per tenant; a gateway outage
    // is global and a bridge session already belongs to one tenant.
    expect(CircuitScope::Provider->isTenantPartitioned())->toBeTrue()
        ->and(CircuitScope::Gateway->isTenantPartitioned())->toBeFalse()
        ->and(CircuitScope::Bridge->isTenantPartitioned())->toBeFalse()
        ->and(CircuitScope::Tenant->isTenantPartitioned())->toBeFalse();
});
