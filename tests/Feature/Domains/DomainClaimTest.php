<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\HostUnavailableException;
use App\Exceptions\Url\InvalidBaseUrlException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\DomainRegistrar;

/*
|--------------------------------------------------------------------------
| Custom-domain claims: collision and reserved-name rejection (Req 9.7 / A9)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config()->set('wa.tenancy.apexes', ['app.example.test']);
});

function registrar(): DomainRegistrar
{
    return app(DomainRegistrar::class);
}

it('records a claim unverified, so it cannot be used before it is proven', function (): void {
    $tenant = Tenant::factory()->create();

    $domain = registrar()->claim($tenant, 'Chat.Acme.Example.');

    expect($domain->host)->toBe('chat.acme.example')
        ->and($domain->isVerified())->toBeFalse()
        ->and($domain->canonicalHost())->toBeNull()
        ->and(TenantDomain::canonicalHostFor($tenant->id))->toBeNull();
});

it('audits the claim, because it changes where the tenant\'s links will point', function (): void {
    $tenant = Tenant::factory()->create();

    registrar()->claim($tenant, 'chat.acme.example');

    $entry = AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.claimed')->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->tenant_id)->toBe($tenant->id);
});

it('returns the same tenant\'s existing claim instead of duplicating it', function (): void {
    $tenant = Tenant::factory()->create();

    $first = registrar()->claim($tenant, 'chat.acme.example');
    $second = registrar()->claim($tenant, 'CHAT.acme.example');

    expect($second->id)->toBe($first->id)
        ->and(TenantDomain::query()->count())->toBe(1);
});

it('refuses a host another tenant already claimed, verified or not', function (bool $verified): void {
    $holder = Tenant::factory()->create();
    $claimant = Tenant::factory()->create();

    $state = TenantDomain::factory()->for($holder, 'tenant');
    ($verified ? $state->verified() : $state)->create(['host' => 'chat.acme.example']);

    expect(fn (): TenantDomain => registrar()->claim($claimant, 'chat.acme.example'))
        ->toThrow(HostUnavailableException::class);
})->with([
    'verified holder' => [true],
    'pending holder' => [false],
]);

it('explains the refusal without naming the tenant that holds the host', function (): void {
    $holder = Tenant::factory()->create(['name' => 'Holder Industries', 'slug' => 'holder-industries']);
    $claimant = Tenant::factory()->create();

    TenantDomain::factory()->for($holder, 'tenant')->verified()->create(['host' => 'chat.acme.example']);

    try {
        registrar()->claim($claimant, 'chat.acme.example');

        $this->fail('The claim should have been refused.');
    } catch (HostUnavailableException $e) {
        // Both the public sentence and the internal message must be free of the holder's
        // identity: the internal one ends up in logs, which are read by more people than
        // the API response is.
        expect($e->reason)->toBe('already_in_use')
            ->and($e->host)->toBe('chat.acme.example')
            ->and($e->publicMessage())->toBe(HostUnavailableException::PUBLIC_MESSAGE)
            ->and($e->publicMessage())->not->toContain('Holder')
            ->and($e->getMessage())->not->toContain($holder->id)
            ->and($e->getMessage())->not->toContain('holder-industries')
            ->and($e->getMessage())->not->toContain('Holder');
    }
});

it('refuses a platform apex, a reserved label under it, and any host inside it', function (
    string $host,
    string $reason,
): void {
    $tenant = Tenant::factory()->create();

    try {
        registrar()->claim($tenant, $host);

        $this->fail(sprintf('Host [%s] should have been refused.', $host));
    } catch (HostUnavailableException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->publicMessage())->toBe(HostUnavailableException::PUBLIC_MESSAGE);
    }
})->with([
    'the apex itself' => ['app.example.test', 'platform_apex'],
    'a reserved label' => ['admin.app.example.test', 'reserved'],
    'another reserved label' => ['api.app.example.test', 'reserved'],
    'an issued tenant host' => ['acme.app.example.test', 'tenant_subdomain'],
    'a nested platform host' => ['deep.nested.app.example.test', 'tenant_subdomain'],
]);

it('refuses a malformed host as malformed rather than as unavailable', function (string $host): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): TenantDomain => registrar()->claim($tenant, $host))
        ->toThrow(InvalidBaseUrlException::class);
})->with([
    'a port' => ['chat.acme.example:8443'],
    'a scheme' => ['https://chat.acme.example'],
    'a CRLF payload' => ["chat.acme.example\r\nX-Injected: 1"],
    'empty' => ['   '],
]);

it('answers the availability question without throwing', function (): void {
    $holder = Tenant::factory()->create();
    TenantDomain::factory()->for($holder, 'tenant')->create(['host' => 'taken.acme.example']);

    expect(registrar()->isAvailable('free.acme.example'))->toBeTrue()
        ->and(registrar()->isAvailable('taken.acme.example'))->toBeFalse()
        ->and(registrar()->isAvailable('admin.app.example.test'))->toBeFalse()
        ->and(registrar()->isAvailable('app.example.test'))->toBeFalse()
        ->and(registrar()->isAvailable('not a host'))->toBeFalse();
});

it('frees the host again when a claim is released', function (): void {
    $holder = Tenant::factory()->create();
    $claimant = Tenant::factory()->create();

    $domain = TenantDomain::factory()->for($holder, 'tenant')->verified()->create([
        'host' => 'chat.acme.example',
    ]);

    registrar()->release($domain);

    expect(registrar()->isAvailable('chat.acme.example'))->toBeTrue()
        ->and(registrar()->claim($claimant, 'chat.acme.example')->tenant_id)->toBe($claimant->id)
        ->and(AuditLog::withoutTenantScope()->where('action', '=', 'tenant.domain.released')->exists())->toBeTrue();
});

it('stops using a released domain for URL generation immediately', function (): void {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->for($tenant, 'tenant')->verified()->create([
        'host' => 'chat.acme.example',
    ]);

    expect(TenantDomain::canonicalHostFor($tenant->id))->toBe('chat.acme.example');

    registrar()->release($domain);

    expect(TenantDomain::canonicalHostFor($tenant->id))->toBeNull();
});
