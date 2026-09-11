<?php

declare(strict_types=1);

use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Services\Bridge\HttpBridgeClient;
use App\Services\Bridge\MediaPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The wire to the Node + Baileys sidecar (engine Req 35; Req 2.9 / A2)
|--------------------------------------------------------------------------
| A real HTTP client, not a placeholder: one typed call becomes one request and one
| decoded response. What is asserted here is everything a caller downstream depends on
| and cannot see — the routes, the bearer token, the bounded timeouts, and above all that
| every way of failing is a typed exception rather than a plausible-looking success.
|
| The sidecar itself is a separate deployable (a JS runtime) and is out of scope here;
| `Http::fake()` stands in for it, which is exactly the seam a real deployment replaces.
*/

beforeEach(function (): void {
    config()->set('wa.bridge.url', 'http://bridge.test:3111');
    config()->set('wa.bridge.token', 'bridge-token');
    config()->set('wa.bridge.prefix', 'v1');
});

function bridgeSessionId(): string
{
    return Str::ulid()->toBase32();
}

function httpBridge(): HttpBridgeClient
{
    return app(HttpBridgeClient::class);
}

/*
|--------------------------------------------------------------------------
| Session lifecycle
|--------------------------------------------------------------------------
*/

it('provisions a session as a POST carrying the login method and the derived auth-state directory', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(['status' => 'QR_PENDING', 'qr' => 'base64png'])]);

    $id = bridgeSessionId();
    $init = httpBridge()->provisionSession($id, SessionLoginMethod::Qr, null, '/srv/auth/tenants/T/'.$id);

    expect($init->status)->toBe(SessionStatus::QrPending)
        ->and($init->qr)->toBe('base64png')
        ->and($init->sessionId)->toBe($id);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://bridge.test:3111/v1/sessions'
        && $r->method() === 'POST'
        && $r->hasHeader('Authorization', 'Bearer bridge-token')
        && $r->data() === [
            'session_id' => $id,
            'login_method' => 'QR',
            'auth_state_dir' => '/srv/auth/tenants/T/'.$id,
        ]);
});

it('refuses a pairing-code provision with no number before a request is made', function (): void {
    Http::fake();

    expect(fn () => httpBridge()->provisionSession(bridgeSessionId(), SessionLoginMethod::PairingCode))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('treats a provision response with no recognised status as a transport failure', function (): void {
    // A 2xx that says nothing usable is not a session that started: defaulting the status
    // would make a failed provision look like a successful one.
    Http::fake(['bridge.test:3111/*' => Http::response(['status' => 'TELEPORTING'])]);

    expect(fn () => httpBridge()->provisionSession(bridgeSessionId(), SessionLoginMethod::Qr))
        ->toThrow(BridgeUnreachableException::class);
});

it('starts and stops a session on their own routes, with logout an explicit flag', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response([])]);

    $id = bridgeSessionId();
    httpBridge()->startSession($id);
    httpBridge()->stopSession($id);
    httpBridge()->stopSession($id, logout: true);

    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/v1/sessions/{$id}/start");
    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/v1/sessions/{$id}/stop"
        && $r->data() === ['logout' => false]);
    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/v1/sessions/{$id}/stop"
        && $r->data() === ['logout' => true]);
});

it('reads the session state the bridge reports, including its unmapped disconnect reason', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response([
        'status' => 'RECONNECTING',
        'phone' => '919812345678',
        'push_name' => 'Acme Support',
        'device_id' => 'device-7',
        'disconnect_reason' => 'restartRequired',
        'last_seen_at' => '2024-06-20T10:00:00+00:00',
    ])]);

    $state = httpBridge()->sessionState($id = bridgeSessionId());

    expect($state->status)->toBe(SessionStatus::Reconnecting)
        ->and($state->phone)->toBe('919812345678')
        ->and($state->pushName)->toBe('Acme Support')
        // Left as the protocol's own string: deciding whether a reason is retryable is
        // ReconnectPolicy's job (task 9.2), and a DTO that pre-judged it would duplicate it.
        ->and($state->disconnectReason)->toBe('restartRequired')
        ->and($state->lastSeenAt?->toIso8601String())->toBe('2024-06-20T10:00:00+00:00')
        ->and($state->isOnline())->toBeFalse();

    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/v1/sessions/{$id}"
        && $r->method() === 'GET');
});

it('reports "no QR to show" as a null rather than a failure', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response([])]);

    expect(httpBridge()->qr(bridgeSessionId()))->toBeNull();
});

it('refuses to hand back an empty pairing code', function (): void {
    // An empty string here would be displayed to a tenant as a code to type into their phone.
    Http::fake(['bridge.test:3111/*' => Http::response(['pairing_code' => '  '])]);

    expect(fn () => httpBridge()->pairingCode(bridgeSessionId(), '+91 98123-45678'))
        ->toThrow(BridgeUnreachableException::class);
});

it('normalises a phone number to E.164 digits on the way out', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(['pairing_code' => 'ABCD1234'])]);

    expect(httpBridge()->pairingCode(bridgeSessionId(), '+91 98123-45678'))->toBe('ABCD1234');

    Http::assertSent(fn (Request $r): bool => $r->data() === ['phone' => '919812345678']);
});

it('refuses a phone number that is not a plausible E.164 number', function (): void {
    Http::fake();

    // Sending to a mangled number is how one tenant's typo becomes a message to a stranger.
    expect(fn () => httpBridge()->pairingCode(bridgeSessionId(), '12'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Messaging
|--------------------------------------------------------------------------
*/

it('sends text with the recipient and content at the top level and caller options nested', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response([
        'wa_message_id' => 'BAE5F1',
        'jid' => '919812345678@s.whatsapp.net',
        'sent_at' => 1718877600,
    ])]);

    $receipt = httpBridge()->sendText(
        $id = bridgeSessionId(),
        '919812345678@s.whatsapp.net',
        'Hello',
        ['link_preview' => false, 'jid' => 'attacker@s.whatsapp.net'],
    );

    expect($receipt->waMessageId)->toBe('BAE5F1')
        ->and($receipt->jid)->toBe('919812345678@s.whatsapp.net')
        ->and($receipt->sentAt?->timestamp)->toBe(1718877600);

    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/v1/sessions/{$id}/messages/text"
        // The option map is nested, so it cannot overwrite the recipient — an option that
        // could rewrite `jid` would make the pipeline's opt-out decision unenforceable.
        && $r->data() === [
            'jid' => '919812345678@s.whatsapp.net',
            'text' => 'Hello',
            'options' => ['link_preview' => false, 'jid' => 'attacker@s.whatsapp.net'],
        ]);
});

it('refuses a send acknowledged without a wa_message_id', function (): void {
    // Nothing could ever reconcile that message: delivery receipts, campaign counters and
    // the duplicate-id assertion are all keyed on it. Retrying risks a duplicate the
    // idempotency key absorbs; recording it as sent loses it for ever.
    Http::fake(['bridge.test:3111/*' => Http::response(['jid' => 'x@s.whatsapp.net'])]);

    expect(fn () => httpBridge()->sendText(bridgeSessionId(), 'x@s.whatsapp.net', 'Hi'))
        ->toThrow(BridgeUnreachableException::class);
});

it('sends media as its own request shape, with the source the payload declared', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(['wa_message_id' => 'MEDIA1'])]);

    httpBridge()->sendMedia(
        $id = bridgeSessionId(),
        '919812345678@s.whatsapp.net',
        MediaPayload::fromUrl('https://cdn.test/a.pdf', 'application/pdf', filename: 'invoice.pdf', caption: 'Your invoice'),
    );

    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/v1/sessions/{$id}/messages/media"
        && $r->data() === [
            'jid' => '919812345678@s.whatsapp.net',
            'media' => [
                'kind' => 'document',
                'mime_type' => 'application/pdf',
                'url' => 'https://cdn.test/a.pdf',
                'filename' => 'invoice.pdf',
                'caption' => 'Your invoice',
            ],
        ]);
});

it('refuses a recipient that is not a fully-qualified protocol address', function (): void {
    Http::fake();

    expect(fn () => httpBridge()->sendText(bridgeSessionId(), "919812345678\n", 'Hi'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => httpBridge()->sendText(bridgeSessionId(), '919812345678', 'Hi'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('publishes a presence update without producing a message', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response([])]);

    httpBridge()->sendPresence($id = bridgeSessionId(), 'x@s.whatsapp.net', PresenceState::Composing);

    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/v1/sessions/{$id}/presence"
        && $r->data() === ['jid' => 'x@s.whatsapp.net', 'presence' => 'composing']);
});

/*
|--------------------------------------------------------------------------
| Number checks
|--------------------------------------------------------------------------
*/

it('answers a number check for every number asked, keyed by the caller spelling', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(['numbers' => [
        ['number' => '919812345678', 'exists' => true, 'jid' => '919812345678@s.whatsapp.net'],
        ['number' => '919800000000', 'exists' => false],
    ]])]);

    $checks = httpBridge()->checkNumbers(bridgeSessionId(), [
        '+91 98123-45678',
        '919800000000',
        '919899999999',   // the bridge says nothing about this one
    ]);

    // Keys are the caller's own spellings. PHP coerces the all-digit ones to integers on the
    // way in *and* on the way out, so a lookup by the original string still resolves.
    expect(array_map('strval', array_keys($checks)))->toBe(['+91 98123-45678', '919800000000', '919899999999'])
        ->and($checks['+91 98123-45678']->isOnWhatsApp())->toBeTrue()
        ->and($checks['+91 98123-45678']->jid)->toBe('919812345678@s.whatsapp.net')
        ->and($checks['919800000000']->exists)->toBeFalse()
        // Unanswered is a third state: collapsing it into "not on WhatsApp" would let a
        // rate limit be recorded as a permanent fact about somebody's phone number.
        ->and($checks['919899999999']->isUnknown())->toBeTrue();
});

it('treats "exists but no jid" as unanswered rather than as an address', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(['numbers' => [
        ['number' => '919812345678', 'exists' => true],
    ]])]);

    $checks = httpBridge()->checkNumbers(bridgeSessionId(), ['919812345678']);

    expect($checks['919812345678']->isUnknown())->toBeTrue();
});

it('answers an empty number check without a round trip', function (): void {
    Http::fake();

    expect(httpBridge()->checkNumbers(bridgeSessionId(), []))->toBe([]);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Failure
|--------------------------------------------------------------------------
*/

it('turns an unreachable bridge into a typed transport failure, never a false success', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('cURL error 7: Failed to connect'));

    expect(fn () => httpBridge()->sendText(bridgeSessionId(), 'x@s.whatsapp.net', 'Hi'))
        ->toThrow(BridgeUnreachableException::class);
});

it('never quotes the underlying client error, which can carry the bridge url and token', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('connect to http://bridge.test:3111 with Bearer bridge-token'));

    try {
        httpBridge()->startSession(bridgeSessionId());
        $message = '';
    } catch (BridgeUnreachableException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toContain('bridge-token')
        ->and($message)->not->toContain('bridge.test')
        ->and($message)->toContain('session.start');
});

it('carries the bridge status and error code out of a refusal, without its prose', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(
        ['code' => 'session_not_connected', 'message' => 'socket closed while sending to 919812345678'],
        409,
        ['Retry-After' => '5'],
    )]);

    try {
        httpBridge()->sendText(bridgeSessionId(), 'x@s.whatsapp.net', 'Hi');
        $failure = null;
    } catch (BridgeRequestFailedException $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(BridgeRequestFailedException::class)
        ->and($failure?->status)->toBe(409)
        ->and($failure?->bridgeCode)->toBe('session_not_connected')
        ->and($failure?->retryAfterSeconds)->toBe(5)
        ->and($failure?->isSessionUnavailable())->toBeTrue()
        // The bridge's prose is written by another process and can echo a request body —
        // including a recipient's number.
        ->and($failure?->getMessage())->not->toContain('919812345678');
});

it('does not pass the bridge status through to an HTTP client', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(['code' => 'not_on_whatsapp'], 422)]);

    try {
        httpBridge()->sendText(bridgeSessionId(), 'x@s.whatsapp.net', 'Hi');
        $failure = null;
    } catch (BridgeRequestFailedException $e) {
        $failure = $e;
    }

    // A 409 between us and an internal sidecar is not a 409 for the tenant's API client.
    expect($failure?->getStatusCode())->toBe(422)
        ->and($failure?->isSessionUnavailable())->toBeFalse();
});

it('rejects a nonsense error code instead of comparing it against the vocabulary', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response(['code' => "session_not_connected\n; DROP"], 409)]);

    try {
        httpBridge()->startSession(bridgeSessionId());
        $failure = null;
    } catch (BridgeRequestFailedException $e) {
        $failure = $e;
    }

    expect($failure?->bridgeCode)->toBeNull()
        ->and($failure?->status)->toBe(409);
});

it('treats a 2xx body that is not a json object as a transport failure', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response('<html>502 from the proxy</html>')]);

    expect(fn () => httpBridge()->sessionState(bridgeSessionId()))->toThrow(BridgeUnreachableException::class);
});

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
*/

it('answers the health probe as a boolean, never an exception', function (): void {
    Http::fake(['bridge.test:3111/v1/health' => Http::response(['ok' => true])]);

    expect(httpBridge()->isReachable())->toBeTrue();

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://bridge.test:3111/v1/health');
});

it('reports a dead bridge as unreachable rather than raising', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('down'));

    expect(httpBridge()->isReachable())->toBeFalse();
});

it('reports a bridge answering 503 as unreachable', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response('', 503)]);

    expect(httpBridge()->isReachable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Config
|--------------------------------------------------------------------------
*/

it('mounts the routes at the root when no prefix is configured', function (): void {
    config()->set('wa.bridge.prefix', '');
    Http::fake(['bridge.test:3111/*' => Http::response([])]);

    httpBridge()->startSession($id = bridgeSessionId());

    Http::assertSent(fn (Request $r): bool => $r->url() === "http://bridge.test:3111/sessions/{$id}/start");
});

it('sends no authorization header when no token is configured, so the bridge answers 401', function (): void {
    // Deliberately not a placeholder token: both produce a 401, and a fake one makes the
    // cause — an unconfigured deployment — harder to see.
    config()->set('wa.bridge.token', null);
    Http::fake(['bridge.test:3111/*' => Http::response([])]);

    httpBridge()->startSession(bridgeSessionId());

    Http::assertSent(fn (Request $r): bool => ! $r->hasHeader('Authorization'));
});

it('degrades a deleted or nonsensical timeout to a working one rather than to none', function (): void {
    config()->set('wa.bridge.timeout', 'soon');
    config()->set('wa.bridge.connect_timeout', 0);
    config()->set('wa.bridge.url', null);

    Http::fake(['127.0.0.1:3000/*' => Http::response([])]);

    httpBridge()->startSession($id = bridgeSessionId());

    Http::assertSent(fn (Request $r): bool => $r->url() === "http://127.0.0.1:3000/v1/sessions/{$id}/start");
});

it('percent-encodes the session id it puts in a path', function (): void {
    Http::fake(['bridge.test:3111/*' => Http::response([])]);

    // The scoped decorator refuses anything but a ULID before the lookup, but this class is
    // also constructible directly, and a path built by concatenation is how `../` reaches a
    // route it should not.
    (new HttpBridgeClient(app(Illuminate\Http\Client\Factory::class)))->startSession('../health');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '%2F') || str_contains($r->url(), '..%2F'));
});
