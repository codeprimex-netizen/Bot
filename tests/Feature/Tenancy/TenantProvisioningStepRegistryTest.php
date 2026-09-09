<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\InvalidProvisioningStepException;
use App\Services\Tenancy\Provisioning\Steps\AssignOwnerMembershipStep;
use App\Services\Tenancy\Provisioning\Steps\AssignSeedPlanStep;
use App\Services\Tenancy\Provisioning\Steps\AssignTenantTierStep;
use App\Services\Tenancy\Provisioning\Steps\CreateTenantRecordStep;
use App\Services\Tenancy\Provisioning\Steps\EnsureStoragePrefixStep;
use App\Services\Tenancy\Provisioning\Steps\ProvisionEncryptionKeyStep;
use App\Services\Tenancy\Provisioning\Steps\RecordProvisioningAuditStep;
use App\Services\Tenancy\Provisioning\TenantProvisioningStep;
use App\Services\Tenancy\Provisioning\TenantProvisioningStepRegistry;

/*
|--------------------------------------------------------------------------
| The provisioning pipeline, pinned (Req 1.8 / A1)
|--------------------------------------------------------------------------
| Req 1.8 names six things a provisioned tenant must have. Two of them cannot be built
| yet — `wallets` arrives with task 10.1 and `chatbots` with task 11.1 — and the honest
| way to carry that debt is not a comment: it is a failing assertion in the future.
|
| The first test below pins the registered pipeline **verbatim**. When task 10.1 appends
| its `CreateWalletStep`, this test fails, and the person adding it has to come here and
| say so; the second test states the two missing steps outright, so the failure explains
| itself instead of looking like an unrelated regression.
|
| That is the whole point of making the pipeline data: the omission is visible in a test
| run rather than remembered.
*/

function registry(): TenantProvisioningStepRegistry
{
    return app(TenantProvisioningStepRegistry::class);
}

it('registers exactly these provisioning steps, in this order', function (): void {
    expect(registry()->classes())->toBe([
        // Order is the design. The record first, because everything hangs off it; the two
        // steps with effects outside the database (the key store, the filesystem) as late
        // as possible, so the cheap failures happen before anything needs compensating;
        // the audit entry last, so it can report what the others actually did.
        CreateTenantRecordStep::class,
        AssignSeedPlanStep::class,
        AssignOwnerMembershipStep::class,
        AssignTenantTierStep::class,
        ProvisionEncryptionKeyStep::class,
        EnsureStoragePrefixStep::class,
        RecordProvisioningAuditStep::class,
    ]);

    expect(registry()->names())->toBe([
        'tenant.record',
        'plan.seed',
        'owner.membership',
        'tier.assignment',
        'encryption.dek',
        'storage.prefix',
        'audit.entry',
    ]);
});

it('does not satisfy Req 1.8 yet: the wallet and default chatbot steps are still owed', function (): void {
    $names = registry()->names();

    // Task 10.1 (`wallets`, Phase 7 — Billing) must register a `CreateWalletStep`, and
    // task 11.1 (`chatbots`, Phase 8 — Conversational AI) a `CreateDefaultChatbotStep`.
    // Deliberately *not* declared as empty classes: a step that claims to create a wallet
    // and does nothing would make Req 1.8 look satisfied while a tenant provisioned today
    // silently has no wallet and no chatbot.
    expect($names)->not->toContain('wallet')
        ->and($names)->not->toContain('chatbot');

    // When one of those tasks lands, delete the matching line above, add the step to
    // `wa.tenancy.provisioning.steps`, and update the pinned list in the test above.
    expect(count($names))->toBe(7);
});

it('resolves a fresh step instance on every run', function (): void {
    // Steps must not carry a remembered tenant or a half-built path from one
    // provisioning into the next — on a long-lived queue worker that would be a
    // cross-tenant leak.
    $first = registry()->steps();
    $second = registry()->steps();

    expect($first[0])->not->toBe($second[0])
        ->and($first[0])->toBeInstanceOf(CreateTenantRecordStep::class);
});

/*
|--------------------------------------------------------------------------
| A misconfigured pipeline is fatal, never silently shorter
|--------------------------------------------------------------------------
*/

it('refuses a configured entry that is not a usable step', function (mixed $steps, string $fragment): void {
    config()->set('wa.tenancy.provisioning.steps', $steps);

    // Unlike `wa.tenancy.resolvers`, which skips what it cannot resolve, a skipped
    // provisioning step would create a tenant with no plan, no key or no storage — and
    // afterwards that is indistinguishable from a tenant that legitimately has none.
    expect(fn (): array => registry()->steps())
        ->toThrow(InvalidProvisioningStepException::class, $fragment);
})->with([
    'not a class name' => [[42], 'not a class name'],
    'missing class' => [['App\\Nope\\NotAStep'], 'does not exist'],
    'not a step' => [[stdClass::class], 'does not implement'],
    'empty pipeline' => [[], 'is empty'],
]);

it('refuses two steps sharing a name, because the audit trail records those names', function (): void {
    config()->set('wa.tenancy.provisioning.steps', [
        CreateTenantRecordStep::class,
        CreateTenantRecordStep::class,
    ]);

    expect(fn (): array => registry()->steps())
        ->toThrow(InvalidProvisioningStepException::class, 'two steps named');
});

it('holds every registered step to the contract', function (): void {
    foreach (registry()->steps() as $step) {
        expect($step)->toBeInstanceOf(TenantProvisioningStep::class)
            ->and($step->name())->not->toBe('')
            // Names end up in the audit payload, so they stay machine-readable.
            ->and($step->name())->toMatch('/^[a-z][a-z0-9_.]*$/');
    }
});
