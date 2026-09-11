<?php

declare(strict_types=1);

use App\Models\TenantApiToken;
use App\Services\Tenancy\QuotaHoldSubject;

/*
|--------------------------------------------------------------------------
| QuotaHoldSubject — what identifies one parked unit of work (Req 20.3 / C3)
|--------------------------------------------------------------------------
| The dedup key is the whole reason a campaign of 5 000 refused sends produces one
| hold instead of 5 000, so it is derived here and nowhere else.
*/

it('derives a stable dedup key from the parked row', function (): void {
    $work = new TenantApiToken;
    $work->id = '01HZY000000000000000000000';

    $subject = QuotaHoldSubject::for($work, resumer: 'campaign', payload: ['cursor' => 7], units: 40);

    expect($subject->dedupKey)->toBe(TenantApiToken::class.':01HZY000000000000000000000')
        ->and($subject->holdable)->toBe($work)
        ->and($subject->resumer)->toBe('campaign')
        ->and($subject->payload)->toBe(['cursor' => 7])
        ->and($subject->units)->toBe(40)
        // Two subjects for the same row agree, which is what makes parking idempotent.
        ->and(QuotaHoldSubject::for($work)->dedupKey)->toBe($subject->dedupKey);
});

it('refuses to park a row that has no identity yet', function (): void {
    QuotaHoldSubject::for(new TenantApiToken);
})->throws(InvalidArgumentException::class, 'must be saved before it can be parked');

it('refuses work with no dedup key at all', function (): void {
    QuotaHoldSubject::named('   ');
})->throws(InvalidArgumentException::class, 'non-empty dedup key');

it('normalizes the resumer key and floors the units at one', function (): void {
    $blank = QuotaHoldSubject::named('import:1', resumer: '  ', units: 0);
    $trimmed = QuotaHoldSubject::named('import:2', resumer: '  campaign  ', units: -5);

    expect($blank->resumer)->toBeNull()          // "no resumer" and "blank resumer" are one thing
        ->and($blank->units)->toBe(1)
        ->and($blank->holdable)->toBeNull()
        ->and($trimmed->resumer)->toBe('campaign')
        ->and($trimmed->units)->toBe(1);
});

it('keeps the dedup key inside what the column can hold', function (): void {
    $subject = QuotaHoldSubject::named(str_repeat('c', 400));

    expect(mb_strlen($subject->dedupKey))->toBe(QuotaHoldSubject::MAX_DEDUP_KEY_LENGTH);
});
