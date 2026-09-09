<?php

declare(strict_types=1);

use App\Enums\IdempotencyState;
use App\Enums\OutboxStatus;

/*
|--------------------------------------------------------------------------
| OutboxStatus / IdempotencyState (Req 31.2, 31.4 / NFR2)
|--------------------------------------------------------------------------
*/

it('treats FAILED as claimable, not terminal', function (): void {
    // "The relay never loses the row" (Req 31.4): FAILED means "retry me later", so it
    // is claimed alongside PENDING and only SENT is final.
    expect(OutboxStatus::Pending->isClaimable())->toBeTrue()
        ->and(OutboxStatus::Failed->isClaimable())->toBeTrue()
        ->and(OutboxStatus::Sent->isClaimable())->toBeFalse()
        ->and(OutboxStatus::Failed->isTerminal())->toBeFalse()
        ->and(OutboxStatus::Sent->isTerminal())->toBeTrue()
        ->and(OutboxStatus::Pending->isTerminal())->toBeFalse();
});

it('knows which states imply a prior attempt', function (): void {
    expect(OutboxStatus::Pending->hasBeenAttempted())->toBeFalse()
        ->and(OutboxStatus::Failed->hasBeenAttempted())->toBeTrue()
        ->and(OutboxStatus::Sent->hasBeenAttempted())->toBeTrue();
});

it('exposes the claimable set both as cases and as raw values', function (): void {
    expect(OutboxStatus::claimable())->toBe([OutboxStatus::Pending, OutboxStatus::Failed])
        ->and(OutboxStatus::claimableValues())->toBe(['PENDING', 'FAILED'])
        ->and(OutboxStatus::values())->toBe(['PENDING', 'SENT', 'FAILED']);

    foreach (OutboxStatus::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});

it('replays only a completed idempotency key and re-runs only a failed one', function (): void {
    expect(IdempotencyState::Completed->isReplayable())->toBeTrue()
        ->and(IdempotencyState::InFlight->isReplayable())->toBeFalse()
        ->and(IdempotencyState::Failed->isReplayable())->toBeFalse()
        // IN_FLIGHT is excluded on purpose: whether its holder crashed is a lease
        // question the state alone cannot answer.
        ->and(IdempotencyState::Failed->allowsExecution())->toBeTrue()
        ->and(IdempotencyState::InFlight->allowsExecution())->toBeFalse()
        ->and(IdempotencyState::Completed->allowsExecution())->toBeFalse();
});

it('settles every idempotency state except IN_FLIGHT', function (): void {
    expect(IdempotencyState::InFlight->isInFlight())->toBeTrue()
        ->and(IdempotencyState::InFlight->isSettled())->toBeFalse()
        ->and(IdempotencyState::Completed->isSettled())->toBeTrue()
        ->and(IdempotencyState::Failed->isSettled())->toBeTrue()
        ->and(IdempotencyState::values())->toBe(['IN_FLIGHT', 'COMPLETED', 'FAILED']);

    foreach (IdempotencyState::cases() as $case) {
        expect($case->label())->not->toBe('');
    }
});
