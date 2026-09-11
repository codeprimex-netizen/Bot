<?php

declare(strict_types=1);

use App\Services\Dispatch\LaneCredits;

/*
|--------------------------------------------------------------------------
| Deficit counters (Req 1.7 / A1; Req 30.2 / NFR1)
|--------------------------------------------------------------------------
| The scheduler's whole memory. Framework-light: no container, no database.
*/

it('credits up to a ceiling and spends down to zero', function (): void {
    $credits = LaneCredits::empty();

    $credits->credit('t1', 5, 10);
    expect($credits->get('t1'))->toBe(5);

    // Carried credit accumulates…
    $credits->credit('t1', 5, 10);
    expect($credits->get('t1'))->toBe(10);

    // …but never past the ceiling: that is the burst bound.
    $credits->credit('t1', 5, 10);
    expect($credits->get('t1'))->toBe(10);

    $credits->spend('t1', 12);
    expect($credits->get('t1'))->toBe(0)
        ->and($credits->all())->toBe([]);
});

it('forfeits the credit of tenants outside the candidate set and drops a stale cursor', function (): void {
    $credits = LaneCredits::empty();
    $credits->credit('busy', 3, 6);
    $credits->credit('quiet', 3, 6);
    $credits->advanceCursor('quiet');

    $credits->retain(['busy']);

    expect($credits->all())->toBe(['busy' => 3])
        // A cursor pointing at a tenant no longer in the rotation would freeze rotation.
        ->and($credits->cursor())->toBeNull();
});

it('survives a round trip through the cache and ignores junk', function (): void {
    $credits = LaneCredits::empty();
    $credits->credit('t1', 4, 8);
    $credits->advanceCursor('t1');

    $restored = LaneCredits::fromArray($credits->toArray());

    expect($restored->get('t1'))->toBe(4)
        ->and($restored->cursor())->toBe('t1');

    // Soft state: an unreadable ledger starts a fresh round rather than failing dispatch.
    expect(LaneCredits::fromArray('not an array')->isEmpty())->toBeTrue()
        ->and(LaneCredits::fromArray(['credits' => ['t1' => -3, '' => 5, 't2' => 'x'], 'cursor' => 7])->all())->toBe([])
        ->and(LaneCredits::fromArray(['credits' => ['t1' => '6']])->get('t1'))->toBe(6);
});

it('hands out independent copies', function (): void {
    $credits = LaneCredits::empty();
    $credits->credit('t1', 2, 4);

    $copy = $credits->copy();
    $copy->spend('t1', 2);

    expect($credits->get('t1'))->toBe(2)
        ->and($copy->get('t1'))->toBe(0);
});
