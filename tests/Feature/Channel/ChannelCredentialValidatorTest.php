<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\AuditLog;
use App\Models\ChannelCredential;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelCredentialValidator;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\DefaultChannelRouter;
use App\Services\Channel\TextContent;
use App\Services\Tenancy\PlanGate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Channel\FakeChannelDriver;
use Tests\Fixtures\Channel\ProbeChannelDriver;

/*
|--------------------------------------------------------------------------
| ChannelCredentialValidator (Req 8.6, 8.13 / A8)
|--------------------------------------------------------------------------
| Req 8.6 is two promises in one sentence — validate *before* activating, and on failure
| retain the previous working credentials — and both are about states the platform must
| never be observed in. So the assertions here are mostly about what did **not** happen:
|
|   1. a refused rotation writes nothing: same row, same secrets, same `verified_at` — and
|      the tenant can still send, which is the only observation that actually matters;
|   2. the accepted path activates by stamping `verified_at`, and drops both memos, so the
|      new set serves the very next resolution and the previous one is retained, not deleted;
|   3. *missing* and *rejected* are distinguishable without reading a sentence (Req 8.13),
|      because the two remedies are "enter credentials" and "re-issue a token";
|   4. a probe that could not be **made** changes nothing at all — one provider outage must
|      not take every tenant's credentials out of service;
|   5. no reroute: a rejected official mode leaves the tenant's Baileys sessions sending and
|      the official one refusing — never the official session's traffic on the bridge;
|   6. cross-tenant refusal happens before a driver is ever constructed;
|   7. nothing that leaves this class — exception, audit row, console line — carries a secret,
|      including when the provider quotes the token back at us.
*/

beforeEach(function (): void {
    Cache::flush();
    ProbeChannelDriver::reset();
});

/*
|--------------------------------------------------------------------------
| Wiring
|--------------------------------------------------------------------------
*/

/**
 * A router wired as `ChannelServiceProvider` wires the real one, with an arrangeable probe
 * driver per mode, and bound into the container so the validator resolves *this* one.
 */
function validatorRouter(ChannelMode ...$modes): DefaultChannelRouter
{
    $router = new DefaultChannelRouter(
        app(TenantContext::class),
        app(ChannelCredentialStore::class),
        app(PlanGate::class),
        ProbeChannelDriver::registry(...($modes === [] ? ChannelMode::cases() : $modes)),
    );

    app()->instance(ChannelRouter::class, $router);

    return $router;
}

function validatorStore(): ChannelCredentialStore
{
    /** @var ChannelCredentialStore $store */
    $store = app(ChannelCredentialStore::class);

    return $store;
}

function channelValidator(): ChannelCredentialValidator
{
    /** @var ChannelCredentialValidator $validator */
    $validator = app(ChannelCredentialValidator::class);

    return $validator;
}

/**
 * A tenant with a session on `$mode`, and nothing configured.
 *
 * @return array{Tenant, Session}
 */
function validatorTenant(ChannelMode $mode = ChannelMode::CloudApi): array
{
    $tenant = Tenant::factory()->create();

    $session = app(TenantContext::class)->runFor($tenant, fn (): Session => Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => $mode,
    ]));

    return [$tenant, $session];
}

/**
 * A tenant whose `CLOUD_API` set is stored and verified — the "previous working credentials"
 * every retention assertion is about.
 *
 * @return array{Tenant, Session}
 */
function validatorTenantWithWorkingCloudApi(string $token = 'EAAG-working-token'): array
{
    [$tenant, $session] = validatorTenant();

    channelValidator()->save($tenant, ChannelMode::CloudApi, [
        'access_token' => $token,
        'app_secret' => 'app-secret-value',
    ], [
        'waba_id' => '1001',
        'phone_number_id' => '5678',
    ]);

    // The clock is deliberately **not** advanced. `verified_at` and `created_at` are both
    // second-resolution, so a rotation validated in the same second as this set ties on every
    // ordering column — and the store now breaks that tie on the ULID primary key, which is
    // monotonic. So the rotation assertions below run at the timing a real rotation actually
    // has (a candidate stamped the moment a healthy provider answers, well inside the second
    // the previous set was verified in) rather than at a second's remove from it, and a
    // regression in that tie-break fails here as well as in `ChannelCredentialStoreTest`.
    return [$tenant, $session];
}

/**
 * Every audit payload written so far, as one string — what a "no secret anywhere" assertion
 * reads.
 */
function auditedText(): string
{
    return (string) json_encode(
        AuditLog::withoutTenantScope()->get()->map(fn (AuditLog $log): array => [
            'action' => $log->action,
            'payload' => $log->payload,
        ])->all(),
    );
}

/*
|--------------------------------------------------------------------------
| The accepted path
|--------------------------------------------------------------------------
*/

it('activates a first credential set only after the driver confirms it', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();

    $validation = channelValidator()->save($tenant, ChannelMode::CloudApi, [
        'access_token' => 'EAAG-first',
    ], [
        'waba_id' => '1001',
        'phone_number_id' => '5678',
    ]);

    $row = $validation->credential->refresh();

    expect($validation->firstSet)->toBeTrue()
        ->and($validation->label())->toBe(ChannelCredential::DEFAULT_LABEL)
        ->and($validation->hasRetainedSet())->toBeFalse()
        // Activation *is* the `verified_at` stamp, and it carries the probe's own timestamp.
        ->and($row->status)->toBe(ChannelCredentialStatus::Active)
        ->and($row->isVerified())->toBeTrue()
        ->and($row->verified_at?->toIso8601String())->toBe($validation->verifiedAt()->toIso8601String())
        // The driver was asked exactly once, with the material that is now stored.
        ->and(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toBe([['access_token' => 'EAAG-first']])
        ->and(validatorStore()->for($tenant, ChannelMode::CloudApi)?->secret('access_token'))->toBe('EAAG-first')
        // No session was named, so no registration was attempted — that is task 9.1's call.
        ->and(ProbeChannelDriver::registered(ChannelMode::CloudApi))->toBe([])
        ->and($validation->isNumberLive())->toBeTrue();
});

it('records an accepted attempt with the field names and no value', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();

    channelValidator()->save($tenant, ChannelMode::CloudApi, [
        'access_token' => 'EAAG-audited-token',
    ], ['phone_number_id' => '5678']);

    $entry = AuditLog::withoutTenantScope()
        ->where('action', ChannelCredentialValidator::ACCEPTED_ACTION)
        ->firstOrFail();

    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->payload['outcome'] ?? null)->toBe('accepted')
        ->and($entry->payload['mode'] ?? null)->toBe(ChannelMode::CloudApi->value)
        ->and($entry->payload['label'] ?? null)->toBe(ChannelCredential::DEFAULT_LABEL)
        ->and($entry->payload['sealed_fields'] ?? null)->toBe(['access_token'])
        ->and($entry->payload['config_keys'] ?? null)->toBe(['phone_number_id'])
        ->and($entry->payload['retained_label'] ?? null)->toBeNull()
        ->and($entry->payload['latency_ms'] ?? null)->toBe(12)
        // The whole trail, not just this entry: `put()` audits the write as well, and neither
        // may carry the value.
        ->and(auditedText())->not->toContain('EAAG-audited-token');
});

it('validates the merged material a rotation will store, not just the fields that were retyped', function (): void {
    validatorRouter();
    [$tenant] = validatorTenantWithWorkingCloudApi();

    // Only the token is retyped, under a *new* label: the app secret must be carried forward,
    // or the set would pass a health check and then fail to verify a webhook.
    $validation = channelValidator()->save($tenant, ChannelMode::CloudApi, [
        'access_token' => 'EAAG-rotated',
    ], label: 'rotated-2026-05');

    $probes = ProbeChannelDriver::probed(ChannelMode::CloudApi);
    $stored = $validation->credential->refresh()->secrets();

    expect($validation->label())->toBe('rotated-2026-05')
        ->and($validation->retainedLabel)->toBe(ChannelCredential::DEFAULT_LABEL)
        ->and(end($probes))->toBe([
            'access_token' => 'EAAG-rotated',
            'app_secret' => 'app-secret-value',
        ])
        // What was validated is what is stored — the assertion that keeps the validator's own
        // merge honest against `put()`'s.
        ->and($stored)->toBe(end($probes))
        // Config carried forward too: a pure secret rotation must not wipe the WABA id.
        ->and($validation->credential->setting('waba_id'))->toBe('1001')
        // And the previous set is retained rather than deleted, so a rollback is possible.
        ->and(ChannelCredential::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(validatorStore()->labelled($tenant, ChannelMode::CloudApi, ChannelCredential::DEFAULT_LABEL)?->secrets())
        ->toBe(['access_token' => 'EAAG-working-token', 'app_secret' => 'app-secret-value'])
        // The newest verified set is the one a send now uses — no pointer had to be updated.
        ->and(validatorStore()->for($tenant, ChannelMode::CloudApi)?->secret('access_token'))->toBe('EAAG-rotated');
});

it('drops the router driver memo and the credential memo so the new set serves the next resolution', function (): void {
    $router = validatorRouter();
    [$tenant, $session] = validatorTenantWithWorkingCloudApi();

    $router->driverFor($session);
    $builtBefore = FakeChannelDriver::built(ChannelMode::CloudApi);

    channelValidator()->save($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-next'], label: 'next');

    $router->driverFor($session);

    expect(FakeChannelDriver::built(ChannelMode::CloudApi))->toBe($builtBefore + 1)
        ->and(validatorStore()->for($tenant, ChannelMode::CloudApi)?->secret('access_token'))->toBe('EAAG-next');
});

/*
|--------------------------------------------------------------------------
| The refused path — Req 8.6's failure clause
|--------------------------------------------------------------------------
*/

it('leaves a tenant sending on its previous credentials when a rotation is refused', function (): void {
    $router = validatorRouter();
    [$tenant, $session] = validatorTenantWithWorkingCloudApi();

    $before = validatorStore()->rowFor($tenant, ChannelMode::CloudApi);
    ProbeChannelDriver::refuse(ChannelMode::CloudApi, 'The access token has expired.');

    expect(fn (): mixed => channelValidator()->save(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-typo'],
        label: 'rotated',
    ))->toThrow(ChannelCredentialException::class);

    $after = validatorStore()->rowFor($tenant, ChannelMode::CloudApi);

    expect(ChannelCredential::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and($after?->id)->toBe($before?->id)
        ->and($after?->label)->toBe(ChannelCredential::DEFAULT_LABEL)
        ->and($after?->status)->toBe(ChannelCredentialStatus::Active)
        ->and($after?->verified_at?->toIso8601String())->toBe($before?->verified_at?->toIso8601String())
        ->and($after?->secrets())->toBe([
            'access_token' => 'EAAG-working-token',
            'app_secret' => 'app-secret-value',
        ]);

    // The observation that actually matters: the tenant can still message people.
    $receipt = $router->driverFor($session)->send(
        $session,
        new TextContent('15550001111@s.whatsapp.net', 'still working', 'idem-after-refusal'),
    );

    expect($receipt->providerMessageId)->toBe(FakeChannelDriver::MESSAGE_ID_PREFIX.'idem-after-refusal')
        ->and(FakeChannelDriver::instance(ChannelMode::CloudApi)?->sends())->toHaveCount(1);
});

it('tells "not set up yet" apart from "the provider refused this" without reading a sentence', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();

    // Nothing stored and nothing submitted: the *absent* flavour, and no driver is troubled.
    $missing = null;

    try {
        channelValidator()->save($tenant, ChannelMode::CloudApi, []);
    } catch (ChannelCredentialException $e) {
        $missing = $e;
    }

    expect($missing?->errorCode())->toBe(ChannelCredentialException::ERROR_CODE)
        ->and($missing?->isRejection())->toBeFalse()
        ->and($missing?->detail())->toBeNull()
        ->and(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toBe([])
        ->and(FakeChannelDriver::built(ChannelMode::CloudApi))->toBe(0)
        ->and(ChannelCredential::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);

    // Stored, offered, and refused by the driver: the *rejected* flavour.
    ProbeChannelDriver::refuse(ChannelMode::CloudApi, 'This phone number id is not on the WABA.');
    $rejected = null;

    try {
        channelValidator()->save($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-wrong-number']);
    } catch (ChannelCredentialException $e) {
        $rejected = $e;
    }

    expect($rejected?->errorCode())->toBe(ChannelCredentialException::ERROR_CODE_REJECTED)
        ->and($rejected?->isRejection())->toBeTrue()
        ->and($rejected?->detail())->toBe('This phone number id is not on the WABA.')
        ->and($rejected?->getStatusCode())->toBe(422)
        // The remedy the panel shows, and the reassurance Req 8.6 entitles the tenant to.
        ->and($rejected?->publicMessage())->toContain('This phone number id is not on the WABA.')
        ->and($rejected?->publicMessage())->toContain('still in use');
});

it('keeps a refused first set as INVALID, so "needs attention" is not the same as "unconfigured"', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();
    ProbeChannelDriver::refuse(ChannelMode::CloudApi, 'Meta rejected the access token.');

    expect(fn (): mixed => channelValidator()->save(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-revoked'],
    ))->toThrow(ChannelCredentialException::class);

    $store = validatorStore();
    $rows = $store->all($tenant, ChannelMode::CloudApi);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe(ChannelCredentialStatus::Invalid)
        ->and($rows[0]->status->needsAttention())->toBeTrue()
        ->and($rows[0]->isVerified())->toBeFalse()
        // Configured, and not usable: exactly the state design § 4.1 row 10 renders as "needs
        // attention" rather than "disabled with setup hint".
        ->and($store->for($tenant, ChannelMode::CloudApi))->toBeNull()
        ->and($store->rowFor($tenant, ChannelMode::CloudApi))->toBeNull();

    $entry = AuditLog::withoutTenantScope()
        ->where('action', ChannelCredentialValidator::REJECTED_ACTION)
        ->firstOrFail();

    expect($entry->payload['outcome'] ?? null)->toBe('rejected')
        ->and($entry->payload['refused_by'] ?? null)->toBe(ChannelCredentialValidator::REFUSED_BY_DRIVER)
        ->and($entry->payload['invalidated'] ?? null)->toBeTrue()
        ->and($entry->payload['detail'] ?? null)->toBe('Meta rejected the access token.');
});

it('records a refused rotation as changing nothing, and names the set that kept serving', function (): void {
    validatorRouter();
    [$tenant] = validatorTenantWithWorkingCloudApi();
    ProbeChannelDriver::refuse(ChannelMode::CloudApi);

    expect(fn (): mixed => channelValidator()->save(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-typo'],
        label: 'rotated',
    ))->toThrow(ChannelCredentialException::class);

    $entry = AuditLog::withoutTenantScope()
        ->where('action', ChannelCredentialValidator::REJECTED_ACTION)
        ->firstOrFail();

    expect($entry->payload['changed'] ?? null)->toBeFalse()
        ->and($entry->payload['invalidated'] ?? null)->toBeFalse()
        ->and($entry->payload['label'] ?? null)->toBe('rotated')
        ->and($entry->payload['retained_label'] ?? null)->toBe(ChannelCredential::DEFAULT_LABEL)
        ->and($entry->payload['sealed_fields'] ?? null)->toBe(['access_token', 'app_secret']);
});

it('refuses without a driver call when a credential-requiring mode is saved with no secrets', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();

    expect(fn (): mixed => channelValidator()->save($tenant, ChannelMode::CloudApi, ['access_token' => '   ']))
        ->toThrow(ChannelCredentialException::class);

    $entry = AuditLog::withoutTenantScope()
        ->where('action', ChannelCredentialValidator::REJECTED_ACTION)
        ->firstOrFail();

    expect($entry->payload['refused_by'] ?? null)->toBe(ChannelCredentialValidator::REFUSED_BY_PLATFORM)
        ->and(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toBe([])
        ->and(ChannelCredential::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A probe that could not be made — decision 3
|--------------------------------------------------------------------------
*/

it('changes nothing and re-raises unchanged when the probe itself could not be performed', function (): void {
    validatorRouter();
    [$tenant] = validatorTenantWithWorkingCloudApi();

    $before = validatorStore()->rowFor($tenant, ChannelMode::CloudApi);
    ProbeChannelDriver::failProbe(ChannelMode::CloudApi, BridgeUnreachableException::transportFailed('probe'));

    expect(fn (): mixed => channelValidator()->save(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-unknowable'],
        label: 'rotated',
    ))->toThrow(BridgeUnreachableException::class);

    $after = validatorStore()->rowFor($tenant, ChannelMode::CloudApi);

    expect(ChannelCredential::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and($after?->status)->toBe(ChannelCredentialStatus::Active)
        ->and($after?->verified_at?->toIso8601String())->toBe($before?->verified_at?->toIso8601String())
        // Recorded as unverifiable, and *not* as a rejection: one provider outage must not read
        // as evidence against a tenant's credentials.
        ->and(AuditLog::withoutTenantScope()->where('action', ChannelCredentialValidator::UNVERIFIABLE_ACTION)->count())
        ->toBe(1)
        ->and(AuditLog::withoutTenantScope()->where('action', ChannelCredentialValidator::REJECTED_ACTION)->count())
        ->toBe(0);
});

it('re-raises an unregistered mode as the deployment defect it is, rather than blaming the tenant', function (): void {
    // A registry with `CLOUD_API` only — `ON_PREMISE` has no driver until task 7.3 lands.
    validatorRouter(ChannelMode::CloudApi);
    [$tenant] = validatorTenant();

    expect(fn (): mixed => channelValidator()->save(
        $tenant,
        ChannelMode::OnPremise,
        ['api_password' => 'on-prem-password'],
    ))->toThrow(LogicException::class);

    // The attempt is in the trail, and nothing was activated.
    expect(AuditLog::withoutTenantScope()->where('action', ChannelCredentialValidator::UNVERIFIABLE_ACTION)->count())
        ->toBe(1)
        ->and(validatorStore()->rowFor($tenant, ChannelMode::OnPremise)?->isVerified())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Registration — credential validity is not session liveness
|--------------------------------------------------------------------------
*/

it('registers the number on an official mode when a session is named, and activates a pending one', function (): void {
    validatorRouter();
    [$tenant, $session] = validatorTenant();
    ProbeChannelDriver::pendRegistration(ChannelMode::CloudApi, 'Meta is still verifying this number.');

    $validation = channelValidator()->save(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-pending'],
        ['phone_number_id' => '5678'],
        session: $session,
    );

    expect(ProbeChannelDriver::registered(ChannelMode::CloudApi))->toBe([['access_token' => 'EAAG-pending']])
        ->and($validation->isRegistrationPending())->toBeTrue()
        ->and($validation->isNumberLive())->toBeFalse()
        // The credentials are still activated: Meta accepted the token, and the thing that is
        // pending is the *number*. Marking a session live is task 9.1's decision to make.
        ->and($validation->credential->refresh()->isVerified())->toBeTrue()
        ->and($validation->summary())->toContain('number registration pending');
});

it('does not register on a mode that has no provider-side registration', function (): void {
    validatorRouter();
    [$tenant, $session] = validatorTenant(ChannelMode::Baileys);

    $validation = channelValidator()->save(
        $tenant,
        ChannelMode::Baileys,
        ['bridge_token' => 'bridge-shared-token'],
        session: $session,
    );

    expect(ProbeChannelDriver::probed(ChannelMode::Baileys))->toHaveCount(1)
        ->and(ProbeChannelDriver::registered(ChannelMode::Baileys))->toBe([])
        ->and($validation->isNumberLive())->toBeTrue();
});

it('activates nothing when registration fails on a rotation', function (): void {
    validatorRouter();
    [$tenant, $session] = validatorTenantWithWorkingCloudApi();
    ProbeChannelDriver::failRegistration(
        ChannelMode::CloudApi,
        BridgeUnreachableException::transportFailed('register'),
    );

    expect(fn (): mixed => channelValidator()->save(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-rotated'],
        label: 'rotated',
        session: $session,
    ))->toThrow(BridgeUnreachableException::class);

    expect(ChannelCredential::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(validatorStore()->for($tenant, ChannelMode::CloudApi)?->secret('access_token'))
        ->toBe('EAAG-working-token');
});

/*
|--------------------------------------------------------------------------
| Re-validation, and Req 8.13's "keep the platform on the BAILEYS default"
|--------------------------------------------------------------------------
*/

it('takes a set out of service when a re-check is refused, without rerouting the session', function (): void {
    $router = validatorRouter();
    [$tenant, $cloudSession] = validatorTenantWithWorkingCloudApi();

    $baileysSession = app(TenantContext::class)->runFor($tenant, fn (): Session => Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::Baileys,
    ]));

    ProbeChannelDriver::refuse(ChannelMode::CloudApi, 'The access token was revoked.');

    expect(fn (): mixed => channelValidator()->revalidate($tenant, ChannelMode::CloudApi))
        ->toThrow(ChannelCredentialException::class);

    expect(validatorStore()->rowFor($tenant, ChannelMode::CloudApi))->toBeNull()
        ->and(validatorStore()->all($tenant, ChannelMode::CloudApi)[0]->status)
        ->toBe(ChannelCredentialStatus::Invalid);

    // The mode is unselectable, and the refusal is a refusal — never a quiet reroute onto the
    // bridge, which would put this brand's traffic on a number it never registered.
    expect(fn (): mixed => $router->driverFor($cloudSession))
        ->toThrow(ChannelCredentialException::class);

    // Meanwhile the platform carries on with the Baileys default, which is all Req 8.13's last
    // clause asks for.
    $receipt = $router->driverFor($baileysSession)->send(
        $baileysSession,
        new TextContent('15550002222@s.whatsapp.net', 'unaffected', 'idem-baileys'),
    );

    expect($receipt->mode)->toBe(ChannelMode::Baileys)
        ->and(FakeChannelDriver::instance(ChannelMode::CloudApi)?->called('send'))->toBeFalse();
});

it('re-stamps the verification of a set that still works', function (): void {
    validatorRouter();
    [$tenant] = validatorTenantWithWorkingCloudApi();

    $before = validatorStore()->rowFor($tenant, ChannelMode::CloudApi)?->verified_at;

    thisTest()->travelTo(now()->addHour());

    $validation = channelValidator()->revalidate($tenant, ChannelMode::CloudApi);
    $probes = ProbeChannelDriver::probed(ChannelMode::CloudApi);

    expect($validation->credential->verified_at?->greaterThan($before))->toBeTrue()
        // The stored bag was probed, which proves the round trip through the envelope rather
        // than what a form once submitted.
        ->and(end($probes))->toBe([
            'access_token' => 'EAAG-working-token',
            'app_secret' => 'app-secret-value',
        ]);

    thisTest()->travelBack();
});

it('leaves a set exactly as it was when a re-check cannot reach the provider', function (): void {
    $router = validatorRouter();
    [$tenant, $session] = validatorTenantWithWorkingCloudApi();

    $before = validatorStore()->rowFor($tenant, ChannelMode::CloudApi);
    ProbeChannelDriver::failProbe(ChannelMode::CloudApi, BridgeUnreachableException::transportFailed('probe'));

    expect(fn (): mixed => channelValidator()->revalidate($tenant, ChannelMode::CloudApi))
        ->toThrow(BridgeUnreachableException::class);

    $after = validatorStore()->rowFor($tenant, ChannelMode::CloudApi);

    expect($after?->status)->toBe(ChannelCredentialStatus::Active)
        ->and($after?->verified_at?->toIso8601String())->toBe($before?->verified_at?->toIso8601String())
        ->and($router->driverFor($session)->send(
            $session,
            new TextContent('15550001111@s.whatsapp.net', 'unaffected', 'idem-outage'),
        )->mode)->toBe(ChannelMode::CloudApi);
});

it('refuses to re-check a mode with nothing usable stored', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();

    expect(fn (): mixed => channelValidator()->revalidate($tenant, ChannelMode::CloudApi))
        ->toThrow(ChannelCredentialException::class)
        ->and(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Tenancy — Correctness Property 23
|--------------------------------------------------------------------------
*/

it('refuses to validate for another tenant before a driver is ever constructed', function (): void {
    validatorRouter();
    [$acme] = validatorTenant();
    [$globex] = validatorTenant();

    app(TenantContext::class)->set($acme);

    expect(fn (): mixed => channelValidator()->save($globex, ChannelMode::CloudApi, ['access_token' => 'EAAG-x']))
        ->toThrow(CrossTenantAccessException::class)
        ->and(FakeChannelDriver::built(ChannelMode::CloudApi))->toBe(0)
        ->and(ChannelCredential::withoutTenantScope()->where('tenant_id', $globex->id)->count())->toBe(0);
});

it('refuses to register another tenant\'s session with this tenant\'s credentials', function (): void {
    validatorRouter();
    [$acme] = validatorTenant();
    [, $globexSession] = validatorTenant();

    expect(fn (): mixed => channelValidator()->save(
        $acme,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-acme'],
        session: $globexSession,
    ))->toThrow(CrossTenantAccessException::class)
        ->and(ProbeChannelDriver::probed(ChannelMode::CloudApi))->toBe([]);
});

it('keeps a BSP provider\'s credentials disjoint from a partner\'s on the same mode', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant(ChannelMode::BspGateway);

    channelValidator()->save(
        $tenant,
        ChannelMode::BspGateway,
        ['api_key' => 'twilio-key'],
        provider: BspProvider::Twilio,
    );

    ProbeChannelDriver::refuse(ChannelMode::BspGateway, 'This sender id is not on the account.');

    expect(fn (): mixed => channelValidator()->save(
        $tenant,
        ChannelMode::BspGateway,
        ['api_key' => 'gupshup-key'],
        provider: BspProvider::Gupshup,
    ))->toThrow(ChannelCredentialException::class);

    // Twilio is untouched by Gupshup's refusal — and because one usable partner already makes
    // the mode resolvable, Gupshup took the in-memory path and nothing at all was written for
    // it. Refusing without a write is the better of the two outcomes, so the mode-granular
    // resolvability test is left as it is rather than narrowed to the provider.
    expect(validatorStore()->for($tenant, ChannelMode::BspGateway, BspProvider::Twilio)?->secret('api_key'))
        ->toBe('twilio-key')
        ->and(validatorStore()->rowFor($tenant, ChannelMode::BspGateway, BspProvider::Gupshup))->toBeNull()
        ->and(validatorStore()->all($tenant, ChannelMode::BspGateway, BspProvider::Gupshup))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Secrecy — Req 8.5 / 32.3
|--------------------------------------------------------------------------
*/

it('cannot carry a secret out in an exception or an audit row, even when the provider quotes it back', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();
    $token = 'EAAG-quoted-back-by-the-provider';

    // What a `401` body actually looks like: the provider echoing part of what it was sent.
    ProbeChannelDriver::refuse(
        ChannelMode::CloudApi,
        sprintf('Invalid OAuth access token: Bearer %s could not be validated.', $token),
    );

    $rejected = null;

    try {
        channelValidator()->save($tenant, ChannelMode::CloudApi, ['access_token' => $token]);
    } catch (ChannelCredentialException $e) {
        $rejected = $e;
    }

    expect($rejected?->getMessage())->not->toContain($token)
        ->and($rejected?->publicMessage())->not->toContain($token)
        ->and((string) $rejected?->detail())->not->toContain($token)
        ->and((string) $rejected?->detail())->toContain('[redacted]')
        ->and(auditedText())->not->toContain($token)
        // And the tenant is still fingerprinted rather than named, as in the *absent* flavour.
        ->and($rejected?->getMessage())->not->toContain($tenant->id);
});

it('scrubs a driver exception message before recording an unverifiable attempt', function (): void {
    validatorRouter();
    [$tenant] = validatorTenant();
    $token = 'EAAG-leaked-through-an-exception';

    ProbeChannelDriver::failProbe(
        ChannelMode::CloudApi,
        new RuntimeException(sprintf('Request failed with Authorization: Bearer %s', $token)),
    );

    expect(fn (): mixed => channelValidator()->save($tenant, ChannelMode::CloudApi, ['access_token' => $token]))
        ->toThrow(RuntimeException::class);

    $entry = AuditLog::withoutTenantScope()
        ->where('action', ChannelCredentialValidator::UNVERIFIABLE_ACTION)
        ->firstOrFail();

    expect($entry->payload['failure'] ?? null)->toBe(RuntimeException::class)
        ->and((string) ($entry->payload['detail'] ?? ''))->not->toContain($token)
        ->and((string) ($entry->payload['detail'] ?? ''))->toContain('[redacted]')
        ->and(auditedText())->not->toContain($token);
});
