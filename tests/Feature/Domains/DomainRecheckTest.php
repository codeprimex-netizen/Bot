<?php

declare(strict_types=1);

use App\Enums\DomainVerificationFailure;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Tests\Fixtures\Domains\DomainWorld;
use Tests\Fixtures\Domains\FakeDomainProbe;

/*
|--------------------------------------------------------------------------
| Scheduled re-validation of verified custom domains (Req 9.7 / A9)
|--------------------------------------------------------------------------
| A verification is a statement about one instant. The sweep re-runs both halves —
| the standing ownership challenge and the certificate — so a domain that changes
| hands is caught, not just one whose certificate lapsed.
*/

beforeEach(function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);
    config()->set('wa.tenancy.domains.recheck.interval_hours', 24);
});

/**
 * Age a domain's evidence so the sweep considers it stale.
 */
function age(TenantDomain $domain, int $days = 3): TenantDomain
{
    $domain->forceFill(['last_checked_at' => now()->subDays($days)])->save();

    return $domain;
}

it('re-checks only domains whose evidence is stale', function (): void {
    $stale = DomainWorld::create('stale.acme.example')->verify();
    $fresh = DomainWorld::create('fresh.acme.example')->verify();

    // Both worlds bind their own probe; the last one wins, so re-satisfy the first.
    $stale->satisfyChallenge()->satisfyTls();

    age($stale->domain);
    $fresh->domain->forceFill(['last_checked_at' => now()->subHour()])->save();
    $freshCheckedAt = $fresh->stored()->last_checked_at?->toIso8601String();

    thisTest()->artisan('wa:domains:recheck')->assertSuccessful();

    expect($fresh->stored()->last_checked_at?->toIso8601String())->toBe($freshCheckedAt)
        ->and($stale->stored()->last_checked_at?->greaterThan(now()->subMinute()))->toBeTrue()
        ->and($stale->stored()->isVerified())->toBeTrue();
});

it('revokes a domain whose proof has gone, and says so', function (): void {
    $world = DomainWorld::create('gone.acme.example')->verify();
    age($world->domain);

    $world->probe->withdrawCertificate('gone.acme.example');

    thisTest()->artisan('wa:domains:recheck')
        ->expectsOutputToContain('Revoked gone.acme.example')
        ->assertSuccessful();

    expect($world->stored()->isVerified())->toBeFalse()
        ->and($world->stored()->last_failure_reason)->toBe(DomainVerificationFailure::TlsUnavailable);
});

it('changes nothing at all when the platform cannot reach the network', function (): void {
    $world = DomainWorld::create('unreachable.acme.example')->verify();
    age($world->domain);

    $world->probe->unavailable();

    thisTest()->artisan('wa:domains:recheck')->assertSuccessful();

    expect($world->stored()->isVerified())->toBeTrue()
        ->and($world->stored()->last_failure_reason)->toBe(DomainVerificationFailure::ProbeUnavailable);
});

it('never touches an unverified claim', function (): void {
    FakeDomainProbe::bind();
    $tenant = Tenant::factory()->create();
    $claim = TenantDomain::factory()->for($tenant, 'tenant')->create(['host' => 'pending.acme.example']);

    thisTest()->artisan('wa:domains:recheck')->assertSuccessful();

    expect($claim->fresh()?->last_checked_at)->toBeNull()
        ->and($claim->fresh()?->isVerified())->toBeFalse();
});

it('bounds the work per run', function (): void {
    foreach (['a', 'b', 'c'] as $label) {
        age(DomainWorld::create($label.'.acme.example')->verify()->domain);
    }

    thisTest()->artisan('wa:domains:recheck', ['--batch' => 1])->assertSuccessful();

    expect(TenantDomain::query()->where('last_checked_at', '>', now()->subMinute())->count())->toBe(1);
});

it('re-checks one named host on demand, ignoring staleness', function (): void {
    $world = DomainWorld::create('named.acme.example')->verify();

    $world->probe->withdrawCertificate('named.acme.example');

    thisTest()->artisan('wa:domains:recheck', ['--host' => 'Named.Acme.Example.'])->assertSuccessful();

    expect($world->stored()->isVerified())->toBeFalse();
});

it('rejects a nonsense batch size rather than guessing', function (): void {
    thisTest()->artisan('wa:domains:recheck', ['--batch' => '0'])->assertExitCode(2);
});
