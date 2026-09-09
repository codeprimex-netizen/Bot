<?php

declare(strict_types=1);

use App\Enums\TenantTier;

/*
|--------------------------------------------------------------------------
| The isolation ladder (Req 1.6 / A1)
|--------------------------------------------------------------------------
| The enum carries the three facts the rest of the platform needs about a tier —
| does it get its own workers, does it get its own database, and what share of a
| dispatch window does it start with — so that no call site has to branch on the
| tier itself.
*/

it('names exactly the three design tiers', function (): void {
    expect(TenantTier::values())->toBe(['SHARED', 'DEDICATED_WORKER', 'DEDICATED_DB'])
        ->and(TenantTier::from('SHARED'))->toBe(TenantTier::Shared)
        ->and(TenantTier::from('DEDICATED_WORKER'))->toBe(TenantTier::DedicatedWorker)
        ->and(TenantTier::from('DEDICATED_DB'))->toBe(TenantTier::DedicatedDb);
});

it('gives dedicated workers to both dedicated tiers and a dedicated connection only to the top one', function (): void {
    expect(TenantTier::Shared->usesDedicatedWorkers())->toBeFalse()
        ->and(TenantTier::DedicatedWorker->usesDedicatedWorkers())->toBeTrue()
        ->and(TenantTier::DedicatedDb->usesDedicatedWorkers())->toBeTrue()
        // The hybrid model: own lanes on the shared database is a supported
        // resting state, own database is the escalation beyond it.
        ->and(TenantTier::Shared->usesDedicatedConnection())->toBeFalse()
        ->and(TenantTier::DedicatedWorker->usesDedicatedConnection())->toBeFalse()
        ->and(TenantTier::DedicatedDb->usesDedicatedConnection())->toBeTrue();
});

it('orders the ladder so escalation compares ranks', function (): void {
    expect(TenantTier::Shared->rank())->toBe(0)
        ->and(TenantTier::DedicatedWorker->rank())->toBe(1)
        ->and(TenantTier::DedicatedDb->rank())->toBe(2)
        ->and(TenantTier::DedicatedDb->isAtLeast(TenantTier::DedicatedWorker))->toBeTrue()
        ->and(TenantTier::DedicatedWorker->isAtLeast(TenantTier::DedicatedWorker))->toBeTrue()
        ->and(TenantTier::Shared->isAtLeast(TenantTier::DedicatedWorker))->toBeFalse();
});

it('reads default lane weights from config so the fairness curve is a config flip', function (): void {
    expect(TenantTier::Shared->defaultLaneWeight())->toBe(1)
        ->and(TenantTier::DedicatedWorker->defaultLaneWeight())->toBe(5)
        ->and(TenantTier::DedicatedDb->defaultLaneWeight())->toBe(10);

    config(['wa.tenancy.tiers.lane_weights.DEDICATED_WORKER' => 42]);

    expect(TenantTier::DedicatedWorker->defaultLaneWeight())->toBe(42);
});

it('falls back to a built-in positive weight when config names a useless one', function (): void {
    foreach ([null, 0, -3, 'nonsense', []] as $useless) {
        config(['wa.tenancy.tiers.lane_weights.SHARED' => $useless]);

        // Never zero: a zero weight would drop the lane out of the rotation, which
        // is the starvation Req 1.7 forbids.
        expect(TenantTier::Shared->defaultLaneWeight())->toBe(1);
    }

    // A numeric string from the environment is still a weight.
    config(['wa.tenancy.tiers.lane_weights.SHARED' => '9']);

    expect(TenantTier::Shared->defaultLaneWeight())->toBe(9);
});

it('parses loosely spelled tier names from config and the environment', function (): void {
    expect(TenantTier::tryFromLoose('DEDICATED_DB'))->toBe(TenantTier::DedicatedDb)
        ->and(TenantTier::tryFromLoose('dedicated-db'))->toBe(TenantTier::DedicatedDb)
        ->and(TenantTier::tryFromLoose(' dedicated worker '))->toBe(TenantTier::DedicatedWorker)
        ->and(TenantTier::tryFromLoose(TenantTier::Shared))->toBe(TenantTier::Shared)
        ->and(TenantTier::tryFromLoose('ENTERPRISE'))->toBeNull()
        ->and(TenantTier::tryFromLoose(''))->toBeNull()
        ->and(TenantTier::tryFromLoose(null))->toBeNull()
        ->and(TenantTier::tryFromLoose(7))->toBeNull();
});

it('labels every tier for admin panels', function (): void {
    foreach (TenantTier::cases() as $tier) {
        expect($tier->label())->not->toBe('');
    }
});
