<?php

declare(strict_types=1);

use App\Enums\KeyPurpose;
use App\Enums\StorageArea;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Enums\TenantTier;
use App\Exceptions\Tenancy\InvalidProvisioningSpecException;
use App\Exceptions\Tenancy\SeedPlanUnavailableException;
use App\Exceptions\Tenancy\TenantAlreadyExistsException;
use App\Models\AuditLog;
use App\Models\EncryptionKey;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantTierAssignment;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Lifecycle;
use Tests\Fixtures\Provisioning\FailingProvisioningStep;
use Tests\Fixtures\Provisioning\ProvisioningSpy;
use Tests\Fixtures\Provisioning\SpyProvisioningStep;

/*
|--------------------------------------------------------------------------
| Tenant provisioning (Req 1.8 / A1; design.md §"Tenant lifecycle" → Provisioning)
|--------------------------------------------------------------------------
| Req 1.8 asks for six things created **atomically**: tenant record, wallet, default
| chatbot, per-tenant DEK, storage prefix, seed plan. Four of them are buildable today —
| `wallets` (task 10.1) and `chatbots` (task 11.1) have no table yet, and
| `TenantProvisioningStepRegistryTest` is what keeps that omission visible.
|
| So what is asserted here is the four that exist, plus the word the requirement turns
| on: that a failure anywhere in the pipeline leaves **nothing** behind — no tenant row,
| no plan association, no key row, no membership, no audit entry, and no directory on
| disk. The last one is the interesting case, because a database transaction cannot undo
| it.
*/

beforeEach(function (): void {
    // Nothing may touch the real per-tenant directories.
    foreach (StorageArea::cases() as $area) {
        Storage::fake($area->disk());
    }

    // Provisioning refuses to create a planless tenant, so the catalogue has to exist —
    // which is exactly the coupling the "fails loudly" test below removes again.
    (new PlanSeeder)->run();

    ProvisioningSpy::reset();
});

/**
 * Whether the tenant's auth-state directory exists on the faked disk.
 */
function authStateDirectoryExists(Tenant $tenant): bool
{
    return Storage::disk(StorageArea::AuthState->disk())->directoryExists($tenant->storagePrefix());
}

/*
|--------------------------------------------------------------------------
| The happy path — everything Req 1.8 can create today, in one call
|--------------------------------------------------------------------------
*/

it('creates the tenant record, seed plan, owner membership, DEK, storage prefix and audit entry', function (): void {
    $owner = User::factory()->create();

    $tenant = Lifecycle::service()->provision([
        'name' => 'Acme Corp',
        'owner' => $owner,
    ]);

    // 1. the tenant record
    expect($tenant->exists)->toBeTrue()
        ->and(Tenant::query()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->slug)->toBe('acme-corp')
        ->and($tenant->subdomain)->toBe('acme-corp')
        ->and($tenant->timezone)->toBe('UTC')
        ->and($tenant->locale)->toBe('en');

    // 2. the seed plan
    $starter = Plan::query()->where('slug', 'starter')->sole();
    expect($tenant->plan_id)->toBe($starter->id);

    // 3. the owner membership — without it nobody could ever open the tenant
    $membership = TenantUser::query()->where('tenant_id', $tenant->id)->sole();
    expect($membership->user_id)->toBe($owner->getKey())
        ->and($membership->role)->toBe(TenantRole::Owner)
        ->and($membership->isPending())->toBeFalse();

    // 4. the per-tenant DEK
    $key = EncryptionKey::activeFor($tenant->id, KeyPurpose::Field);
    expect($key)->not->toBeNull()
        ->and($key?->version)->toBe(EncryptionKey::FIRST_VERSION);

    // 5. the storage prefix
    expect(authStateDirectoryExists($tenant))->toBeTrue();

    // 6. one audit entry, on the tenant's own chain
    $entry = Lifecycle::trail($tenant)->sole();
    expect($entry->action)->toBe('tenant.provisioned')
        ->and($entry->chain_key)->toBe($tenant->id)
        ->and($entry->subject_type)->toBe(Tenant::class)
        ->and($entry->subject_id)->toBe($tenant->id);
});

it('records what each step did in the provisioning audit entry', function (): void {
    $tenant = Lifecycle::service()->provision(['name' => 'Audit Co']);

    $payload = Lifecycle::trail($tenant)->sole()->payload;

    expect($payload['status'] ?? null)->toBe('TRIAL')
        ->and($payload['slug'] ?? null)->toBe('audit-co')
        ->and($payload['plan'] ?? null)->toMatchArray(['slug' => 'starter', 'requested' => false])
        ->and($payload['encryption_key']['version'] ?? null)->toBe(1)
        ->and($payload['storage'] ?? null)->toMatchArray(['area' => 'auth', 'created' => true])
        // The step names are the per-tenant record of *which* pipeline created it — the
        // evidence that a tenant provisioned before task 10.1 never had a wallet step.
        ->and($payload['steps'] ?? null)->toBe([
            'tenant.record',
            'plan.seed',
            'owner.membership',
            'tier.assignment',
            'encryption.dek',
            'storage.prefix',
            'audit.entry',
        ]);
});

it('starts the tenant on TRIAL with trial_ends_at taken from wa.tenancy.trial_days', function (): void {
    config()->set('wa.tenancy.trial_days', 5);

    $tenant = Lifecycle::service()->provision(['name' => 'Five Day Co']);

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->trial_ends_at?->toDateString())->toBe(now()->addDays(5)->toDateString())
        // ...and it is the state machine's entry point, so every other state is reachable.
        ->and($tenant->status->allowedNext())->toBe([TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Cancelled]);
});

it('applies the configured timezone and locale defaults, and accepts overrides', function (): void {
    config()->set('wa.tenancy.default_timezone', 'Europe/Berlin');
    config()->set('wa.tenancy.default_locale', 'de');

    $defaulted = Lifecycle::service()->provision(['name' => 'Default Co']);
    $override = Lifecycle::service()->provision([
        'name' => 'Override Co',
        'timezone' => 'Asia/Kolkata',
        'locale' => 'en_IN',
    ]);

    expect($defaulted->timezone)->toBe('Europe/Berlin')
        ->and($defaulted->locale)->toBe('de')
        ->and($override->timezone)->toBe('Asia/Kolkata')
        ->and($override->locale)->toBe('en_IN');
});

it('seeds an explicitly requested plan instead of the default', function (): void {
    $tenant = Lifecycle::service()->provision(['name' => 'Growth Co', 'plan' => 'growth']);

    expect($tenant->plan_id)->toBe(Plan::query()->where('slug', 'growth')->sole()->id)
        ->and(Lifecycle::trail($tenant)->sole()->payload['plan'] ?? null)
        ->toMatchArray(['slug' => 'growth', 'requested' => true]);
});

/*
|--------------------------------------------------------------------------
| Slug / subdomain normalisation and uniqueness (Req 9.3 / A9)
|--------------------------------------------------------------------------
*/

it('normalises names, slugs and subdomains into DNS labels', function (array $spec, string $slug, ?string $subdomain): void {
    $tenant = Lifecycle::service()->provision($spec);

    expect($tenant->slug)->toBe($slug)
        ->and($tenant->subdomain)->toBe($subdomain);
})->with([
    'slug derived from the name' => [['name' => 'Acme Corp'], 'acme-corp', 'acme-corp'],
    'accents folded, punctuation dropped' => [['name' => '  Açme Corp!!  '], 'acme-corp', 'acme-corp'],
    'underscores and case in an explicit slug' => [['name' => 'Acme', 'slug' => 'Acme_Corp'], 'acme-corp', 'acme-corp'],
    'an explicit subdomain is lower-cased' => [['name' => 'Acme', 'subdomain' => 'ACME-EU'], 'acme', 'acme-eu'],
    'an explicit null subdomain is honoured' => [['name' => 'Api Only', 'subdomain' => null], 'api-only', null],
]);

it('refuses a slug or subdomain another tenant already owns', function (): void {
    Lifecycle::service()->provision(['name' => 'Acme Corp']);

    // Same name → same derived slug.
    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Acme Corp']))
        ->toThrow(TenantAlreadyExistsException::class);

    // Different name, but claiming the taken host.
    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Other Co', 'subdomain' => 'acme-corp']))
        ->toThrow(TenantAlreadyExistsException::class);

    expect(Tenant::query()->count())->toBe(1);
});

it('refuses a label that is already an existing tenant implicit host', function (): void {
    // A tenant with no explicit subdomain is still reachable at `{slug}.{apex}` through
    // SubdomainTenantResolver's fallback, so its slug is not free to take as a subdomain
    // even though both unique indexes would allow it.
    Tenant::factory()->create(['slug' => 'legacy', 'subdomain' => null]);

    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'New Co', 'subdomain' => 'legacy']))
        ->toThrow(TenantAlreadyExistsException::class, 'already the host');

    expect(Tenant::query()->where('name', 'New Co')->exists())->toBeFalse();
});

it('refuses a reserved platform label', function (string $label): void {
    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Squatter', 'subdomain' => $label]))
        ->toThrow(InvalidProvisioningSpecException::class, 'reserved');
})->with(['admin', 'api', 'www', 'billing']);

/*
|--------------------------------------------------------------------------
| Idempotency: provisioning is a create, and stays one
|--------------------------------------------------------------------------
*/

it('never hands back an existing tenant on a repeat call', function (): void {
    $first = Lifecycle::service()->provision(['name' => 'Acme Corp']);

    // The tempting "idempotent" reading — return the tenant that is already there — would
    // hand a caller somebody else's workspace, because a slug collision is far more often
    // two customers picking the same company name than one customer retrying.
    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Acme Corp']))
        ->toThrow(TenantAlreadyExistsException::class);

    expect(Tenant::query()->count())->toBe(1)
        ->and($first->fresh()?->status)->toBe(TenantStatus::Trial)
        ->and(Lifecycle::trail($first))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Atomicity — the word Req 1.8 turns on
|--------------------------------------------------------------------------
*/

it('leaves nothing behind when a step fails: no tenant, no plan link, no key, no directory, no audit entry', function (): void {
    ProvisioningSpy::append([SpyProvisioningStep::class, FailingProvisioningStep::class]);

    $owner = User::factory()->create();
    $tenantsBefore = Tenant::query()->count();

    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Doomed Co', 'owner' => $owner]))
        ->toThrow(RuntimeException::class, FailingProvisioningStep::MESSAGE);

    expect(Tenant::query()->count())->toBe($tenantsBefore)
        ->and(Tenant::query()->where('slug', 'doomed-co')->exists())->toBeFalse()
        ->and(TenantUser::query()->where('user_id', $owner->getKey())->exists())->toBeFalse()
        ->and(EncryptionKey::withoutTenantScope()->count())->toBe(0)
        ->and(AuditLog::withoutTenantScope()->where('action', 'tenant.provisioned')->count())->toBe(0);

    // The filesystem is the half a ROLLBACK cannot reach, so it is compensated
    // explicitly — nothing is left under the tenant prefix on any area disk.
    $disk = Storage::disk(StorageArea::AuthState->disk());
    expect($disk->directories('tenants'))->toBe([])
        ->and($disk->allFiles('tenants'))->toBe([]);
});

it('compensates the steps that ran in exact reverse order', function (): void {
    ProvisioningSpy::append([SpyProvisioningStep::class, FailingProvisioningStep::class]);

    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Doomed Co']))
        ->toThrow(RuntimeException::class);

    // The failing step is compensated too — it is marked started *before* it runs,
    // because a step that threw halfway is precisely the one with a partial effect.
    expect(ProvisioningSpy::rollbacks())->toBe([
        FailingProvisioningStep::NAME,
        SpyProvisioningStep::NAME,
    ]);
});

it('still provisions the next tenant successfully after a failed attempt', function (): void {
    ProvisioningSpy::append([FailingProvisioningStep::class]);

    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Acme Corp']))
        ->toThrow(RuntimeException::class);

    // Nothing was left holding the label, so the retry is a clean create rather than a
    // conflict against a half-provisioned ghost.
    config()->set('wa.tenancy.provisioning.steps', array_values(array_filter(
        (array) config('wa.tenancy.provisioning.steps'),
        static fn (mixed $step): bool => $step !== FailingProvisioningStep::class,
    )));

    $tenant = Lifecycle::service()->provision(['name' => 'Acme Corp']);

    expect($tenant->slug)->toBe('acme-corp')
        ->and(authStateDirectoryExists($tenant))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Failing loudly rather than provisioning something unusable
|--------------------------------------------------------------------------
*/

it('refuses to provision a tenant when no default plan has been seeded', function (): void {
    Plan::query()->delete();

    try {
        Lifecycle::service()->provision(['name' => 'Planless Co']);
        thisTest()->fail('Provisioning without a seed plan must throw.');
    } catch (SeedPlanUnavailableException $exception) {
        expect($exception->slug)->toBe('starter')
            ->and($exception->requestedExplicitly)->toBeFalse()
            ->and($exception->getStatusCode())->toBe(503)
            ->and($exception->getMessage())->toContain('PlanSeeder');
    }

    // A tenant with no plan is gated to no features and no allowance, so it would look
    // like a broken account rather than a failed signup — nothing is created.
    expect(Tenant::query()->where('slug', 'planless-co')->exists())->toBeFalse()
        ->and(EncryptionKey::withoutTenantScope()->count())->toBe(0)
        ->and(Storage::disk(StorageArea::AuthState->disk())->directories('tenants'))->toBe([]);
});

it('refuses a plan slug that does not exist', function (): void {
    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Acme', 'plan' => 'enterprise-xl']))
        ->toThrow(SeedPlanUnavailableException::class);

    expect(Tenant::query()->where('slug', 'acme')->exists())->toBeFalse();
});

it('refuses an owner that does not exist rather than creating an unreachable tenant', function (): void {
    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Ghost Co', 'owner' => 9_999]))
        ->toThrow(InvalidProvisioningSpecException::class);

    expect(Tenant::query()->where('slug', 'ghost-co')->exists())->toBeFalse();
});

it('creates no membership row when the spec names no owner — the admin-created tenant', function (): void {
    $tenant = Lifecycle::service()->provision(['name' => 'Admin Made Co']);

    // Inventing a placeholder owner would be worse than an empty table: it would be an
    // account with no owner that *looks* owned. The invitation flow writes the row later.
    $payload = Lifecycle::trail($tenant)->sole()->payload;

    expect(TenantUser::query()->where('tenant_id', $tenant->id)->count())->toBe(0)
        // Recorded as an explicit null rather than omitted: "this tenant has no owner yet"
        // is a fact worth having in the trail, and it is not the same fact as "the owner
        // step did not run".
        ->and(array_key_exists('owner', $payload))->toBeTrue()
        ->and($payload['owner'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Spec validation — refused, never repaired
|--------------------------------------------------------------------------
*/

it('rejects an unusable spec instead of guessing', function (array $spec, string $fragment): void {
    expect(fn (): Tenant => Lifecycle::service()->provision($spec))
        ->toThrow(InvalidProvisioningSpecException::class, $fragment);

    expect(Tenant::query()->count())->toBe(0);
})->with([
    'no name' => [[], 'requires a non-empty "name"'],
    'blank name' => [['name' => '   '], 'requires a non-empty "name"'],
    // A typo that is ignored provisions a tenant that looks correct and is not.
    'unknown key' => [['name' => 'Acme', 'timzone' => 'UTC'], 'Unknown tenant provisioning spec key'],
    'unknown timezone' => [['name' => 'Acme', 'timezone' => 'Mars/Olympus'], 'not a known IANA timezone'],
    'malformed locale' => [['name' => 'Acme', 'locale' => 'not a locale'], 'not a well-formed locale'],
    'both trial keys' => [['name' => 'Acme', 'trial_days' => 5, 'trial_ends_at' => '2030-01-01'], 'both "trial_days" and "trial_ends_at"'],
    'negative trial' => [['name' => 'Acme', 'trial_days' => -1], 'non-negative integer'],
    'unknown tier' => [['name' => 'Acme', 'tier' => 'PLATINUM'], 'not one of the isolation tiers'],
    'lane weight without a tier' => [['name' => 'Acme', 'lane_weight' => 4], 'without naming a "tier"'],
    'zero lane weight' => [['name' => 'Acme', 'tier' => 'SHARED', 'lane_weight' => 0], 'at least'],
    'slug that normalises to nothing' => [['name' => 'Acme', 'slug' => '!!!'], 'nothing usable'],
]);

it('accepts an explicit trial window, including an open-ended one', function (): void {
    $dated = Lifecycle::service()->provision(['name' => 'Dated Co', 'trial_ends_at' => '2030-06-01 00:00:00']);
    $openEnded = Lifecycle::service()->provision(['name' => 'Open Co', 'trial_ends_at' => null]);

    expect($dated->trial_ends_at?->toDateString())->toBe('2030-06-01')
        ->and($openEnded->trial_ends_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Tier assignment (Req 1.6 / A1)
|--------------------------------------------------------------------------
*/

it('writes a tenant_tiers row only when the spec asks for something other than the default', function (): void {
    $shared = Lifecycle::service()->provision(['name' => 'Shared Co', 'tier' => TenantTier::Shared]);
    $dedicated = Lifecycle::service()->provision([
        'name' => 'Dedicated Co',
        'tier' => TenantTier::DedicatedWorker,
        'lane_weight' => 7,
        'data_region' => 'eu-central',
    ]);

    // A row restating the configured default would pin the tenant to today's value, so
    // flipping `wa.tenancy.tiers.default` later would move nobody.
    expect(TenantTierAssignment::forTenant($shared)->exists())->toBeFalse()
        ->and(app(App\Services\Tenancy\TierResolver::class)->tierOf($shared))->toBe(TenantTier::Shared);

    $row = TenantTierAssignment::forTenant($dedicated)->sole();
    expect($row->tier)->toBe(TenantTier::DedicatedWorker)
        ->and($row->lane_weight)->toBe(7)
        ->and($row->data_region)->toBe('eu-central')
        ->and(app(App\Services\Tenancy\TierResolver::class)->laneWeight($dedicated))->toBe(7);
});

it('writes a tenant_tiers row for a pinned weight even on the default tier', function (): void {
    $tenant = Lifecycle::service()->provision([
        'name' => 'Throttled Co',
        'tier' => TenantTier::Shared,
        'lane_weight' => 2,
    ]);

    expect(TenantTierAssignment::forTenant($tenant)->sole()->lane_weight)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Context: provisioning happens before any tenant exists
|--------------------------------------------------------------------------
*/

it('provisions with no tenant context bound, and binds none as a side effect', function (): void {
    expect(Lifecycle::tenancy()->current())->toBeNull();

    $tenant = Lifecycle::service()->provision(['name' => 'Console Co']);

    // Every read and write inside the pipeline names its tenant explicitly, so nothing
    // needed a context — and provisioning must not leave one bound behind either: it runs
    // inside a signup request that is still acting as nobody.
    expect(Lifecycle::tenancy()->current())->toBeNull()
        ->and(Lifecycle::tenancy()->actingAsPlatform())->toBeFalse()
        ->and($tenant->status)->toBe(TenantStatus::Trial)
        ->and(EncryptionKey::activeFor($tenant->id, KeyPurpose::Field))->not->toBeNull();
});

it('provisions from platform mode without moving the audit entry off the tenant chain', function (): void {
    $tenant = Lifecycle::tenancy()->asPlatform('admin creates a tenant', function (): Tenant {
        return Lifecycle::service()->provision(['name' => 'Admin Created Co']);
    });

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and(Lifecycle::trail($tenant)->sole()->action)->toBe('tenant.provisioned')
        // The chain is named explicitly, so a new tenant's first event lands on its own
        // history rather than on the platform chain.
        ->and(Lifecycle::platformTrail())->toHaveCount(0);
});

it('refuses to provision from inside another tenant context, and rolls the attempt back', function (): void {
    $acting = Tenant::factory()->create();
    Lifecycle::tenancy()->set($acting);

    // Task 0.4's ownership guard refuses to attribute a new row to a tenant other than the
    // acting one — forging attribution is exactly what it exists to stop, and it cannot
    // tell "an admin creating an account" from "a tenant writing into another tenant".
    // The sanctioned door is the audited platform mode (the test above), or no context at
    // all (self-service registration).
    expect(fn (): Tenant => Lifecycle::service()->provision(['name' => 'Forged Co']))
        ->toThrow(App\Exceptions\Tenancy\CrossTenantAccessException::class);

    // ...and the refusal is atomic like any other failure: the tenant row and its plan
    // association went in before the guard fired, and both are gone.
    expect(Tenant::query()->where('slug', 'forged-co')->exists())->toBeFalse()
        ->and(Lifecycle::trail($acting))->toHaveCount(0)
        ->and(Storage::disk(StorageArea::AuthState->disk())->directories('tenants'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The provisioned tenant is a first-class citizen of the state machine
|--------------------------------------------------------------------------
*/

it('hands back a tenant the rest of the lifecycle can drive', function (): void {
    $tenant = Lifecycle::service()->provision(['name' => 'Lifecycle Co']);

    Lifecycle::service()->activate($tenant, 'card added');
    Lifecycle::service()->suspend($tenant, 'chargeback');

    expect($tenant->fresh()?->status)->toBe(TenantStatus::Suspended)
        ->and(Lifecycle::service()->canSendOutbound($tenant))->toBeFalse()
        ->and(Lifecycle::trail($tenant)->pluck('action')->all())->toBe([
            'tenant.provisioned',
            'tenant.activated',
            'tenant.suspended',
        ]);
});
