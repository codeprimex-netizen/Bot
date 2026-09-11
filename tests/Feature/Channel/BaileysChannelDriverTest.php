<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use App\Enums\MediaKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Channel\ModeCapabilityException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Tenancy\CrossTenantAccessException;
use App\Models\IdempotencyKey;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Bridge\BridgeClient;
use App\Services\Bridge\HttpBridgeClient;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\TenantScopedBridgeClient;
use App\Services\Channel\BaileysChannelDriver;
use App\Services\Channel\ChannelDriver;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\ModeGuardedChannelDriver;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use App\Services\Channel\TextContent;
use App\Services\Security\SigningSecretStore;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| BaileysChannelDriver (Req 8.1, 8.8 / A8)
|--------------------------------------------------------------------------
| The default mode, and the one place on this platform where "no behavioural change for
| existing tenants" is a testable claim rather than an intention (design § Channel Mode 2.1).
| Four things are asserted, in the form that would catch each being lost silently:
|
|   1. **it delegates, verbatim.** Every transport method reaches the composed bridge chain
|      with the caller's arguments unchanged, and every failure propagates as the exact
|      exception the bridge raised — because a swallowed refusal is a send recorded as sent,
|      and a re-wrapped one stops being classified (`BridgeErrorClassifier`);
|   2. **it cannot lose the guard decorators.** It asks for the `BridgeClient` *interface*, so
|      the ownership and breaker decorators `BridgeServiceProvider` composed are what it gets
|      — proved by a cross-tenant send being refused *through the driver*;
|   3. **`send()` is idempotent on the content's key**, replaying the original receipt and
|      putting nothing new on the wire (design § 2.4);
|   4. **`parseWebhook()` verifies before it believes**, and derives its HMAC scope from the
|      credentials rather than from the payload — the cross-tenant injection that ordering
|      prevents is asserted directly.
|
| `Http::fake()` stands in for the Node sidecar, exactly as `HttpBridgeClientTest` does; the
| real signing-secret store, idempotency store and tenant scoping are all live.
*/

beforeEach(function (): void {
    config()->set('wa.bridge.url', 'http://bridge.test:3111');
    config()->set('wa.bridge.token', 'bridge-token');
    config()->set('wa.bridge.prefix', 'v1');
});

/**
 * A tenant, bound as the acting one, with a connected session on the default mode.
 *
 * @return array{0: Tenant, 1: Session}
 */
function baileysSession(SessionStatus $status = SessionStatus::Connected): array
{
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::Baileys,
        'status' => $status,
    ]);

    return [$tenant, $session];
}

function baileysDriver(): BaileysChannelDriver
{
    return app(BaileysChannelDriver::class);
}

/**
 * A signed webhook request the sidecar could have sent for `$session`.
 *
 * @param  array<string, mixed>  $payload
 */
function bridgeWebhook(Session $session, array $payload, ?string $signature = null, bool $sign = true): Request
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $headers = [];

    if ($signature !== null) {
        $headers['HTTP_'.str_replace('-', '_', strtoupper(BaileysChannelDriver::SIGNATURE_HEADER))] = $signature;
    } elseif ($sign) {
        $headers['HTTP_'.str_replace('-', '_', strtoupper(BaileysChannelDriver::SIGNATURE_HEADER))]
            = app(SigningSecretStore::class)->sign(BaileysChannelDriver::webhookScope($session->id), $body);
    }

    return Request::create(
        '/webhooks/bridge/route-key',
        'POST',
        [],
        [],
        [],
        $headers + ['CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

/*
|--------------------------------------------------------------------------
| What this backend is
|--------------------------------------------------------------------------
*/

it('is the default mode, needs no tenant credentials, and derives its policy from the matrix', function (): void {
    $driver = baileysDriver();

    expect($driver->mode())->toBe(ChannelMode::Baileys)
        ->and($driver->mode()->isDefault())->toBeTrue()
        ->and($driver->mode()->requiresTenantCredentials())->toBeFalse()
        // Req 8.8: the anti-ban gate applies and no configuration can turn it off — the answer
        // is `mode()->isWebProtocol()` and nothing else.
        ->and($driver->requiresAntiBan())->toBeTrue();

    // Every cell, from the matrix rather than from a hand-written list — including the five
    // Baileys-only capabilities and the two `⚠️` cells.
    foreach (ChannelCapability::cases() as $capability) {
        expect($driver->supportFor($capability))->toBe($capability->supportOn(ChannelMode::Baileys))
            ->and($driver->supports($capability))->toBe($capability->supportedOn(ChannelMode::Baileys));
    }

    expect($driver->supportFor(ChannelCapability::Template))->toBe(ChannelCapabilitySupport::Conditional)
        ->and($driver->supportFor(ChannelCapability::Groups))->toBe(ChannelCapabilitySupport::Native)
        ->and($driver->capabilities())->toBe(ChannelCapability::cases());
});

it('is registered for BAILEYS in the container, wrapped, and reachable through the router', function (): void {
    [$tenant, $session] = baileysSession();

    $driver = app(ChannelRouter::class)->driverFor($session);

    // The registry entry of task 7.1 — a mode with no entry raises instead, so this asserts
    // the wiring and not just the class.
    expect($driver)->toBeInstanceOf(ModeGuardedChannelDriver::class)
        ->and($driver->mode())->toBe(ChannelMode::Baileys);

    assert($driver instanceof ModeGuardedChannelDriver);
    expect($driver->inner())->toBeInstanceOf(BaileysChannelDriver::class)
        // A tenant that has configured nothing routes: BAILEYS needs no credential row
        // (Req 8.13 / `ChannelMode::requiresTenantCredentials()`).
        ->and(app(App\Services\Channel\ChannelCredentialStore::class)->rowFor($tenant, ChannelMode::Baileys))
        ->toBeNull();
});

it('depends on the bridge interface, so it cannot be handed anything but the composed chain', function (): void {
    // The regression this rules out has no symptom: a driver that constructed
    // `HttpBridgeClient` itself would pass every test that only looks at the wire, while
    // silently dropping ownership scoping and the breaker for every Channel Mode send.
    // Asserted structurally rather than behaviourally, because the behavioural half (a
    // cross-tenant send being refused *through the driver*) cannot distinguish "it asked for
    // the interface" from "someone happened to inject the right thing today".
    $constructor = (new ReflectionClass(BaileysChannelDriver::class))->getConstructor();
    $parameter = $constructor?->getParameters()[0] ?? null;
    $type = $parameter?->getType();

    expect($parameter?->getName())->toBe('bridge')
        ->and($type instanceof ReflectionNamedType ? $type->getName() : null)->toBe(BridgeClient::class)
        // ...and that name resolves to the whole chain, ownership decorator outermost.
        ->and(app(BridgeClient::class))->toBeInstanceOf(TenantScopedBridgeClient::class)
        ->and(app(BaileysChannelDriver::class))->toBeInstanceOf(ChannelDriver::class);
});

/*
|--------------------------------------------------------------------------
| Transport — delegated verbatim
|--------------------------------------------------------------------------
*/

it('forwards every transport call to the bridge with the caller\'s arguments unchanged', function (): void {
    [, $session] = baileysSession();

    Http::fake(['bridge.test:3111/*' => Http::response([
        'status' => 'QR_PENDING',
        'qr' => 'base64png',
        'pairing_code' => 'PAIR9999',
        'wa_message_id' => 'wamid.delegated',
        'jid' => '15550001111@s.whatsapp.net',
        'numbers' => [['number' => '15550001111', 'exists' => true, 'jid' => '15550001111@s.whatsapp.net']],
    ])]);

    $driver = baileysDriver();

    $init = $driver->provisionSession($session->id, SessionLoginMethod::Qr);
    $driver->startSession($session->id);
    $driver->stopSession($session->id, true);
    $state = $driver->sessionState($session->id);
    $qr = $driver->qr($session->id);
    $code = $driver->pairingCode($session->id, '15550001111');
    $text = $driver->sendText($session->id, '15550001111@s.whatsapp.net', 'hello', ['link_preview' => false]);
    $media = $driver->sendMedia(
        $session->id,
        '15550001111@s.whatsapp.net',
        new MediaPayload(MediaKind::Image, 'image/png', url: 'https://cdn.test/a.png'),
    );
    $driver->sendPresence($session->id, '15550001111@s.whatsapp.net', PresenceState::Composing);
    $checks = $driver->checkNumbers($session->id, ['15550001111']);

    expect($init->status)->toBe(SessionStatus::QrPending)
        ->and($state->sessionId)->toBe($session->id)
        ->and($qr)->toBe('base64png')
        ->and($code)->toBe('PAIR9999')
        ->and($text->waMessageId)->toBe('wamid.delegated')
        ->and($media->waMessageId)->toBe('wamid.delegated')
        ->and($checks['15550001111']->exists)->toBeTrue();

    // The arguments, on the wire, unaltered: the `opts` map nested rather than merged, the
    // logout flag carried, the presence value as the enum spells it.
    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->url() === 'http://bridge.test:3111/v1/sessions/'.$session->id.'/messages/text'
        && $r->data() === [
            'jid' => '15550001111@s.whatsapp.net',
            'text' => 'hello',
            'options' => ['link_preview' => false],
        ]);

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->url() === 'http://bridge.test:3111/v1/sessions/'.$session->id.'/stop'
        && $r->data() === ['logout' => true]);

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->url() === 'http://bridge.test:3111/v1/sessions/'.$session->id.'/presence'
        && $r->data() === ['jid' => '15550001111@s.whatsapp.net', 'presence' => 'composing']);

    // Ten operations, ten requests: nothing retried, nothing added, nothing coalesced. (Ten
    // rather than eleven because `isReachable()` has its own test — it is the one transport
    // method that answers with a value rather than by raising.)
    Http::assertSentCount(10);
});

it('lets a bridge refusal propagate as the exact exception the bridge raised', function (): void {
    [, $session] = baileysSession();

    Http::fake(['bridge.test:3111/*' => Http::response(['code' => 'session_not_connected'], 409)]);

    try {
        baileysDriver()->sendText($session->id, '15550001111@s.whatsapp.net', 'hello');
        $thrown = null;
    } catch (BridgeRequestFailedException $refused) {
        $thrown = $refused;
    }

    // Not swallowed, not re-wrapped, and above all not reclassified: the code and status are
    // what `BridgeErrorClassifier` reads to decide this is retryable on the BRIDGE budget.
    expect($thrown)->toBeInstanceOf(BridgeRequestFailedException::class)
        ->and($thrown?->bridgeCode)->toBe(BridgeRequestFailedException::CODE_SESSION_NOT_CONNECTED)
        ->and($thrown?->status)->toBe(409);
});

it('lets an unreachable bridge propagate as a transport failure, never as a falsy result', function (): void {
    [, $session] = baileysSession();

    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('refused'));

    expect(fn (): App\Services\Bridge\SentMessageDto => baileysDriver()
        ->sendText($session->id, '15550001111@s.whatsapp.net', 'hello'))
        ->toThrow(BridgeUnreachableException::class);
});

it('refuses another tenant\'s session through the driver, because the ownership decorator is still there', function (): void {
    [, $session] = baileysSession();

    $intruder = Tenant::factory()->create();
    app(TenantContext::class)->set($intruder);

    Http::fake();

    // The whole argument for depending on the interface: the refusal is not the driver's, and
    // the driver could not have implemented it — the sidecar has no tenant column.
    expect(fn (): App\Services\Bridge\SentMessageDto => baileysDriver()
        ->sendText($session->id, '15550001111@s.whatsapp.net', 'hello'))
        ->toThrow(CrossTenantAccessException::class);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| send()
|--------------------------------------------------------------------------
*/

it('sends text through the bridge and promotes the acknowledgement into a receipt', function (): void {
    [, $session] = baileysSession();

    Http::fake(['bridge.test:3111/*' => Http::response([
        'wa_message_id' => 'wamid.sent',
        'jid' => '15550001111@s.whatsapp.net',
        'sent_at' => '2025-01-02T03:04:05+00:00',
    ])]);

    $receipt = baileysDriver()->send(
        $session,
        TextContent::to('15550001111@s.whatsapp.net', 'Your order shipped.', 'order-4417'),
    );

    expect($receipt->mode)->toBe(ChannelMode::Baileys)
        ->and($receipt->providerMessageId)->toBe('wamid.sent')
        ->and($receipt->recipient)->toBe('15550001111@s.whatsapp.net')
        ->and($receipt->idempotencyKey)->toBe('order-4417')
        ->and($receipt->capability)->toBe(ChannelCapability::SendSingle)
        // `SEND_SINGLE` is `✅` on Baileys, so nothing was flattened.
        ->and($receipt->degraded)->toBeFalse()
        ->and($receipt->provider)->toBeNull()
        ->and($receipt->acceptedAt?->toIso8601String())->toBe('2025-01-02T03:04:05+00:00');

    Http::assertSent(fn (Illuminate\Http\Client\Request $r): bool => $r->url() === 'http://bridge.test:3111/v1/sessions/'.$session->id.'/messages/text'
        && $r->data() === ['jid' => '15550001111@s.whatsapp.net', 'text' => 'Your order shipped.']);
});

it('sends once per idempotency key and replays the original receipt', function (): void {
    [, $session] = baileysSession();

    Http::fake(['bridge.test:3111/*' => Http::response([
        'wa_message_id' => 'wamid.once',
        'jid' => '15550001111@s.whatsapp.net',
    ])]);

    $content = TextContent::to('15550001111@s.whatsapp.net', 'Your order shipped.', 'order-4417');
    $driver = baileysDriver();

    $first = $driver->send($session, $content);
    $second = $driver->send($session, $content);

    // design § 2.4: *a second `send()` with a key already accepted must return the original
    // receipt and put nothing new on the wire*. A duplicate here is a message a customer
    // reads twice.
    expect($second->providerMessageId)->toBe($first->providerMessageId)
        ->and($second->recipient)->toBe($first->recipient)
        ->and($second->idempotencyKey)->toBe($first->idempotencyKey)
        ->and($second->capability)->toBe($first->capability)
        ->and($second->degraded)->toBe($first->degraded);

    Http::assertSentCount(1);

    // Scoped by tenant, because `idempotency_keys` is not tenant-scoped and a caller-chosen
    // key could collide across tenants.
    expect(IdempotencyKey::query()->where('scope', 'channel.send:'.$session->tenant_id)->count())->toBe(1);
});

it('lets two tenants use the same idempotency key without either replaying the other\'s receipt', function (): void {
    [, $acmeSession] = baileysSession();
    [, $globexSession] = baileysSession();

    Http::fake(['bridge.test:3111/*' => Http::sequence()
        ->push(['wa_message_id' => 'wamid.acme', 'jid' => '15550001111@s.whatsapp.net'])
        ->push(['wa_message_id' => 'wamid.globex', 'jid' => '15550002222@s.whatsapp.net'])]);

    $driver = baileysDriver();

    // Acme's session is no longer the acting tenant's, so each send runs as its own owner —
    // which is also what makes this a Property 23 assertion rather than only a dedup one.
    $globex = $driver->send(
        $globexSession,
        TextContent::to('15550002222@s.whatsapp.net', 'Globex', 'shared-key'),
    );

    app(TenantContext::class)->set($acmeSession->tenant);
    $acme = $driver->send(
        $acmeSession,
        TextContent::to('15550001111@s.whatsapp.net', 'Acme', 'shared-key'),
    );

    expect($globex->providerMessageId)->toBe('wamid.acme')
        ->and($acme->providerMessageId)->toBe('wamid.globex')
        ->and($acme->providerMessageId)->not->toBe($globex->providerMessageId);

    Http::assertSentCount(2);
});

it('refuses to reuse one key for a different send rather than answering with the first receipt', function (): void {
    [, $session] = baileysSession();

    Http::fake(['bridge.test:3111/*' => Http::response([
        'wa_message_id' => 'wamid.first',
        'jid' => '15550001111@s.whatsapp.net',
    ])]);

    $driver = baileysDriver();
    $driver->send($session, TextContent::to('15550001111@s.whatsapp.net', 'First', 'order-4417'));

    // Replaying the first receipt here would tell the caller a message it never sent was
    // delivered, and to the wrong person.
    expect(fn (): SendReceipt => $driver->send(
        $session,
        TextContent::to('15550009999@s.whatsapp.net', 'Second', 'order-4417'),
    ))->toThrow(IdempotencyKeyReuseException::class);

    Http::assertSentCount(1);
});

it('does not record a key when the bridge refuses, so a retry may send', function (): void {
    [, $session] = baileysSession();

    Sleep::fake();

    // Two refusals, because `GuardedBridgeClient` spends its inline attempt budget
    // (`wa.bridge.guard.attempts`) before the failure reaches the driver — the guard is part of
    // the behaviour this driver must not change, so the test works with it rather than round it.
    Http::fake(['bridge.test:3111/*' => Http::sequence()
        ->push(['code' => 'session_not_connected'], 409)
        ->push(['code' => 'session_not_connected'], 409)
        ->push(['wa_message_id' => 'wamid.retried', 'jid' => '15550001111@s.whatsapp.net'])]);

    $content = TextContent::to('15550001111@s.whatsapp.net', 'Your order shipped.', 'order-4417');
    $driver = baileysDriver();

    expect(fn (): SendReceipt => $driver->send($session, $content))
        ->toThrow(BridgeRequestFailedException::class);

    // The failure is retryable (`ErrorClass::Bridge`), so the key must not have burned the
    // work: a released key is what lets the queue's retry actually send.
    expect($driver->send($session, $content)->providerMessageId)->toBe('wamid.retried');

    Http::assertSentCount(3);
});

it('refuses a send the bridge acknowledged with no message id', function (): void {
    [, $session] = baileysSession();

    Http::fake(['bridge.test:3111/*' => Http::response(['jid' => '15550001111@s.whatsapp.net'])]);

    // An unreconcilable "sent" is worse than a retry: no later delivery receipt could ever be
    // matched to it. `SentMessageDto` refuses it first, as a malformed response.
    expect(fn (): SendReceipt => baileysDriver()->send(
        $session,
        TextContent::to('15550001111@s.whatsapp.net', 'Your order shipped.', 'order-4417'),
    ))->toThrow(BridgeUnreachableException::class);
});

/*
|--------------------------------------------------------------------------
| sendTemplate()
|--------------------------------------------------------------------------
*/

it('refuses an approved-template send, because the web protocol has no approval registry', function (): void {
    [, $session] = baileysSession();

    Http::fake();

    try {
        baileysDriver()->sendTemplate($session, new TemplateRef('order_update', 'en_US'), ['brand' => 'ACME']);
        $thrown = null;
    } catch (ModeCapabilityException $refused) {
        $thrown = $refused;
    }

    // Rendering the body as text instead would return a receipt naming a template whose
    // approval nobody ever obtained — a send log a tenant cannot trust. The `⚠️` cell is
    // honoured by `send()` of rendered text, not by pretending an approval exists.
    expect($thrown)->toBeInstanceOf(ModeCapabilityException::class)
        ->and($thrown?->mode)->toBe(ChannelMode::Baileys)
        ->and($thrown?->capability)->toBe(ChannelCapability::Template)
        ->and($thrown?->isAvailableElsewhere())->toBeTrue()
        // Every official mode has a real approval registry, which is where the remedy is. The
        // matrix still lists BAILEYS as supporting `TEMPLATE` (`⚠️` = text-templated), so this
        // list is "modes that may attempt it" and not "modes `sendTemplate()` works on" — the
        // panel reads it to say *use an official mode for approved templates*.
        ->and($thrown?->availableOn())->toContain(ChannelMode::CloudApi, ChannelMode::BspGateway);

    Http::assertNothingSent();
});

it('still reports TEMPLATE as attemptable, so the capability gate is unchanged', function (): void {
    [, $session] = baileysSession();

    // The refusal is the *operation's*, not the matrix's: `assertSupported()` must keep
    // allowing `TEMPLATE` on Baileys or every templated-text send would be refused too.
    app(ChannelRouter::class)->assertSupported($session, ChannelCapability::Template);

    expect(baileysDriver()->supports(ChannelCapability::Template))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| register() and healthCheck()
|--------------------------------------------------------------------------
*/

it('reports pairing state rather than registering with a provider', function (): void {
    [, $pending] = baileysSession(SessionStatus::QrPending);

    Http::fake();

    $credentials = BaileysChannelDriver::credentialsFor($pending);
    $result = baileysDriver()->register($pending, $credentials);

    expect($result->isPending())->toBeTrue()
        ->and($result->isLive())->toBeFalse()
        ->and($result->mode)->toBe(ChannelMode::Baileys)
        ->and($result->providerNumberId)->toBe($pending->id)
        ->and($result->detail)->toContain('QR_PENDING')
        // No callback is claimed: registering the sidecar's callback URL is task 8.3's, and a
        // URL on a panel that nothing answers is worse than none.
        ->and($result->hasCallback())->toBeFalse();

    $pending->forceFill(['status' => SessionStatus::Connected])->save();

    $live = baileysDriver()->register($pending, $credentials);

    // Idempotent, and it reports the current state rather than resetting anything.
    expect($live->isLive())->toBeTrue()
        ->and($live->providerNumberId)->toBe($pending->id);

    Http::assertNothingSent();
});

it('health-checks the bridge itself and reports a failure as data', function (): void {
    [, $session] = baileysSession();
    $credentials = BaileysChannelDriver::credentialsFor($session);

    // One fake, two answers in order: a second `Http::fake()` call merges rather than replaces,
    // so the first stub would keep winning.
    Http::fake(['bridge.test:3111/v1/health' => Http::sequence()
        ->push(['ok' => true])
        ->push([], 503)]);

    $healthy = baileysDriver()->healthCheck($credentials);

    expect($healthy->healthy)->toBeTrue()
        ->and($healthy->mode)->toBe(ChannelMode::Baileys)
        ->and($healthy->latencyMs)->toBeGreaterThanOrEqual(0);

    $unhealthy = baileysDriver()->healthCheck($credentials);

    // A value, not an exception: task 7.6 records it and the connection screen renders it.
    expect($unhealthy->healthy)->toBeFalse()
        ->and($unhealthy->isUsable())->toBeFalse()
        // The URL is deployment config and is what makes the answer actionable; the token is
        // not read here at all.
        ->and($unhealthy->detail)->toContain('bridge.test:3111')
        ->and($unhealthy->detail)->not->toContain('bridge-token');
});

it('builds the platform credentials the contract needs for a mode that has no credential row', function (): void {
    [, $session] = baileysSession();

    $credentials = BaileysChannelDriver::credentialsFor($session);

    expect($credentials->mode)->toBe(ChannelMode::Baileys)
        ->and($credentials->tenantId)->toBe($session->tenant_id)
        ->and($credentials->provider)->toBeNull()
        ->and($credentials->requireConfig(BaileysChannelDriver::SESSION_CONFIG_KEY))->toBe($session->id)
        // Req 8.13 / `ChannelMode::requiresTenantCredentials()`: complete with no secrets at
        // all, which is the zero-official-API-onboarding promise.
        ->and($credentials->hasSecrets())->toBeFalse()
        ->and($credentials->isComplete())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| parseWebhook()
|--------------------------------------------------------------------------
*/

it('normalises a signed inbound message into the canonical event', function (): void {
    [, $session] = baileysSession();

    $request = bridgeWebhook($session, [
        'event' => 'message',
        'session_id' => $session->id,
        'wa_message_id' => '3EB0C4319D',
        'from' => '15550001111@s.whatsapp.net',
        'text' => 'is my order shipped?',
        'timestamp' => 1735786800,
    ]);

    $event = baileysDriver()->parseWebhook($request, BaileysChannelDriver::credentialsFor($session));

    expect($event->kind)->toBe(InboundEventKind::Message)
        ->and($event->mode)->toBe(ChannelMode::Baileys)
        // From the credentials, never from the payload.
        ->and($event->tenantId)->toBe($session->tenant_id)
        ->and($event->providerMessageId)->toBe('3EB0C4319D')
        ->and($event->from)->toBe('15550001111@s.whatsapp.net')
        ->and($event->text)->toBe('is my order shipped?')
        // A Baileys session has no provider-side number id, so the session *is* the identity
        // the payload was addressed to.
        ->and($event->channelIdentity)->toBe($session->id)
        ->and($event->occurredAt?->toIso8601String())->toBe('2025-01-02T03:00:00+00:00')
        ->and($event->isActionable())->toBeTrue()
        ->and($event->contentHash())->toBe(hash('sha256', 'is my order shipped?'))
        // Req 7.3 / A7: the body is content, so debug output carries a digest instead.
        ->and($event->__debugInfo())->not->toContain('is my order shipped?');
});

it('normalises each receipt kind, and carries a redacted failure reason on a failure only', function (): void {
    [, $session] = baileysSession();
    $credentials = BaileysChannelDriver::credentialsFor($session);
    $driver = baileysDriver();

    $delivered = $driver->parseWebhook(bridgeWebhook($session, [
        'event' => 'delivered',
        'session_id' => $session->id,
        'wa_message_id' => 'wamid.out',
    ]), $credentials);

    $read = $driver->parseWebhook(bridgeWebhook($session, [
        'event' => 'read',
        'session_id' => $session->id,
        'wa_message_id' => 'wamid.out',
    ]), $credentials);

    $failed = $driver->parseWebhook(bridgeWebhook($session, [
        'event' => 'failed',
        'session_id' => $session->id,
        'wa_message_id' => 'wamid.out',
        'error' => 'the recipient is not on WhatsApp',
    ]), $credentials);

    expect($delivered->kind)->toBe(InboundEventKind::DeliveryReceipt)
        ->and($delivered->failureReason)->toBeNull()
        ->and($read->kind)->toBe(InboundEventKind::ReadReceipt)
        ->and($read->failureReason)->toBeNull()
        ->and($failed->kind)->toBe(InboundEventKind::SendFailure)
        ->and($failed->failureReason)->toBe('the recipient is not on WhatsApp')
        // All three are keyed on the outbound id, which is the only thing that says which
        // message the receipt is about.
        ->and($failed->providerMessageId)->toBe('wamid.out');
});

it('accepts a verified event it does not act on, so the sidecar stops retrying', function (): void {
    [, $session] = baileysSession();

    $event = baileysDriver()->parseWebhook(bridgeWebhook($session, [
        'event' => 'chats.upsert',
        'session_id' => $session->id,
    ]), BaileysChannelDriver::credentialsFor($session));

    // A 403 storm on every sidecar feature addition is the alternative.
    expect($event->kind)->toBe(InboundEventKind::Unsupported)
        ->and($event->isActionable())->toBeFalse()
        ->and($event->payloadValue('event'))->toBe('chats.upsert');
});

it('refuses a payload with no signature header', function (): void {
    [, $session] = baileysSession();

    $request = bridgeWebhook($session, [
        'event' => 'message',
        'session_id' => $session->id,
        'wa_message_id' => 'x',
        'from' => 'y',
    ], sign: false);

    expect(fn (): App\Services\Channel\InboundEvent => baileysDriver()
        ->parseWebhook($request, BaileysChannelDriver::credentialsFor($session)))
        ->toThrow(WebhookVerificationException::class, 'carries no [X-Bridge-Signature] header');
});

it('refuses a payload whose signature does not match the raw body', function (): void {
    [, $session] = baileysSession();
    $credentials = BaileysChannelDriver::credentialsFor($session);
    $body = ['event' => 'message', 'session_id' => $session->id, 'wa_message_id' => 'x', 'from' => 'y'];

    // A signature over a *different* body: the signature is over bytes, so re-encoding the
    // decoded array would have verified here and must not.
    $stale = app(SigningSecretStore::class)->sign(
        BaileysChannelDriver::webhookScope($session->id),
        json_encode($body + ['text' => 'tampered'], JSON_THROW_ON_ERROR),
    );

    expect(fn (): App\Services\Channel\InboundEvent => baileysDriver()
        ->parseWebhook(bridgeWebhook($session, $body, signature: $stale), $credentials))
        ->toThrow(WebhookVerificationException::class, 'does not match the body')
        ->and(fn (): App\Services\Channel\InboundEvent => baileysDriver()
            ->parseWebhook(bridgeWebhook($session, $body, signature: 'sha256=deadbeef'), $credentials))
        ->toThrow(WebhookVerificationException::class);
});

it('keeps verifying a payload signed with the previous secret through the rotation window', function (): void {
    [, $session] = baileysSession();
    $scope = BaileysChannelDriver::webhookScope($session->id);
    $secrets = app(SigningSecretStore::class);

    $body = ['event' => 'delivered', 'session_id' => $session->id, 'wa_message_id' => 'wamid.out'];
    $signedWithOld = $secrets->sign($scope, json_encode($body, JSON_THROW_ON_ERROR));

    $secrets->rotate($scope);
    $secrets->forgetSecrets();

    // The dual-secret window is `SigningSecretStore`'s, and reusing it rather than inventing a
    // second HMAC scheme is what makes a webhook-secret rotation not an inbound outage.
    $event = baileysDriver()->parseWebhook(
        bridgeWebhook($session, $body, signature: $signedWithOld),
        BaileysChannelDriver::credentialsFor($session),
    );

    expect($event->kind)->toBe(InboundEventKind::DeliveryReceipt);
});

it('refuses a validly-signed payload that names another tenant\'s session', function (): void {
    [, $acme] = baileysSession();
    [, $globex] = baileysSession();

    // The attack this closes: a body signed with Acme's session secret, delivered on Globex's
    // route. Verifying under the scope the *payload* names would pass, and the event would
    // carry Globex's tenant id — one valid signature injecting a message into any tenant's
    // conversation history.
    $body = [
        'event' => 'message',
        'session_id' => $acme->id,
        'wa_message_id' => 'wamid.injected',
        'from' => '15550001111@s.whatsapp.net',
        'text' => 'pay this invoice instead',
    ];

    $signedAsAcme = app(SigningSecretStore::class)->sign(
        BaileysChannelDriver::webhookScope($acme->id),
        json_encode($body, JSON_THROW_ON_ERROR),
    );

    expect(fn (): App\Services\Channel\InboundEvent => baileysDriver()->parseWebhook(
        bridgeWebhook($globex, $body, signature: $signedAsAcme),
        BaileysChannelDriver::credentialsFor($globex),
    ))->toThrow(WebhookVerificationException::class);
});

it('refuses a verified payload that is the wrong shape, rather than half-populating an event', function (): void {
    [, $session] = baileysSession();
    $credentials = BaileysChannelDriver::credentialsFor($session);
    $driver = baileysDriver();

    $cases = [
        // Names no session, so it cannot be matched against the route it arrived on.
        ['event' => 'message', 'wa_message_id' => 'x', 'from' => 'y'],
        // A message with no id can never be deduplicated or correlated.
        ['event' => 'message', 'session_id' => $session->id, 'from' => 'y'],
        // A message with no sender.
        ['event' => 'message', 'session_id' => $session->id, 'wa_message_id' => 'x'],
        // A receipt about nothing.
        ['event' => 'read', 'session_id' => $session->id],
    ];

    foreach ($cases as $payload) {
        expect(fn (): App\Services\Channel\InboundEvent => $driver
            ->parseWebhook(bridgeWebhook($session, $payload), $credentials))
            // 403 and not a 500: `InboundEvent`'s own constructor would raise
            // `InvalidArgumentException` for the same payloads, and this is a public endpoint.
            ->toThrow(WebhookVerificationException::class);
    }
});

it('refuses a verified body that is not a JSON object', function (): void {
    [, $session] = baileysSession();

    $body = '"just a string"';
    $signature = app(SigningSecretStore::class)->sign(BaileysChannelDriver::webhookScope($session->id), $body);

    $request = Request::create('/webhooks/bridge/route-key', 'POST', [], [], [], [
        'HTTP_X_BRIDGE_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    expect(fn (): App\Services\Channel\InboundEvent => baileysDriver()
        ->parseWebhook($request, BaileysChannelDriver::credentialsFor($session)))
        ->toThrow(WebhookVerificationException::class, 'not a JSON object');
});

it('names one HMAC scope, the one the rotation command and task 8.3 use', function (): void {
    // A second spelling would mean the secret this driver verifies with is not the row
    // `wa:security:rotate-hmac` rotates — a rotation that silently stopped working.
    expect(BaileysChannelDriver::webhookScope('01JABC'))->toBe('bridge:session:01JABC')
        ->and(BaileysChannelDriver::WEBHOOK_SCOPE_PREFIX)->toBe('bridge:session:')
        ->and(HttpBridgeClient::DEFAULT_URL)->toBe('http://127.0.0.1:3000');
});
