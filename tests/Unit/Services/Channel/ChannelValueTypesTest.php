<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\ChannelTemplateCategory;
use App\Models\CloudApiTemplate;
use App\Services\Bridge\SentMessageDto;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelHealth;
use App\Services\Channel\RegistrationResult;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use App\Services\Channel\TextContent;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The value types ChannelDriver is stated in terms of (Req 8.1, 8.5, 8.6, 8.12 / A8)
|--------------------------------------------------------------------------
| Each of these exists to make one class of mistake unrepresentable, so what is pinned is the
| refusal rather than the happy path: a send acknowledged without an id nothing can reconcile,
| a template name no provider could hold, credentials for a mode with no provider, a health
| report quoting the token it was probing with.
*/

/**
 * @param  array<string, mixed>  $secrets
 */
function credentialsFor(
    ChannelMode $mode,
    array $secrets = [],
    ?BspProvider $provider = null,
): ChannelCredentials {
    return new ChannelCredentials(
        tenantId: 'tenant-1',
        mode: $mode,
        provider: $provider,
        config: ['phone_number_id' => '109876543210'],
        secrets: $secrets,
    );
}

/*
|--------------------------------------------------------------------------
| ChannelCredentials — decrypted, in-request only
|--------------------------------------------------------------------------
*/

it('requires a BSP provider for BSP_GATEWAY and refuses one anywhere else', function (): void {
    // The invariant the database cannot carry (`provider` is nullable for the three modes
    // that have no provider) and task 7.4 depends on: a null provider there would silently
    // report the mode ceiling for all eight partners.
    expect(fn (): ChannelCredentials => credentialsFor(ChannelMode::BspGateway))
        ->toThrow(InvalidArgumentException::class, 'BspProvider')
        ->and(fn (): ChannelCredentials => credentialsFor(ChannelMode::CloudApi, provider: BspProvider::Twilio))
        ->toThrow(InvalidArgumentException::class, 'TWILIO');

    $bsp = credentialsFor(ChannelMode::BspGateway, provider: BspProvider::Gupshup);

    expect($bsp->provider)->toBe(BspProvider::Gupshup)
        ->and(credentialsFor(ChannelMode::Baileys)->provider)->toBeNull();
});

it('refuses to exist without the tenant it belongs to', function (): void {
    expect(fn (): ChannelCredentials => new ChannelCredentials(tenantId: '  ', mode: ChannelMode::Baileys))
        ->toThrow(InvalidArgumentException::class, 'tenant');
});

it('names the missing key and never a value when a required field is absent', function (): void {
    $credentials = credentialsFor(ChannelMode::CloudApi, ['access_token' => 'EAAG-token-value']);

    expect(fn (): string => $credentials->requireSecret('verify_token'))
        ->toThrow(InvalidArgumentException::class, 'verify_token')
        ->and(fn (): string => $credentials->requireConfig('waba_id'))
        ->toThrow(InvalidArgumentException::class, 'waba_id');

    // The message lists which keys *are* present — names, so a tenant who pasted a token into
    // the wrong field can see it — and no value.
    try {
        $credentials->requireSecret('verify_token');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('access_token')
            ->and($e->getMessage())->not->toContain('EAAG-token-value');
    }

    expect($credentials->requireSecret('access_token'))->toBe('EAAG-token-value')
        ->and($credentials->requireConfig('phone_number_id'))->toBe('109876543210')
        ->and($credentials->secret('nope'))->toBeNull()
        ->and($credentials->secretKeys())->toBe(['access_token']);
});

it('scrubs stored secret values out of any string, longest match first', function (): void {
    $credentials = credentialsFor(ChannelMode::CloudApi, [
        'app_secret' => 'abc123secret',
        'access_token' => 'abc123secretEXTENDED',
    ]);

    $echoed = 'Provider said: Bearer abc123secretEXTENDED is expired.';

    expect($credentials->containsSecret($echoed))->toBeTrue()
        // Longest-first, so the shorter credential being a prefix of the longer one does not
        // leave the longer one half-scrubbed and still guessable.
        ->and($credentials->redact($echoed))->toBe('Provider said: Bearer [redacted] is expired.')
        ->and($credentials->containsSecret($credentials->redact($echoed)))->toBeFalse();

    // A value too short to distinguish from ordinary prose is left alone rather than turning
    // every message into redaction confetti.
    $short = credentialsFor(ChannelMode::CloudApi, ['access_token' => 'ab']);

    expect($short->containsSecret('ab ovo'))->toBeFalse()
        ->and($short->redact('ab ovo'))->toBe('ab ovo');

    // And the result is bounded, so an unbounded provider body cannot be copied whole.
    expect(mb_strlen($credentials->redact(str_repeat('x', 5_000))))
        ->toBeLessThanOrEqual(ChannelCredentials::MAX_DETAIL_LENGTH);
});

it('keeps secrets out of every serialisation, dump, and queue payload', function (): void {
    $credentials = credentialsFor(ChannelMode::CloudApi, ['access_token' => 'EAAG-token-value']);

    // json_encode walks public properties; the bags are private and this implements neither
    // Arrayable nor JsonSerializable.
    $json = json_encode($credentials, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('EAAG-token-value')
        ->and(class_implements($credentials))->not->toHaveKey(JsonSerializable::class)
        ->and(class_implements($credentials))->not->toHaveKey(Illuminate\Contracts\Support\Arrayable::class);

    // var_dump / dd / a test-failure diff see key names and no values.
    expect($credentials->__debugInfo()['secrets'])->toBe(['access_token' => '[redacted]']);

    // A queue payload is stored in the clear, so this must fail at dispatch rather than write
    // a decrypted token to disk (Req 8.5).
    expect(fn (): string => serialize($credentials))
        ->toThrow(LogicException::class, 'Refusing to serialise');
});

it('reports completeness the way Req 8.13 needs it', function (): void {
    // BAILEYS needs no tenant secrets at all — that is the zero-onboarding promise.
    expect(credentialsFor(ChannelMode::Baileys)->isComplete())->toBeTrue()
        ->and(credentialsFor(ChannelMode::CloudApi)->isComplete())->toBeFalse()
        ->and(credentialsFor(ChannelMode::CloudApi, ['access_token' => 'value'])->isComplete())->toBeTrue()
        // An empty string is not a secret.
        ->and(credentialsFor(ChannelMode::CloudApi, ['access_token' => ''])->hasSecrets())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| TextContent — the one variant this phase needs
|--------------------------------------------------------------------------
*/

it('refuses content that would send blank or unguarded', function (): void {
    expect(fn (): TextContent => TextContent::to('jid', '   ', 'key'))
        ->toThrow(InvalidArgumentException::class, 'body')
        ->and(fn (): TextContent => TextContent::to('jid', 'hi', ' '))
        ->toThrow(InvalidArgumentException::class, 'idempotency key')
        ->and(fn (): TextContent => TextContent::to('', 'hi', 'key'))
        ->toThrow(InvalidArgumentException::class, 'recipient')
        ->and(fn (): TextContent => TextContent::to('jid', str_repeat('x', TextContent::MAX_LENGTH + 1), 'key'))
        ->toThrow(InvalidArgumentException::class, '4096');
});

it('requires SEND_SINGLE, which every mode supports natively', function (): void {
    $content = TextContent::to('15550001111@s.whatsapp.net', 'Your order shipped.', 'idem-1');

    expect($content->capability())->toBe(ChannelCapability::SendSingle)
        ->and($content->plainText())->toBe('Your order shipped.')
        ->and($content->recipient())->toBe('15550001111@s.whatsapp.net')
        ->and($content->idempotencyKey())->toBe('idem-1');

    // Which is why a text send is never refused for a capability reason on any backend.
    foreach (ChannelMode::cases() as $mode) {
        expect($mode->supportFor(ChannelCapability::SendSingle)->isNative())->toBeTrue($mode->value);
    }
});

/*
|--------------------------------------------------------------------------
| SendReceipt — the boundary with the transport ack
|--------------------------------------------------------------------------
*/

it('refuses a send acknowledged without anything to reconcile it by', function (): void {
    $make = fn (string $id): SendReceipt => new SendReceipt(
        mode: ChannelMode::Baileys,
        providerMessageId: $id,
        recipient: 'jid',
        idempotencyKey: 'idem-1',
        capability: ChannelCapability::SendSingle,
    );

    expect(fn (): SendReceipt => $make(''))
        ->toThrow(InvalidArgumentException::class, 'provider message id')
        ->and($make('wamid.1')->providerMessageId)->toBe('wamid.1');
});

it('requires the partner on a BSP receipt and refuses one elsewhere', function (): void {
    $make = fn (ChannelMode $mode, ?BspProvider $provider): SendReceipt => new SendReceipt(
        mode: $mode,
        providerMessageId: 'wamid.1',
        recipient: 'jid',
        idempotencyKey: 'idem-1',
        capability: ChannelCapability::SendSingle,
        provider: $provider,
    );

    expect(fn (): SendReceipt => $make(ChannelMode::BspGateway, null))
        ->toThrow(InvalidArgumentException::class, 'partner')
        ->and(fn (): SendReceipt => $make(ChannelMode::CloudApi, BspProvider::Twilio))
        ->toThrow(InvalidArgumentException::class, 'TWILIO')
        ->and($make(ChannelMode::BspGateway, BspProvider::Wati)->provider)->toBe(BspProvider::Wati);
});

it('promotes a transport ack by adding exactly what the wire could not know', function (): void {
    // The seam between BridgeClient::sendText() and ChannelDriver::send(): the wire supplied
    // the message id and the address it resolved to; the driver adds mode, key, capability,
    // and whether it had to degrade.
    $sent = new SentMessageDto(
        waMessageId: 'wamid.abc',
        jid: '15550002222@s.whatsapp.net',
        sentAt: CarbonImmutable::parse('2025-01-01T10:00:00Z'),
    );

    $receipt = SendReceipt::fromSentMessage(
        mode: ChannelMode::Baileys,
        sent: $sent,
        idempotencyKey: 'idem-9',
        capability: ChannelCapability::Interactive,
        degraded: true,
    );

    expect($receipt->providerMessageId)->toBe('wamid.abc')
        ->and($receipt->recipient)->toBe('15550002222@s.whatsapp.net')
        ->and($receipt->acceptedAt)->toEqual($sent->sentAt)
        ->and($receipt->mode)->toBe(ChannelMode::Baileys)
        ->and($receipt->idempotencyKey)->toBe('idem-9')
        ->and($receipt->capability)->toBe(ChannelCapability::Interactive)
        // Interactive is `⚠️ if the WA version supports it` on Baileys, so a flattened send is
        // a legitimate success that the caller must still be able to see happened.
        ->and($receipt->degraded)->toBeTrue()
        ->and($receipt->isTemplated())->toBeFalse();

    expect(SendReceipt::fromSentMessage(
        mode: ChannelMode::CloudApi,
        sent: $sent,
        idempotencyKey: 'idem-10',
        capability: ChannelCapability::Template,
        templateName: 'order_update',
    )->isTemplated())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| TemplateRef — an identity, not a snapshot
|--------------------------------------------------------------------------
*/

it('refuses a template name or language no provider could hold', function (): void {
    expect(fn (): TemplateRef => new TemplateRef('Order Update', 'en_US'))
        ->toThrow(InvalidArgumentException::class, 'template name')
        ->and(fn (): TemplateRef => new TemplateRef('order_update', 'en-US'))
        ->toThrow(InvalidArgumentException::class, 'underscore')
        ->and((new TemplateRef('order_update', 'en'))->key())->toBe('order_update:en')
        ->and((new TemplateRef('order_update', 'pt_BR'))->key())->toBe('order_update:pt_BR');
});

it('references a registry row by (name, language, credential) and caches nothing else', function (): void {
    $template = new CloudApiTemplate([
        'credential_id' => 'cred-1',
        'name' => 'order_update',
        'language' => 'en_US',
        'category' => ChannelTemplateCategory::Utility,
        'body' => 'Hello {{1}}',
    ]);

    $ref = TemplateRef::fromModel($template);

    expect($ref->name)->toBe('order_update')
        ->and($ref->language)->toBe('en_US')
        ->and($ref->credentialId)->toBe('cred-1')
        ->and($ref->category)->toBe(ChannelTemplateCategory::Utility);

    // Neither the body nor the approval status is carried: both change independently of any
    // send, so sendTemplate() re-reads the row (task 8.4).
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(TemplateRef::class))->getProperties(),
    );

    sort($properties);

    expect($properties)->toBe(['category', 'credentialId', 'language', 'name']);
});

/*
|--------------------------------------------------------------------------
| ChannelHealth & RegistrationResult — scrubbed by construction
|--------------------------------------------------------------------------
*/

it('cannot be built without the credentials its detail is scrubbed against', function (): void {
    foreach ([ChannelHealth::class, RegistrationResult::class] as $type) {
        $constructor = (new ReflectionClass($type))->getConstructor();

        expect($constructor)->not->toBeNull()
            ->and($constructor?->isPrivate())->toBeTrue($type);
    }
});

it('never lets a probe report the token it was probing with', function (): void {
    // The most likely leak on the whole driver surface: a 401 body that echoes part of the
    // Authorization header, interpolated into a message a tenant is shown and an audit row
    // keeps.
    $credentials = credentialsFor(ChannelMode::CloudApi, ['access_token' => 'EAAGm0PX4ZoBACCESSTOKEN']);

    $health = ChannelHealth::unhealthy(
        $credentials,
        'Meta rejected Bearer EAAGm0PX4ZoBACCESSTOKEN: session expired.',
        latencyMs: -5,
    );

    expect($health->detail)->toBe('Meta rejected Bearer [redacted]: session expired.')
        ->and($health->healthy)->toBeFalse()
        ->and($health->isUsable())->toBeFalse()
        ->and($health->mode)->toBe(ChannelMode::CloudApi)
        ->and($health->provider)->toBeNull()
        // A negative measurement is a clock artefact, not a fast probe.
        ->and($health->latencyMs)->toBe(0);

    $ok = ChannelHealth::healthy($credentials, latencyMs: 42);

    expect($ok->healthy)->toBeTrue()
        ->and($ok->isUsable())->toBeTrue()
        ->and($ok->latencyMs)->toBe(42)
        ->and($ok->checkedAt)->toBeInstanceOf(CarbonImmutable::class);

    // The mode and provider are read from the credentials, so a report cannot be attributed
    // to a mode other than the one that was probed.
    $bsp = ChannelHealth::healthy(
        credentialsFor(ChannelMode::BspGateway, provider: BspProvider::ThreeSixtyDialog),
    );

    expect($bsp->mode)->toBe(ChannelMode::BspGateway)
        ->and($bsp->provider)->toBe(BspProvider::ThreeSixtyDialog);
});

it('separates a live registration from one the provider has not finished', function (): void {
    $credentials = credentialsFor(ChannelMode::CloudApi, ['access_token' => 'EAAG-secret-token']);

    $live = RegistrationResult::live(
        $credentials,
        providerNumberId: '109876543210',
        routeKey: 'r0ut3-k3y',
        callbackUrl: 'https://app.test/webhooks/cloud-api/r0ut3-k3y',
    );

    expect($live->isLive())->toBeTrue()
        ->and($live->isPending())->toBeFalse()
        ->and($live->providerNumberId)->toBe('109876543210')
        ->and($live->hasCallback())->toBeTrue()
        ->and($live->completedAt)->toBeInstanceOf(CarbonImmutable::class);

    // "Live" without the id every later send addresses itself with is a claim nothing can act
    // on — so the session would be marked sendable and fail on its first send.
    expect(fn (): RegistrationResult => RegistrationResult::live($credentials, '  '))
        ->toThrow(InvalidArgumentException::class, 'provider number id');

    $pending = RegistrationResult::pending(
        $credentials,
        'Awaiting verification; token EAAG-secret-token accepted.',
    );

    expect($pending->isLive())->toBeFalse()
        ->and($pending->isPending())->toBeTrue()
        ->and($pending->providerNumberId)->toBeNull()
        ->and($pending->hasCallback())->toBeFalse()
        ->and($pending->detail)->toBe('Awaiting verification; token [redacted] accepted.');
});
