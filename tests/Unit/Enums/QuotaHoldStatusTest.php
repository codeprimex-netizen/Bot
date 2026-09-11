<?php

declare(strict_types=1);

use App\Enums\QuotaHoldStatus;

/*
|--------------------------------------------------------------------------
| QuotaHoldStatus — the QUOTA_PAUSED lifecycle (Req 20.3 / C3)
|--------------------------------------------------------------------------
*/

it('spells the paused state exactly as the requirement names it', function (): void {
    // Req 20.3 says "pause it as `QUOTA_PAUSED`", and task 26.2's campaign status mirrors
    // this value — so the string is part of the contract, not an implementation detail.
    expect(QuotaHoldStatus::QuotaPaused->value)->toBe('QUOTA_PAUSED')
        ->and(QuotaHoldStatus::values())->toBe(['QUOTA_PAUSED', 'RESUMING', 'RESUMED', 'CANCELLED']);
});

it('treats exactly the unfinished states as owing a resume', function (): void {
    expect(QuotaHoldStatus::openValues())->toBe(['QUOTA_PAUSED', 'RESUMING']);

    foreach (QuotaHoldStatus::cases() as $case) {
        // Open and terminal are complements: a hold either still owes its owner a resume or
        // it does not, and there is no third answer for a sweep to overlook.
        expect($case->isOpen())->toBe(! $case->isTerminal())
            ->and($case->label())->not->toBe('');
    }
});

it('lets only an unclaimed pause be claimed', function (): void {
    expect(QuotaHoldStatus::QuotaPaused->isPaused())->toBeTrue()
        ->and(QuotaHoldStatus::QuotaPaused->isClaimed())->toBeFalse()
        ->and(QuotaHoldStatus::Resuming->isPaused())->toBeFalse()
        ->and(QuotaHoldStatus::Resuming->isClaimed())->toBeTrue()
        ->and(QuotaHoldStatus::Resumed->isPaused())->toBeFalse()
        ->and(QuotaHoldStatus::Cancelled->isOpen())->toBeFalse();
});
