<?php

declare(strict_types=1);

use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Enums\TenantStatus;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelCredentialValidator;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\DefaultChannelRouter;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Command\Command;
use Tests\Fixtures\Channel\ProbeChannelDriver;

/*
|--------------------------------------------------------------------------
| wa:channel:revalidate (Req 8.6, 8.13 / A8)
|--------------------------------------------------------------------------
| The command owns *which* sets to ask about and how to report the answers; every decision is
| the validator's. So what is asserted here is the operator contract:
|
|   1. `--dry-run` contacts nothing;
|   2. a refused set is taken out of service and reported as such;
|   3. a set that cannot be reached is reported as skipped and left exactly as it was — the
|      property that makes this safe to run during a provider incident;
|   4. a suspended tenant is not probed at all;
|   5. bad options are refused rather than coerced;
|   6. nothing it prints is a secret.
*/

beforeEach(function (): void {
    Cache::flush();
    ProbeChannelDriver::reset();

    $router = new DefaultChannelRouter(
        app(TenantContext::class),
        app(ChannelCredentialStore::class),
        app(PlanGate::class),
        ProbeChannelDriver::registry(...ChannelMode::cases()),
    );

    app()->instance(ChannelRouter::class, $router);
});

/**
 * A tenant with one verified `CLOUD_API` set.
 */
function revalidateTenant(TenantStatus $status = TenantStatus::Active, string $token = 'EAAG-command-token'): Tenant
{
    $tenant = Tenant::factory()->create(['status' => $status]);

    app(TenantContext::class)->runFor($tenant, fn (): Session => Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::CloudApi,
    ]));

    /** @var ChannelCredentialValidator $validator */
    $validator = app(ChannelCredentialValidator::class);

    $validator->save($tenant, ChannelMode::CloudApi, [
        'access_token' => $token,
    ], ['phone_number_id' => '5678']);

    return $tenant;
}

function revalidateStore(): ChannelCredentialStore
{
    /** @var ChannelCredentialStore $store */
    $store = app(ChannelCredentialStore::class);

    return $store;
}

it('lists what is due without contacting a provider', function (): void {
    $tenant = revalidateTenant();
    $probesBefore = count(ProbeChannelDriver::probed(ChannelMode::CloudApi));

    thisTest()->artisan('wa:channel:revalidate', ['--dry-run' => true])
        ->expectsOutputToContain($tenant->id)
        ->expectsOutputToContain('due')
        ->assertExitCode(Command::SUCCESS);

    expect(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toHaveCount($probesBefore);
});

it('re-stamps a set the driver still accepts', function (): void {
    $tenant = revalidateTenant();

    thisTest()->travelTo(now()->addHour());

    thisTest()->artisan('wa:channel:revalidate')
        ->expectsOutputToContain('ok')
        ->expectsOutputToContain('verified')
        ->assertExitCode(Command::SUCCESS);

    thisTest()->travelBack();

    $row = revalidateStore()->rowFor($tenant, ChannelMode::CloudApi);

    expect($row?->status)->toBe(ChannelCredentialStatus::Active)
        ->and($row?->isVerified())->toBeTrue();
});

it('takes a refused set out of service and says why, without printing the secret', function (): void {
    $tenant = revalidateTenant(token: 'EAAG-revoked-secret-value');
    ProbeChannelDriver::refuse(ChannelMode::CloudApi, 'Meta rejected the access token.');

    thisTest()->artisan('wa:channel:revalidate')
        // The reason first and the outcome word second, deliberately: each expected substring is
        // satisfied by one written line, and the row's own line contains both — so asking for
        // the reason first leaves the tally line to satisfy `rejected`.
        ->expectsOutputToContain('Meta')
        ->expectsOutputToContain('rejected')
        ->doesntExpectOutputToContain('EAAG-revoked-secret-value')
        // A tenant's broken credential set is not a broken sweep: a non-zero code would be read
        // by a scheduler as "this command is failing".
        ->assertExitCode(Command::SUCCESS);

    expect(revalidateStore()->all($tenant, ChannelMode::CloudApi)[0]->status)
        ->toBe(ChannelCredentialStatus::Invalid)
        ->and(revalidateStore()->rowFor($tenant, ChannelMode::CloudApi))->toBeNull();
});

it('changes nothing when the provider cannot be reached', function (): void {
    $tenant = revalidateTenant();
    $before = revalidateStore()->rowFor($tenant, ChannelMode::CloudApi);

    ProbeChannelDriver::failProbe(ChannelMode::CloudApi, BridgeUnreachableException::transportFailed('probe'));

    thisTest()->artisan('wa:channel:revalidate')
        ->expectsOutputToContain('skipped')
        ->assertExitCode(Command::SUCCESS);

    $after = revalidateStore()->rowFor($tenant, ChannelMode::CloudApi);

    expect($after?->status)->toBe(ChannelCredentialStatus::Active)
        ->and($after?->verified_at?->toIso8601String())->toBe($before?->verified_at?->toIso8601String());
});

it('does not probe a tenant that is not allowed to send', function (): void {
    revalidateTenant(TenantStatus::Suspended);
    $probesBefore = count(ProbeChannelDriver::probed(ChannelMode::CloudApi));

    thisTest()->artisan('wa:channel:revalidate')
        ->expectsOutputToContain('tenant not operational')
        ->assertExitCode(Command::SUCCESS);

    expect(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toHaveCount($probesBefore);
});

it('checks only the tenant, mode and label it was given', function (): void {
    $acme = revalidateTenant();
    revalidateTenant();

    thisTest()->artisan('wa:channel:revalidate', [
        '--tenant' => $acme->id,
        '--mode' => 'cloud_api',
        '--label' => 'default',
    ])->assertExitCode(Command::SUCCESS);

    // Two tenants are configured; only one was asked about, so exactly one probe was added to
    // the two the two `save()` calls made.
    expect(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toHaveCount(3);
});

it('reports nothing to do rather than pretending to work', function (): void {
    thisTest()->artisan('wa:channel:revalidate')
        ->expectsOutputToContain('No channel credential sets are due')
        ->assertExitCode(Command::SUCCESS);
});

it('refuses a limit or a mode it cannot read, instead of guessing', function (): void {
    revalidateTenant();

    thisTest()->artisan('wa:channel:revalidate', ['--limit' => 'abc'])
        ->assertExitCode(Command::INVALID);

    thisTest()->artisan('wa:channel:revalidate', ['--limit' => '0'])
        ->assertExitCode(Command::INVALID);

    thisTest()->artisan('wa:channel:revalidate', ['--mode' => 'CLOUD_APIS'])
        ->assertExitCode(Command::INVALID);
});
