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
use App\Models\CloudApiTemplate;
use App\Models\IdempotencyKey;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\SentMessageDto;
use App\Services\Channel\BaileysChannelDriver;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\ChannelHealth;
use App\Services\Channel\ChannelRouter;
use App\Services\Channel\CloudApiChannelDriver;
use App\Services\Channel\CloudApiErrorClassifier;
use App\Services\Channel\CloudApiMessage;
use App\Services\Channel\InboundEvent;
use App\Services\Channel\ModeGuardedChannelDriver;
use App\Services\Channel\OnPremiseAccessToken;
use App\Services\Channel\OnPremiseChannelDriver;
use App\Services\Channel\OnPremiseDeprecation;
use App\Services\Channel\OnPremiseErrorClassifier;
use App\Services\Channel\OnPremiseMessage;
use App\Services\Channel\OnPremiseTokenCache;
use App\Services\Channel\RegistrationResult;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use App\Services\Channel\TextContent;
use App\Services\Reliability\ErrorClassifier;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| OnPremiseChannelDriver (Req 8.9 / A8, Property 24)
|--------------------------------------------------------------------------
| The legacy self-hosted client. This file pins what is genuinely new with it rather than
| re-asserting the contract (`ChannelDriverContractTest`) or the matrix (`ChannelCapabilityTest`):
|
|   1. **the policy/shape split** — `requiresAntiBan()` is `true` here (Baileys' policy) while the
|      wire is an HTTP provider API (Cloud API's shape), and no configuration can flip the first;
|   2. **the bearer token with an expiry** — one login per unit of work, a token inside its skew
|      window replaced before it is spent, and a token refused mid-flight discarded and the call
|      replayed exactly once so an expiring token is not a run of unretryable AUTH failures;
|   3. **deprecation as a deliverable** — the notice on every health and registration detail, one
|      operator warning per unit of work, and an ordered migration path with its hazards;
|   4. **the cross-tenant webhook refusal**, on a protocol that names no business number: the
|      per-credential secret is the tenant binding, and a recipient claim is refused when present
|      and wrong (Property 23);
|   5. **the client's `{"errors": [...]}` envelope → `ErrorClass`**, through the existing chain, and
|      the mode check that keeps Meta's numbering off it;
|   6. **`checkNumbers()` is real here** — the one thing this backend does that Cloud API cannot.
|
| `Http::fake()` stands in for the tenant's container; the real credential store (with real
| envelope encryption), idempotency store, circuit breaker and tenant scoping are all live.
|
| The driver is mostly constructed directly (`app(OnPremiseChannelDriver::class)`) because these
| assertions are about the driver rather than about routing; the registry entry and the `scoped()`
| token-cache binding `ChannelServiceProvider` supplies have two tests of their own below.
*/

const ON_PREM_BASE = 'https://on-prem.test:9090';
const ON_PREM_USER = 'wa-platform';
const ON_PREM_PASSWORD = 'D0nt-Log-Th1s-Password';
const ON_PREM_WEBHOOK_SECRET = 'a3f1c9d5e7b2486fa0c1d3e5f7091b2d';
const ON_PREM_PHONE = '919812340000';
const ON_PREM_TOKEN = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.ONPREMTOKEN.first';
const ON_PREM_TOKEN_2 = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.ONPREMTOKEN.second';

beforeEach(function (): void {
    // `OnPremiseTokenCache` is bound `scoped()` by `ChannelServiceProvider`, which is what bounds
    // a bearer token's life to one request or job and what makes `app(OnPremiseChannelDriver::class)`
    // resolved twice in one test share one cache — the assumption every "one login per unit of
    // work" assertion below rests on. Asserted directly further down rather than arranged here,
    // so these tests fail if that binding is ever removed instead of quietly re-creating it.
    config()->set('wa.channel.on_premise.timeout', 20);
    config()->set('wa.channel.on_premise.connect_timeout', 5);
    config()->set('wa.channel.on_premise.allow_private_hosts', false);
});

/**
 * A tenant bound as the acting one, with `ON_PREMISE` credentials stored and a session on that mode.
 *
 * @param  array<string, mixed>  $secrets  merged over the complete §2.7 set
 * @param  array<string, mixed>  $config  merged over the complete §2.7 set
 * @return array{0: Tenant, 1: Session}
 */
function onPremTenant(array $secrets = [], array $config = []): array
{
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    app(ChannelCredentialStore::class)->put(
        $tenant,
        ChannelMode::OnPremise,
        secrets: array_merge([
            'username' => ON_PREM_USER,
            'password' => ON_PREM_PASSWORD,
            'webhook_secret' => ON_PREM_WEBHOOK_SECRET,
        ], $secrets),
        config: array_merge([
            'base_url' => ON_PREM_BASE,
            'phone_number' => ON_PREM_PHONE,
        ], $config),
    );

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::OnPremise,
        'status' => SessionStatus::Connected,
    ]);

    return [$tenant, $session];
}

/**
 * How many faked requests went to a route containing `$path`.
 *
 * A helper rather than `expect(Http::recorded(...))->toHaveCount()` because that collection's value
 * template is invariant, so passing it to `expect()` is a static-analysis error at level 6 — and an
 * int is what these assertions are actually about.
 */
function onPremRequestCount(string $path): int
{
    $recorded = Http::recorded(fn ($request): bool => str_contains($request->url(), $path));

    return $recorded === null ? 0 : $recorded->count();
}

function onPremDriver(): OnPremiseChannelDriver
{
    return app(OnPremiseChannelDriver::class);
}

function onPremCredentialsFor(Tenant $tenant): ChannelCredentials
{
    $credentials = app(ChannelCredentialStore::class)->for($tenant, ChannelMode::OnPremise);

    expect($credentials)->not->toBeNull();
    assert($credentials instanceof ChannelCredentials);

    return $credentials;
}

/**
 * The client's `POST /v1/users/login` answer.
 *
 * @return array<string, mixed>
 */
function onPremLoginResponse(string $token = ON_PREM_TOKEN, ?string $expiresAfter = null): array
{
    return ['users' => [[
        'token' => $token,
        'expires_after' => $expiresAfter ?? now()->addDays(7)->format('Y-m-d H:i:sP'),
    ]]];
}

/**
 * The client's successful `POST /v1/messages` answer — a message id, and nothing about the contact.
 *
 * @return array<string, mixed>
 */
function onPremMessageResponse(string $messageId = 'gBEGkYiEB1VXAglK1ZEqA1YKPrU'): array
{
    return ['messages' => [['id' => $messageId]]];
}

/**
 * The client's error envelope — a **list**, unlike Meta's single object.
 *
 * @return array<string, mixed>
 */
function onPremError(int $code, string $title = 'Access denied'): array
{
    return ['errors' => [[
        'code' => $code,
        'title' => $title,
        // The field that quotes the request, and which the driver must never carry.
        'details' => 'Rejected request with Authorization: Basic '.base64_encode(ON_PREM_USER.':'.ON_PREM_PASSWORD),
    ]]];
}

/**
 * The client's `GET /v1/health` answer.
 *
 * @return array<string, mixed>
 */
function onPremHealthResponse(string $gatewayStatus = 'connected'): array
{
    return ['health' => [
        'gateway_status' => $gatewayStatus,
        'role' => 'primary_master',
    ]];
}

/**
 * An On-Premise callback POST, signed the way the platform requires a proxy to sign one.
 *
 * @param  array<string, mixed>  $payload
 */
function onPremWebhook(
    array $payload,
    string $secret = ON_PREM_WEBHOOK_SECRET,
    ?string $signature = null,
    bool $sign = true,
    string $routeKey = 'route-key-a',
): Request {
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $headers['HTTP_X_WA_SIGNATURE_256'] = $signature;
    } elseif ($sign) {
        $headers['HTTP_X_WA_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return Request::create('/webhooks/on-premise/'.$routeKey, 'POST', [], [], [], $headers, $body);
}

/**
 * One inbound text message, the shape the client actually posts.
 *
 * @return array<string, mixed>
 */
function onPremInboundMessage(string $id = 'ABGGFlA5FpafAgo6tHcNmNjXmuSf', string $from = '919899990000'): array
{
    return [
        'from' => $from,
        'id' => $id,
        'timestamp' => '1518694235',
        'type' => 'text',
        'text' => ['body' => 'Where is my order?'],
    ];
}

/*
|--------------------------------------------------------------------------
| What this backend is: Baileys' policy, Cloud API's shape
|--------------------------------------------------------------------------
*/

it('is a deprecated official mode that the anti-ban gate still applies to', function (): void {
    $driver = onPremDriver();

    expect($driver->mode())->toBe(ChannelMode::OnPremise)
        ->and($driver->mode()->isOfficial())->toBeTrue()
        // Property 24 / Req 8.8: ON_PREMISE rides the WhatsApp *web* protocol underneath, so the
        // warm-up ramp applies exactly as it does to Baileys — the one place the two axes of the
        // matrix come apart, and the answer no configuration may change.
        ->and($driver->requiresAntiBan())->toBeTrue()
        ->and($driver->requiresAntiBan())->toBe(app(BaileysChannelDriver::class)->requiresAntiBan())
        ->and($driver->requiresAntiBan())->not->toBe(app(CloudApiChannelDriver::class)->requiresAntiBan())
        // Req 8.9: deprecated, with a target.
        ->and($driver->mode()->isDeprecated())->toBeTrue()
        ->and($driver->mode()->migrationTarget())->toBe(ChannelMode::CloudApi)
        ->and($driver->mode()->requiresTenantCredentials())->toBeTrue()
        ->and($driver->mode()->usesProvider())->toBeFalse();

    // Every cell, from the matrix rather than a hand-written list.
    foreach (ChannelCapability::cases() as $capability) {
        expect($driver->supportFor($capability))->toBe($capability->supportOn(ChannelMode::OnPremise))
            ->and($driver->supports($capability))->toBe($capability->supportedOn(ChannelMode::OnPremise));
    }

    // The five Baileys-only rows are `❌` here even though the anti-ban gate applies: this mode is
    // an official Business API client, not a WhatsApp Web session.
    foreach ([
        ChannelCapability::Groups,
        ChannelCapability::Welcome,
        ChannelCapability::Extraction,
        ChannelCapability::Tagging,
        ChannelCapability::Channels,
    ] as $refused) {
        expect($driver->supports($refused))->toBeFalse($refused->value);
    }

    // ...and the official-mode rows are native.
    expect($driver->supports(ChannelCapability::Template))->toBeTrue()
        ->and($driver->supports(ChannelCapability::Interactive))->toBeTrue()
        ->and($driver->supports(ChannelCapability::DeliveryReceipts))->toBeTrue()
        // Both axes are reported independently — the design ambiguity `enforcesSessionWindow()`
        // resolves conservatively.
        ->and($driver->mode()->enforcesSessionWindow())->toBeTrue();
});

it('is registered for ON_PREMISE in the container and reachable through the router, wrapped', function (): void {
    [, $session] = onPremTenant();

    $driver = app(ChannelRouter::class)->driverFor($session);

    // The registry entry of task 7.3 — a mode with no entry raises instead, so this asserts the
    // wiring and not just the class.
    expect($driver)->toBeInstanceOf(ModeGuardedChannelDriver::class)
        ->and($driver->mode())->toBe(ChannelMode::OnPremise);

    assert($driver instanceof ModeGuardedChannelDriver);
    expect($driver->inner())->toBeInstanceOf(OnPremiseChannelDriver::class)
        // And the wrapper is not decoration here: this is the one *official* mode whose
        // anti-ban answer is `true`, so a driver handed out unwrapped would be a send on a
        // deprecated backend with no ramp (Req 8.8, Property 24).
        ->and($driver->requiresAntiBan())->toBeTrue();
});

it('binds the token cache scoped, so a bearer token cannot outlive the job that minted it', function (): void {
    // The binding is Req 8.5's mechanism rather than a convenience: the cache holds a token
    // minted from a decrypted password, so the holder must not span two units of work.
    $first = app(OnPremiseTokenCache::class);

    expect(app(OnPremiseTokenCache::class))->toBe($first)
        // One instance per unit of work is also what makes "one login per campaign, not one per
        // send" true of the driver, which resolves the cache by constructor injection.
        ->and(app(OnPremiseChannelDriver::class))->toBeInstanceOf(OnPremiseChannelDriver::class);

    // What the queue worker does between jobs, and the framework at the end of a request.
    app()->forgetScopedInstances();

    expect(app(OnPremiseTokenCache::class))->not->toBe($first);
});

it('refuses a capability this mode does not have, before any driver call', function (): void {
    [, $session] = onPremTenant();
    Http::fake();

    foreach (ChannelCapability::unsupportedBy(ChannelMode::OnPremise) as $capability) {
        try {
            app(ChannelRouter::class)->assertSupported($session, $capability);
            $thrown = null;
        } catch (ModeCapabilityException $refused) {
            $thrown = $refused;
        }

        expect($thrown)->toBeInstanceOf(ModeCapabilityException::class, $capability->value)
            ->and($thrown?->mode)->toBe(ChannelMode::OnPremise)
            ->and($thrown?->capability)->toBe($capability)
            // The remedy a panel shows: these exist on the Baileys bridge.
            ->and($thrown?->isAvailableElsewhere())->toBeTrue();
    }

    Http::assertNothingSent();
});

it('shares the idempotency scope and batch conventions of 7.1 and 7.2 rather than minting new ones', function (): void {
    // Guarding deliberate duplications: task 8.3 reads the batch keys without branching on the mode,
    // and the dedup scope is one namespace for every driver. These are what fail the day one moves.
    expect(OnPremiseChannelDriver::SEND_SCOPE_PREFIX)->toBe(BaileysChannelDriver::SEND_SCOPE_PREFIX)
        ->and(OnPremiseChannelDriver::SEND_SCOPE_PREFIX)->toBe(CloudApiChannelDriver::SEND_SCOPE_PREFIX)
        ->and(OnPremiseChannelDriver::BATCH_SIZE_KEY)->toBe(CloudApiChannelDriver::BATCH_SIZE_KEY)
        ->and(OnPremiseChannelDriver::BATCH_INDEX_KEY)->toBe(CloudApiChannelDriver::BATCH_INDEX_KEY)
        ->and(OnPremiseChannelDriver::TEMPLATE_RECIPIENT_VAR)->toBe(CloudApiChannelDriver::TEMPLATE_RECIPIENT_VAR)
        ->and(OnPremiseChannelDriver::TEMPLATE_KEY_VAR)->toBe(CloudApiChannelDriver::TEMPLATE_KEY_VAR);
});

it('reduces a recipient to the same digits Cloud API does, and refuses the same values', function (): void {
    // The rule is restated in `OnPremiseMessage` so its refusals do not send an On-Premise tenant to
    // a Meta console. This is what keeps the two implementations from drifting apart.
    foreach (['919812345678', '+91 98123 45678', '919812345678@s.whatsapp.net'] as $input) {
        expect(OnPremiseMessage::recipientDigits($input))
            ->toBe(CloudApiMessage::recipientDigits($input), $input);
    }

    foreach (['12345', '', 'not-a-number', '1203630000000000@g.us'] as $refused) {
        expect(fn (): string => OnPremiseMessage::recipientDigits($refused))
            ->toThrow(InvalidArgumentException::class);
        expect(fn (): string => CloudApiMessage::recipientDigits($refused))
            ->toThrow(InvalidArgumentException::class);
    }

    // ...and the group refusal says On-Premise rather than Cloud API, which is the point.
    try {
        OnPremiseMessage::recipientDigits('1203630000000000@g.us');
        $message = '';
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('On-Premise')->and($message)->not->toContain('Meta\'s Business Platform');
});

/*
|--------------------------------------------------------------------------
| send() — and the login that precedes it
|--------------------------------------------------------------------------
*/

it('logs in under Basic auth and sends text with the bearer the client issued', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse('gBEG.sent')),
    ]);

    $receipt = onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Your order shipped.', 'order-4417'),
    );

    expect($receipt)->toBeInstanceOf(SendReceipt::class)
        ->and($receipt->mode)->toBe(ChannelMode::OnPremise)
        ->and($receipt->providerMessageId)->toBe('gBEG.sent')
        // The client echoes nothing about the contact, so the recorded recipient is the digits the
        // body addressed — never an invented `wa_id`.
        ->and($receipt->recipient)->toBe('919899990000')
        ->and($receipt->idempotencyKey)->toBe('order-4417')
        ->and($receipt->capability)->toBe(ChannelCapability::SendSingle)
        // `SEND_SINGLE` is `✅` on this mode, so nothing was flattened.
        ->and($receipt->degraded)->toBeFalse()
        ->and($receipt->templateName)->toBeNull()
        ->and($receipt->provider)->toBeNull();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/users/login')) {
            return false;
        }

        // Basic auth, as the login route requires — and the *only* request that carries the password.
        return $request->hasHeader('Authorization', 'Basic '.base64_encode(ON_PREM_USER.':'.ON_PREM_PASSWORD));
    });

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/messages')) {
            return false;
        }

        return $request->hasHeader('Authorization', 'Bearer '.ON_PREM_TOKEN)
            // No `messaging_product`: that field is Cloud API's, and the legacy client refuses it.
            && ! array_key_exists('messaging_product', $request->data())
            && $request->data()['recipient_type'] === 'individual'
            && $request->data()['to'] === '919899990000'
            && $request->data()['type'] === 'text'
            && $request->data()['text'] === ['preview_url' => false, 'body' => 'Your order shipped.'];
    });

    Http::assertSentCount(2);
});

it('logs in once per unit of work rather than once per send', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse()),
    ]);

    $driver = onPremDriver();

    foreach (['a', 'b', 'c'] as $key) {
        $driver->send($session, TextContent::to('919899990000', 'Hello '.$key, $key));
    }

    // Three sends, one login. The scoped token cache is what makes a 9,000-recipient campaign
    // perform one login instead of 9,000 — and what bounds that token to this unit of work.
    expect(app(OnPremiseTokenCache::class)->size())->toBe(1);

    Http::assertSentCount(4);
});

it('re-obtains a token that is inside its expiry skew rather than spending it', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::sequence()
            // A token with barely any life left. It is *technically* valid, and starting a send with
            // it is how a message is lost: the refusal would be AUTH, which is zero attempts.
            ->push(onPremLoginResponse(ON_PREM_TOKEN, now()->addSeconds(OnPremiseTokenCache::EXPIRY_SKEW_SECONDS - 5)->format('Y-m-d H:i:sP')))
            ->push(onPremLoginResponse(ON_PREM_TOKEN_2, now()->addDay()->format('Y-m-d H:i:sP'))),
        '*/v1/messages' => Http::response(onPremMessageResponse()),
    ]);

    $driver = onPremDriver();
    $driver->send($session, TextContent::to('919899990000', 'First', 'first'));
    $driver->send($session, TextContent::to('919899990000', 'Second', 'second'));

    // Two logins for two sends, because the first token was never usable for the second.
    expect(onPremRequestCount('/v1/users/login'))->toBe(2);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/messages')
        && $request->hasHeader('Authorization', 'Bearer '.ON_PREM_TOKEN_2));
});

it('treats an unreadable or past expiry as nearly over rather than as forever', function (): void {
    $token = OnPremiseTokenCache::mint(ON_PREM_TOKEN, 'not a date at all');

    expect($token->secondsUntilExpiry())
        ->toBeLessThanOrEqual(OnPremiseTokenCache::FALLBACK_TTL_SECONDS)
        ->and($token->secondsUntilExpiry())->toBeGreaterThan(0);

    // A container whose clock is behind this one would otherwise mint a token that can never be
    // used, which is an infinite login loop rather than a failure.
    expect(OnPremiseTokenCache::mint(ON_PREM_TOKEN, now()->subHour()->format('Y-m-d H:i:sP'))->secondsUntilExpiry())
        ->toBeLessThanOrEqual(OnPremiseTokenCache::FALLBACK_TTL_SECONDS);

    // ...and one the client actually stamped is read from the answer, not invented.
    expect(OnPremiseTokenCache::mint(ON_PREM_TOKEN, now()->addDays(7)->format('Y-m-d H:i:sP'))->secondsUntilExpiry())
        ->toBeGreaterThan(OnPremiseTokenCache::FALLBACK_TTL_SECONDS);
});

it('discards a token the client refused and replays the call exactly once with a fresh one', function (): void {
    [, $session] = onPremTenant();

    Sleep::fake();

    Http::fake([
        '*/v1/users/login' => Http::sequence()
            ->push(onPremLoginResponse(ON_PREM_TOKEN))
            ->push(onPremLoginResponse(ON_PREM_TOKEN_2)),
        // The token lapsed between the login and the send — the mid-campaign case Req 8.5's
        // "re-obtained rather than a run of auth failures" is about.
        '*/v1/messages' => Http::sequence()
            ->push(onPremError(1005), 401)
            ->push(onPremMessageResponse('gBEG.after-reauth')),
    ]);

    $receipt = onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Your order shipped.', 'mid-campaign'),
    );

    // The send succeeded rather than failing AUTH — which is zero attempts by construction, so it
    // would have been a lost message and not a deferred one.
    expect($receipt->providerMessageId)->toBe('gBEG.after-reauth');

    // Two logins, two message attempts. The replay used the token minted *after* the refusal.
    expect(onPremRequestCount('/v1/users/login'))->toBe(2);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/messages')
        && $request->hasHeader('Authorization', 'Bearer '.ON_PREM_TOKEN_2));
});

it('stops after one replay rather than looping against a client that keeps saying no', function (): void {
    [, $session] = onPremTenant();

    Sleep::fake();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremError(1005), 401),
    ]);

    expect(fn (): SendReceipt => onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Hello', 'always-denied'),
    ))->toThrow(ChannelRequestFailedException::class);

    // A brand-new token being refused *is* an auth failure, so the exception is the honest answer.
    // `AUTH` is zero attempts, so the guard did not retry inside either: exactly two message
    // attempts, and two logins (the second for the single replay).
    expect(onPremRequestCount('/v1/messages'))->toBe(2);
    expect(onPremRequestCount('/v1/users/login'))->toBe(2);
});

it('performs no login and no replay for a deployment that stored its own long-lived token', function (): void {
    [, $session] = onPremTenant(['access_token' => 'out-of-band-token-9f2a', 'username' => null, 'password' => null]);

    Sleep::fake();

    Http::fake(['*/v1/messages' => Http::response(onPremError(1005), 401)]);

    expect(fn (): SendReceipt => onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Hello', 'stored-token'),
    ))->toThrow(ChannelRequestFailedException::class);

    // §2.7's *"client API user/password/token"* escape hatch: there is nothing to refresh, so the
    // refusal is the tenant's to fix and the platform does not double every failed request pretending
    // otherwise. One attempt, no login route touched at all.
    expect(onPremRequestCount('/v1/users/login'))->toBe(0);
    expect(onPremRequestCount('/v1/messages'))->toBe(1);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer out-of-band-token-9f2a'));
});

it('refuses to serialise a bearer token or the cache that holds it', function (): void {
    // A queue payload is stored in the clear, so a job closing over either would write a live bearer
    // token into `jobs` — and into `failed_jobs` indefinitely if it never succeeds.
    expect(fn (): string => serialize(new OnPremiseAccessToken(ON_PREM_TOKEN, now()->addDay()->toImmutable())))
        ->toThrow(LogicException::class)
        ->and(fn (): string => serialize(new OnPremiseTokenCache))
        ->toThrow(LogicException::class);

    $token = new OnPremiseAccessToken(ON_PREM_TOKEN, now()->addDay()->toImmutable());

    expect($token->__debugInfo()['value'])->toBe('[redacted]')
        ->and(print_r($token, true))->not->toContain(ON_PREM_TOKEN);
});

it('mints a fresh token when the password is rotated rather than carrying the old one over', function (): void {
    [$tenant] = onPremTenant();

    $before = onPremCredentialsFor($tenant);

    app(ChannelCredentialStore::class)->put($tenant, ChannelMode::OnPremise, ['password' => 'a-new-password-1234']);
    app(ChannelCredentialStore::class)->forget($tenant, ChannelMode::OnPremise);

    $after = onPremCredentialsFor($tenant);

    // Req 8.6's *"apply new sends using the new credentials"*, holding for the token layer too: the
    // cache key is derived from the password, so a rotation cannot be answered from a token minted
    // with the value it replaced.
    expect(OnPremiseTokenCache::keyFor($after, 'username', 'password'))
        ->not->toBe(OnPremiseTokenCache::keyFor($before, 'username', 'password'))
        // ...and the key reveals nothing: it is a single digest.
        ->and(OnPremiseTokenCache::keyFor($after, 'username', 'password'))->not->toContain('a-new-password-1234');
});

it('sends once per idempotency key and replays the original receipt', function (): void {
    [$tenant, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse('gBEG.once')),
    ]);

    $content = TextContent::to('919899990000', 'Your order shipped.', 'order-4417');
    $driver = onPremDriver();

    $first = $driver->send($session, $content);
    $second = $driver->send($session, $content);

    expect($second->providerMessageId)->toBe($first->providerMessageId)
        ->and($second->recipient)->toBe($first->recipient)
        ->and($second->capability)->toBe($first->capability)
        ->and($second->degraded)->toBe($first->degraded);

    // One login and one message: the ledger answered the second send without touching the wire.
    Http::assertSentCount(2);

    // 7.1's scope: the tenant is in the namespace because `idempotency_keys` is not tenant-scoped and
    // a caller-chosen key could collide across tenants.
    expect(IdempotencyKey::query()->where('scope', 'channel.send:'.$tenant->id)->count())->toBe(1);
});

it('refuses to reuse one key for a different send rather than answering with the first receipt', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse('gBEG.first')),
    ]);

    $driver = onPremDriver();
    $driver->send($session, TextContent::to('919899990000', 'First', 'order-4417'));

    expect(fn (): SendReceipt => $driver->send(
        $session,
        TextContent::to('919899991111', 'Second', 'order-4417'),
    ))->toThrow(IdempotencyKeyReuseException::class);

    Http::assertSentCount(2);
});

it('does not record a key when the client refuses, so a retry may send', function (): void {
    [, $session] = onPremTenant();

    Sleep::fake();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        // Two 5xx — the inline guard spends its own attempt budget (`wa.channel.guard`) before a
        // retryable failure reaches the driver — then a success.
        '*/v1/messages' => Http::sequence()
            ->push(onPremError(1014, 'Internal error'), 500)
            ->push(onPremError(1014, 'Internal error'), 500)
            ->push(onPremMessageResponse('gBEG.retried')),
    ]);

    $content = TextContent::to('919899990000', 'Your order shipped.', 'order-4417');
    $driver = onPremDriver();

    expect(fn (): SendReceipt => $driver->send($session, $content))
        ->toThrow(ChannelRequestFailedException::class);

    // The failure is retryable, so the key must not have burned the work.
    expect($driver->send($session, $content)->providerMessageId)->toBe('gBEG.retried');
});

it('refuses a send the client acknowledged with no message id', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(['messages' => []]),
    ]);

    // An unreconcilable "sent" is worse than a retry: no later delivery receipt could ever be
    // matched to it.
    expect(fn (): SendReceipt => onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Your order shipped.', 'no-id'),
    ))->toThrow(BridgeUnreachableException::class);
});

it('refuses a login the client answered 2xx without a token, as a transport failure', function (): void {
    [, $session] = onPremTenant();

    Sleep::fake();

    Http::fake(['*/v1/users/login' => Http::response(['users' => [[]]])]);

    // Kept, not dropped: a login that authenticated nothing leaves the send's outcome unknown, and
    // `BridgeUnreachableException` is classified `BRIDGE` so the work survives.
    expect(fn (): SendReceipt => onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Hello', 'no-token'),
    ))->toThrow(BridgeUnreachableException::class);
});

it('refuses to send for a tenant with no On-Premise credentials, and never reroutes to Baileys', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::OnPremise,
        'status' => SessionStatus::Connected,
    ]);

    Http::fake();

    expect(fn (): SendReceipt => onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Hello', 'no-creds'),
    ))->toThrow(ChannelCredentialException::class);

    Http::assertNothingSent();
});

it('refuses another tenant\'s session, because credentials are resolved for the session\'s owner', function (): void {
    [, $session] = onPremTenant();

    $intruder = Tenant::factory()->create();
    app(TenantContext::class)->set($intruder);

    Http::fake();

    // `ResolvesOwnedSession` resolves the id against the acting tenant *before* a credential is
    // decrypted, so a foreign session cannot be sent from with the caller's own container
    // credentials (Property 23). A `send()` reaches the store instead, which refuses the same way.
    expect(fn (): SentMessageDto => onPremDriver()->sendText($session->id, '919899990000', 'Hello'))
        ->toThrow(CrossTenantAccessException::class)
        ->and(fn (): SendReceipt => onPremDriver()->send(
            $session,
            TextContent::to('919899990000', 'Hello', 'cross-tenant'),
        ))->toThrow(CrossTenantAccessException::class);

    // ...and a plain typo is a 404, which is what keeps a real isolation breach visible in the logs.
    expect(fn (): SentMessageDto => onPremDriver()->sendText('not-a-ulid', '919899990000', 'Hello'))
        ->toThrow(UnknownSessionException::class);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The client's error envelope → ErrorClass, through the existing chain
|--------------------------------------------------------------------------
*/

it('carries the client\'s error envelope into the typed refusal without quoting its prose', function (): void {
    [, $session] = onPremTenant(['access_token' => 'out-of-band-token-9f2a']);

    Sleep::fake();

    Http::fake(['*/v1/messages' => Http::response(onPremError(1013, 'User is not valid'), 404)]);

    try {
        onPremDriver()->send($session, TextContent::to('919899990000', 'Hello', 'bad-number'));
        $thrown = null;
    } catch (ChannelRequestFailedException $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(ChannelRequestFailedException::class)
        ->and($thrown?->mode)->toBe(ChannelMode::OnPremise)
        ->and($thrown?->status)->toBe(404)
        // The envelope is a **list** here, unlike Meta's single `error` object.
        ->and($thrown?->errorCode)->toBe('1013')
        ->and($thrown?->hasErrorCode(1013))->toBeTrue()
        // `details` echoes the credentials it was sent, so neither it nor the client's `title` is
        // carried — only the machine-readable code, which is what the classifier keys on.
        ->and($thrown?->getMessage())->not->toContain(ON_PREM_PASSWORD)
        ->and($thrown?->getMessage())->not->toContain(base64_encode(ON_PREM_USER.':'.ON_PREM_PASSWORD))
        ->and($thrown?->getMessage())->not->toContain('Rejected request with')
        ->and($thrown?->getMessage())->not->toContain('User is not valid');
});

/**
 * The platform classifier chain **with this task's classifier registered**.
 *
 * The registration itself is one line in `wa.reliability.retry.classifiers`, applied by the
 * consolidation pass rather than by this task — so it is applied here instead, which both makes
 * these assertions about the real chain and documents the exact wiring that line has to be.
 */
function onPremClassifierChain(): ErrorClassifier
{
    /** @var list<class-string> $registered */
    $registered = config('wa.reliability.retry.classifiers', []);

    config()->set('wa.reliability.retry.classifiers', [...$registered, OnPremiseErrorClassifier::class]);
    app()->forgetInstance(App\Services\Reliability\CompositeErrorClassifier::class);

    return app(ErrorClassifier::class);
}

it('classifies the client\'s rate limits as retryable-with-backoff and a denied token as fail-fast', function (): void {
    $classifier = onPremClassifierChain();
    $policy = app(App\Services\Reliability\RetryPolicy::class);

    $refusal = static fn (int $code, int $status): ChannelRequestFailedException => ChannelRequestFailedException::refused(
        mode: ChannelMode::OnPremise,
        operation: 'on_premise.message.text',
        status: $status,
        errorCode: (string) $code,
    );

    foreach ([[471, 400], [1015, 429]] as [$code, $status]) {
        $class = $classifier->classify($refusal($code, $status));

        expect($class)->toBe(ErrorClass::RateLimit, 'code '.$code)
            ->and($class?->isDeferrable())->toBeTrue()
            ->and($policy->decideFor(ErrorClass::RateLimit, 1)->keepsWork())->toBeTrue();
    }

    // A plain `429` with no code the client sent is a rate limit by status alone.
    expect($classifier->classify(ChannelRequestFailedException::refused(
        mode: ChannelMode::OnPremise,
        operation: 'on_premise.message.text',
        status: 429,
    )))->toBe(ErrorClass::RateLimit);

    // ...and *access denied* is never retried, structurally. Which is safe only because the driver
    // has already discarded the cached token and replayed the call once before this is consulted.
    expect($classifier->classify($refusal(1005, 401)))->toBe(ErrorClass::Auth)
        ->and(ErrorClass::Auth->isRetryableByNature())->toBeFalse()
        ->and($policy->decideFor(ErrorClass::Auth, 1)->shouldRetry)->toBeFalse();
});

it('classifies the rest of the client\'s envelope the way the retry matrix expects', function (): void {
    $classifier = onPremClassifierChain();

    $cases = [
        // The account may not do this.
        [1031, 403, ErrorClass::Permission],
        // There is nobody to deliver to, ever — the recipient is not on WhatsApp, or cannot receive
        // this kind of message.
        [1013, 404, ErrorClass::NotOnWhatsApp],
        [1026, 400, ErrorClass::NotOnWhatsApp],
        // Deterministic refusals: the 24-hour window rule, an experiment exclusion, a bad parameter.
        [470, 400, ErrorClass::Validation],
        [472, 400, ErrorClass::Validation],
        [1001, 400, ErrorClass::Validation],
        [1008, 400, ErrorClass::Validation],
        [1009, 400, ErrorClass::Validation],
        // The template block, by range: a new code in a known family must not take the fallback.
        [2000, 400, ErrorClass::Validation],
        [2001, 400, ErrorClass::Validation],
        [2099, 400, ErrorClass::Validation],
        // The client's own transient trouble keeps the work — including its explicit "resend".
        [1000, 500, ErrorClass::Network],
        [1011, 503, ErrorClass::Network],
        [1014, 500, ErrorClass::Network],
        [1016, 500, ErrorClass::Network],
    ];

    foreach ($cases as [$code, $status, $expected]) {
        expect($classifier->classify(ChannelRequestFailedException::refused(
            mode: ChannelMode::OnPremise,
            operation: 'on_premise.message.text',
            status: $status,
            errorCode: (string) $code,
        )))->toBe($expected, 'code '.$code);
    }

    // A code this release does not know falls back to the status, and an unreachable container is the
    // bridge classifier's `BRIDGE` — both keep the work.
    expect($classifier->classify(ChannelRequestFailedException::refused(
        mode: ChannelMode::OnPremise,
        operation: 'x',
        status: 503,
        errorCode: '99999',
    )))->toBe(ErrorClass::Network)
        ->and($classifier->classify(ChannelRequestFailedException::refused(
            mode: ChannelMode::OnPremise,
            operation: 'x',
            status: 408,
        )))->toBe(ErrorClass::Timeout)
        ->and($classifier->classify(BridgeUnreachableException::transportFailed('on_premise.message.text')))
        ->toBe(ErrorClass::Bridge);
});

it('keeps Meta\'s numbering off an On-Premise refusal, and its own off Meta\'s', function (): void {
    $onPremise = new OnPremiseErrorClassifier;
    $cloudApi = new CloudApiErrorClassifier;

    $refusal = static fn (ChannelMode $mode, int $code): ChannelRequestFailedException => ChannelRequestFailedException::refused(
        mode: $mode,
        operation: 'message.text',
        status: 400,
        errorCode: (string) $code,
    );

    // The collision that matters. Meta's `4` is an app-level rate limit — deferred and retried eight
    // times. Read against an On-Premise refusal it would retry something terminal.
    expect($cloudApi->classify($refusal(ChannelMode::CloudApi, 4)))->toBe(ErrorClass::RateLimit)
        ->and($onPremise->classify($refusal(ChannelMode::OnPremise, 4)))->toBe(ErrorClass::Validation);

    // Each declines the other's, so the chain reaches the right one.
    expect($onPremise->classify($refusal(ChannelMode::CloudApi, 190)))->toBeNull()
        ->and($cloudApi->classify($refusal(ChannelMode::OnPremise, 1005)))->toBeNull()
        // ...and neither claims task 7.4's.
        ->and($onPremise->classify($refusal(ChannelMode::BspGateway, 1005)))->toBeNull()
        // Nor anything that is not a channel refusal at all.
        ->and($onPremise->classify(new RuntimeException('unrelated')))->toBeNull();
});

it('recognises an expired token from either the code or a bare 401', function (): void {
    // One definition of "this looks like an expired token", read by the classifier and by the driver
    // that tries to make it not matter.
    $refusal = static fn (ChannelMode $mode, int $status, ?string $code): ChannelRequestFailedException => ChannelRequestFailedException::refused(
        mode: $mode,
        operation: 'on_premise.message.text',
        status: $status,
        errorCode: $code,
    );

    expect(OnPremiseErrorClassifier::looksLikeExpiredToken($refusal(ChannelMode::OnPremise, 401, '1005')))->toBeTrue()
        // A container behind a proxy that stripped the body still answers the status.
        ->and(OnPremiseErrorClassifier::looksLikeExpiredToken($refusal(ChannelMode::OnPremise, 401, null)))->toBeTrue()
        ->and(OnPremiseErrorClassifier::looksLikeExpiredToken($refusal(ChannelMode::OnPremise, 400, '1005')))->toBeTrue()
        // Not every refusal, and not another mode's.
        ->and(OnPremiseErrorClassifier::looksLikeExpiredToken($refusal(ChannelMode::OnPremise, 400, '1009')))->toBeFalse()
        ->and(OnPremiseErrorClassifier::looksLikeExpiredToken($refusal(ChannelMode::CloudApi, 401, null)))->toBeFalse();
});

it('honours a Retry-After the client named, in its numeric form only', function (): void {
    [, $session] = onPremTenant(['access_token' => 'out-of-band-token-9f2a']);

    Sleep::fake();

    Http::fake(['*/v1/messages' => Http::response(onPremError(1015, 'Too many requests'), 429, ['Retry-After' => '90'])]);

    try {
        onPremDriver()->send($session, TextContent::to('919899990000', 'Hello', 'rate-limited'));
        $thrown = null;
    } catch (ChannelRequestFailedException $refused) {
        $thrown = $refused;
    }

    expect($thrown?->retryAfterSeconds)->toBe(90)
        ->and($thrown?->getHeaders())->toBe(['Retry-After' => '90'])
        ->and($thrown?->getStatusCode())->toBe(503);
});

/*
|--------------------------------------------------------------------------
| Deprecation as a deliverable (Req 8.9)
|--------------------------------------------------------------------------
*/

it('documents an ordered ON_PREMISE to CLOUD_API path whose steps state what breaks if taken early', function (): void {
    expect(OnPremiseDeprecation::target())->toBe(ChannelMode::CloudApi)
        ->and(OnPremiseDeprecation::path())->toBe('ON_PREMISE → CLOUD_API')
        ->and(OnPremiseDeprecation::MODE)->toBe(ChannelMode::OnPremise);

    $steps = OnPremiseDeprecation::migrationPath();

    expect($steps)->not->toBeEmpty();

    // Ordered 1..n with no gaps, because the steps are not interchangeable.
    foreach ($steps as $index => $step) {
        expect($step->order)->toBe($index + 1)
            ->and(trim($step->title))->not->toBe('')
            // The field that earns the class: every step says what goes wrong if it runs too early.
            ->and(trim($step->breaksIfEarly))->not->toBe('')
            ->and($step->toArray()['order'])->toBe($step->order);
    }

    // The two irreversible steps are in the middle — deregistering the container, and destroying it
    // — which is exactly why the ordering has to be stated rather than inferred.
    $irreversible = array_values(array_filter($steps, fn ($step): bool => $step->irreversible));

    expect($irreversible)->toHaveCount(2)
        ->and($irreversible[0]->order)->toBeGreaterThan(1)
        ->and($irreversible[0]->order)->toBeLessThan(count($steps));

    // Renderable by the panel: no tenant input, no credential, so unlike ChannelCredentials this is
    // safe to serialise — and being serialisable is the point.
    expect(OnPremiseDeprecation::migrationPathArray())->toHaveCount(count($steps))
        ->and(json_encode(OnPremiseDeprecation::migrationPathArray()))->toBeString();

    // A step cannot be built unrenderable.
    expect(fn (): App\Services\Channel\OnPremiseMigrationStep => new App\Services\Channel\OnPremiseMigrationStep(0, 'a', 'b', 'c'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): App\Services\Channel\OnPremiseMigrationStep => new App\Services\Channel\OnPremiseMigrationStep(1, '', 'b', 'c'))
        ->toThrow(InvalidArgumentException::class);
});

it('puts the deprecation notice on every health and registration detail, untruncated', function (): void {
    [$tenant, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/health' => Http::sequence()
            ->push(onPremHealthResponse('connected'))
            ->push(onPremHealthResponse('connected'))
            ->push(onPremHealthResponse('unregistered'))
            ->push(onPremHealthResponse('unregistered')),
    ]);

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();

    // Every surface that renders a `detail`: healthy, live, and each unhealthy or pending flavour.
    $details = [
        'healthy' => $driver->healthCheck($credentials)->detail,
        'live' => $driver->register($session, $credentials)->detail,
        'unregistered health' => $driver->healthCheck($credentials)->detail,
        'pending' => $driver->register($session, $credentials)->detail,
        'incomplete' => $driver->healthCheck(
            ChannelCredentials::platform($tenant->id, ChannelMode::OnPremise),
        )->detail,
    ];

    expect($details)->toHaveCount(5);

    foreach ($details as $which => $detail) {
        expect(str_starts_with($detail, 'DEPRECATED:'))->toBeTrue($which)
            ->and(str_contains($detail, 'CLOUD_API'))->toBeTrue($which)
            // The notice is short on purpose: both halves have to fit inside
            // ChannelCredentials::MAX_DETAIL_LENGTH, or the part a tenant needs is what is cut.
            ->and(mb_strlen($detail))->toBeLessThanOrEqual(ChannelCredentials::MAX_DETAIL_LENGTH, $which)
            ->and(str_contains($detail, '…'))->toBeFalse($which);
    }

    // ...and the health answer itself survived beside the notice, which is the point of bounding it.
    expect($details['healthy'])->toContain('accepted these credentials')
        ->and($details['incomplete'])->toContain('username')
        ->and($details['incomplete'])->toContain('webhook_secret')
        ->and($details['unregistered health'])->toContain('unregistered')
        ->and($details['pending'])->toContain('not registered');
});

it('warns an operator once per unit of work rather than once per send', function (): void {
    [, $session] = onPremTenant();

    $warnings = [];

    Log::listen(function ($message) use (&$warnings): void {
        if ($message->level === 'warning' && $message->message === OnPremiseDeprecation::CODE) {
            $warnings[] = $message->context;
        }
    });

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse()),
    ]);

    $driver = onPremDriver();

    foreach (range(1, 5) as $n) {
        $driver->send($session, TextContent::to('919899990000', 'Hello '.$n, 'k'.$n));
    }

    // Five sends, one warning: a nine-thousand-recipient campaign must not produce nine thousand of
    // them, or the warning stops meaning anything. The login is the event that happens once per unit
    // of work, which is why the log line lives there.
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['migration'])->toBe('ON_PREMISE → CLOUD_API')
        ->and($warnings[0]['mode'])->toBe('ON_PREMISE')
        ->and($warnings[0]['steps'])->toBe(count(OnPremiseDeprecation::migrationPath()))
        // The tenant is fingerprinted rather than written down (Req 7.3 / A7).
        ->and($warnings[0]['tenant'])->toStartWith('#')
        ->and($warnings[0]['tenant'])->not->toBe($session->tenant_id);
});

/*
|--------------------------------------------------------------------------
| sendTemplate()
|--------------------------------------------------------------------------
*/

it('sends an approved template with positional body parameters and the legacy language policy', function (): void {
    [$tenant, $session] = onPremTenant([], ['template_namespace' => '3fcb1a2e_9d44_4c3f_bb1c_0d5f8e7a1234']);

    $credentials = onPremCredentialsFor($tenant);

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credentials->credentialId,
        'name' => 'order_update',
        'language' => 'en_US',
        'body' => 'Hello {{1}}, your order {{2}} is on its way.',
    ]);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse('gBEG.template')),
    ]);

    $receipt = onPremDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($template),
        ['1' => 'Asha', '2' => '#4417'],
        '919899990000',
        'tpl-4417',
    );

    expect($receipt->templateName)->toBe('order_update')
        ->and($receipt->isTemplated())->toBeTrue()
        ->and($receipt->capability)->toBe(ChannelCapability::Template)
        ->and($receipt->providerMessageId)->toBe('gBEG.template');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/messages')) {
            return false;
        }

        $body = $request->data();

        return $body['type'] === 'template'
            && $body['template']['name'] === 'order_update'
            // The legacy client's own spelling: a policy beside the code, so a Hindi campaign cannot
            // silently go out in English.
            && $body['template']['language'] === ['policy' => 'deterministic', 'code' => 'en_US']
            && $body['template']['namespace'] === '3fcb1a2e_9d44_4c3f_bb1c_0d5f8e7a1234'
            && $body['template']['components'] === [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => 'Asha'],
                    ['type' => 'text', 'text' => '#4417'],
                ],
            ]];
    });
});

it('omits the namespace a tenant has not configured, and the components of a template with none', function (): void {
    [$tenant, $session] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credentials->credentialId,
        'name' => 'thanks',
        'language' => 'en',
        'body' => 'Thank you for your order.',
    ]);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse()),
    ]);

    onPremDriver()->sendTemplateTo($session, TemplateRef::fromModel($template), [], '919899990000', 'tpl-thanks');

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/messages')) {
            return false;
        }

        $template = $request->data()['template'];

        // An empty string namespace is refused by every build, and `"components": []` by some — so
        // neither key is emitted rather than emitted empty.
        return ! array_key_exists('namespace', $template) && ! array_key_exists('components', $template);
    });
});

it('orders body parameters by placeholder position, not by where they appear in the copy', function (): void {
    [$tenant, $session] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credentials->credentialId,
        'name' => 'reversed',
        'language' => 'hi',
        // A translation legitimately mentions {{2}} first; the parameters are still positional.
        'body' => 'Order {{2}} for {{1}} is on its way.',
    ]);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse()),
    ]);

    onPremDriver()->sendTemplateTo(
        $session,
        TemplateRef::fromModel($template),
        ['2' => '#4417', '1' => 'Asha'],
        '919899990000',
        'tpl-reversed',
    );

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/v1/messages')
        || $request->data()['template']['components'][0]['parameters'] === [
            ['type' => 'text', 'text' => 'Asha'],
            ['type' => 'text', 'text' => '#4417'],
        ]);
});

it('refuses an unapproved, unknown, or foreign-account template before any request', function (): void {
    [$tenant, $session] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();

    Http::fake();

    // Unknown: nothing was ever submitted, so the instruction is "submit this template".
    try {
        $driver->sendTemplateTo($session, new TemplateRef('never_made', 'en_US'), [], '919899990000', 'k1');
        $thrown = null;
    } catch (ChannelTemplateException $e) {
        $thrown = $e;
    }

    expect($thrown?->mode)->toBe(ChannelMode::OnPremise)
        ->and($thrown?->templateKey)->toBe('never_made:en_US')
        ->and($thrown?->status)->toBeNull();

    // Present and not approved: the instruction is "wait for review", which is a different one.
    $pending = CloudApiTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credentials->credentialId,
        'name' => 'pending_one',
        'language' => 'en_US',
        'status' => App\Enums\ChannelTemplateStatus::Pending,
    ]);

    try {
        $driver->sendTemplateTo($session, TemplateRef::fromModel($pending), [], '919899990000', 'k2');
        $thrown = null;
    } catch (ChannelTemplateException $e) {
        $thrown = $e;
    }

    expect($thrown?->status)->toBe(App\Enums\ChannelTemplateStatus::Pending);

    // Approved under another provider account: approval does not transfer.
    expect(fn (): SendReceipt => $driver->sendTemplateTo(
        $session,
        new TemplateRef('order_update', 'en_US', credentialId: '01HXXXXXXXXXXXXXXXXXXXXXXX'),
        [],
        '919899990000',
        'k3',
    ))->toThrow(ChannelTemplateException::class);

    // Every refusal is local: the container was never asked, so nothing was charged to the number.
    Http::assertNothingSent();
});

it('refuses a template whose variables do not fill its placeholders exactly', function (): void {
    [$tenant, $session] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credentials->credentialId,
        'name' => 'order_update',
        'language' => 'en_US',
        'body' => 'Hello {{1}}, your order {{2}} is on its way.',
    ]);

    Http::fake();

    $ref = TemplateRef::fromModel($template);
    $driver = onPremDriver();

    // Missing, and surplus — refused as firmly, because a surplus key is nearly always a renamed
    // placeholder and the send would otherwise go out with a stale value in the wrong slot.
    foreach ([['1' => 'Asha'], ['1' => 'Asha', '2' => '#4417', '3' => 'extra']] as $vars) {
        expect(fn (): SendReceipt => $driver->sendTemplateTo($session, $ref, $vars, '919899990000', 'k'))
            ->toThrow(ChannelTemplateException::class);
    }

    Http::assertNothingSent();
});

it('reads the recipient and key from the reserved $vars of the contract\'s own signature', function (): void {
    [$tenant, $session] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credentials->credentialId,
        'name' => 'thanks',
        'language' => 'en',
        'body' => 'Thank you, {{1}}.',
    ]);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse('gBEG.reserved')),
    ]);

    $driver = onPremDriver();

    $receipt = $driver->sendTemplate($session, TemplateRef::fromModel($template), [
        '1' => 'Asha',
        OnPremiseChannelDriver::TEMPLATE_RECIPIENT_VAR => '919899990000',
        OnPremiseChannelDriver::TEMPLATE_KEY_VAR => 'tpl-reserved',
    ]);

    expect($receipt->recipient)->toBe('919899990000')
        ->and($receipt->idempotencyKey)->toBe('tpl-reserved');

    // Without them the send has no recipient and no key, and a receipt nothing can be reconciled
    // with is worse than a refusal.
    expect(fn (): SendReceipt => $driver->sendTemplate($session, TemplateRef::fromModel($template), ['1' => 'Asha']))
        ->toThrow(InvalidArgumentException::class);
});

it('dedups a template send separately from a free-form send that shares its key', function (): void {
    [$tenant, $session] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    $template = CloudApiTemplate::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'credential_id' => $credentials->credentialId,
        'name' => 'thanks',
        'language' => 'en',
        'body' => 'Thank you.',
    ]);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse()),
    ]);

    $driver = onPremDriver();
    $driver->send($session, TextContent::to('919899990000', 'Thank you.', 'shared-key'));

    // The fingerprint carries the capability and a template discriminator, so one key cannot answer
    // a template send with the text send's receipt (which would report `templateName` of null).
    expect(fn (): SendReceipt => $driver->sendTemplateTo(
        $session,
        TemplateRef::fromModel($template),
        [],
        '919899990000',
        'shared-key',
    ))->toThrow(IdempotencyKeyReuseException::class);
});

/*
|--------------------------------------------------------------------------
| parseWebhook() — origin, recipient, shape
|--------------------------------------------------------------------------
*/

it('normalises a signed inbound message into the canonical event', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    $event = onPremDriver()->parseWebhook(onPremWebhook([
        'contacts' => [['profile' => ['name' => 'Asha'], 'wa_id' => '919899990000']],
        'messages' => [onPremInboundMessage()],
    ]), $credentials);

    expect($event)->toBeInstanceOf(InboundEvent::class)
        ->and($event->kind)->toBe(InboundEventKind::Message)
        ->and($event->mode)->toBe(ChannelMode::OnPremise)
        // From the credentials the driver was handed, never from the payload.
        ->and($event->tenantId)->toBe($tenant->id)
        ->and($event->channelIdentity)->toBe(ON_PREM_PHONE)
        ->and($event->providerMessageId)->toBe('ABGGFlA5FpafAgo6tHcNmNjXmuSf')
        ->and($event->from)->toBe('919899990000')
        ->and($event->text)->toBe('Where is my order?')
        // The client's own clock, quoted epoch seconds — never `now()`, which would be a fabricated
        // occurrence time indistinguishable from a real one afterwards.
        ->and($event->occurredAt?->getTimestamp())->toBe(1518694235)
        ->and($event->isActionable())->toBeTrue()
        // The verified body is kept for the fields this type does not model.
        ->and($event->payload()['contacts'])->toHaveCount(1);
});

it('reads an interactive reply and a template quick reply as what the customer said', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();

    $button = $driver->parseWebhook(onPremWebhook(['messages' => [[
        'from' => '919899990000',
        'id' => 'ABG.button',
        'timestamp' => '1518694235',
        'type' => 'interactive',
        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'track', 'title' => 'Track my order']],
    ]]]), $credentials);

    $row = $driver->parseWebhook(onPremWebhook(['messages' => [[
        'from' => '919899990000',
        'id' => 'ABG.list',
        'timestamp' => '1518694235',
        'type' => 'interactive',
        'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'sizes', 'title' => 'Sizes']],
    ]]]), $credentials);

    $quick = $driver->parseWebhook(onPremWebhook(['messages' => [[
        'from' => '919899990000',
        'id' => 'ABG.quick',
        'timestamp' => '1518694235',
        'type' => 'button',
        'button' => ['text' => 'Stop', 'payload' => 'STOP'],
    ]]]), $credentials);

    $media = $driver->parseWebhook(onPremWebhook(['messages' => [[
        'from' => '919899990000',
        'id' => 'ABG.media',
        'timestamp' => '1518694235',
        'type' => 'image',
        'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
    ]]]), $credentials);

    expect($button->text)->toBe('Track my order')
        ->and($row->text)->toBe('Sizes')
        ->and($quick->text)->toBe('Stop')
        // No invented caption for a shape that carries no words — and the reply id stays reachable
        // through the payload, which is what a flow correlates on.
        ->and($media->text)->toBeNull()
        ->and($button->payloadValue('message')['interactive']['button_reply']['id'])->toBe('track');
});

it('normalises each receipt kind and carries a redacted failure reason on a failure only', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();

    $kindOf = static fn (InboundEvent $event): InboundEventKind => $event->kind;

    $receipt = static fn (string $status, array $extra = []): array => ['statuses' => [array_merge([
        'id' => 'gBEG.outbound',
        'recipient_id' => '919899990000',
        'status' => $status,
        'timestamp' => '1518694700',
    ], $extra)]];

    // `sent` and `delivered` collapse into one canonical kind, per InboundEventKind's own table —
    // and the raw string is preserved so task 9.5 can apply the never-downgrades rule.
    expect($kindOf($driver->parseWebhook(onPremWebhook($receipt('sent')), $credentials)))
        ->toBe(InboundEventKind::DeliveryReceipt)
        ->and($kindOf($driver->parseWebhook(onPremWebhook($receipt('delivered')), $credentials)))
        ->toBe(InboundEventKind::DeliveryReceipt)
        ->and($kindOf($driver->parseWebhook(onPremWebhook($receipt('read')), $credentials)))
        ->toBe(InboundEventKind::ReadReceipt);

    $sent = $driver->parseWebhook(onPremWebhook($receipt('sent')), $credentials);

    expect($sent->payloadValue('status')['status'])->toBe('sent')
        ->and($sent->providerMessageId)->toBe('gBEG.outbound')
        ->and($sent->occurredAt?->getTimestamp())->toBe(1518694700)
        ->and($sent->failureReason)->toBeNull();

    $failed = $driver->parseWebhook(onPremWebhook($receipt('failed', [
        'errors' => [[
            'code' => 470,
            'title' => 'Message failed to send because more than 24 hours have passed',
            'details' => 'Sent with token '.ON_PREM_WEBHOOK_SECRET,
        ]],
    ])), $credentials);

    expect($failed->kind)->toBe(InboundEventKind::SendFailure)
        ->and($failed->failureReason)->toContain('more than 24 hours')
        ->and($failed->failureReason)->toContain('470')
        // `details` is not read at all, and even the rendered reason goes through
        // ChannelCredentials::redact() on its way into the event.
        ->and($failed->failureReason)->not->toContain(ON_PREM_WEBHOOK_SECRET);

    // A status a newer client build introduces: verified, acknowledged, acted on by nobody — not a
    // 403 on every container upgrade.
    expect($kindOf($driver->parseWebhook(onPremWebhook($receipt('deleted')), $credentials)))
        ->toBe(InboundEventKind::Unsupported);
});

it('returns every event of a batched body, and says how many there were', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    $body = [
        'contacts' => [['wa_id' => '919899990000']],
        'messages' => [onPremInboundMessage('ABG.one'), onPremInboundMessage('ABG.two')],
        'statuses' => [['id' => 'gBEG.one', 'status' => 'delivered', 'timestamp' => '1518694700']],
    ];

    $events = onPremDriver()->parseWebhookBatch(onPremWebhook($body), $credentials);

    expect($events)->toHaveCount(3)
        // Messages first, then statuses, document order within each.
        ->and($events[0]->providerMessageId)->toBe('ABG.one')
        ->and($events[1]->providerMessageId)->toBe('ABG.two')
        ->and($events[2]->kind)->toBe(InboundEventKind::DeliveryReceipt);

    foreach ($events as $index => $event) {
        // The batch shape travels with every event, so task 8.3 dropping all but the first is
        // detectable rather than silent.
        expect($event->payloadValue(OnPremiseChannelDriver::BATCH_SIZE_KEY))->toBe(3)
            ->and($event->payloadValue(OnPremiseChannelDriver::BATCH_INDEX_KEY))->toBe($index);
    }

    // ...and the contract's single-event method answers with the first of them.
    expect(onPremDriver()->parseWebhook(onPremWebhook($body), $credentials)->providerMessageId)->toBe('ABG.one');
});

it('refuses a validly-signed payload replayed onto another tenant\'s route', function (): void {
    // The cross-tenant case on a protocol that names no business number: `webhook_secret` is per
    // credential row, so the signature is the origin proof *and* the tenant binding (Property 23).
    [$a] = onPremTenant();
    $secretA = onPremCredentialsFor($a);

    [$b] = onPremTenant(['webhook_secret' => 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0']);
    $secretB = onPremCredentialsFor($b);

    $body = ['messages' => [onPremInboundMessage('ABG.forged')]];

    // Signed with A's secret and POSTed to B's route key: refused, so it never becomes an
    // InboundEvent carrying B's tenant id and A's content.
    try {
        onPremDriver()->parseWebhook(
            onPremWebhook($body, ON_PREM_WEBHOOK_SECRET, routeKey: 'route-key-b'),
            $secretB,
        );
        $thrown = null;
    } catch (WebhookVerificationException $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(WebhookVerificationException::class)
        ->and($thrown?->getStatusCode())->toBe(403)
        // The presented signature is never quoted: whoever reads logs could otherwise replay it.
        ->and($thrown?->getMessage())->not->toContain(ON_PREM_WEBHOOK_SECRET)
        ->and($thrown?->publicMessage())->toBe(WebhookVerificationException::PUBLIC_MESSAGE);

    // ...and each tenant's own body still verifies under its own secret.
    expect(onPremDriver()->parseWebhook(onPremWebhook($body, ON_PREM_WEBHOOK_SECRET), $secretA)->tenantId)
        ->toBe($a->id)
        ->and(onPremDriver()->parseWebhook(onPremWebhook($body, 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0'), $secretB)->tenantId)
        ->toBe($b->id);
});

it('refuses a payload that names a business number these credentials do not own', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    // The conditional half of the recipient check: the legacy callback names no business number, but
    // when a proxy adds one it is checked against the credentials and never read in place of them.
    try {
        onPremDriver()->parseWebhook(onPremWebhook([
            'metadata' => ['phone_number' => '447700900123'],
            'messages' => [onPremInboundMessage()],
        ]), $credentials);
        $thrown = null;
    } catch (WebhookVerificationException $refused) {
        $thrown = $refused;
    }

    expect($thrown)->toBeInstanceOf(WebhookVerificationException::class)
        ->and($thrown?->getStatusCode())->toBe(403)
        // Both numbers are fingerprinted rather than written down, so two refusals about one number
        // correlate without the number reaching a log line (Req 7.3 / A7).
        ->and($thrown?->getMessage())->not->toContain('447700900123')
        ->and($thrown?->getMessage())->not->toContain(ON_PREM_PHONE)
        ->and($thrown?->getMessage())->toContain('#');

    // A claim spelled differently but naming the same number is *not* a mismatch: comparing raw
    // strings would refuse correct traffic over a plus sign, and a check that fires on correct
    // traffic is a check an operator turns off.
    expect(onPremDriver()->parseWebhook(onPremWebhook([
        'metadata' => ['display_phone_number' => '+91 98123 40000'],
        'messages' => [onPremInboundMessage()],
    ]), $credentials)->channelIdentity)->toBe(ON_PREM_PHONE);

    // ...and no claim at all changes nothing, which is why the check can never be a regression.
    expect(onPremDriver()->parseWebhook(onPremWebhook([
        'messages' => [onPremInboundMessage()],
    ]), $credentials)->channelIdentity)->toBe(ON_PREM_PHONE);
});

it('refuses a payload with no signature, and one whose signature is over a different body', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();
    $body = ['messages' => [onPremInboundMessage()]];

    // Unsigned: fails closed. The legacy client signs nothing itself, so the platform requires that
    // the proxy in front of it does — Req 8.4 admits no unverified inbound event.
    expect(fn (): InboundEvent => $driver->parseWebhook(onPremWebhook($body, sign: false), $credentials))
        ->toThrow(WebhookVerificationException::class);

    // Wrong secret, a signature over a different body, and a garbage header value.
    foreach ([
        onPremWebhook($body, 'ffffffffffffffffffffffffffffffff'),
        onPremWebhook($body, signature: 'sha256='.hash_hmac('sha256', '{"messages":[]}', ON_PREM_WEBHOOK_SECRET)),
        onPremWebhook($body, signature: 'sha256=not-hex-at-all'),
        onPremWebhook($body, signature: 'sha256='),
    ] as $request) {
        expect(fn (): InboundEvent => $driver->parseWebhook($request, $credentials))
            ->toThrow(WebhookVerificationException::class);
    }
});

it('refuses to verify at all when the tenant stored no webhook secret, and says which problem it is', function (): void {
    [$tenant] = onPremTenant(['webhook_secret' => null]);

    $credentials = onPremCredentialsFor($tenant);

    // A credential problem rather than a bad signature: one is a tenant field left blank, the other
    // is a rotated secret or a forgery, and they send an operator to different places.
    expect(fn (): InboundEvent => onPremDriver()->parseWebhook(
        onPremWebhook(['messages' => [onPremInboundMessage()]]),
        $credentials,
    ))->toThrow(ChannelCredentialException::class);
});

it('accepts a verified payload it does not act on, so the client stops retrying', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();

    foreach ([
        // The client reporting its own trouble, with nothing to route.
        ['errors' => [['code' => 1011, 'title' => 'Service not ready']]],
        // A contacts-only body, and a shape a newer build introduces.
        ['contacts' => [['wa_id' => '919899990000']]],
        [],
    ] as $body) {
        $event = $driver->parseWebhook(onPremWebhook($body), $credentials);

        expect($event->kind)->toBe(InboundEventKind::Unsupported)
            ->and($event->isActionable())->toBeFalse()
            ->and($event->tenantId)->toBe($tenant->id)
            ->and($event->channelIdentity)->toBe(ON_PREM_PHONE);
    }
});

it('refuses a verified payload that is the wrong shape, rather than half-populating an event', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();

    foreach ([
        // A message with no id: nothing could ever be correlated with it.
        ['messages' => [['from' => '919899990000', 'timestamp' => '1518694235']]],
        // A message that names no sender.
        ['messages' => [['id' => 'ABG.x', 'timestamp' => '1518694235']]],
        // A status that says nothing about which message it is about.
        ['statuses' => [['status' => 'delivered', 'timestamp' => '1518694700']]],
    ] as $body) {
        expect(fn (): InboundEvent => $driver->parseWebhook(onPremWebhook($body), $credentials))
            ->toThrow(WebhookVerificationException::class);
    }

    // ...and a body that is not a JSON object at all, signed correctly.
    $notJson = Request::create('/webhooks/on-premise/route-key-a', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WA_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', 'not json', ON_PREM_WEBHOOK_SECRET),
    ], 'not json');

    expect(fn (): InboundEvent => $driver->parseWebhook($notJson, $credentials))
        ->toThrow(WebhookVerificationException::class);
});

/*
|--------------------------------------------------------------------------
| register(), pointWebhookAt(), healthCheck()
|--------------------------------------------------------------------------
*/

it('reports the container\'s gateway state rather than throwing, and registers nothing', function (): void {
    [$tenant, $session] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/health' => Http::sequence()
            ->push(onPremHealthResponse('connected'))
            ->push(onPremHealthResponse('unregistered'))
            ->push(onPremHealthResponse('connecting')),
    ]);

    $driver = onPremDriver();

    $live = $driver->register($session, $credentials);

    expect($live)->toBeInstanceOf(RegistrationResult::class)
        ->and($live->isLive())->toBeTrue()
        ->and($live->mode)->toBe(ChannelMode::OnPremise)
        // No provider-assigned id to echo: the container *is* the registration, so the tenant's own
        // number is the honest answer.
        ->and($live->providerNumberId)->toBe(ON_PREM_PHONE)
        // No callback is claimed — the route key is minted by task 8.3, and a URL nothing answers on
        // a panel is worse than none.
        ->and($live->hasCallback())->toBeFalse()
        ->and($live->routeKey)->toBeNull()
        ->and($live->callbackUrl)->toBeNull();

    // `unregistered` and `connecting` are both *pending*, not failures: task 9.1 keeps the session out
    // of SENDABLE, which is right, because its first send would be refused.
    $unregistered = $driver->register($session, $credentials);
    $connecting = $driver->register($session, $credentials);

    expect($unregistered->isPending())->toBeTrue()
        ->and($unregistered->detail)->toContain('not registered')
        ->and($connecting->isPending())->toBeTrue()
        ->and($connecting->detail)->toContain('connecting');

    // Read-only: the account route was never touched. Re-running the legacy client's registration
    // during a session restart could throw away a working one.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/account'));
});

it('lets a provider refusal of the registration probe propagate as an exception', function (): void {
    [$tenant, $session] = onPremTenant();

    Sleep::fake();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/health' => Http::response(onPremError(1011, 'Service not ready'), 503),
    ]);

    // A registration that *failed at the provider* is an exception, as the contract requires — not a
    // `pending()`, which describes a state the number is in.
    expect(fn (): RegistrationResult => onPremDriver()->register($session, onPremCredentialsFor($tenant)))
        ->toThrow(ChannelRequestFailedException::class);
});

it('points the container\'s webhook at a canonical https callback, and refuses anything else', function (): void {
    [$tenant] = onPremTenant();

    $credentials = onPremCredentialsFor($tenant);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        // The settings route answers 200 with an empty body on some builds and 204 on others.
        '*/v1/settings/application' => Http::response('', 200),
    ]);

    onPremDriver()->pointWebhookAt($credentials, 'https://app.example.test/webhooks/on-premise/rk-123');

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/v1/settings/application')
        || ($request->method() === 'PATCH'
            && $request->hasHeader('Authorization', 'Bearer '.ON_PREM_TOKEN)
            && $request->data() === ['webhooks' => ['url' => 'https://app.example.test/webhooks/on-premise/rk-123']]));

    // Verified customer content travels over this URL and the secret that authenticates it is in a
    // header, so a plaintext or relative one is refused rather than accepted.
    foreach ([
        'http://app.example.test/webhooks/on-premise/rk-123',
        '/webhooks/on-premise/rk-123',
        'not a url',
        '',
    ] as $refused) {
        expect(fn () => onPremDriver()->pointWebhookAt($credentials, $refused))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('reports incomplete credentials as unhealthy, naming the keys and probing nothing', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    Http::fake();

    $health = onPremDriver()->healthCheck(ChannelCredentials::platform($tenant->id, ChannelMode::OnPremise));

    expect($health->healthy)->toBeFalse()
        ->and($health->isUsable())->toBeFalse()
        ->and($health->detail)->toContain('base_url')
        ->and($health->detail)->toContain('phone_number')
        ->and($health->detail)->toContain('username')
        ->and($health->detail)->toContain('password')
        ->and($health->detail)->toContain('webhook_secret');

    // Nothing was probed: task 7.6 needs this answer in order to keep the previous working set, and
    // a request would have been a request with no credentials.
    Http::assertNothingSent();
});

it('does not require the optional client settings a tenant may not need', function (): void {
    [$tenant] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/health' => Http::response(onPremHealthResponse('connected')),
    ]);

    // `template_namespace` and `media_provider` are unset, and the credentials are still healthy: a
    // health check that retired a working set over an unused optional setting would be the failure
    // task 7.6 exists to prevent.
    $health = onPremDriver()->healthCheck(onPremCredentialsFor($tenant));

    expect($health->healthy)->toBeTrue()
        ->and($health->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('accepts a stored access token in place of a username and password', function (): void {
    [$tenant] = onPremTenant(['access_token' => 'out-of-band-token-9f2a', 'username' => null, 'password' => null]);

    Http::fake(['*/v1/health' => Http::response(onPremHealthResponse('connected'))]);

    // §2.7's *"client API user/password/token"*: a deployment minting its own token is complete
    // without the login pair, and no login route is touched.
    expect(onPremDriver()->healthCheck(onPremCredentialsFor($tenant))->healthy)->toBeTrue();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/users/login'));
});

it('reports a refusal, an unreadable answer, and an unregistered gateway as unhealthy data', function (): void {
    [$tenant] = onPremTenant();

    Sleep::fake();

    $credentials = onPremCredentialsFor($tenant);
    $driver = onPremDriver();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/health' => Http::sequence()
            // Twice, because a `1005` is first treated as an expired bearer: the driver mints a new
            // one and replays the probe exactly once. Only a refusal that survives a brand-new token
            // is reported as a credential failure, which is what makes that classification honest.
            ->push(onPremError(1005), 401)
            ->push(onPremError(1005), 401)
            ->push(['nothing' => 'useful'])
            ->push(onPremHealthResponse('unregistered'))
            // Transient states are *healthy*: a credential set retired because a container was
            // reconnecting is a working number taken offline by a health check.
            ->push(onPremHealthResponse('connecting'))
            ->push(onPremHealthResponse('stale')),
    ]);

    $refused = $driver->healthCheck($credentials);

    expect($refused->healthy)->toBeFalse()
        ->and($refused->detail)->toContain('rejected the username and password')
        ->and($refused->detail)->toContain('HTTP 401')
        ->and($refused->detail)->toContain('code 1005')
        // Never the client's prose: its 401 body echoes the credentials it was sent.
        ->and($refused->detail)->not->toContain(ON_PREM_PASSWORD)
        ->and($refused->detail)->not->toContain('Rejected request with');

    expect($driver->healthCheck($credentials)->healthy)->toBeFalse()
        ->and($driver->healthCheck($credentials)->healthy)->toBeFalse()
        ->and($driver->healthCheck($credentials)->healthy)->toBeTrue()
        ->and($driver->healthCheck($credentials)->healthy)->toBeTrue();
});

it('lets an unreachable container propagate from a health check, because "could not ask" is not "said no"', function (): void {
    [$tenant] = onPremTenant();

    Sleep::fake();

    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('connection refused'));

    // The contract's own division: the two outcomes lead to different operator actions, so only the
    // second is reported as data.
    expect(fn (): ChannelHealth => onPremDriver()->healthCheck(onPremCredentialsFor($tenant)))
        ->toThrow(BridgeUnreachableException::class);
});

/*
|--------------------------------------------------------------------------
| base_url — the one tenant-supplied host on the platform
|--------------------------------------------------------------------------
*/

it('refuses a base URL that would aim the platform\'s HTTP client somewhere it must not go', function (): void {
    Http::fake();

    foreach ([
        // The cloud metadata endpoint, and the private and loopback ranges around it.
        'http://169.254.169.254',
        'http://127.0.0.1:9090',
        'https://10.0.0.7:9090',
        'http://192.168.1.10',
        'http://localhost:9090',
        'https://api.localhost',
        // Not an http(s) URL at all.
        'file:///etc/passwd',
        'gopher://on-prem.test',
        'on-prem.test:9090',
        // Userinfo, which is how a credential ends up in every log line that records the URL.
        'https://admin:hunter2@on-prem.test:9090',
    ] as $base) {
        [$tenant] = onPremTenant([], ['base_url' => $base]);

        try {
            onPremDriver()->healthCheck(onPremCredentialsFor($tenant));
            $thrown = null;
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(InvalidArgumentException::class, $base)
            // The offending value is never quoted: a base URL is exactly the field somebody pastes a
            // token into by mistake, and this message reaches logs.
            ->and($thrown?->getMessage())->not->toContain($base)
            ->and($thrown?->getMessage())->toContain('base_url');
    }

    Http::assertNothingSent();
});

it('allows a private container only when the deployment says so, in one place', function (): void {
    [$tenant] = onPremTenant([], ['base_url' => 'https://10.0.0.7:9090']);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/health' => Http::response(onPremHealthResponse('connected')),
    ]);

    // Off by default, because on a hosted platform a private address in a tenant credential is either
    // a mistake or an attempt to reach inside the platform's own network.
    expect(fn (): ChannelHealth => onPremDriver()->healthCheck(onPremCredentialsFor($tenant)))
        ->toThrow(InvalidArgumentException::class);

    config()->set('wa.channel.on_premise.allow_private_hosts', true);

    expect(onPremDriver()->healthCheck(onPremCredentialsFor($tenant))->healthy)->toBeTrue();

    // A hostname is not decidable from the string, and the driver does not pretend otherwise: it
    // resolves no DNS, because a name that resolves privately now can resolve elsewhere before the
    // connection is made. That boundary is the network's.
    config()->set('wa.channel.on_premise.allow_private_hosts', false);

    [$named] = onPremTenant([], ['base_url' => 'https://on-prem.internal.example']);

    expect(onPremDriver()->healthCheck(onPremCredentialsFor($named))->healthy)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The transport half
|--------------------------------------------------------------------------
*/

it('sends media by uploaded id, and by link with the tenant\'s configured media provider', function (): void {
    [, $session] = onPremTenant([], ['media_provider' => 'tenant-cdn']);

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/media' => Http::response(['media' => [['id' => 'media-abc']]]),
        '*/v1/messages' => Http::response(onPremMessageResponse('gBEG.media')),
    ]);

    $driver = onPremDriver();

    // Inline bytes: the client has no inline-bytes field, so they are uploaded first — as the **raw
    // body** with the sniffed MIME type, which is where it differs from Cloud API's multipart.
    $driver->sendMedia($session->id, '919899990000', MediaPayload::fromBytes(
        'PNGBYTES',
        'image/png',
        MediaKind::Image,
        caption: 'Your receipt',
    ));

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/v1/media')
        || ($request->hasHeader('Content-Type', 'image/png') && $request->body() === 'PNGBYTES'));

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/v1/messages')
        || $request->data()['image'] === ['id' => 'media-abc', 'caption' => 'Your receipt']);

    // A URL: sent by `link`, with the container's configured media provider named beside it — the one
    // shape that genuinely differs from Cloud API's, because the client fetches a link only through
    // a provider the operator configured.
    $driver->sendMedia($session->id, '919899990000', MediaPayload::fromUrl(
        'https://cdn.example.test/invoice.pdf',
        'application/pdf',
        MediaKind::Document,
        filename: 'invoice.pdf',
    ));

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/v1/messages')
        || ! array_key_exists('document', $request->data())
        || $request->data()['document'] === [
            'link' => 'https://cdn.example.test/invoice.pdf',
            'provider' => ['name' => 'tenant-cdn'],
            'filename' => 'invoice.pdf',
        ]);

    // Exactly one upload: the link path does not touch `/v1/media`.
    expect(onPremRequestCount('/v1/media'))->toBe(1);
});

it('sends a link with no provider block when the tenant configured none', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse()),
    ]);

    onPremDriver()->sendMedia($session->id, '919899990000', MediaPayload::fromUrl(
        'https://cdn.example.test/photo.jpg',
        'image/jpeg',
    ));

    // The client refuses this with `1009`, which surfaces as a typed refusal naming the missing
    // setting. Deliberate: the alternative is for the platform to fetch a tenant-supplied URL and
    // re-upload it, which reintroduces on a hot path the SSRF surface `base_url` validation bounds.
    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/v1/messages')
        || $request->data()['image'] === ['link' => 'https://cdn.example.test/photo.jpg']);
});

it('sends text through the transport method with only the options the client understands', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response(onPremMessageResponse('gBEG.opts')),
    ]);

    $sent = onPremDriver()->sendText($session->id, '919899990000', 'Reply', [
        'preview_url' => true,
        'reply_to' => 'ABG.inbound',
        // Not in the allowlist: passing a caller's map through would turn a typo into a `1010`.
        'ephemeral' => true,
    ]);

    expect($sent)->toBeInstanceOf(SentMessageDto::class)
        ->and($sent->waMessageId)->toBe('gBEG.opts')
        ->and($sent->jid)->toBe('919899990000')
        // The client sends no accepted-at timestamp, and `null` is honest where `now()` would be a
        // fabricated fact on the delivery board.
        ->and($sent->sentAt)->toBeNull();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/v1/messages')) {
            return false;
        }

        $body = $request->data();

        return $body['text']['preview_url'] === true
            && $body['context'] === ['message_id' => 'ABG.inbound']
            && ! array_key_exists('ephemeral', $body);
    });
});

it('really checks numbers, which is the one thing this backend does that Cloud API cannot', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/contacts' => Http::response(['contacts' => [
            ['input' => '919899990000', 'status' => 'valid', 'wa_id' => '919899990000'],
            ['input' => '919899991111', 'status' => 'invalid'],
            ['input' => '919899992222', 'status' => 'processing'],
        ]]),
    ]);

    $checks = onPremDriver()->checkNumbers($session->id, [
        '919899990000',
        '919899991111',
        '919899992222',
        // Not mentioned in the answer at all.
        '919899993333',
    ]);

    expect($checks)->toHaveCount(4)
        ->and($checks['919899990000']->exists)->toBeTrue()
        ->and($checks['919899991111']->exists)->toBeFalse()
        // `processing` is *unknown*, not "not on WhatsApp": collapsing it would record an
        // in-progress lookup as a permanent fact and have every later campaign skip a real customer.
        ->and($checks['919899992222']->exists)->toBeNull()
        ->and($checks['919899992222']->isUnknown())->toBeTrue()
        ->and($checks['919899993333']->exists)->toBeNull();

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/v1/contacts')
        // `force_check` is false so the client may answer from its own cache: a forced check is a
        // WhatsApp-side lookup per number, which is the traffic the anti-ban engine exists for.
        || ($request->data()['force_check'] === false && $request->data()['blocking'] === 'wait'));

    // An empty ask makes no request, and still resolves ownership first.
    Http::fake();
    expect(onPremDriver()->checkNumbers($session->id, []))->toBe([]);
    expect(fn (): array => onPremDriver()->checkNumbers('not-a-ulid', []))->toThrow(UnknownSessionException::class);
});

it('reports the container\'s view of the number as a session state', function (): void {
    [, $session] = onPremTenant();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/health' => Http::sequence()
            ->push(onPremHealthResponse('connected'))
            ->push(onPremHealthResponse('disconnected')),
    ]);

    $driver = onPremDriver();

    $connected = $driver->sessionState($session->id);

    expect($connected->sessionId)->toBe($session->id)
        ->and($connected->status)->toBe(SessionStatus::Connected)
        // The `/v1/health` route describes the gateway, not the account, so the number comes from
        // config — which is the value `sessions_wa.phone` already holds.
        ->and($connected->phone)->toBe(ON_PREM_PHONE)
        ->and($connected->disconnectReason)->toBeNull();

    $down = $driver->sessionState($session->id);

    // `QR_PENDING` means *awaiting the backend* here, the same meaning it carries for a Baileys
    // session awaiting a scan — and it is a report, not the state of record.
    expect($down->status)->toBe(SessionStatus::QrPending)
        ->and($down->disconnectReason)->toBe('disconnected');
});

it('answers the transport methods the legacy client has no equivalent for without a silent no-op', function (): void {
    [, $session] = onPremTenant();

    Http::fake();

    $driver = onPremDriver();

    // `null` is a documented answer rather than a failure: an officially-registered number never
    // shows a QR, so a connection screen renders nothing without knowing which mode it is looking at.
    expect($driver->qr($session->id))->toBeNull();

    $refusals = [
        'provisionSession' => fn () => $driver->provisionSession($session->id, SessionLoginMethod::Qr),
        'startSession' => fn () => $driver->startSession($session->id),
        'stopSession' => fn () => $driver->stopSession($session->id, true),
        'pairingCode' => fn () => $driver->pairingCode($session->id, '919812340000'),
        'sendPresence' => fn () => $driver->sendPresence($session->id, '919899990000', PresenceState::Composing),
    ];

    foreach ($refusals as $operation => $call) {
        try {
            $call();
            $thrown = null;
        } catch (ChannelOperationException $refused) {
            $thrown = $refused;
        }

        expect($thrown)->toBeInstanceOf(ChannelOperationException::class, $operation)
            ->and($thrown?->mode)->toBe(ChannelMode::OnPremise)
            ->and($thrown?->operation)->toBe($operation)
            // 422 and never retried: no amount of waiting gives a Business API client a pairing code.
            ->and($thrown?->getStatusCode())->toBe(422);
    }

    // `stopSession()` in particular must not become `DELETE /v1/account`: that is the one
    // irreversible step of the migration path, not a side effect of stopping a session.
    Http::assertNothingSent();
});

it('answers isReachable for the acting tenant\'s container, and false when it cannot ask', function (): void {
    // The one method whose meaning changes on this mode: the base URL is a tenant credential, so
    // there is no single host to probe.
    expect(onPremDriver()->isReachable())->toBeFalse();

    [$tenant] = onPremTenant();

    // Any answer at all counts, including a 401: an unauthenticated /v1/health is *supposed* to be
    // refused, and a refusal proves the container is serving.
    Http::fake(['*/v1/health' => Http::response(onPremError(1005), 401)]);
    expect(onPremDriver()->isReachable())->toBeTrue();

    // Only a transport failure is a false — the one method in the contract that reports a failure as
    // a value, so every way of failing to ask is a "no".
    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('connection refused'));
    expect(onPremDriver()->isReachable())->toBeFalse();

    // ...including a base URL the driver refuses to request, which must not escape as an exception
    // from a boolean probe.
    app(ChannelCredentialStore::class)->put($tenant, ChannelMode::OnPremise, [], ['base_url' => 'http://127.0.0.1:9090']);
    Http::fake(['*/v1/health' => Http::response(onPremHealthResponse())]);
    expect(onPremDriver()->isReachable())->toBeFalse();
});

it('fails closed when the container answers 2xx with something that is not a result', function (): void {
    [, $session] = onPremTenant();

    Sleep::fake();

    Http::fake([
        '*/v1/users/login' => Http::response(onPremLoginResponse()),
        '*/v1/messages' => Http::response('<html>502 from a proxy</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    // A 2xx that is not a result leaves the outcome unknown, which is a transport failure and keeps
    // the work — not a result to read, and certainly not a "sent".
    expect(fn (): SendReceipt => onPremDriver()->send(
        $session,
        TextContent::to('919899990000', 'Hello', 'html-body'),
    ))->toThrow(BridgeUnreachableException::class);
});

it('builds the interactive shapes the matrix says this mode supports natively', function (): void {
    // `INTERACTIVE` is `✅` on ON_PREMISE, so the wire shapes must exist even though no
    // `OutboundContent` variant can express one yet — task 12.4 adds one `match` arm to `send()` and
    // nothing else.
    $buttons = OnPremiseMessage::buttons('919899990000', 'Track your order?', [
        ['id' => 'track', 'title' => 'Track it'],
        ['id' => 'later', 'title' => 'Later'],
    ], footer: 'ACME');

    expect($buttons->type)->toBe('interactive')
        ->and($buttons->body()['interactive']['type'])->toBe('button')
        ->and($buttons->body()['interactive']['action']['buttons'][0])
        ->toBe(['type' => 'reply', 'reply' => ['id' => 'track', 'title' => 'Track it']])
        ->and($buttons->body()['interactive']['footer'])->toBe(['text' => 'ACME'])
        // No `messaging_product`: that field is Cloud API's.
        ->and($buttons->body())->not->toHaveKey('messaging_product');

    $list = OnPremiseMessage::list('919899990000', 'Pick a size', 'Sizes', [
        ['id' => 's', 'title' => 'Small', 'description' => 'EU 38'],
        ['id' => 'm', 'title' => 'Medium'],
    ]);

    expect($list->body()['interactive']['type'])->toBe('list')
        ->and($list->body()['interactive']['action']['button'])->toBe('Sizes')
        ->and($list->body()['interactive']['action']['sections'][0]['rows'][1])->toBe(['id' => 'm', 'title' => 'Medium']);

    // Every cap is refused locally, because each is a 4xx the tenant's own container charges.
    expect(fn (): OnPremiseMessage => OnPremiseMessage::buttons('919899990000', 'Body', []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OnPremiseMessage => OnPremiseMessage::buttons('919899990000', 'Body', array_fill(0, 4, ['id' => 'a', 'title' => 'A'])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OnPremiseMessage => OnPremiseMessage::buttons('919899990000', 'Body', [['id' => '', 'title' => 'A']]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OnPremiseMessage => OnPremiseMessage::buttons('919899990000', 'Body', [['id' => 'a', 'title' => str_repeat('A', 21)]]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OnPremiseMessage => OnPremiseMessage::list('919899990000', 'Body', 'Menu', array_fill(0, 11, ['id' => 'a', 'title' => 'A'])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OnPremiseMessage => OnPremiseMessage::text('919899990000', str_repeat('x', OnPremiseMessage::MAX_TEXT_LENGTH + 1)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OnPremiseMessage => OnPremiseMessage::text('919899990000', '   '))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OnPremiseMessage => OnPremiseMessage::template('919899990000', '', 'en_US'))
        ->toThrow(InvalidArgumentException::class);
});
