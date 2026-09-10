<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Enums\InboundEventKind;
use App\Enums\MediaKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Bridge\UnknownSessionException;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ChannelOperationException;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\ChannelTemplateException;
use App\Exceptions\Channel\ModeCapabilityException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\ChannelCredential;
use App\Models\CloudApiTemplate;
use App\Models\IdempotencyKey;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionStateDto;
use App\Services\Channel\BaileysChannelDriver;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelHealth;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\CloudApiChannelDriver;
use App\Services\Channel\InboundEvent;
use App\Services\Channel\ModeGuardedChannelDriver;
use App\Services\Channel\RegistrationResult;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use App\Services\Channel\TextContent;
use App\Services\Reliability\ErrorClassifier;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| CloudApiChannelDriver (Req 8.2, 8.4, 8.12 / A8)
|--------------------------------------------------------------------------
| The first *official* mode, so this file pins the things that are new with it rather than
| re-asserting the contract (`ChannelDriverContractTest`) or the matrix (`ChannelCapabilityTest`):
|
|   1. **the cross-tenant webhook injection is refused.** One Meta app fronts many WABAs, so a
|      validly-signed body is not a body about *this* tenant's number. The recipient is taken
|      from the credentials and the payload's claim checked against it (Property 23);
|   2. **the verify-token handshake** is answered only on a constant-time match, and echoes
|      nothing otherwise — a token compared with `==` is a webhook takeover;
|   3. **Meta's error envelope maps onto the platform's `ErrorClass`**, through the existing
|      classifier chain: a `400` carrying code `130429` is a rate limit and defers, and a `190`
|      is `AUTH` and never retries;
|   4. **`sendTemplate()` refuses locally** — unknown, unapproved, wrong account, wrong
|      variables — so a rejection is never charged to the number's quality rating;
|   5. **the batch answer**: Meta puts several events in one request, `parseWebhookBatch()`
|      returns all of them, and every event says how many there were;
|   6. **`send()` is idempotent** on the content's key, with 7.1's scope convention.
|
| `Http::fake()` stands in for `graph.facebook.com`; the real credential store (with real
| envelope encryption), idempotency store, circuit breaker and tenant scoping are all live.
*/

beforeEach(function (): void {
    config()->set('wa.channel.cloud_api.base_url', 'https://graph.test');
    config()->set('wa.channel.cloud_api.api_version', 'v21.0');
});

const CLOUD_API_TOKEN = 'EAAGm0PX4ZoBACCESSTOKEN0123456789';
const CLOUD_API_APP_SECRET = 'e3b0c44298fc1c149afbf4c8996fb924';
const CLOUD_API_VERIFY_TOKEN = 'verify-token-2c26b46b68ff';
const CLOUD_API_PHONE_ID = '109876543210';
const CLOUD_API_WABA_ID = '102290129340398';

/**
 * A tenant bound as the acting one, with `CLOUD_API` credentials stored and a session on that
 * mode.
 *
 * @param  array<string, mixed>  $secrets  merged over the complete §2.7 set
 * @param  array<string, mixed>  $config  merged over the complete §2.7 set
 * @return array{0: Tenant, 1: Session, 2: ChannelCredential}
 */
function cloudApiTenant(array $secrets = [], array $config = [], ?string $phoneNumberId = null): array
{
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $credential = app(ChannelCredentialStore::class)->put(
        $tenant,
        ChannelMode::CloudApi,
        secrets: array_merge([
            'access_token' => CLOUD_API_TOKEN,
            'verify_token' => CLOUD_API_VERIFY_TOKEN,
            'app_secret' => CLOUD_API_APP_SECRET,
        ], $secrets),
        config: array_merge([
            'waba_id' => CLOUD_API_WABA_ID,
            'phone_number_id' => $phoneNumberId ?? CLOUD_API_PHONE_ID,
        ], $config),
    );

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::CloudApi,
        'status' => SessionStatus::Connected,
    ]);

    return [$tenant, $session, $credential];
}

function cloudApiDriver(): CloudApiChannelDriver
{
    return app(CloudApiChannelDriver::class);
}

function cloudApiCredentialsFor(Tenant $tenant): ChannelCredentials
{
    $credentials = app(ChannelCredentialStore::class)->for($tenant, ChannelMode::CloudApi);

    expect($credentials)->not->toBeNull();
    assert($credentials instanceof ChannelCredentials);

    return $credentials;
}

/**
 * Meta's own successful message-send response.
 *
 * @return array<string, mixed>
 */
function metaMessageResponse(string $messageId = 'wamid.HBgLOTE5ODEyMzQ1Njc4', string $waId = '919812345678'): array
{
    return [
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => $waId, 'wa_id' => $waId]],
        'messages' => [['id' => $messageId, 'message_status' => 'accepted']],
    ];
}

/**
 * Meta's error envelope, as the Graph API sends it.
 *
 * @return array<string, mixed>
 */
function metaError(int $code, string $type = 'OAuthException', ?int $subcode = null): array
{
    $error = [
        'message' => 'Invalid OAuth access token - Cannot parse access token EAAGm0PX4ZoBACCESSTOKEN0123456789',
        'type' => $type,
        'code' => $code,
        'fbtrace_id' => 'A1bCdEfGhIjK',
    ];

    if ($subcode !== null) {
        $error['error_subcode'] = $subcode;
    }

    return ['error' => $error];
}

/**
 * A Cloud API webhook POST, signed the way Meta signs one.
 *
 * @param  array<string, mixed>  $payload
 */
function metaWebhook(
    array $payload,
    string $appSecret = CLOUD_API_APP_SECRET,
    ?string $signature = null,
    bool $sign = true,
): Request {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = $signature;
    } elseif ($sign) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $appSecret);
    }

    return Request::create('/webhooks/cloud-api/route-key', 'POST', [], [], [], $headers, $body);
}

/**
 * One `entry[].changes[]` envelope, the shape Meta actually posts.
 *
 * @param  array<string, mixed>  $value
 * @return array<string, mixed>
 */
function metaWebhookBody(array $value, string $field = 'messages', string $object = 'whatsapp_business_account'): array
{
    return [
        'object' => $object,
        'entry' => [[
            'id' => CLOUD_API_WABA_ID,
            'changes' => [['field' => $field, 'value' => $value]],
        ]],
    ];
}

/**
 * The `value` of a `messages` change, with Meta's `metadata` block.
 *
 * @param  list<array<string, mixed>>  $messages
 * @param  list<array<string, mixed>>  $statuses
 * @return array<string, mixed>
 */
function metaMessagesValue(array $messages = [], array $statuses = [], string $phoneNumberId = CLOUD_API_PHONE_ID): array
{
    $value = [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => $phoneNumberId],
    ];

    if ($messages !== []) {
        $value['contacts'] = [['profile' => ['name' => 'Asha'], 'wa_id' => '919812345678']];
        $value['messages'] = $messages;
    }

    if ($statuses !== []) {
        $value['statuses'] = $statuses;
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| What this backend is
|--------------------------------------------------------------------------
*/

it('is an official mode whose policy comes from the matrix, not from the driver', function (): void {
    $driver = cloudApiDriver();

    expect($driver->mode())->toBe(ChannelMode::CloudApi)
        ->and($driver->mode()->isOfficial())->toBeTrue()
        // Property 24: an official route follows the provider's rules instead of the warm-up ramp.
        ->and($driver->requiresAntiBan())->toBeFalse()
        ->and($driver->mode()->requiresTenantCredentials())->toBeTrue()
        ->and($driver->mode()->enforcesSessionWindow())->toBeTrue();

    // Every cell, from the matrix rather than a hand-written list.
    foreach (ChannelCapability::cases() as $capability) {
        expect($driver->supportFor($capability))->toBe($capability->supportOn(ChannelMode::CloudApi))
            ->and($driver->supports($capability))->toBe($capability->supportedOn(ChannelMode::CloudApi));
    }

    // The five Baileys-only rows are `❌` here, which is what Property 21 is about.
    foreach ([
        ChannelCapability::Groups,
        ChannelCapability::Welcome,
        ChannelCapability::Extraction,
        ChannelCapability::Tagging,
        ChannelCapability::Channels,
    ] as $refused) {
        expect($driver->supports($refused))->toBeFalse($refused->value);
    }

    // ...and the ones this mode is chosen for are native.
    expect($driver->supports(ChannelCapability::Template))->toBeTrue()
        ->and($driver->supports(ChannelCapability::Interactive))->toBeTrue()
        ->and($driver->supports(ChannelCapability::DeliveryReceipts))->toBeTrue();
});

it('is registered for CLOUD_API in the container and reachable through the router, wrapped', function (): void {
    [, $session] = cloudApiTenant();

    $driver = app(ChannelRouter::class)->driverFor($session);

    // The registry entry of task 7.2 — a mode with no entry raises instead, so this asserts the
    // wiring and not just the class.
    expect($driver)->toBeInstanceOf(ModeGuardedChannelDriver::class)
        ->and($driver->mode())->toBe(ChannelMode::CloudApi);

    assert($driver instanceof ModeGuardedChannelDriver);
    expect($driver->inner())->toBeInstanceOf(CloudApiChannelDriver::class);
});

it('refuses a capability this mode does not have, before any driver call', function (): void {
    [, $session] = cloudApiTenant();
    Http::fake();

    foreach (ChannelCapability::unsupportedBy(ChannelMode::CloudApi) as $capability) {
        try {
            app(ChannelRouter::class)->assertSupported($session, $capability);
            $thrown = null;
        } catch (ModeCapabilityException $refused) {
            $thrown = $refused;
        }

        expect($thrown)->toBeInstanceOf(ModeCapabilityException::class, $capability->value)
            ->and($thrown?->mode)->toBe(ChannelMode::CloudApi)
            ->and($thrown?->capability)->toBe($capability)
            // The remedy a panel shows: these exist on the Baileys bridge.
            ->and($thrown?->isAvailableElsewhere())->toBeTrue();
    }

    Http::assertNothingSent();
});

it('shares task 7.1\'s idempotency scope convention rather than minting a second one', function (): void {
    // Guarding a deliberate duplication: `DedupesChannelSends` carries the same literal
    // `BaileysChannelDriver` declares, and this is what fails the day one of them moves.
    expect(CloudApiChannelDriver::SEND_SCOPE_PREFIX)->toBe(BaileysChannelDriver::SEND_SCOPE_PREFIX)
        ->and(CloudApiChannelDriver::SEND_SCOPE_PREFIX)->toBe('channel.send:');
});

/*
|--------------------------------------------------------------------------
| send()
|--------------------------------------------------------------------------
*/

it('sends text to the Graph API and promotes Meta\'s acknowledgement into a receipt', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse())]);

    $receipt = cloudApiDriver()->send(
        $session,
        TextContent::to('919812345678', 'Your order shipped.', 'order-4417'),
    );

    expect($receipt->mode)->toBe(ChannelMode::CloudApi)
        ->and($receipt->providerMessageId)->toBe('wamid.HBgLOTE5ODEyMzQ1Njc4')
        // Meta's own `wa_id`, not the number that was asked for: it resolves some numbers to a
        // different account id, and the receipt records what was actually addressed.
        ->and($receipt->recipient)->toBe('919812345678')
        ->and($receipt->idempotencyKey)->toBe('order-4417')
        ->and($receipt->capability)->toBe(ChannelCapability::SendSingle)
        // `SEND_SINGLE` is `✅` here, so nothing was flattened.
        ->and($receipt->degraded)->toBeFalse()
        ->and($receipt->provider)->toBeNull()
        ->and($receipt->isTemplated())->toBeFalse()
        // Meta sends no accepted-at timestamp, and a fabricated one would be indistinguishable
        // from a real one on the delivery board.
        ->and($receipt->acceptedAt)->toBeNull();

    Http::assertSent(function (Illuminate\Http\Client\Request $r): bool {
        return $r->url() === 'https://graph.test/v21.0/'.CLOUD_API_PHONE_ID.'/messages'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer '.CLOUD_API_TOKEN)
            && $r->data() === [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => '919812345678',
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => 'Your order shipped.'],
            ];
    });

    Http::assertSentCount(1);
});

it('normalises a JID recipient to the digits Meta addresses, and refuses a group', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse())]);

    cloudApiDriver()->send(
        $session,
        TextContent::to('919812345678@s.whatsapp.net', 'Hello', 'jid-key'),
    );

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => is_array($r->data())
        && ($r->data()['to'] ?? null) === '919812345678');

    // A group JID stripped of its suffix is a plausible-looking number belonging to a stranger,
    // and Meta has no group messaging at all (`GROUPS` is `❌`).
    expect(fn (): SendReceipt => cloudApiDriver()->send(
        $session,
        TextContent::to('120363021234567890@g.us', 'Hello', 'group-key'),
    ))->toThrow(InvalidArgumentException::class, 'group JID');
});

it('sends once per idempotency key and replays the original receipt', function (): void {
    [$tenant, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse('wamid.once'))]);

    $content = TextContent::to('919812345678', 'Your order shipped.', 'order-4417');
    $driver = cloudApiDriver();

    $first = $driver->send($session, $content);
    $second = $driver->send($session, $content);

    // Meta has no idempotency key of its own, which is exactly why the local ledger matters: a
    // duplicate here is a message a customer reads twice.
    expect($second->providerMessageId)->toBe($first->providerMessageId)
        ->and($second->recipient)->toBe($first->recipient)
        ->and($second->capability)->toBe($first->capability)
        ->and($second->degraded)->toBe($first->degraded);

    Http::assertSentCount(1);

    // 7.1's scope: the tenant is in the namespace because `idempotency_keys` is not
    // tenant-scoped and a caller-chosen key could collide across tenants.
    expect(IdempotencyKey::query()->where('scope', 'channel.send:'.$tenant->id)->count())->toBe(1);
});

it('refuses to reuse one key for a different send rather than answering with the first receipt', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse('wamid.first'))]);

    $driver = cloudApiDriver();
    $driver->send($session, TextContent::to('919812345678', 'First', 'order-4417'));

    expect(fn (): SendReceipt => $driver->send(
        $session,
        TextContent::to('919899999999', 'Second', 'order-4417'),
    ))->toThrow(IdempotencyKeyReuseException::class);

    Http::assertSentCount(1);
});

it('does not record a key when Meta refuses, so a retry may send', function (): void {
    [, $session] = cloudApiTenant();

    Sleep::fake();

    // Three responses: the inline guard spends its own attempt budget (`wa.channel.guard`)
    // before a retryable failure reaches the driver.
    Http::fake(['graph.test/*' => Http::sequence()
        ->push(metaError(131_000, 'OAuthException'), 500)
        ->push(metaError(131_000, 'OAuthException'), 500)
        ->push(metaMessageResponse('wamid.retried'))]);

    $content = TextContent::to('919812345678', 'Your order shipped.', 'order-4417');
    $driver = cloudApiDriver();

    expect(fn (): SendReceipt => $driver->send($session, $content))
        ->toThrow(ChannelRequestFailedException::class);

    // The failure is retryable, so the key must not have burned the work.
    expect($driver->send($session, $content)->providerMessageId)->toBe('wamid.retried');

    Http::assertSentCount(3);
});

it('refuses a send Meta acknowledged with no message id', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => []])]);

    // An unreconcilable "sent" is worse than a retry: no later delivery receipt could ever be
    // matched to it.
    expect(fn (): SendReceipt => cloudApiDriver()->send(
        $session,
        TextContent::to('919812345678', 'Your order shipped.', 'no-id'),
    ))->toThrow(BridgeUnreachableException::class);
});

it('refuses to send for a tenant with no Cloud API credentials, and never reroutes to Baileys', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::CloudApi,
        'status' => SessionStatus::Connected,
    ]);

    Http::fake();

    expect(fn (): SendReceipt => cloudApiDriver()->send(
        $session,
        TextContent::to('919812345678', 'Hello', 'no-creds'),
    ))->toThrow(ChannelCredentialException::class);

    Http::assertNothingSent();
});

it('refuses another tenant\'s session, because credentials are resolved for the session\'s owner', function (): void {
    [, $session] = cloudApiTenant();

    $intruder = Tenant::factory()->create();
    app(TenantContext::class)->set($intruder);

    Http::fake();

    // The store refuses a lookup for a tenant other than the bound one, so a foreign session
    // cannot be sent from with the caller's own token (Property 23).
    expect(fn (): SendReceipt => cloudApiDriver()->send(
        $session,
        TextContent::to('919812345678', 'Hello', 'cross-tenant'),
    ))->toThrow(CrossTenantAccessException::class);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Meta's error envelope → ErrorClass, through the existing classifier chain
|--------------------------------------------------------------------------
*/

it('carries Meta\'s error envelope into the typed refusal without quoting its prose', function (): void {
    [, $session] = cloudApiTenant();

    Sleep::fake();

    Http::fake(['graph.test/*' => Http::response(metaError(190, 'OAuthException', 463), 401)]);

    try {
        cloudApiDriver()->send($session, TextContent::to('919812345678', 'Hello', 'auth-fail'));
        $thrown = null;
    } catch (ChannelRequestFailedException $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(ChannelRequestFailedException::class)
        ->and($thrown?->mode)->toBe(ChannelMode::CloudApi)
        ->and($thrown?->status)->toBe(401)
        ->and($thrown?->errorCode)->toBe('190')
        ->and($thrown?->errorSubcode)->toBe('463')
        ->and($thrown?->errorType)->toBe('OAuthException')
        ->and($thrown?->traceId)->toBe('A1bCdEfGhIjK')
        ->and($thrown?->hasErrorCode(190))->toBeTrue()
        // Meta's `error.message` echoes the access token it was sent, so it is never carried.
        ->and($thrown?->getMessage())->not->toContain(CLOUD_API_TOKEN)
        ->and($thrown?->getMessage())->not->toContain('Cannot parse access token');
});

it('classifies Meta rate limits as retryable-with-backoff and an invalid token as fail-fast', function (): void {
    $classifier = app(ErrorClassifier::class);
    $policy = app(App\Services\Reliability\RetryPolicy::class);

    $refusal = static fn (int $code, int $status): ChannelRequestFailedException => ChannelRequestFailedException::refused(
        mode: ChannelMode::CloudApi,
        operation: 'cloud_api.message.text',
        status: $status,
        errorCode: (string) $code,
        errorType: 'OAuthException',
    );

    // The rows the task turns on. Note the statuses: Cloud API answers **400** for a throughput
    // limit, so a status-first reading would call this `VALIDATION` and fail fast.
    foreach ([[4, 400], [80_007, 400], [130_429, 400], [131_048, 400], [131_056, 400]] as [$code, $status]) {
        $class = $classifier->classify($refusal($code, $status));

        expect($class)->toBe(ErrorClass::RateLimit, 'code '.$code)
            ->and($class?->isDeferrable())->toBeTrue()
            ->and($policy->decideFor(ErrorClass::RateLimit, 1)->keepsWork())->toBeTrue();
    }

    // A plain `429` with no code Meta recognised is a rate limit by status alone.
    expect($classifier->classify(ChannelRequestFailedException::refused(
        mode: ChannelMode::CloudApi,
        operation: 'cloud_api.message.text',
        status: 429,
    )))->toBe(ErrorClass::RateLimit);

    // …and an invalid or revoked token is never retried, structurally.
    expect($classifier->classify($refusal(190, 401)))->toBe(ErrorClass::Auth)
        ->and(ErrorClass::Auth->isRetryableByNature())->toBeFalse()
        ->and($policy->decideFor(ErrorClass::Auth, 1)->shouldRetry)->toBeFalse();
});

it('classifies the rest of Meta\'s envelope the way the retry matrix expects', function (): void {
    $classifier = app(ErrorClassifier::class);

    $cases = [
        // A permission failure: the token is valid and may not manage this number.
        [200, 403, ErrorClass::Permission],
        [131_031, 403, ErrorClass::Permission],
        // There is nobody to deliver to, ever.
        [131_026, 400, ErrorClass::NotOnWhatsApp],
        // Template problems and the window rule: deterministic, so waiting changes nothing.
        [132_001, 400, ErrorClass::Validation],
        [132_015, 400, ErrorClass::Validation],
        [131_047, 400, ErrorClass::Validation],
        [133_010, 400, ErrorClass::Validation],
        // Meta's own transient trouble keeps the work.
        [131_000, 500, ErrorClass::Network],
        [2, 500, ErrorClass::Network],
    ];

    foreach ($cases as [$code, $status, $expected]) {
        expect($classifier->classify(ChannelRequestFailedException::refused(
            mode: ChannelMode::CloudApi,
            operation: 'cloud_api.message.text',
            status: $status,
            errorCode: (string) $code,
        )))->toBe($expected, 'code '.$code);
    }

    // A code this release does not know falls back to Meta's status, and an unreachable Meta is
    // the bridge classifier's `BRIDGE` — both keep the work.
    expect($classifier->classify(ChannelRequestFailedException::refused(
        mode: ChannelMode::CloudApi,
        operation: 'x',
        status: 503,
        errorCode: '999999',
    )))->toBe(ErrorClass::Network)
        ->and($classifier->classify(BridgeUnreachableException::transportFailed('cloud_api.message.text')))
        ->toBe(ErrorClass::Bridge);
});

it('leaves another official mode\'s refusal to that mode\'s own classifier', function (): void {
    // Provider error numbers collide: Meta's `4` is an app-level rate limit, and an On-Premise
    // or partner `4` is whatever that provider decided. Tasks 7.3 and 7.4 add a classifier each,
    // and this is what keeps Meta's policy from being applied to their numbers.
    expect((new App\Services\Channel\CloudApiErrorClassifier)->classify(
        ChannelRequestFailedException::refused(
            mode: ChannelMode::OnPremise,
            operation: 'on_premise.message.text',
            status: 400,
            errorCode: '4',
        ),
    ))->toBeNull();
});

it('honours a Retry-After Meta named, in its numeric form only', function (): void {
    [, $session] = cloudApiTenant();

    Sleep::fake();

    Http::fake(['graph.test/*' => Http::response(
        metaError(130_429),
        400,
        ['Retry-After' => '120'],
    )]);

    try {
        cloudApiDriver()->send($session, TextContent::to('919812345678', 'Hello', 'rate-limited'));
        $thrown = null;
    } catch (ChannelRequestFailedException $refused) {
        $thrown = $refused;
    }

    expect($thrown?->retryAfterSeconds)->toBe(120)
        ->and($thrown?->getHeaders())->toBe(['Retry-After' => '120'])
        // A rate limit is a transient condition, so the status reported outward is 503 rather
        // than Meta's own 400 — which would tell an API client the request was malformed.
        ->and($thrown?->getStatusCode())->toBe(503);
});

/*
|--------------------------------------------------------------------------
| sendTemplate()
|--------------------------------------------------------------------------
*/

it('sends an approved template with Meta\'s positional body parameters', function (): void {
    [$tenant, $session, $credential] = cloudApiTenant();

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'order_update',
        'language' => 'en_US',
        'body' => 'Hello {{1}}, your order {{2}} is on its way.',
    ]);

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse('wamid.template'))]);

    $receipt = cloudApiDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($template),
        ['1' => 'Asha', '2' => '#4417'],
        '919812345678',
        'tpl-4417',
    );

    expect($receipt->providerMessageId)->toBe('wamid.template')
        ->and($receipt->capability)->toBe(ChannelCapability::Template)
        ->and($receipt->templateName)->toBe('order_update')
        ->and($receipt->isTemplated())->toBeTrue()
        ->and($receipt->degraded)->toBeFalse();

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->data() === [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => '919812345678',
        'type' => 'template',
        'template' => [
            'name' => 'order_update',
            // Underscore-separated, as WhatsApp writes locales — a hyphen is the commonest way a
            // template send fails at Meta with an unhelpful error.
            'language' => ['code' => 'en_US'],
            'components' => [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => 'Asha'],
                    ['type' => 'text', 'text' => '#4417'],
                ],
            ]],
        ],
    ]);
});

it('orders body parameters by placeholder position, not by where they appear in the copy', function (): void {
    [$tenant, $session, $credential] = cloudApiTenant();

    // A translation legitimately reorders placeholders; Meta's parameters stay positional.
    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'reordered',
        'language' => 'de_DE',
        'body' => 'Ihre Bestellung {{2}} ist unterwegs, {{1}}.',
    ]);

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse())]);

    cloudApiDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($template),
        ['2' => '#4417', '1' => 'Asha'],
        '919812345678',
        'tpl-reordered',
    );

    Http::assertSent(function (Illuminate\Http\Client\Request $r): bool {
        $data = $r->data();

        return is_array($data)
            && $data['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'Asha'],
                ['type' => 'text', 'text' => '#4417'],
            ];
    });
});

it('omits components entirely for a template with no placeholders', function (): void {
    [$tenant, $session, $credential] = cloudApiTenant();

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'static_notice',
        'language' => 'en',
        'body' => 'Your account is now verified.',
    ]);

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse())]);

    cloudApiDriver()->sendTemplateTo($session, TemplateRef::fromModel($template), [], '919812345678', 'tpl-static');

    // Meta refuses `"components": []` on some template shapes, and a template without
    // placeholders genuinely has none.
    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => is_array($r->data())
        && ! array_key_exists('components', $r->data()['template']));
});

it('refuses an unapproved, paused, unknown, or foreign-account template before any Graph API call', function (): void {
    [$tenant, $session, $credential] = cloudApiTenant();
    Http::fake();

    $pending = CloudApiTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'pending_one',
        'language' => 'en_US',
    ]);

    $paused = CloudApiTemplate::factory()->paused()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'paused_one',
        'language' => 'en_US',
    ]);

    $driver = cloudApiDriver();

    // Awaiting review: recoverable on its own, which is what a panel needs to know.
    try {
        $driver->sendTemplateTo($session, TemplateRef::fromModel($pending), [], '919812345678', 'k1');
        $thrown = null;
    } catch (ChannelTemplateException $refused) {
        $thrown = $refused;
    }

    expect($thrown?->templateKey)->toBe('pending_one:en_US')
        ->and($thrown?->status)->toBe(App\Enums\ChannelTemplateStatus::Pending)
        ->and($thrown?->mayBecomeSendable())->toBeTrue()
        ->and($thrown?->getStatusCode())->toBe(422);

    // Paused is refused as firmly as pending: Meta has stopped accepting it, so attempting the
    // send would turn a local block into a remote failure mid-campaign.
    expect(fn (): SendReceipt => $driver->sendTemplateTo(
        $session,
        TemplateRef::fromModel($paused),
        [],
        '919812345678',
        'k2',
    ))->toThrow(ChannelTemplateException::class, 'PAUSED');

    // No row at all — a different remedy, so a different named constructor.
    expect(fn (): SendReceipt => $driver->sendTemplateTo(
        $session,
        new TemplateRef('never_created', 'en_US'),
        [],
        '919812345678',
        'k3',
    ))->toThrow(ChannelTemplateException::class, 'No template [never_created:en_US] is registered');

    // Approval is per provider account, and the reference says which one it came from.
    $otherAccount = ChannelCredential::factory()->create(['tenant_id' => $tenant->id, 'label' => 'sandbox']);

    expect(fn (): SendReceipt => $driver->sendTemplateTo(
        $session,
        new TemplateRef('pending_one', 'en_US', $otherAccount->id),
        [],
        '919812345678',
        'k4',
    ))->toThrow(ChannelTemplateException::class, 'different');

    Http::assertNothingSent();
});

it('refuses a template whose variables do not fill its placeholders exactly', function (): void {
    [$tenant, $session, $credential] = cloudApiTenant();
    Http::fake();

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'two_slots',
        'language' => 'en_US',
        'body' => 'Hello {{1}}, your order {{2}} is on its way.',
    ]);

    $ref = TemplateRef::fromModel($template);
    $driver = cloudApiDriver();

    // Missing: Meta would reject it and charge the rejection to the number's quality rating.
    try {
        $driver->sendTemplateTo($session, $ref, ['1' => 'Asha'], '919812345678', 'missing');
        $thrown = null;
    } catch (ChannelTemplateException $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(ChannelTemplateException::class)
        ->and($thrown?->getMessage())->toContain('missing 2')
        // Only the placeholder *names*: the values are per-send content (Req 7.3 / A7).
        ->and($thrown?->getMessage())->not->toContain('Asha');

    // Surplus is refused too: it is nearly always a renamed placeholder, and the send would
    // otherwise go out with a stale value in the wrong slot.
    expect(fn (): SendReceipt => $driver->sendTemplateTo(
        $session,
        $ref,
        ['1' => 'Asha', '2' => '#4417', '3' => 'left over'],
        '919812345678',
        'surplus',
    ))->toThrow(ChannelTemplateException::class, 'unexpected 3');

    Http::assertNothingSent();
});

it('reads the recipient and key from the reserved $vars of the contract\'s own signature', function (): void {
    [$tenant, $session, $credential] = cloudApiTenant();

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'reserved_vars',
        'language' => 'en_US',
        'body' => 'Hello {{1}}.',
    ]);

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse('wamid.reserved'))]);

    $driver = cloudApiDriver();
    $ref = TemplateRef::fromModel($template);

    // `ChannelDriver::sendTemplate()` has no recipient and no idempotency-key parameter — a gap
    // in design § 2.4 that task 8.4 should close. Until then the two travel as reserved keys,
    // which cannot collide with a placeholder (a leading `_` is not a legal Meta parameter name).
    $receipt = $driver->sendTemplate($session, $ref, [
        '1' => 'Asha',
        CloudApiChannelDriver::TEMPLATE_RECIPIENT_VAR => '919812345678',
        CloudApiChannelDriver::TEMPLATE_KEY_VAR => 'tpl-reserved',
    ]);

    expect($receipt->providerMessageId)->toBe('wamid.reserved')
        ->and($receipt->idempotencyKey)->toBe('tpl-reserved')
        ->and($receipt->templateName)->toBe('reserved_vars');

    // The reserved keys are stripped before the components are built, so they are not sent as a
    // third parameter.
    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => is_array($r->data())
        && $r->data()['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => 'Asha']]);

    // …and an omitted one is a loud local failure rather than an unreconcilable receipt.
    expect(fn (): SendReceipt => $driver->sendTemplate($session, $ref, ['1' => 'Asha']))
        ->toThrow(InvalidArgumentException::class, 'sendTemplateTo()');
});

it('dedups a template send separately from a free-form send that shares its key', function (): void {
    [$tenant, $session, $credential] = cloudApiTenant();

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credential->id,
        'name' => 'same_key',
        'language' => 'en_US',
        'body' => 'Hello.',
    ]);

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse())]);

    $driver = cloudApiDriver();
    $driver->send($session, TextContent::to('919812345678', 'Free form', 'shared-key'));

    // Replaying the text send's receipt here would report a template send that never happened,
    // with `templateName` of null — so the fingerprint discriminates on the shape.
    expect(fn (): SendReceipt => $driver->sendTemplateTo(
        $session,
        TemplateRef::fromModel($template),
        [],
        '919812345678',
        'shared-key',
    ))->toThrow(IdempotencyKeyReuseException::class);
});

/*
|--------------------------------------------------------------------------
| parseWebhook() — origin, then recipient, then shape
|--------------------------------------------------------------------------
*/

it('normalises a signed inbound message into the canonical event', function (): void {
    [$tenant, $session] = cloudApiTenant();

    $request = metaWebhook(metaWebhookBody(metaMessagesValue([[
        'from' => '919812345678',
        'id' => 'wamid.HBgLOTE5ODEyMzQ1Njc4FQIAEhgg',
        'timestamp' => '1735786800',
        'type' => 'text',
        'text' => ['body' => 'is my order shipped?'],
    ]])));

    $event = cloudApiDriver()->parseWebhook($request, cloudApiCredentialsFor($tenant));

    expect($event->kind)->toBe(InboundEventKind::Message)
        ->and($event->mode)->toBe(ChannelMode::CloudApi)
        // From the credentials the driver was handed, never from the payload.
        ->and($event->tenantId)->toBe($tenant->id)
        ->and($event->providerMessageId)->toBe('wamid.HBgLOTE5ODEyMzQ1Njc4FQIAEhgg')
        ->and($event->from)->toBe('919812345678')
        ->and($event->text)->toBe('is my order shipped?')
        // The tenant number the payload is addressed to — the credentials' value, which the
        // payload's claim was checked against.
        ->and($event->channelIdentity)->toBe(CLOUD_API_PHONE_ID)
        // Meta's own clock, never `now()`: two receipts that arrive out of order can only be
        // ordered by the provider's timestamp.
        ->and($event->occurredAt?->toIso8601String())->toBe('2025-01-02T03:00:00+00:00')
        ->and($event->isActionable())->toBeTrue()
        ->and($event->contentHash())->toBe(hash('sha256', 'is my order shipped?'))
        // Req 7.3 / A7: the body is content, so debug output carries a digest instead.
        ->and($event->__debugInfo())->not->toContain('is my order shipped?')
        // A single-event body still says so, which is what makes a batch detectable.
        ->and($event->payloadValue(CloudApiChannelDriver::BATCH_SIZE_KEY))->toBe(1)
        ->and($event->payloadValue(CloudApiChannelDriver::BATCH_INDEX_KEY))->toBe(0);
});

it('reads an interactive reply and a template quick reply as what the customer said', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);
    $driver = cloudApiDriver();

    $button = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue([[
        'from' => '919812345678',
        'id' => 'wamid.button',
        'type' => 'interactive',
        'interactive' => [
            'type' => 'button_reply',
            'button_reply' => ['id' => 'track_order', 'title' => 'Track my order'],
        ],
    ]]))), $credentials);

    $row = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue([[
        'from' => '919812345678',
        'id' => 'wamid.list',
        'type' => 'interactive',
        'interactive' => [
            'type' => 'list_reply',
            'list_reply' => ['id' => 'plan_pro', 'title' => 'Pro plan'],
        ],
    ]]))), $credentials);

    $quickReply = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue([[
        'from' => '919812345678',
        'id' => 'wamid.quick',
        'type' => 'button',
        'button' => ['text' => 'Stop', 'payload' => 'STOP'],
    ]]))), $credentials);

    $image = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue([[
        'from' => '919812345678',
        'id' => 'wamid.image',
        'type' => 'image',
        'image' => ['id' => '1234', 'mime_type' => 'image/jpeg'],
    ]]))), $credentials);

    // The engine sees the words; the reply **id** a flow correlates on stays in the payload.
    expect($button->text)->toBe('Track my order')
        ->and($row->text)->toBe('Pro plan')
        ->and($quickReply->text)->toBe('Stop')
        // A media message has no words at all, which is `null` and not an invented caption.
        ->and($image->text)->toBeNull()
        ->and($image->kind)->toBe(InboundEventKind::Message);

    $interactive = $button->payloadValue('message');
    expect(is_array($interactive) ? $interactive['interactive']['button_reply']['id'] : null)->toBe('track_order');
});

it('normalises each receipt kind and carries a redacted failure reason on a failure only', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);
    $driver = cloudApiDriver();

    $receipt = static fn (string $status, array $extra = []): array => array_merge([
        'id' => 'wamid.out',
        'status' => $status,
        'timestamp' => '1735786800',
        'recipient_id' => '919812345678',
    ], $extra);

    $sent = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue(statuses: [$receipt('sent')]))), $credentials);
    $delivered = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue(statuses: [$receipt('delivered')]))), $credentials);
    $read = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue(statuses: [$receipt('read')]))), $credentials);
    $failed = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue(statuses: [$receipt('failed', [
        'errors' => [[
            'code' => 131_026,
            'title' => 'Message Undeliverable',
            'message' => 'Message undeliverable for token '.CLOUD_API_TOKEN,
        ]],
    ])]))), $credentials);
    $deleted = $driver->parseWebhook(metaWebhook(metaWebhookBody(metaMessagesValue(statuses: [$receipt('deleted')]))), $credentials);

    // `sent` and `delivered` collapse into one kind, as `InboundEventKind`'s own table
    // prescribes…
    expect($sent->kind)->toBe(InboundEventKind::DeliveryReceipt)
        ->and($delivered->kind)->toBe(InboundEventKind::DeliveryReceipt)
        ->and($read->kind)->toBe(InboundEventKind::ReadReceipt)
        ->and($failed->kind)->toBe(InboundEventKind::SendFailure)
        // …so the raw status is preserved for task 9.5, which owns the never-downgrades rule
        // (Req 20.6 / C3, Property 9) and needs "accepted by Meta" distinguishable from "on the
        // device".
        ->and(is_array($sent->payloadValue('status')) ? $sent->payloadValue('status')['status'] : null)->toBe('sent')
        ->and(is_array($delivered->payloadValue('status')) ? $delivered->payloadValue('status')['status'] : null)->toBe('delivered');

    // All keyed on the outbound id, which is the only thing that says which message the receipt
    // is about.
    expect($sent->providerMessageId)->toBe('wamid.out')
        ->and($read->providerMessageId)->toBe('wamid.out')
        ->and($sent->failureReason)->toBeNull()
        ->and($read->failureReason)->toBeNull()
        // Meta's `title` and code, never its `message` — which here quotes the access token.
        ->and($failed->failureReason)->toBe('Message Undeliverable (code 131026)')
        ->and($failed->failureReason)->not->toContain(CLOUD_API_TOKEN);

    // A status this release does not act on is a successful parse and a 200, not a 403 storm on
    // every Meta feature announcement.
    expect($deleted->kind)->toBe(InboundEventKind::Unsupported)
        ->and($deleted->isActionable())->toBeFalse();
});

it('returns every event of a batched body, and says how many there were', function (): void {
    [$tenant] = cloudApiTenant();

    // Meta batches: this single POST carries two customer messages and one receipt.
    $request = metaWebhook(metaWebhookBody(metaMessagesValue(
        messages: [
            ['from' => '919812345678', 'id' => 'wamid.one', 'type' => 'text', 'text' => ['body' => 'first']],
            ['from' => '919899999999', 'id' => 'wamid.two', 'type' => 'text', 'text' => ['body' => 'second']],
        ],
        statuses: [['id' => 'wamid.out', 'status' => 'read', 'timestamp' => '1735786800']],
    )));

    $driver = cloudApiDriver();
    $credentials = cloudApiCredentialsFor($tenant);

    $events = $driver->parseWebhookBatch($request, $credentials);

    expect($events)->toHaveCount(3)
        ->and($events[0]->text)->toBe('first')
        ->and($events[1]->text)->toBe('second')
        ->and($events[2]->kind)->toBe(InboundEventKind::ReadReceipt);

    // Every event says how many there were and which one it is — so task 8.3 can detect that a
    // single-event API is being handed a batch, instead of learning it from a customer complaint.
    foreach ($events as $index => $event) {
        expect($event->payloadValue(CloudApiChannelDriver::BATCH_SIZE_KEY))->toBe(3)
            ->and($event->payloadValue(CloudApiChannelDriver::BATCH_INDEX_KEY))->toBe($index)
            ->and($event->tenantId)->toBe($tenant->id);
    }

    // The contract's single-event method returns the first of them.
    expect($driver->parseWebhook($request, $credentials)->providerMessageId)->toBe('wamid.one');
});

it('refuses a validly-signed payload about another tenant\'s number', function (): void {
    // The attack this closes: one Meta app fronts many WABAs, so a body signed with Acme's app
    // secret is a *validly signed* body. Verifying under the number the **payload** names would
    // pass, and the event would carry Globex's tenant id — one valid signature injecting
    // messages into any other tenant's conversation history, replied to from Globex's number.
    [$acme] = cloudApiTenant(phoneNumberId: '111111111111');
    $acmeSecret = CLOUD_API_APP_SECRET;

    // Both tenants are onboarded through the same Meta app, so they share its app secret — the
    // configuration that makes this attack possible at all.
    [$globex] = cloudApiTenant(secrets: ['app_secret' => $acmeSecret], phoneNumberId: '222222222222');

    $body = metaWebhookBody(metaMessagesValue([[
        'from' => '919812345678',
        'id' => 'wamid.injected',
        'type' => 'text',
        'text' => ['body' => 'pay this invoice instead'],
    ]], phoneNumberId: '111111111111'));

    // Delivered on Globex's route, signed with a secret Globex's route accepts, and *about*
    // Acme's number.
    try {
        cloudApiDriver()->parseWebhook(metaWebhook($body, $acmeSecret), cloudApiCredentialsFor($globex));
        $thrown = null;
    } catch (WebhookVerificationException $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(WebhookVerificationException::class)
        ->and($thrown?->getStatusCode())->toBe(403)
        ->and($thrown?->getMessage())->toContain('addressed to')
        // Numbers are fingerprinted, never written down (Req 7.3 / A7).
        ->and($thrown?->getMessage())->not->toContain('111111111111')
        ->and($thrown?->getMessage())->not->toContain('222222222222');

    // The same body on Acme's own route is fine, which is what proves the refusal was about the
    // recipient and not about the signature.
    app(TenantContext::class)->set($acme);

    expect(cloudApiDriver()->parseWebhook(metaWebhook($body, $acmeSecret), cloudApiCredentialsFor($acme))->text)
        ->toBe('pay this invoice instead');
});

it('aborts a whole batch when any change in it names a foreign number', function (): void {
    [$tenant] = cloudApiTenant();

    // A body that mixes a legitimate change with one about another number is not a
    // partially-usable body; it is a forged one.
    $body = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => CLOUD_API_WABA_ID,
            'changes' => [
                ['field' => 'messages', 'value' => metaMessagesValue([
                    ['from' => '919812345678', 'id' => 'wamid.ours', 'type' => 'text', 'text' => ['body' => 'ours']],
                ])],
                ['field' => 'messages', 'value' => metaMessagesValue([
                    ['from' => '919899999999', 'id' => 'wamid.theirs', 'type' => 'text', 'text' => ['body' => 'theirs']],
                ], phoneNumberId: '999999999999')],
            ],
        ]],
    ];

    expect(fn (): array => cloudApiDriver()->parseWebhookBatch(metaWebhook($body), cloudApiCredentialsFor($tenant)))
        ->toThrow(WebhookVerificationException::class);
});

it('refuses a payload with no signature, and one whose signature is over a different body', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);
    $driver = cloudApiDriver();

    $payload = metaWebhookBody(metaMessagesValue([[
        'from' => '919812345678', 'id' => 'wamid.x', 'type' => 'text', 'text' => ['body' => 'hello'],
    ]]));

    expect(fn (): InboundEvent => $driver->parseWebhook(metaWebhook($payload, sign: false), $credentials))
        ->toThrow(WebhookVerificationException::class, 'carries no [X-Hub-Signature-256] header');

    // Signed over a *different* body: the signature is over bytes, so re-encoding the decoded
    // array would have verified here and must not.
    $stale = 'sha256='.hash_hmac(
        'sha256',
        json_encode($payload + ['tampered' => true], JSON_THROW_ON_ERROR),
        CLOUD_API_APP_SECRET,
    );

    expect(fn (): InboundEvent => $driver->parseWebhook(metaWebhook($payload, signature: $stale), $credentials))
        ->toThrow(WebhookVerificationException::class, 'does not match the body')
        // Signed with the wrong secret.
        ->and(fn (): InboundEvent => $driver->parseWebhook(
            metaWebhook($payload, 'somebody-elses-app-secret'),
            $credentials,
        ))->toThrow(WebhookVerificationException::class)
        // A garbage header value reduces to nothing rather than raising.
        ->and(fn (): InboundEvent => $driver->parseWebhook(
            metaWebhook($payload, signature: 'sha256=not-hex'),
            $credentials,
        ))->toThrow(WebhookVerificationException::class);
});

it('refuses to verify at all when the tenant stored no app secret, and says which problem it is', function (): void {
    // A route with no app secret can verify nothing, so the payload is refused either way — but
    // as a *configuration* defect, so an operator is not sent hunting an attacker because a
    // tenant left a field blank.
    [$tenant] = cloudApiTenant(secrets: ['app_secret' => null]);

    expect(fn (): InboundEvent => cloudApiDriver()->parseWebhook(
        metaWebhook(metaWebhookBody(metaMessagesValue())),
        cloudApiCredentialsFor($tenant),
    ))->toThrow(ChannelCredentialException::class);
});

it('accepts a verified payload it does not act on, so Meta stops retrying', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);
    $driver = cloudApiDriver();

    // Task 8.4 subscribes to template status updates; until then they are acknowledged and
    // dropped, which is a 200 rather than a 403 on every Meta feature announcement.
    $templateUpdate = $driver->parseWebhook(metaWebhook(metaWebhookBody(
        ['event' => 'APPROVED', 'message_template_id' => 1234],
        field: 'message_template_status_update',
    )), $credentials);

    $otherProduct = $driver->parseWebhook(metaWebhook(metaWebhookBody(
        metaMessagesValue(),
        object: 'instagram',
    )), $credentials);

    $empty = $driver->parseWebhook(metaWebhook(['object' => 'whatsapp_business_account', 'entry' => []]), $credentials);

    expect($templateUpdate->kind)->toBe(InboundEventKind::Unsupported)
        ->and($templateUpdate->isActionable())->toBeFalse()
        ->and($otherProduct->kind)->toBe(InboundEventKind::Unsupported)
        ->and($empty->kind)->toBe(InboundEventKind::Unsupported)
        ->and($empty->tenantId)->toBe($tenant->id);
});

it('refuses a verified payload that is the wrong shape, rather than half-populating an event', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);
    $driver = cloudApiDriver();

    $bodies = [
        // No metadata, so the change cannot be matched against the route it arrived on.
        metaWebhookBody(['messaging_product' => 'whatsapp', 'messages' => [
            ['from' => '919812345678', 'id' => 'wamid.x', 'type' => 'text'],
        ]]),
        // A message with no id can never be deduplicated or correlated.
        metaWebhookBody(metaMessagesValue([['from' => '919812345678', 'type' => 'text']])),
        // A message that names no sender.
        metaWebhookBody(metaMessagesValue([['id' => 'wamid.x', 'type' => 'text']])),
        // A receipt about nothing.
        metaWebhookBody(metaMessagesValue(statuses: [['status' => 'read']])),
    ];

    foreach ($bodies as $body) {
        expect(fn (): InboundEvent => $driver->parseWebhook(metaWebhook($body), $credentials))
            // 403 and not a 500: `InboundEvent`'s constructor would raise
            // `InvalidArgumentException` for the same payloads, and this is a public endpoint.
            ->toThrow(WebhookVerificationException::class);
    }

    // A verified body that is not a JSON object at all.
    $signature = 'sha256='.hash_hmac('sha256', '"just a string"', CLOUD_API_APP_SECRET);
    $request = Request::create('/webhooks/cloud-api/route-key', 'POST', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], '"just a string"');

    expect(fn (): InboundEvent => $driver->parseWebhook($request, $credentials))
        ->toThrow(WebhookVerificationException::class, 'not a JSON object');
});

/*
|--------------------------------------------------------------------------
| verifySubscription() — the GET verify-token challenge
|--------------------------------------------------------------------------
*/

it('echoes the challenge only when the verify token matches', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);
    $driver = cloudApiDriver();

    $handshake = static fn (array $query): Request => Request::create('/webhooks/cloud-api/route-key', 'GET', $query);

    expect($driver->verifySubscription($handshake([
        'hub.mode' => 'subscribe',
        'hub.verify_token' => CLOUD_API_VERIFY_TOKEN,
        'hub.challenge' => '1158201444',
    ]), $credentials))->toBe('1158201444');

    // A token compared with `==` is a webhook takeover, so a mismatch echoes nothing at all —
    // including for a token that is a *prefix* of the real one, which a length-comparing check
    // would leak.
    foreach ([
        'wrong-token-entirely',
        substr(CLOUD_API_VERIFY_TOKEN, 0, -1),
        CLOUD_API_VERIFY_TOKEN.'x',
        '',
    ] as $presented) {
        expect(fn (): string => $driver->verifySubscription($handshake([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => $presented,
            'hub.challenge' => '1158201444',
        ]), $credentials))->toThrow(WebhookVerificationException::class, 'echoed verify token is not this route\'s');
    }
});

it('refuses a handshake that is not a subscription, or that has nothing to echo', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);
    $driver = cloudApiDriver();

    $handshake = static fn (array $query): Request => Request::create('/webhooks/cloud-api/route-key', 'GET', $query);

    expect(fn (): string => $driver->verifySubscription($handshake([
        'hub.mode' => 'unsubscribe',
        'hub.verify_token' => CLOUD_API_VERIFY_TOKEN,
        'hub.challenge' => '1158201444',
    ]), $credentials))->toThrow(WebhookVerificationException::class, 'hub.mode')
        // A challenge that is absent, or is not the short opaque nonce Meta sends: refused rather
        // than echoed, because this is the one value a driver reflects back to the network.
        ->and(fn (): string => $driver->verifySubscription($handshake([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => CLOUD_API_VERIFY_TOKEN,
        ]), $credentials))->toThrow(WebhookVerificationException::class, 'hub.challenge')
        ->and(fn (): string => $driver->verifySubscription($handshake([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => CLOUD_API_VERIFY_TOKEN,
            'hub.challenge' => '<script>alert(1)</script>',
        ]), $credentials))->toThrow(WebhookVerificationException::class, 'hub.challenge');
});

it('refuses a handshake when the tenant stored no verify token', function (): void {
    [$tenant] = cloudApiTenant(secrets: ['verify_token' => null]);

    expect(fn (): string => cloudApiDriver()->verifySubscription(
        Request::create('/webhooks/cloud-api/route-key', 'GET', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => CLOUD_API_VERIFY_TOKEN,
            'hub.challenge' => '1158201444',
        ]),
        cloudApiCredentialsFor($tenant),
    ))->toThrow(ChannelCredentialException::class);
});

/*
|--------------------------------------------------------------------------
| register() and healthCheck()
|--------------------------------------------------------------------------
*/

it('verifies the number against the WABA and reports state rather than throwing', function (): void {
    [$tenant, $session] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);

    Http::fake(['graph.test/*' => Http::sequence()
        // Registered and verified.
        ->push(['data' => [
            ['id' => '999999999999', 'code_verification_status' => 'VERIFIED'],
            ['id' => CLOUD_API_PHONE_ID, 'code_verification_status' => 'VERIFIED', 'verified_name' => 'ACME Ltd'],
        ]])
        // On the WABA, verification outstanding.
        ->push(['data' => [['id' => CLOUD_API_PHONE_ID, 'code_verification_status' => 'NOT_VERIFIED']]])
        // Not on this WABA at all.
        ->push(['data' => [['id' => '999999999999', 'code_verification_status' => 'VERIFIED']]])]);

    $driver = cloudApiDriver();

    $live = $driver->register($session, $credentials);

    expect($live->isLive())->toBeTrue()
        ->and($live->mode)->toBe(ChannelMode::CloudApi)
        ->and($live->providerNumberId)->toBe(CLOUD_API_PHONE_ID)
        ->and($live->detail)->toContain('ACME Ltd')
        // The WABA id is fingerprinted rather than written into a stored, tenant-visible detail.
        ->and($live->detail)->not->toContain(CLOUD_API_WABA_ID)
        // No callback is claimed: Meta's webhook is configured on the App, and the route key is
        // minted by the webhook-routing task. A URL on a panel that nothing answers is worse
        // than none.
        ->and($live->hasCallback())->toBeFalse();

    // Task 9.1 keeps a pending session out of SENDABLE — its first send would be refused by Meta.
    $pending = $driver->register($session, $credentials);

    expect($pending->isPending())->toBeTrue()
        ->and($pending->isLive())->toBeFalse()
        ->and($pending->detail)->toContain('NOT_VERIFIED')
        ->and($pending->providerNumberId)->toBe(CLOUD_API_PHONE_ID);

    $absent = $driver->register($session, $credentials);

    expect($absent->isPending())->toBeTrue()
        ->and($absent->detail)->toContain('phone_number_id');

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => str_starts_with(
        $r->url(),
        'https://graph.test/v21.0/'.CLOUD_API_WABA_ID.'/phone_numbers',
    ));
});

it('lets a provider refusal of the registration lookup propagate as an exception', function (): void {
    [$tenant, $session] = cloudApiTenant();

    Sleep::fake();
    Http::fake(['graph.test/*' => Http::response(metaError(190), 401)]);

    // The contract: *a registration that failed is an exception, not a `pending()`* — a `pending()`
    // here would leave a session waiting for a verification that is never coming.
    expect(fn (): RegistrationResult => cloudApiDriver()->register($session, cloudApiCredentialsFor($tenant)))
        ->toThrow(ChannelRequestFailedException::class);
});

it('health-checks the credentials against Meta and reports a refusal as data', function (): void {
    [$tenant] = cloudApiTenant();
    $credentials = cloudApiCredentialsFor($tenant);

    Sleep::fake();

    Http::fake(['graph.test/*' => Http::sequence()
        ->push(['id' => CLOUD_API_PHONE_ID, 'quality_rating' => 'GREEN', 'code_verification_status' => 'VERIFIED'])
        ->push(metaError(190), 401)
        ->push(metaError(190), 401)]);

    $driver = cloudApiDriver();

    $healthy = $driver->healthCheck($credentials);

    expect($healthy->healthy)->toBeTrue()
        ->and($healthy->mode)->toBe(ChannelMode::CloudApi)
        ->and($healthy->isUsable())->toBeTrue()
        ->and($healthy->detail)->toContain('GREEN')
        ->and($healthy->latencyMs)->toBeGreaterThanOrEqual(0);

    $unhealthy = $driver->healthCheck($credentials);

    // A value, not an exception: task 7.6 records it and keeps the previous working set.
    expect($unhealthy->healthy)->toBeFalse()
        ->and($unhealthy->isUsable())->toBeFalse()
        ->and($unhealthy->detail)->toContain('access token')
        // Meta's `401` body echoes the token it was sent; neither the rendered sentence nor
        // `ChannelHealth`'s redaction lets it back out.
        ->and($unhealthy->detail)->not->toContain(CLOUD_API_TOKEN);
});

it('reports incomplete credentials as unhealthy, naming the keys and probing nothing', function (): void {
    [$tenant] = cloudApiTenant(secrets: ['access_token' => null, 'app_secret' => null]);

    Http::fake();

    $health = cloudApiDriver()->healthCheck(cloudApiCredentialsFor($tenant));

    // What task 7.6 needs in order to keep the previous working credentials rather than crash on
    // a half-filled form.
    expect($health->healthy)->toBeFalse()
        ->and($health->detail)->toContain('access_token')
        ->and($health->detail)->toContain('app_secret')
        ->and($health->detail)->not->toContain('verify_token');

    Http::assertNothingSent();
});

it('lets an unreachable Meta propagate from a health check, because "could not ask" is not "said no"', function (): void {
    [$tenant] = cloudApiTenant();

    Sleep::fake();
    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('refused'));

    // The contract reserves the exception for a probe that could not be *made*: an operator
    // whose egress is broken must not be told the tenant's token is invalid.
    expect(fn (): ChannelHealth => cloudApiDriver()->healthCheck(cloudApiCredentialsFor($tenant)))
        ->toThrow(BridgeUnreachableException::class);
});

/*
|--------------------------------------------------------------------------
| The transport half
|--------------------------------------------------------------------------
*/

it('sends media by link, and uploads inline bytes before sending them by id', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::sequence()
        ->push(metaMessageResponse('wamid.linked'))
        ->push(['id' => '1234567890'])
        ->push(metaMessageResponse('wamid.uploaded'))]);

    $driver = cloudApiDriver();

    $linked = $driver->sendMedia($session->id, '919812345678', MediaPayload::fromUrl(
        url: 'https://cdn.test/invoice.pdf',
        mimeType: 'application/pdf',
        kind: MediaKind::Document,
        filename: 'invoice.pdf',
        caption: 'Your invoice',
    ));

    expect($linked->waMessageId)->toBe('wamid.linked');

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->data() === [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => '919812345678',
        'type' => 'document',
        'document' => [
            'link' => 'https://cdn.test/invoice.pdf',
            'caption' => 'Your invoice',
            'filename' => 'invoice.pdf',
        ],
    ]);

    // The Graph API has no inline-bytes field, so bytes are uploaded first and sent by id.
    $uploaded = $driver->sendMedia($session->id, '919812345678', MediaPayload::fromBytes(
        bytes: 'not-really-a-png',
        mimeType: 'image/png',
        kind: MediaKind::Image,
    ));

    expect($uploaded->waMessageId)->toBe('wamid.uploaded');

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->url() === 'https://graph.test/v21.0/'.CLOUD_API_PHONE_ID.'/media');

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => is_array($r->data())
        && ($r->data()['image'] ?? null) === ['id' => '1234567890']);

    Http::assertSentCount(3);
});

it('sends text through the transport method with only the options Meta understands', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::response(metaMessageResponse())]);

    cloudApiDriver()->sendText($session->id, '919812345678', 'Hello', [
        'link_preview' => true,
        'reply_to' => 'wamid.inbound',
        // Meta rejects unknown fields, so a caller's extra key is dropped rather than forwarded —
        // otherwise a typo becomes a 400 charged to the number's quality rating.
        'ephemeral' => 86400,
    ]);

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->data() === [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => '919812345678',
        'type' => 'text',
        'context' => ['message_id' => 'wamid.inbound'],
        'text' => ['preview_url' => true, 'body' => 'Hello'],
    ]);
});

it('reports the provider\'s view of the number as a session state', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake(['graph.test/*' => Http::sequence()
        ->push(['id' => CLOUD_API_PHONE_ID, 'display_phone_number' => '+91 98120 00000', 'verified_name' => 'ACME Ltd', 'code_verification_status' => 'VERIFIED'])
        ->push(['id' => CLOUD_API_PHONE_ID, 'display_phone_number' => '+91 98120 00000', 'code_verification_status' => 'NOT_VERIFIED'])]);

    $driver = cloudApiDriver();

    $connected = $driver->sessionState($session->id);

    expect($connected->sessionId)->toBe($session->id)
        ->and($connected->status)->toBe(SessionStatus::Connected)
        ->and($connected->isOnline())->toBeTrue()
        // Meta formats the number for humans; reduced to the digits `sessions_wa.phone` holds.
        ->and($connected->phone)->toBe('919812000000')
        ->and($connected->pushName)->toBe('ACME Ltd')
        ->and($connected->disconnectReason)->toBeNull();

    $awaiting = $driver->sessionState($session->id);

    // `QR_PENDING` reads as *awaiting provider-side verification* here, which is the same meaning
    // it carries for a Baileys session awaiting a scan.
    expect($awaiting->status)->toBe(SessionStatus::QrPending)
        ->and($awaiting->disconnectReason)->toBe('NOT_VERIFIED');
});

it('answers the transport methods Meta has no equivalent for without a silent no-op', function (): void {
    [, $session] = cloudApiTenant();

    Http::fake();

    $driver = cloudApiDriver();

    // Each refusal is typed and 422 — never a no-op, which would be reported as a success, and
    // never an `Error` from an unimplemented method (Property 26, Property 28).
    $refusals = [
        'provisionSession' => function () use ($driver, $session): void {
            $driver->provisionSession($session->id, SessionLoginMethod::Qr);
        },
        'startSession' => function () use ($driver, $session): void {
            $driver->startSession($session->id);
        },
        'stopSession' => function () use ($driver, $session): void {
            $driver->stopSession($session->id, true);
        },
        'pairingCode' => function () use ($driver, $session): void {
            $driver->pairingCode($session->id, '919812345678');
        },
        'sendPresence' => function () use ($driver, $session): void {
            $driver->sendPresence($session->id, '919812345678', PresenceState::Composing);
        },
    ];

    foreach ($refusals as $operation => $call) {
        try {
            $call();
            $thrown = null;
        } catch (ChannelOperationException $refused) {
            $thrown = $refused;
        }

        expect($thrown)->toBeInstanceOf(ChannelOperationException::class, $operation)
            ->and($thrown?->mode)->toBe(ChannelMode::CloudApi)
            ->and($thrown?->operation)->toBe($operation)
            ->and($thrown?->getStatusCode())->toBe(422);
    }

    // `qr()` is the one whose null is an *answer*: `BridgeClient::qr()` already documents null as
    // "this session is not showing one", which is permanently true of a registered number.
    expect($driver->qr($session->id))->toBeNull();

    // Cloud API has no contacts endpoint, so every answer is `unknown` — never `false`, which
    // would record "not on WhatsApp" as a permanent fact and have every later campaign skip a
    // real customer.
    $checks = $driver->checkNumbers($session->id, ['919812345678', '919899999999']);

    expect($checks)->toHaveCount(2)
        ->and($checks['919812345678']->isUnknown())->toBeTrue()
        ->and($checks['919812345678']->isOnWhatsApp())->toBeFalse()
        ->and($checks['919899999999']->isUnknown())->toBeTrue();

    Http::assertNothingSent();
});

it('resolves ownership on every transport call, before any credential is decrypted', function (): void {
    [, $session] = cloudApiTenant();

    $intruder = Tenant::factory()->create();
    app(TenantContext::class)->set($intruder);

    Http::fake();

    // The lookup *is* the enforcement: `Session` is tenant-scoped, and the scoped builder turns a
    // foreign id into a typed 403 rather than a bare 404.
    expect(fn (): SentMessageDto => cloudApiDriver()->sendText($session->id, '919812345678', 'Hello'))
        ->toThrow(CrossTenantAccessException::class)
        ->and(fn (): SessionStateDto => cloudApiDriver()->sessionState($session->id))
        ->toThrow(CrossTenantAccessException::class);

    // …and an ordinary typo stays a 404, so it does not look like an isolation breach in the logs
    // an operator watches for real ones.
    expect(fn (): SentMessageDto => cloudApiDriver()->sendText('01JNOTAREALSESSIONID0000', '919812345678', 'Hello'))
        ->toThrow(UnknownSessionException::class)
        ->and(fn (): SentMessageDto => cloudApiDriver()->sendText('not-a-ulid', '919812345678', 'Hello'))
        ->toThrow(UnknownSessionException::class);

    Http::assertNothingSent();
});

it('reports the Graph API reachable on any answer at all, and unreachable only on a transport failure', function (): void {
    Http::fake(['graph.test/*' => Http::response(metaError(0, 'GraphMethodException'), 400)]);

    // An unauthenticated Graph request is *supposed* to be refused, and a refusal proves the host
    // is serving — this probe carries no credentials on purpose, so it works for a tenant who has
    // entered none.
    expect(cloudApiDriver()->isReachable())->toBeTrue();

    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('refused'));

    expect(cloudApiDriver()->isReachable())->toBeFalse();
});

it('fails closed when Meta answers 2xx with something that is not a result', function (): void {
    [, $session] = cloudApiTenant();

    Sleep::fake();
    Http::fake(['graph.test/*' => Http::response('<html>gateway</html>', 200, ['Content-Type' => 'text/html'])]);

    // A proxy's error page is not a send outcome: the result is unknown, which must never look
    // like a success.
    expect(fn (): SendReceipt => cloudApiDriver()->send(
        $session,
        TextContent::to('919812345678', 'Hello', 'html-body'),
    ))->toThrow(BridgeUnreachableException::class);
});
