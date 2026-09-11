<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\ChannelTemplateStatus;
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
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Bridge\MediaPayload;
use App\Services\Channel\Bsp\BspAdapterRegistry;
use App\Services\Channel\BspGatewayChannelDriver;
use App\Services\Channel\BspGatewayErrorClassifier;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\ModeGuardedChannelDriver;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use App\Services\Channel\TextContent;
use App\Services\Reliability\ErrorClassifier;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Channel\BspCredentialFixtures;

/*
|--------------------------------------------------------------------------
| BspGatewayChannelDriver (Req 8.1, 8.2 / A8)
|--------------------------------------------------------------------------
| The fourth mode, and the first with **eight backends behind one driver**. This file pins what is new
| with that rather than re-asserting the contract (`ChannelDriverContractTest`) or the mode matrix
| (`ChannelCapabilityTest`); the sub-matrix invariant has a file of its own
| (`BspGatewaySubMatrixTest`):
|
|   1. **the capability gate the router cannot make.** `ChannelRouter::assertSupported()` gates on the
|      mode, whose `BSP_GATEWAY` row is a *ceiling*; only the partner's resolved sub-matrix knows that
|      WATI has no media route, so the driver refuses before any request (Req 8.3, Property 21);
|   2. **the cross-tenant webhook injection is refused, per partner.** The recipient identity comes from
|      the `ChannelCredentials` and the payload's claim is checked against it — asserted for three
|      partners with three different signature schemes (Property 23);
|   3. **each partner's error vocabulary maps onto the platform's `ErrorClass`**, through the real
|      classifier chain, and an unrecognised refusal keeps the work;
|   4. **one driver, eight wires**: each adapter's real endpoint, auth header and body encoding;
|   5. **`send()` is idempotent** on the content's key, with 7.1's scope convention;
|   6. **health and registration report as data**, never by raising, and never leak a key.
|
| `Http::fake()` stands in for the partner APIs; the real credential store (with real envelope
| encryption), idempotency store, circuit breaker and tenant scoping are all live.
|
| The driver is mostly constructed directly rather than through `app(ChannelRouter::class)`, because
| these assertions are about the eight wires rather than about routing; the `BSP_GATEWAY` registry
| entry and the shared adapter registry have a test each below.
*/

const BSP_RECIPIENT = '919812345678';

/**
 * A tenant bound as the acting one, with complete `BSP_GATEWAY` credentials for `$provider` and a
 * session on that mode.
 *
 * @param  array<string, mixed>  $config  merged over the partner's complete §2.7 set
 * @param  array<string, mixed>  $secrets  merged over the partner's complete §2.7 set
 * @return array{0: Tenant, 1: Session, 2: ChannelCredential}
 */
function bspGatewayTenant(BspProvider $provider, array $config = [], array $secrets = []): array
{
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $credential = app(ChannelCredentialStore::class)->put(
        $tenant,
        ChannelMode::BspGateway,
        secrets: array_merge(BspCredentialFixtures::secrets($provider), $secrets),
        config: array_merge(BspCredentialFixtures::config($provider), $config),
        provider: $provider,
    );

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::BspGateway,
        'status' => SessionStatus::Connected,
    ]);

    return [$tenant, $session, $credential];
}

function bspGatewayDriver(): BspGatewayChannelDriver
{
    return app(BspGatewayChannelDriver::class);
}

function bspGatewayCredentials(Tenant $tenant): ChannelCredentials
{
    $credentials = app(ChannelCredentialStore::class)->for($tenant, ChannelMode::BspGateway);

    expect($credentials)->not->toBeNull();
    assert($credentials instanceof ChannelCredentials);

    return $credentials;
}

/*
|--------------------------------------------------------------------------
| What this backend is
|--------------------------------------------------------------------------
*/

it('is an official mode whose anti-ban answer is derived, not chosen', function (): void {
    $driver = bspGatewayDriver();

    expect($driver->mode())->toBe(ChannelMode::BspGateway)
        ->and($driver->mode()->isOfficial())->toBeTrue()
        ->and($driver->mode()->isWebProtocol())->toBeFalse()
        // Property 24: no configuration and no partner can turn the anti-ban ramp on or off here.
        ->and($driver->requiresAntiBan())->toBeFalse()
        ->and($driver->mode()->requiresTenantCredentials())->toBeTrue()
        ->and($driver->mode()->usesProvider())->toBeTrue()
        ->and($driver->mode()->enforcesSessionWindow())->toBeTrue();
});

it('is registered for BSP_GATEWAY in the container and reachable through the router, wrapped', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Twilio);

    $driver = app(ChannelRouter::class)->driverFor($session);

    // The registry entry of task 7.4 — a mode with no entry raises instead, so this asserts the
    // wiring and not just the class. One driver serves all eight partners, so this is the last
    // mode the registry needed and the router now resolves every `ChannelMode` case.
    expect($driver)->toBeInstanceOf(ModeGuardedChannelDriver::class)
        ->and($driver->mode())->toBe(ChannelMode::BspGateway);

    assert($driver instanceof ModeGuardedChannelDriver);
    expect($driver->inner())->toBeInstanceOf(BspGatewayChannelDriver::class)
        // Resolution goes through `rowFor($tenant, BSP_GATEWAY)` with **no partner named**, which
        // is the lookup whose memoised miss used to outlive the write that configured a partner:
        // the credentials this session's first send will use were stored moments ago, in this
        // same unit of work.
        ->and(app(ChannelCredentialStore::class)->rowFor($session->tenant, ChannelMode::BspGateway)?->provider)
        ->toBe(BspProvider::Twilio);
});

it('shares one stateless adapter registry across the driver and the classifier', function (): void {
    // A `singleton()`: the eight adapters are `final readonly`, hold nothing and do no I/O, so
    // there is no tenant state to carry into another tenant's job — which is exactly why this may
    // be a singleton while the store and the router may not. The classifier is resolved from
    // inside a failed job, and re-validating the eight-provider map there would be eight object
    // constructions spent while something is already failing.
    $registry = app(BspAdapterRegistry::class);

    expect(app(BspAdapterRegistry::class))->toBe($registry)
        ->and($registry->all())->toHaveCount(count(BspProvider::cases()));

    // …and it survives what a queue worker does between jobs, unlike the scoped bindings — the
    // observable difference between `singleton()` and `scoped()`, and the reason a stateless
    // object may take the first.
    app()->forgetScopedInstances();

    expect(app(BspAdapterRegistry::class))->toBe($registry);
});

/*
|--------------------------------------------------------------------------
| Outbound
|--------------------------------------------------------------------------
*/

it('sends through the session\'s partner and reports the partner on the receipt', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Twilio);

    Http::fake(['twilio.test/*' => Http::response([
        'sid' => 'SM0123456789abcdef',
        'to' => 'whatsapp:+'.BSP_RECIPIENT,
        'status' => 'queued',
    ])]);

    $receipt = bspGatewayDriver()->send(
        $session,
        TextContent::to(BSP_RECIPIENT, 'Your order shipped.', 'send-key-1'),
    );

    expect($receipt)->toBeInstanceOf(SendReceipt::class)
        ->and($receipt->mode)->toBe(ChannelMode::BspGateway)
        // `SendReceipt` refuses to exist without this for `BSP_GATEWAY`: channel_send_log records per
        // provider, and a failover across two partners would otherwise look like a retry on one.
        ->and($receipt->provider)->toBe(BspProvider::Twilio)
        ->and($receipt->providerMessageId)->toBe('SM0123456789abcdef')
        ->and($receipt->recipient)->toBe(BSP_RECIPIENT)
        ->and($receipt->capability)->toBe(ChannelCapability::SendSingle)
        // `SEND_SINGLE` is `✅` on this mode, so nothing degraded.
        ->and($receipt->degraded)->toBeFalse();

    Http::assertSent(function ($request): bool {
        expect($request->url())->toBe('https://twilio.test/2010-04-01/Accounts/AC00000000000000000000000000000001/Messages.json')
            ->and($request->method())->toBe('POST')
            // Twilio's own channel spelling on both ends, and Basic auth under the Account SID.
            ->and($request['To'])->toBe('whatsapp:+'.BSP_RECIPIENT)
            ->and($request['From'])->toBe('whatsapp:+15550001111')
            ->and($request['Body'])->toBe('Your order shipped.')
            ->and($request->header('Authorization')[0])
            ->toBe('Basic '.base64_encode('AC00000000000000000000000000000001:'.BspCredentialFixtures::API_SECRET));

        return true;
    });
});

it('is idempotent on the content key: a replay returns the original receipt and sends nothing', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);

    Http::fake(['360dialog.test/*' => Http::response([
        'messages' => [['id' => 'wamid.AAAA']],
        'contacts' => [['wa_id' => BSP_RECIPIENT]],
    ])]);

    $driver = bspGatewayDriver();
    $content = TextContent::to(BSP_RECIPIENT, 'Only once.', 'replay-key');

    $first = $driver->send($session, $content);
    $second = $driver->send($session, $content);

    expect($second->providerMessageId)->toBe($first->providerMessageId)
        ->and($second->idempotencyKey)->toBe('replay-key')
        ->and($second->provider)->toBe(BspProvider::ThreeSixtyDialog);

    Http::assertSentCount(1);
});

it('refuses to answer one key with a different send', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);

    Http::fake(['360dialog.test/*' => Http::response(['messages' => [['id' => 'wamid.AAAA']]])]);

    $driver = bspGatewayDriver();
    $driver->send($session, TextContent::to(BSP_RECIPIENT, 'First body.', 'shared-key'));

    // Same key, different message: answering it with the first send's receipt would mean a message
    // silently not sent, with another message's provider id recorded against it.
    $driver->send($session, TextContent::to(BSP_RECIPIENT, 'Second body.', 'shared-key'));
})->throws(IdempotencyKeyReuseException::class);

it('refuses a capability this partner does not have, before any request', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Wati);

    Http::preventStrayRequests();

    // The gate the router cannot make: the mode's `MEDIA` cell is `⚠️ per provider`, and only WATI's
    // resolved sub-matrix knows it has no send-by-link route. Property 21's shape — typed, pre-dispatch,
    // no side effect.
    expect(fn (): mixed => bspGatewayDriver()->sendMedia(
        $session->id,
        BSP_RECIPIENT,
        MediaPayload::fromUrl('https://cdn.test/a.png', 'image/png'),
    ))->toThrow(ModeCapabilityException::class);

    Http::assertNothingSent();
});

it('refuses an inline-bytes media payload, because no partner shares an upload route', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Infobip);

    Http::preventStrayRequests();

    expect(fn (): mixed => bspGatewayDriver()->sendMedia(
        $session->id,
        BSP_RECIPIENT,
        MediaPayload::fromBytes('not-really-an-image', 'image/png'),
    ))->toThrow(ChannelOperationException::class, 'sendMedia');

    Http::assertNothingSent();
});

it('sends media by link on a partner that supports it', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Infobip);

    Http::fake(['infobip.test/*' => Http::response(['messageId' => 'IB-0001', 'to' => BSP_RECIPIENT])]);

    $sent = bspGatewayDriver()->sendMedia(
        $session->id,
        BSP_RECIPIENT,
        MediaPayload::fromUrl('https://cdn.test/invoice.pdf', 'application/pdf', MediaKind::Document, 'invoice.pdf'),
    );

    expect($sent->waMessageId)->toBe('IB-0001');

    Http::assertSent(function ($request): bool {
        // Infobip has a route per kind rather than a type field.
        expect($request->url())->toBe('https://infobip.test/whatsapp/1/message/document')
            ->and($request->data()['content']['mediaUrl'])->toBe('https://cdn.test/invoice.pdf')
            ->and($request->data()['content']['filename'])->toBe('invoice.pdf')
            ->and($request->header('Authorization')[0])->toBe('App '.BspCredentialFixtures::API_SECRET);

        return true;
    });
});

it('refuses a group recipient, which no partner route can address', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Kaleyra);

    Http::preventStrayRequests();

    expect(fn (): mixed => bspGatewayDriver()->sendText($session->id, '120363000000000000@g.us', 'hi'))
        ->toThrow(InvalidArgumentException::class, 'group');

    Http::assertNothingSent();
});

it('refuses a send with no usable credentials, and never reroutes onto BAILEYS', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::BspGateway,
        'status' => SessionStatus::Connected,
    ]);

    Http::preventStrayRequests();

    expect(fn (): mixed => bspGatewayDriver()->send($session, TextContent::to(BSP_RECIPIENT, 'hi', 'k')))
        ->toThrow(ChannelCredentialException::class);

    Http::assertNothingSent();
});

it('resolves ownership before credentials, so a foreign session id is a 403 and not a send', function (): void {
    // The foreign session is created while acting as *its* tenant; only then does the acting tenant
    // become somebody else, which is the situation an isolation breach would arrive in.
    $other = Tenant::factory()->create();
    app(TenantContext::class)->set($other);
    $foreign = Session::factory()->create([
        'tenant_id' => $other->id,
        'channel_mode' => ChannelMode::BspGateway,
    ]);

    bspGatewayTenant(BspProvider::Twilio);

    Http::preventStrayRequests();

    expect(fn (): mixed => bspGatewayDriver()->sendText($foreign->id, BSP_RECIPIENT, 'hi'))
        ->toThrow(CrossTenantAccessException::class);

    expect(fn (): mixed => bspGatewayDriver()->sendText('01JNOTASESSION0000000000', BSP_RECIPIENT, 'hi'))
        ->toThrow(UnknownSessionException::class);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Templates — reusing cloud_api_templates, keyed on the partner account
|--------------------------------------------------------------------------
*/

/**
 * An approved template row against `$credential`, which is the `(tenant, BSP_GATEWAY, provider)` row.
 */
function bspTemplate(
    ChannelCredential $credential,
    string $body = 'Hello {{1}}, your order {{2}} is on its way.',
    ChannelTemplateStatus $status = ChannelTemplateStatus::Approved,
    ?string $providerTemplateId = null,
    string $name = 'order_update',
): CloudApiTemplate {
    return CloudApiTemplate::factory()->create([
        'tenant_id' => $credential->tenant_id,
        'credential_id' => $credential->id,
        'name' => $name,
        'language' => 'en_US',
        'body' => $body,
        'status' => $status,
        'provider_template_id' => $providerTemplateId,
    ]);
}

it('sends an approved template in the partner\'s own shape', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);
    $row = bspTemplate($credential);

    Http::fake(['360dialog.test/*' => Http::response(['messages' => [['id' => 'wamid.TPL']]])]);

    $receipt = bspGatewayDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($row),
        ['1' => 'Asha', '2' => 'A-1001'],
        BSP_RECIPIENT,
        'tpl-key-1',
    );

    expect($receipt->isTemplated())->toBeTrue()
        ->and($receipt->templateName)->toBe('order_update')
        ->and($receipt->capability)->toBe(ChannelCapability::Template)
        ->and($receipt->provider)->toBe(BspProvider::ThreeSixtyDialog);

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        expect($request->url())->toBe('https://360dialog.test/messages')
            ->and($body['type'])->toBe('template')
            ->and($body['template']['name'])->toBe('order_update')
            ->and($body['template']['language']['code'])->toBe('en_US')
            // Positional order, ascending — not order of appearance in the copy.
            ->and(array_column($body['template']['components'][0]['parameters'], 'text'))->toBe(['Asha', 'A-1001'])
            ->and($request->header('D360-API-KEY')[0])->toBe(BspCredentialFixtures::API_SECRET);

        return true;
    });
});

it('orders template parameters by placeholder position, not by appearance', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);
    $row = bspTemplate($credential, 'Order {{2}} for {{1}} is ready.');

    Http::fake(['360dialog.test/*' => Http::response(['messages' => [['id' => 'wamid.TPL2']]])]);

    bspGatewayDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($row),
        ['1' => 'Asha', '2' => 'A-1001'],
        BSP_RECIPIENT,
        'tpl-key-order',
    );

    Http::assertSent(function ($request): bool {
        expect(array_column($request->data()['template']['components'][0]['parameters'], 'text'))
            ->toBe(['Asha', 'A-1001']);

        return true;
    });
});

it('refuses a mis-filled, unapproved, unregistered or foreign template locally', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::Gupshup);
    $row = bspTemplate($credential);
    $driver = bspGatewayDriver();

    Http::preventStrayRequests();

    // Every refusal is before the POST, because a mis-filled template rejected at the partner is
    // charged to the number's quality rating at Meta.
    expect(fn (): mixed => $driver->sendTemplateTo($session, TemplateRef::fromModel($row), ['1' => 'Asha'], BSP_RECIPIENT, 'k1'))
        ->toThrow(ChannelTemplateException::class)
        ->and(fn (): mixed => $driver->sendTemplateTo($session, TemplateRef::fromModel($row), ['1' => 'a', '2' => 'b', '3' => 'c'], BSP_RECIPIENT, 'k2'))
        ->toThrow(ChannelTemplateException::class)
        ->and(fn (): mixed => $driver->sendTemplateTo($session, new TemplateRef('never_synced', 'en_US'), [], BSP_RECIPIENT, 'k3'))
        ->toThrow(ChannelTemplateException::class)
        // A reference stamped with another partner account's credential row.
        ->and(fn (): mixed => $driver->sendTemplateTo($session, new TemplateRef('order_update', 'en_US', '01JOTHERCRED000000000000'), ['1' => 'a', '2' => 'b'], BSP_RECIPIENT, 'k4'))
        ->toThrow(ChannelTemplateException::class);

    bspTemplate($credential, 'Paused {{1}}.', ChannelTemplateStatus::Paused, name: 'paused_note');

    expect(fn (): mixed => $driver->sendTemplateTo($session, new TemplateRef('paused_note', 'en_US'), ['1' => 'a'], BSP_RECIPIENT, 'k5'))
        ->toThrow(ChannelTemplateException::class);

    Http::assertNothingSent();
});

it('refuses a template send when the partner account has no template registry', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::Wati, ['templates_enabled' => false]);
    bspTemplate($credential);

    Http::preventStrayRequests();

    expect(fn (): mixed => bspGatewayDriver()->sendTemplateTo(
        $session,
        new TemplateRef('order_update', 'en_US'),
        ['1' => 'a', '2' => 'b'],
        BSP_RECIPIENT,
        'k',
    ))->toThrow(ModeCapabilityException::class);

    Http::assertNothingSent();
});

it('refuses a Twilio template that the sync has given no ContentSid', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::Twilio, ['messaging_service_sid' => 'MG0001']);
    $row = bspTemplate($credential);

    Http::preventStrayRequests();

    // Twilio addresses a content template only by its own id; a row with none cannot be sent, and the
    // tenant's action is "submit it to Twilio" rather than "wait".
    expect(fn (): mixed => bspGatewayDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($row),
        ['1' => 'Asha', '2' => 'A-1001'],
        BSP_RECIPIENT,
        'k',
    ))->toThrow(ChannelTemplateException::class);

    Http::assertNothingSent();
});

it('sends a Twilio content template with its ContentSid and positional variables', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::Twilio, ['messaging_service_sid' => 'MG0001']);
    $row = bspTemplate($credential, 'Hello {{1}}, your order {{2}} is on its way.', providerTemplateId: 'HX0001');

    Http::fake(['twilio.test/*' => Http::response(['sid' => 'SM-TPL', 'to' => 'whatsapp:+'.BSP_RECIPIENT])]);

    bspGatewayDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($row),
        ['1' => 'Asha', '2' => 'A-1001'],
        BSP_RECIPIENT,
        'twilio-tpl',
    );

    Http::assertSent(function ($request): bool {
        expect($request['ContentSid'])->toBe('HX0001')
            ->and($request['MessagingServiceSid'])->toBe('MG0001')
            ->and(json_decode((string) $request['ContentVariables'], true))->toBe(['1' => 'Asha', '2' => 'A-1001']);

        return true;
    });
});

it('reads the recipient and key from the reserved vars on the contract signature', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);
    $row = bspTemplate($credential, 'Hello {{1}}.');

    Http::fake(['360dialog.test/*' => Http::response(['messages' => [['id' => 'wamid.RSV']]])]);

    $receipt = bspGatewayDriver()->sendTemplate($session, TemplateRef::fromModel($row), [
        '1' => 'Asha',
        BspGatewayChannelDriver::TEMPLATE_RECIPIENT_VAR => BSP_RECIPIENT,
        BspGatewayChannelDriver::TEMPLATE_KEY_VAR => 'reserved-key',
    ]);

    expect($receipt->idempotencyKey)->toBe('reserved-key')
        ->and($receipt->recipient)->toBe(BSP_RECIPIENT);

    // …and the convention is the same one 7.2 reserved, so a caller learns it once.
    expect(BspGatewayChannelDriver::TEMPLATE_RECIPIENT_VAR)
        ->toBe(App\Services\Channel\CloudApiChannelDriver::TEMPLATE_RECIPIENT_VAR)
        ->and(BspGatewayChannelDriver::TEMPLATE_KEY_VAR)
        ->toBe(App\Services\Channel\CloudApiChannelDriver::TEMPLATE_KEY_VAR);
});

it('refuses a template send whose reserved vars are absent', function (): void {
    [, $session, $credential] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);
    $row = bspTemplate($credential, 'Hello {{1}}.');

    Http::preventStrayRequests();

    bspGatewayDriver()->sendTemplate($session, TemplateRef::fromModel($row), ['1' => 'Asha']);
})->throws(InvalidArgumentException::class);

/*
|--------------------------------------------------------------------------
| Inbound — the cross-tenant refusal, for three signature schemes
|--------------------------------------------------------------------------
*/

it('refuses a validly-signed webhook about a number these credentials do not own', function (): void {
    // Three partners, three genuinely different proofs: Twilio's base64 HMAC-SHA1 over a canonical URL
    // string, 360dialog's hex HMAC-SHA256 over the raw body, Gupshup's base64 HMAC-SHA256 over the raw
    // body — and an identity that is a phone number for two of them and an application name for the
    // third. The refusal must not depend on which.
    $cases = [
        [BspProvider::Twilio, [
            'MessageSid' => 'SM-IN', 'From' => 'whatsapp:+'.BSP_RECIPIENT,
            'To' => 'whatsapp:+15559998888', 'Body' => 'hello', 'SmsStatus' => 'received',
        ]],
        [BspProvider::ThreeSixtyDialog, BspCredentialFixtures::metaEnvelope(
            [['id' => 'wamid.IN', 'from' => BSP_RECIPIENT, 'type' => 'text', 'text' => ['body' => 'hello']]],
            displayNumber: '15559998888',
        )],
        [BspProvider::Gupshup, [
            'app' => 'SomebodyElsesApp', 'type' => 'message',
            'payload' => ['id' => 'gs-IN', 'source' => BSP_RECIPIENT, 'payload' => ['text' => 'hello']],
        ]],
    ];

    foreach ($cases as [$provider, $payload]) {
        [$tenant] = bspGatewayTenant($provider);
        $credentials = bspGatewayCredentials($tenant);

        // Signed with *this* tenant's own secret — so the signature is perfectly valid, which is the
        // whole point: a signature proves who sent the body, not whose number it is about.
        $request = BspCredentialFixtures::signedWebhook($provider, $payload);

        expect(fn (): mixed => bspGatewayDriver()->parseWebhook($request, $credentials))
            ->toThrow(WebhookVerificationException::class);

        try {
            bspGatewayDriver()->parseWebhook($request, $credentials);
        } catch (WebhookVerificationException $e) {
            // Fingerprinted, never quoted: two refusals about one number correlate without the number
            // reaching a log line.
            expect($e->getMessage())->toContain('addressed to');
            expect($e->getMessage())->not->toContain('15559998888');
            expect($e->getMessage())->not->toContain('SomebodyElsesApp');
            expect($e->getStatusCode())->toBe(403);
        }
    }
});

it('refuses a webhook whose signature is wrong or absent, per partner scheme', function (): void {
    foreach ([BspProvider::Twilio, BspProvider::ThreeSixtyDialog, BspProvider::Gupshup, BspProvider::MessageBird] as $provider) {
        [$tenant] = bspGatewayTenant($provider);
        $credentials = bspGatewayCredentials($tenant);
        $payload = ['MessageSid' => 'x', 'To' => 'whatsapp:+15550001111', 'MessageStatus' => 'delivered'];

        expect(fn (): mixed => bspGatewayDriver()->parseWebhook(BspCredentialFixtures::signedWebhook($provider, $payload, sign: false), $credentials))
            ->toThrow(WebhookVerificationException::class)
            ->and(fn (): mixed => bspGatewayDriver()->parseWebhook(
                BspCredentialFixtures::signedWebhook($provider, $payload, secret: 'a-different-tenants-secret-000000'),
                $credentials,
            ))->toThrow(WebhookVerificationException::class);
    }
});

it('refuses a Vonage callback whose JWT does not cover the body it escorted', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::Vonage);
    $credentials = bspGatewayCredentials($tenant);

    $body = json_encode(['message_uuid' => 'v-1', 'to' => '15550001111', 'from' => BSP_RECIPIENT, 'text' => 'hi'], JSON_THROW_ON_ERROR);

    // A perfectly valid token — for a different body. Without the `payload_hash` check a captured
    // callback would authenticate any body an attacker liked.
    $request = Request::create('https://platform.test/webhooks/vonage/route-key', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.BspCredentialFixtures::jwt(['payload_hash' => hash('sha256', 'some other body')], BspCredentialFixtures::WEBHOOK_SECRET),
    ], $body);

    expect(fn (): mixed => bspGatewayDriver()->parseWebhook($request, $credentials))
        ->toThrow(WebhookVerificationException::class);
});

it('refuses a signed-webhook token that declares an algorithm the platform did not choose', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::MessageBird);
    $credentials = bspGatewayCredentials($tenant);

    $body = json_encode(['message' => ['id' => 'mb-1']], JSON_THROW_ON_ERROR);
    $url = 'https://platform.test/webhooks/messagebird/route-key';

    // `{"alg":"none"}` is the canonical JWT defeat; the allowlist is what refuses it.
    $unsigned = rtrim(strtr(base64_encode((string) json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=')
        .'.'.rtrim(strtr(base64_encode((string) json_encode([
            'payload_hash' => hash('sha256', $body),
            'url_hash' => hash('sha256', $url),
        ])), '+/', '-_'), '=').'.';

    $request = Request::create($url, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_MESSAGEBIRD_SIGNATURE_JWT' => $unsigned,
    ], $body);

    expect(fn (): mixed => bspGatewayDriver()->parseWebhook($request, $credentials))
        ->toThrow(WebhookVerificationException::class);
});

it('normalises a verified inbound message into a canonical event with the credentials\' tenant', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::Gupshup);
    $credentials = bspGatewayCredentials($tenant);

    $event = bspGatewayDriver()->parseWebhook(BspCredentialFixtures::signedWebhook(BspProvider::Gupshup, [
        // The app name as the tenant typed it in Gupshup's console, in a different case — a typo, not
        // an attack, so it must still verify.
        'app' => 'acmesupport',
        'type' => 'message',
        'timestamp' => 1735786800,
        'payload' => ['id' => 'gs-IN-1', 'source' => BSP_RECIPIENT, 'payload' => ['text' => 'Where is my order?']],
    ]), $credentials);

    expect($event->kind)->toBe(InboundEventKind::Message)
        ->and($event->mode)->toBe(ChannelMode::BspGateway)
        // From the credentials, never from the payload — that is where a cross-tenant injection would
        // land.
        ->and($event->tenantId)->toBe($tenant->id)
        ->and($event->from)->toBe(BSP_RECIPIENT)
        ->and($event->text)->toBe('Where is my order?')
        ->and($event->providerMessageId)->toBe('gs-IN-1')
        ->and($event->isActionable())->toBeTrue();
});

it('returns every event a partner batched, and says how many there were', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);
    $credentials = bspGatewayCredentials($tenant);

    $request = BspCredentialFixtures::signedWebhook(BspProvider::ThreeSixtyDialog, BspCredentialFixtures::metaEnvelope(
        messages: [
            ['id' => 'wamid.1', 'from' => BSP_RECIPIENT, 'type' => 'text', 'text' => ['body' => 'one'], 'timestamp' => '1735786800'],
            ['id' => 'wamid.2', 'from' => BSP_RECIPIENT, 'type' => 'text', 'text' => ['body' => 'two']],
        ],
        statuses: [['id' => 'wamid.out', 'status' => 'read', 'timestamp' => '1735786900']],
    ));

    $events = bspGatewayDriver()->parseWebhookBatch($request, $credentials);

    expect($events)->toHaveCount(3)
        ->and($events[0]->text)->toBe('one')
        ->and($events[0]->occurredAt?->timestamp)->toBe(1735786800)
        ->and($events[1]->text)->toBe('two')
        // A partner timestamp is never fabricated: `null` where the payload said nothing.
        ->and($events[1]->occurredAt)->toBeNull()
        ->and($events[2]->kind)->toBe(InboundEventKind::ReadReceipt)
        ->and($events[0]->payloadValue(BspGatewayChannelDriver::BATCH_SIZE_KEY))->toBe(3)
        ->and($events[2]->payloadValue(BspGatewayChannelDriver::BATCH_INDEX_KEY))->toBe(2);

    // The contract's single-event method returns the first, and the batch shape is what makes the loss
    // detectable rather than silent.
    expect(bspGatewayDriver()->parseWebhook($request, $credentials)->text)->toBe('one');
});

it('aborts a batch that mixes in one foreign number rather than dropping that change', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::ThreeSixtyDialog);
    $credentials = bspGatewayCredentials($tenant);

    $body = BspCredentialFixtures::metaEnvelope([['id' => 'wamid.1', 'from' => BSP_RECIPIENT, 'type' => 'text', 'text' => ['body' => 'mine']]]);
    // A second change about somebody else's number, after a first that checks out.
    $body['entry'][0]['changes'][] = [
        'field' => 'messages',
        'value' => [
            'metadata' => ['display_phone_number' => '15559998888', 'phone_number_id' => '999'],
            'messages' => [['id' => 'wamid.2', 'from' => BSP_RECIPIENT, 'type' => 'text', 'text' => ['body' => 'theirs']]],
        ],
    ];

    // A body that mixes two tenants' numbers is forged, not partly usable.
    expect(fn (): mixed => bspGatewayDriver()->parseWebhookBatch(BspCredentialFixtures::signedWebhook(BspProvider::ThreeSixtyDialog, $body), $credentials))
        ->toThrow(WebhookVerificationException::class);
});

it('acknowledges a verified payload it does not act on instead of refusing it', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::Gupshup);
    $credentials = bspGatewayCredentials($tenant);

    // A billing event: verified, well-formed, consumed by nobody in this release. A 403 here would turn
    // every partner feature announcement into an alert.
    $event = bspGatewayDriver()->parseWebhook(BspCredentialFixtures::signedWebhook(BspProvider::Gupshup, [
        'app' => 'AcmeSupport',
        'type' => 'billing-event',
        'payload' => ['deductions' => []],
    ]), $credentials);

    expect($event->kind)->toBe(InboundEventKind::Unsupported)
        ->and($event->isActionable())->toBeFalse()
        ->and($event->tenantId)->toBe($tenant->id);
});

it('maps each partner\'s delivery words onto the canonical receipt kinds', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::Twilio);
    $credentials = bspGatewayCredentials($tenant);

    $expected = [
        'sent' => InboundEventKind::DeliveryReceipt,
        'delivered' => InboundEventKind::DeliveryReceipt,
        'read' => InboundEventKind::ReadReceipt,
        'undelivered' => InboundEventKind::SendFailure,
    ];

    foreach ($expected as $word => $kind) {
        $event = bspGatewayDriver()->parseWebhook(BspCredentialFixtures::signedWebhook(BspProvider::Twilio, [
            'MessageSid' => 'SM-OUT',
            'To' => 'whatsapp:+15550001111',
            'MessageStatus' => $word,
            'ErrorCode' => '63016',
        ]), $credentials);

        expect($event->kind)->toBe($kind)
            ->and($event->providerMessageId)->toBe('SM-OUT')
            // The raw word survives in the payload, because `sent` and `delivered` collapse and task 9.5
            // needs the distinction for the never-downgrades rule (Property 9).
            ->and($event->payloadValue('MessageStatus'))->toBe($word);

        if ($kind === InboundEventKind::SendFailure) {
            expect($event->failureReason)->toContain('63016');
        }
    }
});

/*
|--------------------------------------------------------------------------
| Error mapping — eight vocabularies, one chain
|--------------------------------------------------------------------------
*/

it('maps each partner\'s refusal onto an ErrorClass through the real classifier chain', function (): void {
    $classifier = app(ErrorClassifier::class);
    $bsp = new BspGatewayErrorClassifier(app(BspAdapterRegistry::class));

    $cases = [
        // A rate limit must be retryable with backoff — and Twilio's `20429` arrives with a 429 while
        // 360dialog forwards Meta's `130429` with a **400**, which is why the code is read first.
        [BspProvider::Twilio, 429, '20429', ErrorClass::RateLimit],
        [BspProvider::ThreeSixtyDialog, 400, '130429', ErrorClass::RateLimit],
        [BspProvider::Infobip, 429, 'TOO_MANY_REQUESTS', ErrorClass::RateLimit],
        [BspProvider::Vonage, 429, 'throttled', ErrorClass::RateLimit],
        // An invalid key must not be retryable: it does not renew itself, and a job that kept trying
        // would hold a send in flight long enough for task 7.6 to mark working credentials invalid.
        [BspProvider::Twilio, 401, '20003', ErrorClass::Auth],
        [BspProvider::MessageBird, 401, '2', ErrorClass::Auth],
        [BspProvider::Infobip, 401, 'UNAUTHORIZED', ErrorClass::Auth],
        [BspProvider::Kaleyra, 400, 'E101', ErrorClass::Auth],
        // Eight vocabularies, and the collisions are the reason the provider is part of the evidence:
        // MessageBird's `2` is an auth failure and 360dialog's `2` is Meta's transient trouble.
        [BspProvider::ThreeSixtyDialog, 500, '2', ErrorClass::Network],
        [BspProvider::MessageBird, 402, '25', ErrorClass::Permission],
        [BspProvider::Twilio, 400, '63003', ErrorClass::NotOnWhatsApp],
        [BspProvider::Twilio, 400, '63016', ErrorClass::Validation],
    ];

    foreach ($cases as [$provider, $status, $code, $class]) {
        $refusal = ChannelRequestFailedException::refused(
            mode: ChannelMode::BspGateway,
            operation: 'bsp.'.$provider->webhookSlug().'.message.text',
            status: $status,
            errorCode: $code,
            provider: $provider,
        );

        expect($bsp->classify($refusal))->toBe($class)
            // …and through the chain the platform actually consults, once the consolidation pass
            // registers this classifier.
            ->and($classifier->classify($refusal))->not->toBeNull();
    }
});

it('keeps the work when it does not recognise a refusal', function (): void {
    $bsp = new BspGatewayErrorClassifier(app(BspAdapterRegistry::class));

    $unknown = fn (int $status): ChannelRequestFailedException => ChannelRequestFailedException::refused(
        mode: ChannelMode::BspGateway,
        operation: 'bsp.kaleyra.message.text',
        status: $status,
        errorCode: 'E999-NEVER-SEEN',
        provider: BspProvider::Kaleyra,
    );

    // A 5xx and a status that is neither 4xx nor 5xx both keep the message: the risk is a duplicate the
    // idempotency key absorbs, and the alternative drops a customer's message, which nothing absorbs.
    expect($bsp->classify($unknown(503)))->toBe(ErrorClass::Network)
        ->and($bsp->classify($unknown(299)))->toBe(ErrorClass::Network)
        // A 4xx is still the partner's rough statement about the request.
        ->and($bsp->classify($unknown(422)))->toBe(ErrorClass::Validation);
});

it('declines to classify another mode\'s refusal, so eight vocabularies stay out of Meta\'s', function (): void {
    $bsp = new BspGatewayErrorClassifier(app(BspAdapterRegistry::class));

    expect($bsp->classify(ChannelRequestFailedException::refused(
        mode: ChannelMode::CloudApi,
        operation: 'cloud_api.message.text',
        status: 400,
        errorCode: '4',
    )))->toBeNull()
        ->and($bsp->classify(new RuntimeException('not ours')))->toBeNull();
});

it('raises a typed refusal carrying the partner when a send is rejected', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Twilio);

    Http::fake(['twilio.test/*' => Http::response(['code' => 21211, 'message' => 'Invalid To', 'status' => 400], 400)]);

    try {
        bspGatewayDriver()->send($session, TextContent::to(BSP_RECIPIENT, 'nope', 'refused-key'));
        expect(false)->toBeTrue('the send should have been refused');
    } catch (ChannelRequestFailedException $e) {
        expect($e->mode)->toBe(ChannelMode::BspGateway)
            ->and($e->provider)->toBe(BspProvider::Twilio)
            ->and($e->errorCode)->toBe('21211')
            ->and($e->operation)->toBe('bsp.twilio.message.text');

        // The partner's prose is never quoted: it echoes the request, and on a 401 the key.
        expect($e->getMessage())->not->toContain('Invalid To');
        expect($e->getMessage())->not->toContain(BspCredentialFixtures::API_SECRET);
    }
});

it('treats a partner refusal delivered with a 200 as a refusal, not a send', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Wati);

    // WATI answers a rejected send `HTTP 200` with `result: false`. Reading that as acceptance would
    // record a message as sent that WATI never accepted — the one outcome that is definitely wrong.
    Http::fake(['wati.test/*' => Http::response(['result' => false, 'info' => 'No open session'], 200)]);

    try {
        bspGatewayDriver()->send($session, TextContent::to(BSP_RECIPIENT, 'hi', 'wati-200'));
        expect(false)->toBeTrue('the 200 should have been read as a refusal');
    } catch (ChannelRequestFailedException $e) {
        expect($e->status)->toBe(200)
            ->and($e->provider)->toBe(BspProvider::Wati);

        // …and it fails fast rather than spending five attempts on a deterministic refusal that has no
        // status for the shared fallback to read.
        expect((new BspGatewayErrorClassifier(app(BspAdapterRegistry::class)))->classify($e))
            ->toBe(ErrorClass::Validation);
    }
});

it('treats a 2xx with no partner message id as a transport failure, never as a send', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Kaleyra);

    Http::fake(['kaleyra.test/*' => Http::response(['status' => 'queued'])]);

    // Nothing could ever be reconciled with this send, and an unreconcilable "sent" is worse than a
    // retry — so it fails closed.
    expect(fn (): mixed => bspGatewayDriver()->send($session, TextContent::to(BSP_RECIPIENT, 'hi', 'no-id')))
        ->toThrow(BridgeUnreachableException::class);
});

/*
|--------------------------------------------------------------------------
| Credentials & onboarding
|--------------------------------------------------------------------------
*/

it('reports an incomplete credential set as unhealthy without probing the partner', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    // A Twilio set with no Account SID: every request without it is refused, so it is refused here.
    app(ChannelCredentialStore::class)->put(
        $tenant,
        ChannelMode::BspGateway,
        secrets: ['auth_token' => BspCredentialFixtures::API_SECRET],
        config: ['base_url' => 'https://twilio.test', 'sender' => '15550001111'],
        provider: BspProvider::Twilio,
    );

    Http::preventStrayRequests();

    $health = bspGatewayDriver()->healthCheck(bspGatewayCredentials($tenant));

    expect($health->isUsable())->toBeFalse()
        // Names the key so task 7.6 can tell the tenant which field to fill in, and keeps the previous
        // working set.
        ->and($health->detail)->toContain('account_sid')
        ->and($health->provider)->toBe(BspProvider::Twilio);

    Http::assertNothingSent();
});

it('reports a partner refusal as unhealthy data, rendered from the code and never the prose', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::Infobip);

    Http::fake(['infobip.test/*' => Http::response([
        'requestError' => ['serviceException' => [
            'messageId' => 'UNAUTHORIZED',
            'text' => 'Invalid login details for App '.BspCredentialFixtures::API_SECRET,
        ]],
    ], 401)]);

    $credentials = bspGatewayCredentials($tenant);
    $health = bspGatewayDriver()->healthCheck($credentials);

    expect($health->isUsable())->toBeFalse()
        ->and($health->detail)->toContain('rejected the API credentials')
        ->and($health->detail)->toContain('Infobip')
        // Belt and braces: the sentence is rendered from the code, and `ChannelHealth` scrubs it anyway.
        ->and($credentials->containsSecret($health->detail))->toBeFalse()
        ->and($health->latencyMs)->not->toBeNull();
});

it('reports a working partner account as healthy', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::MessageBird);

    Http::fake(['conversations.messagebird.com/*' => Http::response(['count' => 1, 'items' => [['id' => 'ch-1']]])]);
    Http::fake(['messagebird.test/*' => Http::response(['count' => 1, 'items' => [['id' => 'ch-1']]])]);

    $health = bspGatewayDriver()->healthCheck(bspGatewayCredentials($tenant));

    expect($health->isUsable())->toBeTrue()
        ->and($health->detail)->toContain('MessageBird')
        ->and($health->provider)->toBe(BspProvider::MessageBird);
});

it('lets a transport failure propagate from a probe, because "could not ask" is not "said no"', function (): void {
    [$tenant] = bspGatewayTenant(BspProvider::Gupshup);

    Http::fake(['gupshup.test/*' => fn (): mixed => throw new Illuminate\Http\Client\ConnectionException('down')]);

    expect(fn (): mixed => bspGatewayDriver()->healthCheck(bspGatewayCredentials($tenant)))
        ->toThrow(BridgeUnreachableException::class);
});

it('verifies rather than registers, and claims no callback URL', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Kaleyra);
    $credentials = bspGatewayCredentials($session->tenant);

    Http::fake(['kaleyra.test/*' => Http::response(['data' => [], 'total_count' => 0])]);

    $result = bspGatewayDriver()->register($session, $credentials);

    expect($result->isLive())->toBeTrue()
        ->and($result->provider)->toBe(BspProvider::Kaleyra)
        ->and($result->providerNumberId)->toBe('15550001111')
        ->and($result->hasCallback())->toBeFalse()
        ->and($result->detail)->toContain('route key');
});

it('reports a registration the partner did not confirm as pending, not live', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Kaleyra);
    $credentials = bspGatewayCredentials($session->tenant);

    // A 2xx whose body says nothing this release can read as a confirmation.
    Http::fake(['kaleyra.test/*' => Http::response(['unexpected' => true])]);

    $result = bspGatewayDriver()->register($session, $credentials);

    expect($result->isPending())->toBeTrue()
        ->and($result->isLive())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The transport half
|--------------------------------------------------------------------------
*/

it('reports the partner account state as a session state', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Twilio);

    Http::fake(['twilio.test/*' => Http::response(['sid' => 'AC0001', 'status' => 'active'])]);

    $state = bspGatewayDriver()->sessionState($session->id);

    expect($state->sessionId)->toBe($session->id)
        ->and($state->status)->toBe(SessionStatus::Connected)
        ->and($state->phone)->toBe('15550001111');
});

it('refuses the WhatsApp-Web transport operations rather than no-opping them', function (): void {
    [, $session] = bspGatewayTenant(BspProvider::Twilio);
    $driver = bspGatewayDriver();

    Http::preventStrayRequests();

    expect(fn (): mixed => $driver->provisionSession($session->id, SessionLoginMethod::Qr))
        ->toThrow(ChannelOperationException::class)
        ->and(function () use ($driver, $session): void {
            $driver->startSession($session->id);
        })
        ->toThrow(ChannelOperationException::class)
        // Deliberately not mapped onto a partner's number-release route: that would make "stop this
        // session" surrender the tenant's number at its partner.
        ->and(function () use ($driver, $session): void {
            $driver->stopSession($session->id);
        })
        ->toThrow(ChannelOperationException::class)
        ->and(fn (): mixed => $driver->pairingCode($session->id, '15550001111'))
        ->toThrow(ChannelOperationException::class)
        ->and(function () use ($driver, $session): void {
            $driver->sendPresence($session->id, BSP_RECIPIENT, PresenceState::Composing);
        })->toThrow(ChannelOperationException::class);

    // …and the two that have honest non-exception answers.
    expect($driver->qr($session->id))->toBeNull();

    $checks = $driver->checkNumbers($session->id, [BSP_RECIPIENT]);

    expect($checks[BSP_RECIPIENT]->isUnknown())->toBeTrue()
        ->and($checks[BSP_RECIPIENT]->isOnWhatsApp())->toBeFalse();

    Http::assertNothingSent();
});

it('answers reachability from a partner host, counting a refusal as reachable', function (): void {
    // Any HTTP answer proves the host is serving: an unauthenticated partner request is *supposed* to be
    // refused.
    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    expect(bspGatewayDriver()->isReachable())->toBeTrue();

    Http::fake(['*' => fn (): mixed => throw new Illuminate\Http\Client\ConnectionException('down')]);

    expect(bspGatewayDriver()->isReachable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Secrecy
|--------------------------------------------------------------------------
*/

it('keeps a partner API key out of a debug dump of the request it authenticates', function (): void {
    $credentials = new ChannelCredentials(
        tenantId: '01JBSPTENANT0000000000000',
        mode: ChannelMode::BspGateway,
        provider: BspProvider::ThreeSixtyDialog,
        config: BspCredentialFixtures::config(BspProvider::ThreeSixtyDialog),
        secrets: BspCredentialFixtures::secrets(BspProvider::ThreeSixtyDialog),
    );

    $request = app(BspAdapterRegistry::class)
        ->for(BspProvider::ThreeSixtyDialog)
        ->textMessage($credentials, BSP_RECIPIENT, 'hello');

    $dumped = print_r($request->__debugInfo(), true);

    expect($dumped)->not->toContain(BspCredentialFixtures::API_SECRET)
        ->and($dumped)->toContain(App\Services\Channel\Bsp\BspRequest::REDACTED)
        // The endpoint is not a secret and stays visible, which is what makes a dump useful.
        ->and($dumped)->toContain('https://360dialog.test/messages');
});
