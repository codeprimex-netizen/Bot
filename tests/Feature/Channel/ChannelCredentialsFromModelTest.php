<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Models\ChannelCredential;
use App\Models\Tenant;
use App\Services\Channel\ChannelCredentials;
use App\Services\Tenancy\TenantContext;

/*
|--------------------------------------------------------------------------
| ChannelCredentials::fromModel() — storage to driver, once (Req 8.5, 8.6 / A8)
|--------------------------------------------------------------------------
| The single translation from an envelope-encrypted `channel_credentials` row into the shape a
| `ChannelDriver` is handed, so "the secret bag is decrypted at the boundary and nowhere else"
| is a fact about one method. Task 6.4's `ChannelCredentialStore::for()` is its caller.
|
| A feature test rather than a unit one because the secret bag really is decrypted here — the
| `EncryptedArray` cast unwraps a per-tenant DEK — and a translation asserted against a
| hand-built array would prove nothing about the path that actually runs.
*/

it('hands a driver the decrypted config and secrets of one stored row', function (): void {
    $tenant = Tenant::factory()->create();

    $credentials = app(TenantContext::class)->runFor($tenant, function (): ChannelCredentials {
        $row = ChannelCredential::create([
            'mode' => ChannelMode::CloudApi,
            'config' => ['waba_id' => '10987', 'phone_number_id' => '109876543210'],
            'secret_config' => ['access_token' => 'EAAGm0PX4ZoBACCESSTOKEN', 'verify_token' => 'vt-secret'],
        ]);

        // The ciphertext is what is stored; nothing about the plaintext survives in the column.
        expect($row->getRawOriginal('secret_config'))->not->toContain('EAAGm0PX4ZoBACCESSTOKEN');

        return ChannelCredentials::fromModel($row->refresh());
    });

    expect($credentials->tenantId)->toBe($tenant->id)
        ->and($credentials->mode)->toBe(ChannelMode::CloudApi)
        ->and($credentials->provider)->toBeNull()
        ->and($credentials->credentialId)->not->toBeNull()
        ->and($credentials->requireConfig('phone_number_id'))->toBe('109876543210')
        ->and($credentials->requireSecret('access_token'))->toBe('EAAGm0PX4ZoBACCESSTOKEN')
        ->and($credentials->secretKeys())->toBe(['access_token', 'verify_token'])
        ->and($credentials->isComplete())->toBeTrue()
        // And the scrubber is armed with the real values, which is what makes a provider echo
        // of one of them impossible to report back out of `ChannelHealth`.
        ->and($credentials->redact('rejected EAAGm0PX4ZoBACCESSTOKEN'))->toBe('rejected [redacted]');
});

it('carries the partner across for a BSP row, because task 7.4 resolves capabilities from it', function (): void {
    $tenant = Tenant::factory()->create();

    $credentials = app(TenantContext::class)->runFor($tenant, fn (): ChannelCredentials => ChannelCredentials::fromModel(
        ChannelCredential::create([
            'mode' => ChannelMode::BspGateway,
            'provider' => BspProvider::Twilio,
            'config' => ['sender' => 'whatsapp:+15550001111'],
            'secret_config' => ['api_key' => 'SK-twilio-key'],
        ])
    ));

    expect($credentials->mode)->toBe(ChannelMode::BspGateway)
        ->and($credentials->provider)->toBe(BspProvider::Twilio)
        ->and($credentials->requireSecret('api_key'))->toBe('SK-twilio-key');
});

it('reports a mode whose secrets were never entered as incomplete, keeping the platform on BAILEYS', function (): void {
    // Req 8.13: a mode with no credentials cannot be selected, and the platform keeps working
    // on the Baileys default. The decrypted view answers that question the same way the row does.
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function (): void {
        $unconfigured = ChannelCredentials::fromModel(ChannelCredential::create([
            'mode' => ChannelMode::OnPremise,
            'config' => ['endpoint' => 'https://onprem.test'],
        ]));

        expect($unconfigured->hasSecrets())->toBeFalse()
            ->and($unconfigured->isComplete())->toBeFalse();

        // Baileys needs none: its bridge token is platform config, not a tenant secret.
        $baileys = ChannelCredentials::fromModel(ChannelCredential::create(['mode' => ChannelMode::Baileys]));

        expect($baileys->hasSecrets())->toBeFalse()
            ->and($baileys->isComplete())->toBeTrue();
    });
});
