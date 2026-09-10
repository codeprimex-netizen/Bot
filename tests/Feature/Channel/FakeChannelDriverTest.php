<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Enums\InboundEventKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Bridge\MediaPayload;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ModeGuardedChannelDriver;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use App\Services\Channel\TextContent;
use App\Services\Reliability\ErrorClassifier;
use Illuminate\Http\Request;
use Tests\Fixtures\Channel\FakeChannelDriver;

/*
|--------------------------------------------------------------------------
| FakeChannelDriver (Req 36.1 / NFR7 — the test double itself)
|--------------------------------------------------------------------------
| A fixture with three properties every test that uses it depends on, and which nothing else
| checks: it is **deterministic**, it is configurable **downward only**, and it **records**
| what was and was not asked of it. The middle one is the important one — a fake that could
| widen a mode's `❌` cell would let a test pass for a reason production cannot reproduce,
| which is the only failure mode a double has that a bug does not.
|
| It lives under `tests/Fixtures/` on the `Tests\` (`autoload-dev`) namespace, so it is not
| autoloadable in a production install at all — the argument `FakeBridgeClient`'s docblock
| makes, and the reason Property 28's scan of `app/` cannot find it.
*/

beforeEach(function (): void {
    FakeChannelDriver::reset();
});

function fakeSession(ChannelMode $mode = ChannelMode::Baileys): Session
{
    $tenant = Tenant::factory()->create();

    return Session::factory()->create(['tenant_id' => $tenant->id, 'channel_mode' => $mode]);
}

function fakeCredentials(ChannelMode $mode = ChannelMode::Baileys): ChannelCredentials
{
    return ChannelCredentials::platform('tenant-1', $mode);
}

/*
|--------------------------------------------------------------------------
| Policy: derived, and narrowable in one direction only
|--------------------------------------------------------------------------
*/

it('derives mode policy from the matrix exactly as the real drivers do', function (): void {
    foreach (ChannelMode::cases() as $mode) {
        $driver = new FakeChannelDriver($mode);

        expect($driver->mode())->toBe($mode)
            // Not a constructor flag: Req 8.8 requires the anti-ban gate be non-disableable,
            // and a fake that could answer otherwise would make a Property 24 test vacuous.
            ->and($driver->requiresAntiBan())->toBe($mode->isWebProtocol());

        foreach (ChannelCapability::cases() as $capability) {
            expect($driver->supportFor($capability))->toBe($capability->supportOn($mode));
        }
    }
});

it('can be narrowed to a backend that lacks a capability its mode allows', function (): void {
    // *"this driver is a partner that does not do media"*, without inventing a mode.
    $driver = (new FakeChannelDriver(ChannelMode::BspGateway))->restrict(ChannelCapability::Media);

    expect($driver->supports(ChannelCapability::Media))->toBeFalse()
        ->and($driver->supportFor(ChannelCapability::Media))->toBe(ChannelCapabilitySupport::Unsupported)
        // Narrowing one cell leaves the rest of the mode alone.
        ->and($driver->supports(ChannelCapability::SendSingle))->toBeTrue()
        ->and($driver->capabilities())->not->toContain(ChannelCapability::Media)
        ->and($driver->capabilities())->toContain(ChannelCapability::SendSingle);

    // And a `✅` can be given a condition, which is the shape task 7.4 resolves per partner.
    $conditional = (new FakeChannelDriver(ChannelMode::CloudApi))->conditional(ChannelCapability::Media);

    expect($conditional->supportFor(ChannelCapability::Media))->toBe(ChannelCapabilitySupport::Conditional)
        ->and($conditional->supports(ChannelCapability::Media))->toBeTrue();
});

it('cannot widen a single refused cell on any mode, however hard it asks', function (): void {
    // The enforcement, over the whole matrix rather than one example: `claimSupport()` exists
    // *so that* this is assertable. Without a fake that tries, "widening is impossible" would
    // be untested, and `ModeGuardedChannelDriver` would be the only thing standing between a
    // per-provider config and a group-create dispatched to Meta (Property 21).
    foreach (ChannelMode::cases() as $mode) {
        $driver = (new FakeChannelDriver($mode))->claimSupport(...ChannelCapability::cases());

        foreach (ChannelCapability::unsupportedBy($mode) as $refused) {
            expect($driver->supports($refused))->toBeFalse()
                ->and($driver->supportFor($refused))->toBe(ChannelCapabilitySupport::Unsupported);
        }

        expect($driver->capabilities())->toBe(ChannelCapability::supportedBy($mode));
    }
});

it('applies a profile to instances a lazy registry has not built yet', function (): void {
    // The registry constructs on demand, so a test cannot reach the instance before the router
    // does — which is what `profile()` is for.
    FakeChannelDriver::profile(ChannelMode::BspGateway, ChannelCapability::Media, ChannelCapabilitySupport::Unsupported);

    $registry = FakeChannelDriver::registry(ChannelMode::BspGateway);
    $driver = $registry[ChannelMode::BspGateway->value]();

    expect($driver->supports(ChannelCapability::Media))->toBeFalse();

    // ...and it does not leak into the next test.
    FakeChannelDriver::reset();

    expect((new FakeChannelDriver(ChannelMode::BspGateway))->supports(ChannelCapability::Media))->toBeTrue();
});

it('survives the mode guard, which re-derives both answers whatever the fake says', function (): void {
    $guarded = new ModeGuardedChannelDriver(
        (new FakeChannelDriver(ChannelMode::CloudApi))->claimSupport(ChannelCapability::Groups),
    );

    expect($guarded->requiresAntiBan())->toBeFalse()
        ->and($guarded->supports(ChannelCapability::Groups))->toBeFalse()
        ->and($guarded->supports(ChannelCapability::SendSingle))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Determinism
|--------------------------------------------------------------------------
*/

it('gives a send the same provider message id every time, from the input alone', function (): void {
    $session = fakeSession();
    $content = TextContent::to('15550001111@s.whatsapp.net', 'Your order shipped.', 'order-4417');

    $first = (new FakeChannelDriver(ChannelMode::Baileys))->send($session, $content);
    $second = (new FakeChannelDriver(ChannelMode::Baileys))->send($session, $content);

    // A counter would make this depend on how many sends ran earlier — in this test, and in
    // whichever test happened to run before it.
    expect($first->providerMessageId)->toBe(FakeChannelDriver::MESSAGE_ID_PREFIX.'order-4417')
        ->and($second->providerMessageId)->toBe($first->providerMessageId)
        // No clock: a receipt carries no timestamp unless something asserts on one.
        ->and($first->acceptedAt)->toBeNull();

    $other = (new FakeChannelDriver(ChannelMode::Baileys))->send(
        $session,
        TextContent::to('15550001111@s.whatsapp.net', 'Your order shipped.', 'order-4418'),
    );

    expect($other->providerMessageId)->not->toBe($first->providerMessageId);
});

it('degrades a conditional capability and says so on the receipt', function (): void {
    $driver = (new FakeChannelDriver(ChannelMode::Baileys))->conditional(ChannelCapability::SendSingle);

    $receipt = $driver->send(
        fakeSession(),
        TextContent::to('15550001111@s.whatsapp.net', 'Reply 1 or 2', 'menu-1'),
    );

    // Read from the same matrix (and the same arranged refinement) the gate reads, so the flag
    // means what it means on a real driver: `⚠️` was flattened, `✅` was not.
    expect($receipt->degraded)->toBeTrue()
        ->and($driver->sends()[0]['degraded'])->toBeTrue()
        ->and((new FakeChannelDriver(ChannelMode::Baileys))
            ->send(fakeSession(), TextContent::to('15550001111@s.whatsapp.net', 'Hi', 'plain-1'))
            ->degraded)->toBeFalse();
});

it('names the partner on a BSP receipt, as the receipt requires', function (): void {
    $receipt = (new FakeChannelDriver(ChannelMode::BspGateway))->send(
        fakeSession(ChannelMode::BspGateway),
        TextContent::to('15550001111@s.whatsapp.net', 'Hi', 'bsp-1'),
    );

    // `SendReceipt` refuses a BSP receipt with no provider, so a fake that omitted it would
    // fail inside the fixture rather than in the code under test.
    expect($receipt->provider)->not->toBeNull()
        ->and($receipt->mode)->toBe(ChannelMode::BspGateway);
});

it('derives every transport answer from its arguments and nothing else', function (): void {
    $driver = new FakeChannelDriver(ChannelMode::Baileys);
    $id = fakeSession()->id;

    $text = $driver->sendText($id, '15550001111@s.whatsapp.net', 'hello');
    $again = $driver->sendText($id, '15550001111@s.whatsapp.net', 'hello');
    $other = $driver->sendText($id, '15550001111@s.whatsapp.net', 'goodbye');
    $media = $driver->sendMedia(
        $id,
        '15550001111@s.whatsapp.net',
        new MediaPayload(App\Enums\MediaKind::Image, 'image/png', url: 'https://cdn.test/a.png'),
    );

    expect($again->waMessageId)->toBe($text->waMessageId)
        ->and($other->waMessageId)->not->toBe($text->waMessageId)
        ->and($media->jid)->toBe('15550001111@s.whatsapp.net')
        ->and($driver->pairingCode($id, '15550001111'))->toBe('FAKE1111')
        ->and($driver->provisionSession($id, SessionLoginMethod::Qr)->status)
        ->toBe(SessionLoginMethod::Qr->initialStatus())
        ->and($driver->checkNumbers($id, ['15550001111'])['15550001111']->exists)->toBeTrue()
        ->and($driver->qr($id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Recording — so "never invoked" is observable
|--------------------------------------------------------------------------
*/

it('records every call in order, so a test can assert what was not dispatched', function (): void {
    $session = fakeSession();
    $driver = new FakeChannelDriver(ChannelMode::Baileys);

    expect($driver->called('send'))->toBeFalse();

    $driver->send($session, TextContent::to('15550001111@s.whatsapp.net', 'one', 'k1'));
    $driver->send($session, TextContent::to('15550001111@s.whatsapp.net', 'two', 'k2'));
    $driver->healthCheck(fakeCredentials());
    $driver->sendPresence($session->id, '15550001111@s.whatsapp.net', PresenceState::Composing);

    expect($driver->calls())->toBe([
        'send:k1',
        'send:k2',
        'healthCheck',
        'sendPresence:'.$session->id.':composing',
    ])
        ->and($driver->callCount('send'))->toBe(2)
        ->and($driver->called('healthCheck'))->toBeTrue()
        // The observable Properties 21, 22 and 24 are stated in terms of: the *absence* of a
        // call, which no return value can show.
        ->and($driver->called('sendTemplate'))->toBeFalse()
        ->and($driver->called('parseWebhook'))->toBeFalse()
        ->and($driver->sends())->toHaveCount(2)
        ->and($driver->receipts())->toHaveCount(2);
});

it('counts constructions per mode, which is what proves a router memoised', function (): void {
    expect(FakeChannelDriver::built(ChannelMode::Baileys))->toBe(0)
        ->and(FakeChannelDriver::instance(ChannelMode::Baileys))->toBeNull();

    $registry = FakeChannelDriver::registry(ChannelMode::Baileys, ChannelMode::CloudApi);

    $first = $registry[ChannelMode::Baileys->value]();
    $second = $registry[ChannelMode::Baileys->value]();

    // Counting equality would prove nothing — two freshly resolved drivers are equal objects.
    // Counting constructions proves everything.
    expect(FakeChannelDriver::built(ChannelMode::Baileys))->toBe(2)
        ->and(FakeChannelDriver::built(ChannelMode::CloudApi))->toBe(0)
        ->and($first)->not->toBe($second)
        ->and(FakeChannelDriver::instance(ChannelMode::Baileys))->toBe($second);

    FakeChannelDriver::reset();

    expect(FakeChannelDriver::built(ChannelMode::Baileys))->toBe(0)
        ->and(FakeChannelDriver::instance(ChannelMode::Baileys))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Failure injection, classified by the platform's own chain
|--------------------------------------------------------------------------
*/

it('fails a send retryably or permanently, as the real classifier reads it', function (): void {
    $session = fakeSession();
    $content = TextContent::to('15550001111@s.whatsapp.net', 'Hi', 'k1');
    $classifier = app(ErrorClassifier::class);

    $retryable = (new FakeChannelDriver(ChannelMode::CloudApi))->failSendRetryably();

    try {
        $retryable->send($session, $content);
        $thrown = null;
    } catch (Throwable $failure) {
        $thrown = $failure;
    }

    // Task 8.5's failover advances on a retryable failure and stops on a fail-fast one, so the
    // distinction has to be the platform's — not a bespoke exception a fake invented.
    expect($thrown)->toBeInstanceOf(BridgeUnreachableException::class)
        ->and($thrown === null ? null : $classifier->classify($thrown))->toBe(ErrorClass::Bridge)
        ->and(ErrorClass::Bridge->isRetryableByNature())->toBeTrue()
        // Recorded before it threw, so "fenced off before dispatch" stays distinguishable from
        // "attempted and refused".
        ->and($retryable->calls())->toBe(['send:k1']);

    $permanent = (new FakeChannelDriver(ChannelMode::CloudApi))->failSendPermanently();

    try {
        $permanent->send($session, $content);
        $refused = null;
    } catch (Throwable $failure) {
        $refused = $failure;
    }

    expect($refused)->toBeInstanceOf(BridgeRequestFailedException::class)
        ->and($refused === null ? null : $classifier->classify($refused))->toBe(ErrorClass::Auth)
        ->and(ErrorClass::Auth->isRetryableByNature())->toBeFalse();
});

it('fails a template send too, and stops failing when told to', function (): void {
    $session = fakeSession();
    $driver = (new FakeChannelDriver(ChannelMode::CloudApi))->failSendRetryably();

    expect(fn (): SendReceipt => $driver->sendTemplate($session, new TemplateRef('order_update', 'en_US'), []))
        ->toThrow(BridgeUnreachableException::class);

    // The transport is deliberately left working: a prefix match on `send` would have broken
    // `sendText`/`sendMedia`/`sendPresence` along with it.
    expect($driver->sendText($session->id, '15550001111@s.whatsapp.net', 'still works')->waMessageId)
        ->toStartWith(FakeChannelDriver::MESSAGE_ID_PREFIX);

    $driver->sendSuccessfully();

    expect($driver->sendTemplate($session, new TemplateRef('order_update', 'en_US'), [])->templateName)
        ->toBe('order_update');
});

it('reports an arranged outage through health, registration and state', function (): void {
    $driver = (new FakeChannelDriver(ChannelMode::CloudApi))->unreachable();
    $credentials = fakeCredentials(ChannelMode::CloudApi);

    expect($driver->healthCheck($credentials)->healthy)->toBeFalse()
        ->and($driver->register(fakeSession(ChannelMode::CloudApi), $credentials)->isPending())->toBeTrue()
        ->and($driver->isReachable())->toBeFalse();

    $driver->reachable();

    expect($driver->healthCheck($credentials)->healthy)->toBeTrue()
        ->and($driver->register(fakeSession(ChannelMode::CloudApi), $credentials)->isLive())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Inbound
|--------------------------------------------------------------------------
*/

it('normalises a webhook without pretending to verify one', function (): void {
    $driver = new FakeChannelDriver(ChannelMode::CloudApi);
    $credentials = fakeCredentials(ChannelMode::CloudApi);

    $event = $driver->parseWebhook(
        Request::create('/webhooks/cloud-api/k', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'wa_message_id' => 'wamid.in',
            'from' => '15550001111',
            'text' => 'hello',
        ])),
        $credentials,
    );

    // A fake holds no secret, so any verification verdict it gave would be its own invention;
    // what it models is the normalisation, which is what a routing test asserts on.
    expect($event->kind)->toBe(InboundEventKind::Message)
        ->and($event->mode)->toBe(ChannelMode::CloudApi)
        ->and($event->tenantId)->toBe('tenant-1')
        ->and($event->providerMessageId)->toBe('wamid.in')
        ->and($event->text)->toBe('hello');

    $unusable = $driver->parseWebhook(
        Request::create('/webhooks/cloud-api/k', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['field' => 'value'])),
        $credentials,
    );

    expect($unusable->kind)->toBe(InboundEventKind::Unsupported)
        ->and($driver->callCount('parseWebhook'))->toBe(2);
});
