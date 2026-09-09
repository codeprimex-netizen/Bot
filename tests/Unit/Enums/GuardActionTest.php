<?php

declare(strict_types=1);

use App\Enums\AbuseSignal;
use App\Enums\GuardAction;

it('exposes the three wire values the schema stores', function (): void {
    expect(GuardAction::values())->toBe(['ALLOW', 'FLAG', 'BLOCK']);
});

it('escalates towards BLOCK and never away from it', function (): void {
    foreach (GuardAction::cases() as $left) {
        foreach (GuardAction::cases() as $right) {
            $escalated = $left->escalate($right);

            expect($escalated->severity())->toBeGreaterThanOrEqual($left->severity())
                ->and($escalated->severity())->toBeGreaterThanOrEqual($right->severity());
        }
    }
});

it('is order-independent when combining signals', function (): void {
    $signals = [AbuseSignal::Obfuscation, AbuseSignal::InstructionOverride, AbuseSignal::ClassifierDegraded];

    expect(AbuseSignal::actionFor($signals))->toBe(GuardAction::Block)
        ->and(AbuseSignal::actionFor(array_reverse($signals)))->toBe(GuardAction::Block);
});

it('permits a flag but not a block', function (): void {
    expect(GuardAction::Allow->permits())->toBeTrue()
        ->and(GuardAction::Flag->permits())->toBeTrue()
        ->and(GuardAction::Block->permits())->toBeFalse();
});

it('records everything except a clean allow', function (): void {
    expect(GuardAction::Allow->isRecordable())->toBeFalse()
        ->and(GuardAction::Flag->isRecordable())->toBeTrue()
        ->and(GuardAction::Block->isRecordable())->toBeTrue();
});

it('has no signals with no action', function (): void {
    expect(AbuseSignal::actionFor([]))->toBe(GuardAction::Allow);
});
