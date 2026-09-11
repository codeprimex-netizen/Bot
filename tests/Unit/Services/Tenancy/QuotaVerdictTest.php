<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Enums\QuotaOutcome;
use App\Enums\QuotaReason;
use App\Services\Tenancy\QuotaConsumption;
use App\Services\Tenancy\QuotaVerdict;

/*
|--------------------------------------------------------------------------
| The shape of a quota answer (Req 3.4, 3.5 / A3)
|--------------------------------------------------------------------------
| No database: these are the value objects the guard's decisions travel in, and the
| defer/block rule they encode.
*/

it('derives the outcome from the reason, so the two can never disagree', function (): void {
    $deferring = [];
    $blocking = [];
    $allowing = [];

    foreach (QuotaReason::cases() as $reason) {
        match ($reason->outcome()) {
            QuotaOutcome::Allow => $allowing[] = $reason,
            QuotaOutcome::Defer => $deferring[] = $reason,
            QuotaOutcome::Block => $blocking[] = $reason,
        };
    }

    // Exactly one reason defers: an accruing counter that a period roll will empty.
    // Every other refusal is structural, and blocks with an explanation instead.
    expect($deferring)->toBe([QuotaReason::PeriodExhausted])
        ->and($allowing)->toBe([QuotaReason::WithinAllowance, QuotaReason::Unlimited, QuotaReason::Overage])
        ->and($blocking)->toHaveCount(6)
        ->and(QuotaReason::PeriodExhausted->isTransient())->toBeTrue()
        ->and(array_filter($blocking, static fn (QuotaReason $r): bool => $r->isTransient()))->toBe([]);
});

it('never drops work: only a block ends it', function (): void {
    expect(QuotaOutcome::Allow->keepsWork())->toBeTrue()
        ->and(QuotaOutcome::Defer->keepsWork())->toBeTrue()
        ->and(QuotaOutcome::Block->keepsWork())->toBeFalse();
});

it('carries a wait only for a deferred verdict, and never a zero-second one', function (): void {
    $deferred = QuotaVerdict::for(QuotaKind::MessagesDaily, QuotaReason::PeriodExhausted, '2025-06-14', 1, 10, 10, 0);
    $blocked = QuotaVerdict::for(QuotaKind::MessagesDaily, QuotaReason::NotPriced, '2025-06-14', 1, 0, 0, 900);

    expect($deferred->secondsUntilPeriodReset())->toBe(1)
        ->and($blocked->secondsUntilPeriodReset())->toBe(0);
});

it('reports an unlimited allowance as a sentinel rather than a number', function (): void {
    $verdict = QuotaVerdict::for(QuotaKind::MessagesMonthly, QuotaReason::Unlimited, '2025-06', 5, 900, null);

    expect($verdict->isUnlimited())->toBeTrue()
        ->and($verdict->remaining())->toBe(QuotaVerdict::UNLIMITED)
        ->and($verdict->toArray()['remaining'])->toBeNull()
        ->and($verdict->toArray()['limit'])->toBeNull();
});

it('clamps a remainder at zero and states the shortfall', function (): void {
    $verdict = QuotaVerdict::for(QuotaKind::MessagesMonthly, QuotaReason::PeriodExhausted, '2025-06', 4, 12, 10, 60);

    expect($verdict->remaining())->toBe(0)
        ->and($verdict->shortfall())->toBe(4)
        ->and(QuotaVerdict::for(QuotaKind::MessagesMonthly, QuotaReason::WithinAllowance, '2025-06', 4, 1, 10)->shortfall())->toBe(0);
});

it('tells a replay apart from a capped consume', function (): void {
    $replay = QuotaConsumption::replayed(QuotaKind::MessagesMonthly, '2025-06', 'msg-1', 3, 3, 3, 10);
    $capped = QuotaConsumption::applied(QuotaKind::MessagesMonthly, '2025-06', 'msg-2', 3, 1, 10, 10);

    expect($replay->isReplay())->toBeTrue()
        // A replay applied nothing *now*, which is not the same as having been capped.
        ->and($replay->wasCapped())->toBeFalse()
        ->and($replay->refused())->toBe(0)
        ->and($capped->isReplay())->toBeFalse()
        ->and($capped->wasCapped())->toBeTrue()
        ->and($capped->refused())->toBe(2)
        ->and($capped->remaining())->toBe(0);
});

it('round-trips a receipt through the ledger payload a duplicate replays', function (): void {
    $original = QuotaConsumption::applied(QuotaKind::MessagesDaily, '2025-06-14', 'msg-1', 2, 2, 7, 20);

    $replayed = QuotaConsumption::fromLedger(QuotaKind::MessagesDaily, 'msg-1', 2, $original->toLedger());

    expect($replayed->isReplay())->toBeTrue()
        ->and($replayed->periodKey)->toBe('2025-06-14')
        ->and($replayed->applied)->toBe(2)
        ->and($replayed->used)->toBe(7)
        ->and($replayed->limit)->toBe(20);
});

it('marks a gauge recount as a measurement rather than a spend', function (): void {
    $measured = QuotaConsumption::measured(QuotaKind::Sessions, QuotaKind::GAUGE_PERIOD_KEY, 4, 10);

    expect($measured->isMeasurement())->toBeTrue()
        ->and($measured->idempotencyKey)->toBeNull()
        ->and($measured->used)->toBe(4)
        ->and($measured->remaining())->toBe(6);
});
