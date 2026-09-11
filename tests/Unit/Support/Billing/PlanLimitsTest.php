<?php

declare(strict_types=1);

use App\Enums\QuotaKind;
use App\Exceptions\Billing\MalformedPlanException;
use App\Support\Billing\PlanLimits;

/*
|--------------------------------------------------------------------------
| plans.limits — shape validation (Req 25.1 / D2)
|--------------------------------------------------------------------------
| `null` means unlimited here, which makes every ambiguous input dangerous: the
| tests below pin down that only an explicit null reads as unlimited, and that
| everything the platform cannot interpret raises instead.
*/

it('reads a declared ceiling', function (): void {
    $limits = PlanLimits::fromRaw([
        QuotaKind::Sessions->value => 3,
        QuotaKind::AiCredits->value => 0,
    ]);

    expect($limits->for(QuotaKind::Sessions))->toBe(3)
        ->and($limits->isUnlimited(QuotaKind::Sessions))->toBeFalse()
        ->and($limits->declares(QuotaKind::Sessions))->toBeTrue()
        ->and($limits->for(QuotaKind::AiCredits))->toBe(0)
        ->and($limits->declares(QuotaKind::AiCredits))->toBeTrue();
});

it('reads an explicit null as unlimited', function (): void {
    $limits = PlanLimits::fromRaw([QuotaKind::MessagesMonthly->value => null]);

    expect($limits->for(QuotaKind::MessagesMonthly))->toBeNull()
        ->and($limits->isUnlimited(QuotaKind::MessagesMonthly))->toBeTrue()
        ->and($limits->declares(QuotaKind::MessagesMonthly))->toBeTrue();
});

it('grants nothing for an undeclared kind, and never reads absence as unlimited', function (): void {
    $limits = PlanLimits::fromRaw([QuotaKind::Sessions->value => 3]);

    expect($limits->for(QuotaKind::Contacts))->toBe(0)
        ->and($limits->for(QuotaKind::Contacts))->not->toBeNull()
        ->and($limits->isUnlimited(QuotaKind::Contacts))->toBeFalse()
        ->and($limits->declares(QuotaKind::Contacts))->toBeFalse()
        ->and(PlanLimits::none()->for(QuotaKind::MessagesMonthly))->toBe(0);
});

it('reports the kinds an admin has not priced yet', function (): void {
    $limits = PlanLimits::fromRaw([
        QuotaKind::MessagesMonthly->value => 1000,
        QuotaKind::MessagesDaily->value => 100,
    ]);

    expect($limits->undeclared())->toBe([
        QuotaKind::Sessions,
        QuotaKind::Contacts,
        QuotaKind::AiCredits,
        QuotaKind::CampaignsConcurrent,
    ])->and(PlanLimits::fromRaw(array_fill_keys(QuotaKind::values(), 1))->undeclared())->toBe([]);
});

it('rejects a null or non-object limits column', function (mixed $raw): void {
    expect(fn (): PlanLimits => PlanLimits::fromRaw($raw, 'plan "growth"'))
        ->toThrow(MalformedPlanException::class);
})->with([
    'null' => [null],
    'string' => ['{"SESSIONS":1}'],
    'int' => [5],
]);

it('rejects a key that is not a quota kind', function (mixed $key): void {
    expect(fn (): PlanLimits => PlanLimits::fromRaw([$key => 10], 'plan "growth"'))
        ->toThrow(MalformedPlanException::class, 'is not a quota kind');
})->with([
    'typo' => ['SESSION'],
    'lowercase' => ['sessions'],
    'label' => ['Connected numbers'],
    'numeric' => [0],
]);

it('rejects a ceiling that is not an integer or null', function (mixed $value): void {
    expect(fn (): PlanLimits => PlanLimits::fromRaw([QuotaKind::Sessions->value => $value]))
        ->toThrow(MalformedPlanException::class, 'only an integer or null');
})->with([
    'numeric string' => ['10'],
    'float' => [1.5],
    'bool' => [true],
    'array' => [[10]],
]);

it('rejects a negative ceiling', function (): void {
    expect(fn (): PlanLimits => PlanLimits::fromRaw([QuotaKind::Sessions->value => -1]))
        ->toThrow(MalformedPlanException::class, 'cannot be negative');
});
