<?php

declare(strict_types=1);

use App\Enums\ChannelMode;
use App\Models\ChannelCredential;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Pii\PiiKeyRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| "secret_config is NEVER returned raw to UI/logs" (Req 8.5 / A8; Req 32.3 / NFR3)
|--------------------------------------------------------------------------
| design.md says it twice, so it is four independent mechanisms rather than a convention, and
| each one is asserted here on a real credential row: encrypted at rest, absent from every
| serialisation, dropped from log context by key name, and not retained on the model after a
| read. A leak through any one of them would be silent, which is why they are pinned
| separately rather than through a single "does the panel show it?" test.
*/

/**
 * The secret field names the four modes actually use (design § Channel Mode 2.7).
 *
 * @return list<string>
 */
function channelSecretFieldNames(): array
{
    return [
        'access_token',      // Cloud API system-user token
        'verify_token',      // Meta webhook handshake
        'app_secret',        // Meta app secret
        'api_key',           // BSP provider key
        'webhook_secret',    // BSP / bridge signature secret
        'password',          // On-Premise client
    ];
}

it('stores the secret bag as one opaque envelope and reads it back for its own tenant only', function (): void {
    $context = app(TenantContext::class);
    [$acme, $globex] = [Tenant::factory()->create(), Tenant::factory()->create()];

    $credential = $context->runFor($acme, fn (): ChannelCredential => ChannelCredential::factory()->create([
        'tenant_id' => $acme->id,
        'secret_config' => ['access_token' => 'EAAG-super-secret', 'verify_token' => 'vt-abc'],
    ]));

    $raw = DB::table('channel_credentials')->where('id', $credential->id)->first();

    expect($raw?->secret_config)->toBeString()
        ->and((string) $raw?->secret_config)->toStartWith('wac1.FIELD.')
        ->and((string) $raw?->secret_config)->not->toContain('EAAG-super-secret')
        // The bag is encrypted whole, so even the *field names* are hidden: the ciphertext
        // does not advertise which provider a tenant uses.
        ->and((string) $raw?->secret_config)->not->toContain('access_token')
        // ...while the non-secret half stays readable, because a panel must show it.
        ->and((string) $raw?->config)->toContain('waba_id');

    expect($context->runFor($acme, fn (): array => ChannelCredential::query()->findOrFail($credential->id)->secrets()))
        ->toBe(['access_token' => 'EAAG-super-secret', 'verify_token' => 'vt-abc']);

    // Property 23: no session's send or inbound parse uses another tenant's credentials —
    // and here the other tenant cannot even see the row.
    $context->runFor($globex, function () use ($credential): void {
        expect(ChannelCredential::query()->count())->toBe(0)
            ->and(ChannelCredential::withoutTenantScope()->count())->toBe(1)
            ->and($credential->id)->not->toBeEmpty();
    });
});

it('omits the secret bag from every serialisation the platform can produce', function (): void {
    $tenant = Tenant::factory()->create();

    $credential = app(TenantContext::class)->runFor($tenant, fn (): ChannelCredential => ChannelCredential::factory()
        ->create([
            'tenant_id' => $tenant->id,
            'secret_config' => ['access_token' => 'EAAG-leaky', 'verify_token' => 'vt-leaky'],
        ]));

    $serialisations = [
        'toArray()' => json_encode($credential->toArray()),
        'toJson()' => $credential->toJson(),
        'json_encode(model)' => json_encode($credential),
        'collection toArray()' => json_encode(ChannelCredential::withoutTenantScope()->get()->toArray()),
    ];

    foreach ($serialisations as $where => $payload) {
        expect((string) $payload)->not->toContain('EAAG-leaky', $where)
            ->and((string) $payload)->not->toContain('vt-leaky', $where)
            ->and((string) $payload)->not->toContain('secret_config', $where)
            // ...and not the ciphertext either: a `wac1.` envelope in an API response is a
            // ciphertext an attacker can keep.
            ->and((string) $payload)->not->toContain('wac1.FIELD.', $where);
    }

    // Which fields are configured is not itself a secret, and a credential screen needs it.
    expect($credential->secretKeys())->toBe(['access_token', 'verify_token']);
});

it('drops the secret bag from log context by key name, even when handed the decrypted array', function (): void {
    $tenant = Tenant::factory()->create();

    $credential = app(TenantContext::class)->runFor($tenant, fn (): ChannelCredential => ChannelCredential::factory()
        ->forMode(ChannelMode::BspGateway)
        ->create([
            'tenant_id' => $tenant->id,
            'secret_config' => ['api_key' => 'sk-live-should-never-be-logged', 'webhook_secret' => 'whsec-nope'],
        ]));

    // A real single-file channel with no tap and no special treatment, so what is asserted
    // is the bytes an ordinary `Log::info()` puts on disk.
    $channel = 'channel_secret_probe_'.Str::lower(Str::random(8));
    $path = sys_get_temp_dir().'/'.$channel.'.log';

    config()->set('logging.channels.'.$channel, [
        'driver' => 'single',
        'path' => $path,
        'level' => 'debug',
    ]);

    // The case `$hidden` cannot cover: by the time a caller has the decrypted bag it is a
    // plain array, so the key rules are what stand between it and the log file.
    Log::channel($channel)->info('channel credentials resolved', [
        'credential_id' => $credential->id,
        'mode' => $credential->mode->value,
        'secret_config' => $credential->secrets(),
        'api_key' => $credential->secrets()['api_key'] ?? null,
        'model' => $credential,
    ]);

    $written = is_file($path) ? (string) file_get_contents($path) : '';

    expect($written)->not->toContain('sk-live-should-never-be-logged')
        ->and($written)->not->toContain('whsec-nope')
        // The record itself still arrived: redaction, not suppression.
        ->and($written)->toContain('channel credentials resolved')
        ->and($written)->toContain($credential->id);
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/channel_secret_probe_*.log') ?: [] as $path) {
        @unlink($path);
    }
});

it('matches every secret field name — and the column itself — against the secret key rule', function (): void {
    // Task 4.4's rule is what keeps the values out of log context, so the column and payload
    // names are chosen to fall under it rather than the rule being widened for them.
    expect(PiiKeyRules::isSecret('secret_config'))->toBeTrue();

    foreach (channelSecretFieldNames() as $field) {
        expect(PiiKeyRules::isSecret($field))->toBeTrue(sprintf(
            'The secret field [%s] is not matched by PiiKeyRules::SECRET_PATTERN, so its value '
            .'would reach the log file verbatim.',
            $field,
        ));
    }

    // ...and the non-secret identifiers are deliberately *not* matched, so an operator can
    // still tell which account a log line is about.
    foreach (['waba_id', 'phone_number_id', 'endpoint', 'sender', 'api_version'] as $field) {
        expect(PiiKeyRules::isSecret($field))->toBeFalse();
    }
});

it('does not retain the decrypted bag on the model after a read', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $credential = ChannelCredential::factory()->create([
            'tenant_id' => $tenant->id,
            'secret_config' => ['access_token' => 'EAAG-transient'],
        ]);

        $fresh = ChannelCredential::query()->findOrFail($credential->id);
        $fresh->secrets();

        // Req 8.5: decrypted values live only as long as the caller holds them. Eloquent's
        // class-cast cache only retains *objects*, and the cast returns an array — so after
        // a read the model still holds nothing but ciphertext.
        $retained = json_encode($fresh->getAttributes());

        expect((string) $retained)->not->toContain('EAAG-transient')
            ->and((string) $retained)->toContain('wac1.FIELD.')
            // ...and asking again still works, because nothing was consumed.
            ->and($fresh->secrets())->toBe(['access_token' => 'EAAG-transient']);
    });
});

it('reports no secrets rather than an empty bag when nothing is stored', function (): void {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($tenant, function () use ($tenant): void {
        $credential = ChannelCredential::factory()->withoutSecrets()->create(['tenant_id' => $tenant->id]);

        // "No credentials" and "unreadable credentials" must not look alike to the code
        // deciding whether a mode is usable — the cast raises on the second case, and this
        // is the first.
        expect($credential->hasSecrets())->toBeFalse()
            ->and($credential->secrets())->toBe([])
            ->and($credential->secretKeys())->toBe([]);
    });
});
