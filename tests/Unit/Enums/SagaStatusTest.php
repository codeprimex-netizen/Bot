<?php

declare(strict_types=1);

use App\Enums\SagaStatus;
use App\Enums\SagaStepStatus;

/*
|--------------------------------------------------------------------------
| SagaStatus / SagaStepStatus (Req 31.5 / NFR2)
|--------------------------------------------------------------------------
| The two halves of Correctness Property 18: COMPLETED means every forward step
| landed, FAILED means every step that landed has been compensated.
*/

it('treats RUNNING and COMPENSATING as work the orchestrator still owes', function (): void {
    // COMPENSATING is resumable because an interrupted unwind must keep unwinding —
    // that is the whole reason sagas are persisted.
    expect(SagaStatus::Running->isResumable())->toBeTrue()
        ->and(SagaStatus::Compensating->isResumable())->toBeTrue()
        ->and(SagaStatus::Completed->isResumable())->toBeFalse()
        ->and(SagaStatus::Failed->isResumable())->toBeFalse()
        ->and(SagaStatus::Completed->isTerminal())->toBeTrue()
        ->and(SagaStatus::Failed->isTerminal())->toBeTrue()
        ->and(SagaStatus::Compensating->isCompensating())->toBeTrue();
});

it('never lets a compensating saga claim success', function (): void {
    expect(SagaStatus::Compensating->canTransitionTo(SagaStatus::Completed))->toBeFalse()
        ->and(SagaStatus::Compensating->allowedNext())->toBe([SagaStatus::Failed])
        ->and(SagaStatus::Running->allowedNext())->toBe([SagaStatus::Completed, SagaStatus::Compensating])
        ->and(SagaStatus::Completed->allowedNext())->toBe([])
        ->and(SagaStatus::Failed->allowedNext())->toBe([]);
});

it('treats a no-op saga transition as legal so a re-run cannot error', function (): void {
    foreach (SagaStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeTrue();
    }
});

it('exposes the resumable set as raw values for a cross-tenant sweep', function (): void {
    expect(SagaStatus::resumableValues())->toBe(['RUNNING', 'COMPENSATING'])
        ->and(SagaStatus::values())->toBe(['RUNNING', 'COMPLETED', 'COMPENSATING', 'FAILED']);

    foreach ([...SagaStatus::cases(), ...SagaStepStatus::cases()] as $case) {
        expect($case->label())->not->toBe('');
    }
});

it('marks exactly the DONE steps as owing a compensation', function (): void {
    // `status = DONE` is the durable form of Algorithm 8's `done[]` set.
    expect(SagaStepStatus::Done->needsCompensation())->toBeTrue()
        ->and(SagaStepStatus::Pending->needsCompensation())->toBeFalse()
        ->and(SagaStepStatus::Compensated->needsCompensation())->toBeFalse()
        // A forward action that failed left nothing to undo.
        ->and(SagaStepStatus::Failed->needsCompensation())->toBeFalse()
        ->and(SagaStepStatus::Pending->awaitsExecution())->toBeTrue()
        ->and(SagaStepStatus::Done->awaitsExecution())->toBeFalse();
});

it('allows only forward-then-compensate step transitions', function (): void {
    expect(SagaStepStatus::Pending->allowedNext())->toBe([SagaStepStatus::Done, SagaStepStatus::Failed])
        ->and(SagaStepStatus::Done->allowedNext())->toBe([SagaStepStatus::Compensated])
        ->and(SagaStepStatus::Compensated->allowedNext())->toBe([])
        ->and(SagaStepStatus::Failed->allowedNext())->toBe([])
        ->and(SagaStepStatus::Compensated->canTransitionTo(SagaStepStatus::Done))->toBeFalse()
        ->and(SagaStepStatus::Failed->canTransitionTo(SagaStepStatus::Done))->toBeFalse()
        ->and(SagaStepStatus::Compensated->isTerminal())->toBeTrue()
        ->and(SagaStepStatus::Failed->isTerminal())->toBeTrue()
        ->and(SagaStepStatus::Done->isTerminal())->toBeFalse();
});

it('treats a no-op step transition as legal so idempotent marking cannot error', function (): void {
    foreach (SagaStepStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeTrue();
    }
});
