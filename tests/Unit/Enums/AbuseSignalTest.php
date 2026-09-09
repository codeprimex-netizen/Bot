<?php

declare(strict_types=1);

use App\Enums\AbuseSignal;
use App\Enums\AbuseVector;
use App\Enums\GuardAction;

it('resolves each wire value back to its case', function (): void {
    foreach (AbuseSignal::cases() as $case) {
        expect(AbuseSignal::from($case->value))->toBe($case);
    }
});

it('gives every signal a vector and a sayable label', function (): void {
    foreach (AbuseSignal::cases() as $case) {
        expect($case->vector())->toBeInstanceOf(AbuseVector::class)
            ->and($case->label())->not->toBe('');
    }
});

it('blocks on every injection detection', function (): void {
    $detections = [
        AbuseSignal::InstructionOverride,
        AbuseSignal::RoleReassignment,
        AbuseSignal::HierarchyPromotion,
        AbuseSignal::FenceEscape,
        AbuseSignal::SystemPromptProbe,
        AbuseSignal::ToolExfiltration,
        AbuseSignal::EncodedPayload,
        AbuseSignal::CustomPattern,
    ];

    foreach ($detections as $signal) {
        expect($signal->action())->toBe(GuardAction::Block)
            ->and($signal->vector())->toBe(AbuseVector::PromptInjection);
    }
});

it('blocks on all three fail-closed outcomes', function (): void {
    foreach ([AbuseSignal::Undecodable, AbuseSignal::Oversized, AbuseSignal::ClassifierUnavailable] as $signal) {
        expect($signal->isFailClosed())->toBeTrue()
            ->and($signal->action())->toBe(GuardAction::Block);
    }
});

it('treats a degraded auxiliary classifier as a flag, not a block or a detection', function (): void {
    // The distinction the composite classifier's degradation rule depends on.
    expect(AbuseSignal::ClassifierDegraded->action())->toBe(GuardAction::Flag)
        ->and(AbuseSignal::ClassifierDegraded->isFailClosed())->toBeFalse();
});

it('suppresses every output finding', function (): void {
    foreach ([
        AbuseSignal::SystemPromptLeak,
        AbuseSignal::FenceLeak,
        AbuseSignal::SecretShaped,
        AbuseSignal::OutputPolicyViolation,
    ] as $signal) {
        expect($signal->action())->toBe(GuardAction::Block)
            ->and($signal->vector())->toBe(AbuseVector::OutputPolicy);
    }
});

it('never blocks a signup for having nothing to count', function (): void {
    // Console provisioning and imports carry no address and no fingerprint.
    expect(AbuseSignal::UncountableSignup->action())->toBe(GuardAction::Flag);
});

it('marks pre-tenant vectors as the ones allowed a null tenant', function (): void {
    expect(AbuseVector::SignupAbuse->isPreTenant())->toBeTrue()
        ->and(AbuseVector::OtpAbuse->isPreTenant())->toBeTrue()
        ->and(AbuseVector::PromptInjection->isPreTenant())->toBeFalse()
        ->and(AbuseVector::SessionRisk->isPreTenant())->toBeFalse();
});
