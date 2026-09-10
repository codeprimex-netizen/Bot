<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCredentialStatus;
use App\Enums\ChannelMode;
use App\Exceptions\Security\KeyUnavailableException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\AuditLog;
use App\Models\ChannelCredential;
use App\Models\Tenant;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\DatabaseChannelCredentialStore;
use App\Services\Security\FieldCipher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Security\FakeKms;

/*
|--------------------------------------------------------------------------
| ChannelCredentialStore (Req 8.5, 8.6, 8.13 / A8; Req 32.3 / NFR3)
|--------------------------------------------------------------------------
| Four things are asserted here, and they are the reasons the class exists rather than
| callers using `ChannelCredential` directly:
|
|   1. resolution is disjoint by (tenant, mode, provider) — Correctness Property 23;
|   2. a missing credential set is an ordinary answer, distinguishable from a present-but-
|      unusable one (Req 8.13);
|   3. `put()` is atomic, and audits *that* it happened without any secret value ever
|      reaching the append-only chain;
|   4. rotation under a new label leaves the previous working set serving untouched — which
|      is what task 7.6's validate-before-activate depends on.
*/

/**
 * The store, resolved the way production does.
 */
function credentialStore(): ChannelCredentialStore
{
    /** @var ChannelCredentialStore $store */
    $store = app(ChannelCredentialStore::class);

    return $store;
}

/*
|--------------------------------------------------------------------------
| Disjointness — Correctness Property 23
|--------------------------------------------------------------------------
*/

it('resolves credentials disjointly by tenant', function (): void {
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $store = credentialStore();

    $store->put($acme, ChannelMode::CloudApi, ['access_token' => 'EAAG-acme'], ['waba_id' => '1001']);
    $store->put($globex, ChannelMode::CloudApi, ['access_token' => 'EAAG-globex'], ['waba_id' => '2002']);

    $acmeCredentials = $store->for($acme, ChannelMode::CloudApi);
    $globexCredentials = $store->for($globex, ChannelMode::CloudApi);

    expect($acmeCredentials?->tenantId)->toBe($acme->id)
        ->and($globexCredentials?->tenantId)->toBe($globex->id)
        ->and($acmeCredentials?->secret('access_token'))->toBe('EAAG-acme')
        ->and($globexCredentials?->secret('access_token'))->toBe('EAAG-globex')
        // ...and the rows behind them, which is what the panel and task 7.6 read.
        ->and($store->rowFor($acme, ChannelMode::CloudApi)?->tenant_id)->toBe($acme->id)
        ->and($store->rowFor($globex, ChannelMode::CloudApi)?->tenant_id)->toBe($globex->id);
});

it('refuses to read another tenant while one tenant is bound, instead of answering with nothing', function (): void {
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $store = credentialStore();
    $store->put($globex, ChannelMode::CloudApi, ['access_token' => 'EAAG-globex']);

    $context = app(TenantContext::class);
    $context->set($acme);

    // An empty result would be indistinguishable from "Globex has no Cloud API credentials",
    // which is a state Req 8.13 makes the platform act on — so the scoping bug is raised.
    expect(fn (): ?ChannelCredentials => $store->for($globex, ChannelMode::CloudApi))
        ->toThrow(CrossTenantAccessException::class);

    expect(fn (): ?ChannelCredential => $store->rowFor($globex, ChannelMode::CloudApi))
        ->toThrow(CrossTenantAccessException::class);

    expect(fn (): ?ChannelCredential => $store->labelled($globex, ChannelMode::CloudApi, 'default'))
        ->toThrow(CrossTenantAccessException::class);

    expect(fn (): array => $store->all($globex, ChannelMode::CloudApi))
        ->toThrow(CrossTenantAccessException::class);

    expect(fn (): ChannelCredential => $store->put($globex, ChannelMode::CloudApi, ['access_token' => 'x']))
        ->toThrow(CrossTenantAccessException::class);

    // ...and the tenant that *is* bound reads its own rows without ceremony.
    expect($store->for($acme, ChannelMode::CloudApi))->toBeNull();
});

it('refuses a memoised resolution to another tenant just as firmly as an uncached one', function (): void {
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $store = credentialStore();
    $store->put($globex, ChannelMode::CloudApi, ['access_token' => 'EAAG-globex']);

    // Resolved with no tenant bound (a console command, a scheduler), so it is now memoised.
    expect($store->for($globex, ChannelMode::CloudApi)?->secret('access_token'))->toBe('EAAG-globex');

    app(TenantContext::class)->set($acme);

    // The check runs before the memo, so a remembered row is not a back door around it.
    expect(fn (): ?ChannelCredentials => $store->for($globex, ChannelMode::CloudApi))
        ->toThrow(CrossTenantAccessException::class);
});

it('resolves credentials disjointly by mode', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-cloud'], ['waba_id' => '1001']);
    $store->put($tenant, ChannelMode::OnPremise, ['password' => 'onprem-pw'], ['endpoint' => 'https://onprem.test']);

    $cloud = $store->for($tenant, ChannelMode::CloudApi);
    $onPremise = $store->for($tenant, ChannelMode::OnPremise);

    expect($cloud?->mode)->toBe(ChannelMode::CloudApi)
        ->and($cloud?->secretKeys())->toBe(['access_token'])
        ->and($onPremise?->mode)->toBe(ChannelMode::OnPremise)
        ->and($onPremise?->secretKeys())->toBe(['password'])
        // A mode the tenant never configured stays unconfigured, however many others exist.
        ->and($store->for($tenant, ChannelMode::BspGateway, BspProvider::Twilio))->toBeNull();
});

it('resolves BSP credentials disjointly by provider', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $store->put($tenant, ChannelMode::BspGateway, ['api_key' => 'twilio-key'], provider: BspProvider::Twilio);
    $store->put($tenant, ChannelMode::BspGateway, ['api_key' => '360-key'], provider: BspProvider::ThreeSixtyDialog);

    $twilio = $store->for($tenant, ChannelMode::BspGateway, BspProvider::Twilio);
    $threeSixty = $store->for($tenant, ChannelMode::BspGateway, BspProvider::ThreeSixtyDialog);

    expect($twilio?->provider)->toBe(BspProvider::Twilio)
        ->and($twilio?->secret('api_key'))->toBe('twilio-key')
        ->and($threeSixty?->provider)->toBe(BspProvider::ThreeSixtyDialog)
        ->and($threeSixty?->secret('api_key'))->toBe('360-key')
        ->and($twilio?->credentialId)->not->toBe($threeSixty?->credentialId)
        // A partner the tenant has no contract with is unconfigured, not "one of the others".
        ->and($store->for($tenant, ChannelMode::BspGateway, BspProvider::Gupshup))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A missing set is an ordinary answer (Req 8.13)
|--------------------------------------------------------------------------
*/

it('answers null for an unconfigured mode rather than throwing', function (): void {
    $tenant = Tenant::factory()->create();

    expect(credentialStore()->for($tenant, ChannelMode::CloudApi))->toBeNull()
        ->and(credentialStore()->all($tenant, ChannelMode::CloudApi))->toBe([]);
});

it('lets a caller tell "never configured" apart from "configured but unusable"', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        ChannelCredential::factory()->invalid()->create(['tenant_id' => $tenant->id, 'mode' => ChannelMode::CloudApi]);
    });

    // The mode cannot be selected — Req 8.13 keeps the platform on the BAILEYS default...
    expect($store->for($tenant, ChannelMode::CloudApi))->toBeNull()
        // ...but the panel must say "needs attention" rather than "set this up".
        ->and($store->all($tenant, ChannelMode::CloudApi))->toHaveCount(1)
        ->and($store->all($tenant, ChannelMode::CloudApi)[0]->status)->toBe(ChannelCredentialStatus::Invalid);
});

it('skips rows a send cannot use, and never returns one isUsable() disagrees with', function (
    string $state,
): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant, $state): void {
        $factory = ChannelCredential::factory()->forMode(ChannelMode::CloudApi);

        $factory = match ($state) {
            'invalid' => $factory->invalid(),
            'disabled' => $factory->disabled(),
            // ACTIVE, but the secrets never landed: `isUsable()`'s second half, which no
            // `where` clause can express because it would have to decrypt to answer.
            'secretless' => $factory->withoutSecrets(),
            default => throw new InvalidArgumentException('Unknown credential state ['.$state.'].'),
        };

        $factory->create(['tenant_id' => $tenant->id]);
    });

    expect(credentialStore()->for($tenant, ChannelMode::CloudApi))->toBeNull()
        ->and(credentialStore()->rowFor($tenant, ChannelMode::CloudApi))->toBeNull()
        ->and(credentialStore()->all($tenant, ChannelMode::CloudApi))->toHaveCount(1);
})->with(['invalid', 'disabled', 'secretless']);

it('returns exactly the first usable row of all(), so the two orderings cannot drift', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        ChannelCredential::factory()->labelled('oldest')->create([
            'tenant_id' => $tenant->id,
            'verified_at' => now()->subDays(3),
        ]);
        ChannelCredential::factory()->labelled('newest-verified')->create([
            'tenant_id' => $tenant->id,
            'verified_at' => now()->subMinute(),
        ]);
        ChannelCredential::factory()->labelled('unverified')->unverified()->create(['tenant_id' => $tenant->id]);
        ChannelCredential::factory()->labelled('rejected')->invalid()->create(['tenant_id' => $tenant->id]);
    });

    $all = $store->all($tenant, ChannelMode::CloudApi);
    $usable = array_values(array_filter($all, static fn (ChannelCredential $c): bool => $c->isUsable()));

    expect($all)->toHaveCount(4)
        ->and($store->rowFor($tenant, ChannelMode::CloudApi)?->id)->toBe($usable[0]->id)
        // An unverified set never displaces a verified one, which is what makes a rotation
        // safe before task 7.6 has validated it.
        ->and($store->rowFor($tenant, ChannelMode::CloudApi)?->label)->toBe('newest-verified')
        // ...and the decrypted view a driver gets is that same row.
        ->and($store->for($tenant, ChannelMode::CloudApi)?->credentialId)->toBe($usable[0]->id);
});

/*
|--------------------------------------------------------------------------
| Writing: encrypted, atomic, and never half-configured
|--------------------------------------------------------------------------
*/

it('stores secrets as one envelope and the non-secret config in the clear', function (): void {
    $tenant = Tenant::factory()->create();

    $credential = credentialStore()->put(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 'EAAG-fresh', 'verify_token' => 'vt-fresh'],
        ['waba_id' => '1001', 'phone_number_id' => '5005', 'api_version' => 'v20.0'],
    );

    $raw = DB::table('channel_credentials')->where('id', $credential->id)->first();

    expect((string) $raw?->secret_config)->toStartWith('wac1.FIELD.')
        ->and((string) $raw?->secret_config)->not->toContain('EAAG-fresh')
        ->and((string) $raw?->config)->toContain('1001')
        // Derived by task 6.1's `saving` hook, never written by the store.
        ->and($raw?->provider_slot)->toBe(ChannelCredential::NO_PROVIDER)
        ->and($credential->status)->toBe(ChannelCredentialStatus::Active)
        ->and($credential->label)->toBe(ChannelCredential::DEFAULT_LABEL)
        // Nothing has validated this material yet — that is task 7.6's stamp to make.
        ->and($credential->isVerified())->toBeFalse()
        ->and($credential->isUsable())->toBeTrue()
        ->and($credential->secretKeys())->toBe(['access_token', 'verify_token']);
});

it('cannot put a secret into a queue payload, whichever shape a caller dispatches', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $stored = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-never-serialised']);
    $row = $store->rowFor($tenant, ChannelMode::CloudApi);
    $credentials = $store->for($tenant, ChannelMode::CloudApi);

    // A job's constructor arguments are written to `jobs` — a database table or Redis — in the
    // clear, and to `failed_jobs` indefinitely if the job fails. The rows the store returns
    // hold ciphertext only...
    foreach (['put' => $stored, 'rowFor' => $row] as $where => $credential) {
        expect(serialize($credential))->not->toContain('EAAG-never-serialised', $where)
            ->and((string) json_encode($credential))->not->toContain('EAAG-never-serialised', $where);
    }

    // ...and the one decrypted shape refuses to be serialised at all, so dispatching it fails
    // loudly at the call site instead of quietly writing a token to disk.
    expect(fn (): string => serialize($credentials))->toThrow(LogicException::class);

    expect($credentials?->requireSecret('access_token'))->toBe('EAAG-never-serialised')
        // Debug output names the fields and shows no value, so a failing test or a `dd()`
        // cannot print one either.
        ->and((string) json_encode($credentials?->__debugInfo()))->not->toContain('EAAG-never-serialised');
});

it('rolls the whole write back when the key store is unavailable, leaving no half-configured row', function (): void {
    $kms = FakeKms::bind();
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    // A working set first, so the failure below is demonstrably about the second write.
    $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-good'], ['waba_id' => '1001']);

    $kms->fail();
    // Drop the memoised DEK, so sealing really does have to reach the failing key store.
    app(FieldCipher::class)->forgetKeys();

    expect(fn (): ChannelCredential => $store->put(
        $tenant,
        ChannelMode::BspGateway,
        ['api_key' => 'twilio-key'],
        ['endpoint' => 'https://api.example.test'],
        BspProvider::Twilio,
    ))->toThrow(KeyUnavailableException::class);

    $kms->recover();

    // No row at all — not a row whose `config` landed and whose `secret_config` did not,
    // which `isUsable()` would have called usable on the strength of its status.
    expect(DB::table('channel_credentials')->where('mode', ChannelMode::BspGateway->value)->count())->toBe(0)
        ->and(DB::table('channel_credentials')->count())->toBe(1)
        // ...and nothing was audited for a write that did not happen.
        ->and(AuditLog::withoutTenantScope()->where('action', DatabaseChannelCredentialStore::CREATED_ACTION)->count())
        ->toBe(1);
});

it('refuses to store a credential-requiring mode with nothing in the secret bag', function (): void {
    $tenant = Tenant::factory()->create();

    expect(fn (): ChannelCredential => credentialStore()->put($tenant, ChannelMode::CloudApi, [], ['waba_id' => '1001']))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::table('channel_credentials')->count())->toBe(0);

    // BAILEYS needs no tenant secrets — its bridge token is platform config — so a config-only
    // set is legitimate there and stays usable.
    $baileys = credentialStore()->put($tenant, ChannelMode::Baileys, [], ['endpoint' => 'http://127.0.0.1:3000']);

    expect($baileys->hasSecrets())->toBeFalse()
        ->and($baileys->isUsable())->toBeTrue();
});

it('requires the mode and the BSP partner to agree on a write', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    // A BSP set with no partner would be a row whose identity is not what the caller thinks.
    expect(fn (): ChannelCredential => $store->put($tenant, ChannelMode::BspGateway, ['api_key' => 'k']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): ChannelCredential => $store->put(
        $tenant,
        ChannelMode::CloudApi,
        ['access_token' => 't'],
        provider: BspProvider::Twilio,
    ))->toThrow(InvalidArgumentException::class);

    // Reads stay lenient: a stray provider on a mode that names none is dropped, so a caller
    // looping over providers needs no special case.
    $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 't']);

    expect($store->for($tenant, ChannelMode::CloudApi, BspProvider::Twilio))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Merge semantics: a screen that cannot echo a secret back
|--------------------------------------------------------------------------
*/

it('merges submitted secrets over the stored bag instead of replacing it', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $store->put($tenant, ChannelMode::CloudApi, [
        'access_token' => 'EAAG-old',
        'verify_token' => 'vt-keep',
        'app_secret' => 'as-keep',
    ]);

    // The operator rotated one field and left the others blank, because the form cannot show
    // them. Replace-wholesale would have stripped two working secrets.
    $rotated = $store->put($tenant, ChannelMode::CloudApi, [
        'access_token' => 'EAAG-new',
        'verify_token' => '',
        'app_secret' => '   ',
    ]);

    expect($rotated->secrets())->toBe([
        'access_token' => 'EAAG-new',
        'app_secret' => 'as-keep',
        'verify_token' => 'vt-keep',
    ]);

    // `null` is the one spelling of "delete this secret".
    $trimmed = $store->put($tenant, ChannelMode::CloudApi, ['app_secret' => null]);

    expect($trimmed->secretKeys())->toBe(['access_token', 'verify_token'])
        ->and($trimmed->id)->toBe($rotated->id);
});

it('replaces the config when one is supplied and keeps it when none is', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 't'], [
        'waba_id' => '1001',
        'phone_number_id' => '5005',
        'api_version' => 'v20.0',
    ]);

    // A pure secret rotation mentions no config and must not wipe the account identifiers.
    $rotated = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 't2']);

    expect($rotated->setting('waba_id'))->toBe('1001')
        ->and($rotated->setting('phone_number_id'))->toBe('5005');

    // A supplied config is the whole config, so a key the screen no longer shows is removed.
    $edited = $store->put($tenant, ChannelMode::CloudApi, [], ['waba_id' => '9009']);

    expect($edited->setting('waba_id'))->toBe('9009')
        ->and($edited->setting('phone_number_id'))->toBeNull()
        ->and($edited->secrets()['access_token'] ?? null)->toBe('t2');
});

/*
|--------------------------------------------------------------------------
| Rotation, verification and status
|--------------------------------------------------------------------------
*/

it('clears a previous driver verification when the stored material changes, and keeps it when nothing does', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $credential = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-1'], ['waba_id' => '1001']);

    // Stand in for task 7.6's stamp after a successful `healthCheck()`.
    app(TenantContext::class)->runFor($tenant, function () use ($credential): void {
        $credential->forceFill(['verified_at' => now()])->save();
    });
    $store->forget($tenant, ChannelMode::CloudApi);

    // Re-saving the same screen with nothing retyped changes nothing, so the row stays
    // verified — otherwise every visit to the panel would de-verify a working set.
    $unchanged = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-1'], ['waba_id' => '1001']);

    expect($unchanged->isVerified())->toBeTrue();

    // New material: whatever the driver confirmed no longer describes what is stored.
    $changed = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-2']);

    expect($changed->isVerified())->toBeFalse()
        ->and($changed->id)->toBe($credential->id);
});

it('keeps the previous working set serving when a rotation is written under a new label', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $live = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-live'], ['waba_id' => '1001']);
    // Verified an hour ago, as a set that has been serving would be: `activeFor()` orders on
    // `verified_at` at datetime precision, so a rotation only takes over if its verification
    // is measurably later — which it is, outside a test that stamps both in the same second.
    app(TenantContext::class)->runFor($tenant, function () use ($live): void {
        $live->forceFill(['verified_at' => now()->subHour()])->save();
    });
    $store->forget($tenant, ChannelMode::CloudApi);

    // Task 7.6's shape: the candidate goes in under its own name, so the live set is not
    // touched and there is nothing to roll back if the driver rejects the new material.
    $candidate = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-candidate'], label: 'rotation-2024-06');

    expect($candidate->id)->not->toBe($live->id)
        ->and($store->rowFor($tenant, ChannelMode::CloudApi)?->id)->toBe($live->id)
        ->and($store->for($tenant, ChannelMode::CloudApi)?->secret('access_token'))->toBe('EAAG-live');

    // Once something verifies the candidate it takes over — no pointer to update, because
    // `activeFor()` orders newest-verified first.
    app(TenantContext::class)->runFor($tenant, function () use ($candidate): void {
        $candidate->forceFill(['verified_at' => now()])->save();
    });
    $store->forget($tenant, ChannelMode::CloudApi);

    expect($store->rowFor($tenant, ChannelMode::CloudApi)?->id)->toBe($candidate->id)
        // ...and the previous set is still there, verified, for an operator to fall back to.
        ->and($store->labelled($tenant, ChannelMode::CloudApi, ChannelCredential::DEFAULT_LABEL)?->isUsable())
        ->toBeTrue();
});

it('gives rejected credentials a fresh chance and leaves a disabled set disabled', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        ChannelCredential::factory()->invalid()->labelled('rejected')->create(['tenant_id' => $tenant->id]);
        ChannelCredential::factory()->disabled()->labelled('switched-off')->create(['tenant_id' => $tenant->id]);
    });

    // New material deserves a new verdict, or a tenant who fixes a bad token would need a
    // second button to clear the rejection.
    $fixed = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-fixed'], label: 'rejected');

    expect($fixed->status)->toBe(ChannelCredentialStatus::Active);

    // Disabled is a deliberate choice; editing config must not silently undo it.
    $edited = $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-edited'], label: 'switched-off');

    expect($edited->status)->toBe(ChannelCredentialStatus::Disabled)
        ->and($store->rowFor($tenant, ChannelMode::CloudApi)?->label)->toBe('rejected');
});

/*
|--------------------------------------------------------------------------
| Auditing: that it happened, never what was stored
|--------------------------------------------------------------------------
*/

it('audits a credential write on the tenant chain with field names and no values', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $credential = $store->put(
        $tenant,
        ChannelMode::BspGateway,
        ['api_key' => 'sk-live-never-in-the-chain', 'webhook_secret' => 'whsec-never'],
        ['endpoint' => 'https://api.example.test', 'sender' => '919876500000'],
        BspProvider::Twilio,
        'production',
    );

    $entry = AuditLog::withoutTenantScope()
        ->where('action', DatabaseChannelCredentialStore::CREATED_ACTION)
        ->firstOrFail();

    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->payload['mode'] ?? null)->toBe(ChannelMode::BspGateway->value)
        ->and($entry->payload['provider'] ?? null)->toBe(BspProvider::Twilio->value)
        ->and($entry->payload['label'] ?? null)->toBe('production')
        // Which fields are set is what a reviewer needs; the values are what must never be
        // here, because the chain is append-only and a secret written into it cannot be
        // removed without breaking every hash after it.
        ->and($entry->payload['sealed_fields'] ?? null)->toBe(['api_key', 'webhook_secret'])
        ->and($entry->payload['config']['endpoint'] ?? null)->toBe('https://api.example.test')
        ->and(json_encode($entry->payload))->not->toContain('sk-live-never-in-the-chain')
        ->and(json_encode($entry->payload))->not->toContain('whsec-never')
        ->and(json_encode($entry->payload))->not->toContain('wac1.FIELD.')
        // The row is the subject, so its id is evidence without being payload.
        ->and($entry->subject_id)->toBe($credential->id);
});

it('records an update separately, with what the write removed and whether it de-verified the row', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-1', 'app_secret' => 'as-1']);
    $store->put($tenant, ChannelMode::CloudApi, ['app_secret' => null]);

    $entry = AuditLog::withoutTenantScope()
        ->where('action', DatabaseChannelCredentialStore::UPDATED_ACTION)
        ->firstOrFail();

    expect($entry->payload['sealed_fields'] ?? null)->toBe(['access_token'])
        ->and($entry->payload['sealed_fields_removed'] ?? null)->toBe(['app_secret'])
        ->and($entry->payload['verification_cleared'] ?? null)->toBeTrue()
        ->and(AuditLog::withoutTenantScope()->where('action', DatabaseChannelCredentialStore::CREATED_ACTION)->count())
        ->toBe(1);
});

it('redacts the value of a config key that is not on the reviewed allowlist', function (): void {
    $tenant = Tenant::factory()->create();

    // Somebody pastes a token into a free-form config field once. `config` is documented as
    // non-secret, but the trail is permanent — so only the reviewed keys keep their values.
    credentialStore()->put($tenant, ChannelMode::CloudApi, ['access_token' => 't'], [
        'waba_id' => '1001',
        'operator_note' => 'EAAG-pasted-in-the-wrong-box',
    ]);

    $entry = AuditLog::withoutTenantScope()
        ->where('action', DatabaseChannelCredentialStore::CREATED_ACTION)
        ->firstOrFail();

    expect($entry->payload['config']['waba_id'] ?? null)->toBe('1001')
        ->and($entry->payload['config']['operator_note'] ?? null)->toBe(DatabaseChannelCredentialStore::REDACTED)
        ->and(json_encode($entry->payload))->not->toContain('EAAG-pasted-in-the-wrong-box');
});

it('leaves the audit chain verifiable after a write', function (): void {
    $tenant = Tenant::factory()->create();

    credentialStore()->put($tenant, ChannelMode::CloudApi, ['access_token' => 't'], ['waba_id' => '1001']);
    credentialStore()->put($tenant, ChannelMode::CloudApi, ['access_token' => 't2']);

    // The audit entry is written after the transaction commits precisely so a rolled-back
    // append cannot leave a hole that `verify()` would report as tampering.
    expect(app(App\Services\Audit\AuditService::class)->verify($tenant)->isIntact())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The memo, and its lifetime
|--------------------------------------------------------------------------
*/

it('memoises a resolution within the unit of work and drops it on a write', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();
    $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-1']);
    $store->for($tenant, ChannelMode::CloudApi);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $store->for($tenant, ChannelMode::CloudApi);
    $store->for($tenant, ChannelMode::CloudApi);

    expect($queries)->toBe(0);

    // A write to any label of this (tenant, mode, provider) invalidates it, because a new
    // label can outrank the old one.
    $store->put($tenant, ChannelMode::CloudApi, ['access_token' => 'EAAG-2'], label: 'second');
    $queries = 0;
    $store->for($tenant, ChannelMode::CloudApi);

    expect($queries)->toBeGreaterThan(0);
});

it('remembers a miss, and re-reads it once told to forget', function (): void {
    $tenant = Tenant::factory()->create();
    $store = credentialStore();

    expect($store->for($tenant, ChannelMode::CloudApi))->toBeNull();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        ChannelCredential::factory()->create(['tenant_id' => $tenant->id, 'mode' => ChannelMode::CloudApi]);
    });

    // A remembered miss is what keeps a campaign from re-querying an unconfigured mode for
    // every message; the row written behind the store's back needs `forget()`, which is the
    // seam task 7.6 uses after it stamps `verified_at` or marks a row INVALID.
    expect($store->for($tenant, ChannelMode::CloudApi))->toBeNull();

    $store->forget($tenant, ChannelMode::CloudApi);

    expect($store->for($tenant, ChannelMode::CloudApi))->not->toBeNull();

    $store->flush();

    expect($store->for($tenant, ChannelMode::CloudApi))->not->toBeNull();
});

it('is bound scoped, so the memo cannot outlive a request or a job', function (): void {
    $first = credentialStore();

    expect(credentialStore())->toBe($first);

    // What the queue worker does between jobs, and the framework at the end of a request.
    app()->forgetScopedInstances();

    expect(credentialStore())->not->toBe($first)
        ->and(credentialStore())->toBeInstanceOf(DatabaseChannelCredentialStore::class);
});

/*
|--------------------------------------------------------------------------
| Platform-mode and job callers
|--------------------------------------------------------------------------
*/

it('reads and writes for one tenant from platform mode without bypassing the scope', function (): void {
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $store = credentialStore();
    $store->put($globex, ChannelMode::CloudApi, ['access_token' => 'EAAG-globex']);

    $context = app(TenantContext::class);

    $resolved = $context->asPlatform(
        'support investigating a mode',
        fn (): ?ChannelCredentials => $store->for($acme, ChannelMode::CloudApi),
    );

    // Platform mode bypasses the tenant scope; the store still answers about the tenant it
    // was asked about, and only that one.
    expect($resolved)->toBeNull();

    $written = $context->asPlatform(
        'operator entering credentials on the tenant’s behalf',
        fn (): ChannelCredential => $store->put($acme, ChannelMode::CloudApi, ['access_token' => 'EAAG-acme']),
    );

    expect($written->tenant_id)->toBe($acme->id)
        ->and($store->for($acme, ChannelMode::CloudApi)?->secret('access_token'))->toBe('EAAG-acme')
        ->and($store->for($globex, ChannelMode::CloudApi)?->secret('access_token'))->toBe('EAAG-globex');
});
