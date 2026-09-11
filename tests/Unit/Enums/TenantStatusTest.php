<?php

declare(strict_types=1);

use App\Enums\TenantStatus;

it('exposes the four wire values the schema stores', function (): void {
    expect(TenantStatus::values())
        ->toBe(['ACTIVE', 'SUSPENDED', 'TRIAL', 'CANCELLED']);
});

it('resolves each wire value back to its case', function (): void {
    foreach (TenantStatus::cases() as $case) {
        expect(TenantStatus::from($case->value))->toBe($case);
    }
});

it('allows exactly the lifecycle transitions the design defines', function (): void {
    expect(TenantStatus::Trial->allowedNext())
        ->toBe([TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Cancelled]);

    expect(TenantStatus::Active->allowedNext())
        ->toBe([TenantStatus::Suspended, TenantStatus::Cancelled]);

    expect(TenantStatus::Suspended->allowedNext())
        ->toBe([TenantStatus::Active, TenantStatus::Cancelled]);

    expect(TenantStatus::Cancelled->allowedNext())->toBe([]);
});

it('rejects transitions that are not on the state machine', function (): void {
    expect(TenantStatus::Cancelled->canTransitionTo(TenantStatus::Active))->toBeFalse();
    expect(TenantStatus::Suspended->canTransitionTo(TenantStatus::Trial))->toBeFalse();
    expect(TenantStatus::Active->canTransitionTo(TenantStatus::Trial))->toBeFalse();
});

it('treats a same-state transition as an idempotent no-op', function (): void {
    foreach (TenantStatus::cases() as $case) {
        expect($case->canTransitionTo($case))->toBeTrue();
    }
});

it('marks only CANCELLED as terminal', function (): void {
    foreach (TenantStatus::cases() as $case) {
        expect($case->isTerminal())->toBe($case === TenantStatus::Cancelled);
    }
});

it('treats only ACTIVE and TRIAL tenants as operational', function (): void {
    expect(TenantStatus::Active->isOperational())->toBeTrue();
    expect(TenantStatus::Trial->isOperational())->toBeTrue();
    expect(TenantStatus::Suspended->isOperational())->toBeFalse();
    expect(TenantStatus::Cancelled->isOperational())->toBeFalse();
});
