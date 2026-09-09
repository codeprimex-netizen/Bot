<?php

declare(strict_types=1);

use App\Services\Dispatch\DispatchLane;
use Tests\Fixtures\Dispatch;

/*
|--------------------------------------------------------------------------
| One tenant's slice of a queue lane (Req 1.7 / A1)
|--------------------------------------------------------------------------
*/

it('treats a bare tenant as a lane with work of unknown depth', function (): void {
    $lane = DispatchLane::from(Dispatch::stubTenant('t1'));

    expect($lane->isUnbounded())->toBeTrue()
        ->and($lane->isEmpty())->toBeFalse()
        ->and($lane->tenantId())->toBe('t1')
        // An existing lane is passed through untouched.
        ->and(DispatchLane::from($lane))->toBe($lane);
});

it('clamps a negative backlog to nothing pending', function (): void {
    expect(DispatchLane::for(Dispatch::stubTenant('t1'), -5)->isEmpty())->toBeTrue()
        ->and(DispatchLane::for(Dispatch::stubTenant('t1'), 0)->isEmpty())->toBeTrue()
        ->and(DispatchLane::for(Dispatch::stubTenant('t1'), 3)->backlog)->toBe(3);
});

it('merges two lanes of the same tenant without overflowing', function (): void {
    $tenant = Dispatch::stubTenant('t1');

    expect(DispatchLane::for($tenant, 4)->merged(DispatchLane::for($tenant, 6))->backlog)->toBe(10)
        // Saturating, not wrapping: a negative backlog would remove the busiest tenant on
        // the platform from the rotation.
        ->and(DispatchLane::for($tenant, DispatchLane::UNBOUNDED)->merged(DispatchLane::for($tenant, 6))->isUnbounded())->toBeTrue();

    expect(fn () => DispatchLane::for($tenant, 1)->merged(DispatchLane::for(Dispatch::stubTenant('t2'), 1)))
        ->toThrow(InvalidArgumentException::class);
});
