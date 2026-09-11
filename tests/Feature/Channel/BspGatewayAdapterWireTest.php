<?php

declare(strict_types=1);

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use App\Enums\SessionStatus;
use App\Exceptions\Channel\ChannelOperationException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Models\Session;
use App\Models\Tenant;
use App\Services\Bridge\MediaPayload;
use App\Services\Channel\Bsp\Adapters\GupshupAdapter;
use App\Services\Channel\Bsp\Adapters\InfobipAdapter;
use App\Services\Channel\Bsp\Adapters\KaleyraAdapter;
use App\Services\Channel\Bsp\Adapters\MessageBirdAdapter;
use App\Services\Channel\Bsp\Adapters\ThreeSixtyDialogAdapter;
use App\Services\Channel\Bsp\Adapters\TwilioAdapter;
use App\Services\Channel\Bsp\Adapters\VonageAdapter;
use App\Services\Channel\Bsp\Adapters\WatiAdapter;
use App\Services\Channel\Bsp\BspAdapterRegistry;
use App\Services\Channel\BspGatewayChannelDriver;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelCredentialStore;
use App\Services\Channel\TextContent;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Channel\BspCredentialFixtures;

/*
|--------------------------------------------------------------------------
| BspGatewayChannelDriver — the eight partner wires (Req 8.1, 8.2 / A8)
|--------------------------------------------------------------------------
| design § Channel Mode 2.2 mode 4 names eight partners and says each is fronted by "a per-provider
| adapter" with "per-provider quirks (endpoint, auth scheme, template sync API, media handling)". This
| file walks **all eight**, twice over:
|
|   1. **outbound** — one text send per partner reaches that partner's real endpoint, with that
|      partner's auth header and body encoding, and its message id is read out of that partner's own
|      response field. Eight partners, eight of everything: no adapter can be a copy of another and
|      still pass.
|   2. **inbound** — one verified callback per partner becomes a canonical `InboundEvent`, and the same
|      callback signed with **another tenant's** secret is refused. The five signature schemes
|      (`BspCredentialFixtures::signedWebhook()`) are exercised by the same loop, so none of them can
|      be quietly left unverified.
|
| It also pins the registry's completeness refusal: a `BspProvider` case with no adapter is a
| deployment defect, reported at construction rather than by a tenant whose sends fail at a partner.
*/

/**
 * A tenant bound as the acting one, with complete credentials for `$provider` and a session on the mode.
 *
 * @return array{0: Tenant, 1: Session}
 */
function bspWireTenant(BspProvider $provider): array
{
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    app(ChannelCredentialStore::class)->put(
        $tenant,
        ChannelMode::BspGateway,
        secrets: BspCredentialFixtures::secrets($provider),
        config: BspCredentialFixtures::config($provider),
        provider: $provider,
    );

    return [$tenant, Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::BspGateway,
        'status' => SessionStatus::Connected,
    ])];
}

function bspWireCredentials(Tenant $tenant): ChannelCredentials
{
    $credentials = app(ChannelCredentialStore::class)->for($tenant, ChannelMode::BspGateway);

    assert($credentials instanceof ChannelCredentials);

    return $credentials;
}

/**
 * The endpoint, method, body encoding and auth header each partner's text send must use.
 *
 * Written out per partner rather than derived, because a derived expectation would be the
 * implementation restated: the point of the table is that somebody read eight sets of partner
 * documentation and wrote down what each one wants.
 *
 * @return array{url: string, method: string, header: string, auth: string, form: bool}
 */
function bspWireExpectation(BspProvider $provider): array
{
    $base = 'https://'.$provider->webhookSlug().'.test';
    $key = BspCredentialFixtures::API_SECRET;

    return match ($provider) {
        BspProvider::Twilio => [
            'url' => $base.'/2010-04-01/Accounts/AC00000000000000000000000000000001/Messages.json',
            'method' => 'POST', 'form' => true,
            'header' => 'Authorization',
            'auth' => 'Basic '.base64_encode('AC00000000000000000000000000000001:'.$key),
        ],
        BspProvider::ThreeSixtyDialog => [
            'url' => $base.'/messages', 'method' => 'POST', 'form' => false,
            'header' => 'D360-API-KEY', 'auth' => $key,
        ],
        BspProvider::Gupshup => [
            'url' => $base.'/wa/api/v1/msg', 'method' => 'POST', 'form' => true,
            'header' => 'apikey', 'auth' => $key,
        ],
        BspProvider::Vonage => [
            'url' => $base.'/v1/messages', 'method' => 'POST', 'form' => false,
            'header' => 'Authorization', 'auth' => 'Basic '.base64_encode('a1b2c3d4:'.$key),
        ],
        BspProvider::MessageBird => [
            'url' => $base.'/v1/send', 'method' => 'POST', 'form' => false,
            'header' => 'Authorization', 'auth' => 'AccessKey '.$key,
        ],
        BspProvider::Infobip => [
            'url' => $base.'/whatsapp/1/message/text', 'method' => 'POST', 'form' => false,
            'header' => 'Authorization', 'auth' => 'App '.$key,
        ],
        BspProvider::Wati => [
            // WATI addresses the recipient in the path and carries the text in the query string, which is
            // its documented shape.
            'url' => $base.'/api/v1/sendSessionMessage/919812345678?'.http_build_query(['messageText' => 'Your order shipped.']),
            'method' => 'POST', 'form' => false,
            'header' => 'Authorization', 'auth' => 'Bearer '.$key,
        ],
        BspProvider::Kaleyra => [
            'url' => $base.'/v1/HXIN0000000000000001/messages', 'method' => 'POST', 'form' => true,
            'header' => 'api-key', 'auth' => $key,
        ],
    };
}

/*
|--------------------------------------------------------------------------
| The registry
|--------------------------------------------------------------------------
*/

it('registers exactly one adapter per BspProvider case', function (): void {
    $registry = app(BspAdapterRegistry::class);

    expect($registry->all())->toHaveCount(count(BspProvider::cases()));

    foreach (BspProvider::cases() as $provider) {
        expect($registry->for($provider)->provider())->toBe($provider);
    }

    // The eight classes design.md names, and each speaks for exactly one partner.
    expect(array_map(static fn (object $adapter): string => $adapter::class, $registry->all()))->toBe([
        TwilioAdapter::class,
        ThreeSixtyDialogAdapter::class,
        GupshupAdapter::class,
        VonageAdapter::class,
        MessageBirdAdapter::class,
        InfobipAdapter::class,
        WatiAdapter::class,
        KaleyraAdapter::class,
    ]);
});

it('refuses to construct with a partner missing or claimed twice', function (): void {
    // A ninth `BspProvider` case with no adapter must be a deployment defect reported at construction —
    // not a tenant discovering it when a send fails at a partner, and never a *default* adapter, which
    // would post one partner's body to another's endpoint under a third's auth header.
    expect(fn (): mixed => new BspAdapterRegistry([new TwilioAdapter]))
        ->toThrow(LogicException::class, '360DIALOG')
        ->and(fn (): mixed => new BspAdapterRegistry([...BspAdapterRegistry::defaults(), new TwilioAdapter]))
        ->toThrow(LogicException::class, 'TWILIO');
});

/*
|--------------------------------------------------------------------------
| Outbound: eight endpoints, eight auth schemes, eight response shapes
|--------------------------------------------------------------------------
*/

it('sends a text message on every partner\'s own endpoint, auth header and encoding', function (): void {
    foreach (BspProvider::cases() as $provider) {
        [, $session] = bspWireTenant($provider);
        $expected = bspWireExpectation($provider);

        Http::fake([BspCredentialFixtures::host($provider).'/*' => Http::response(
            BspCredentialFixtures::sendResponse($provider),
        )]);

        $receipt = app(BspGatewayChannelDriver::class)->send(
            $session,
            TextContent::to('919812345678', 'Your order shipped.', 'wire-'.$provider->value),
        );

        expect($receipt->provider)->toBe($provider)
            // Read out of that partner's own field — `sid`, `messages[0].id`, `messageId`,
            // `message_uuid`, `id`, `message.whatsappMessageId`… no two the same.
            ->and($receipt->providerMessageId)->toBe(BspCredentialFixtures::sentMessageId($provider))
            ->and($receipt->capability)->toBe(ChannelCapability::SendSingle);

        Http::assertSent(function ($request) use ($provider, $expected): bool {
            expect($request->url())->toBe($expected['url'])
                ->and($request->method())->toBe($expected['method'])
                ->and($request->header($expected['header'])[0] ?? null)->toBe($expected['auth'])
                ->and($request->isForm())->toBe($expected['form']);

            // The recipient reaches the wire in the partner's own presentation, and never as the raw
            // input: Twilio and Gupshup want a channel-prefixed or bare number, MessageBird a `+`.
            if ($provider === BspProvider::Twilio) {
                expect($request['To'])->toBe('whatsapp:+919812345678');
            }

            if ($provider === BspProvider::MessageBird) {
                expect($request->data()['to'])->toBe('+919812345678');
            }

            if ($provider === BspProvider::Gupshup) {
                expect($request['destination'])->toBe('919812345678')
                    ->and($request['src.name'])->toBe('AcmeSupport')
                    // Gupshup carries the message itself as a JSON document inside one form field.
                    ->and(json_decode((string) $request['message'], true))
                    ->toBe(['type' => 'text', 'text' => 'Your order shipped.']);
            }

            return true;
        });

        Http::clearResolvedInstances();
    }
});

it('refuses a media send on the one partner with no send-by-link route, and sends it on the rest', function (): void {
    $media = MediaPayload::fromUrl('https://cdn.test/photo.jpg', 'image/jpeg');

    foreach (BspProvider::cases() as $provider) {
        [, $session] = bspWireTenant($provider);

        Http::fake([BspCredentialFixtures::host($provider).'/*' => Http::response(
            BspCredentialFixtures::sendResponse($provider),
        )]);

        if ($provider === BspProvider::Wati) {
            // WATI's session API sends text; a file is uploaded multipart and there is no link route. The
            // sub-matrix refuses `MEDIA` before dispatch, and the adapter refuses a direct caller.
            expect(fn (): mixed => app(BspGatewayChannelDriver::class)->sendMedia($session->id, '919812345678', $media))
                ->toThrow(App\Exceptions\Channel\ModeCapabilityException::class)
                ->and(fn (): mixed => app(BspAdapterRegistry::class)->for($provider)->mediaMessage(
                    bspWireCredentials($session->tenant),
                    '919812345678',
                    $media,
                ))->toThrow(ChannelOperationException::class);

            Http::assertNothingSent();

            continue;
        }

        $sent = app(BspGatewayChannelDriver::class)->sendMedia($session->id, '919812345678', $media);

        expect($sent->waMessageId)->toBe(BspCredentialFixtures::sentMessageId($provider));

        Http::assertSentCount(1);
        Http::clearResolvedInstances();
    }
});

/*
|--------------------------------------------------------------------------
| Inbound: eight callback shapes, five proofs
|--------------------------------------------------------------------------
*/

it('verifies and normalises an inbound message on every partner', function (): void {
    foreach (BspProvider::cases() as $provider) {
        [$tenant] = bspWireTenant($provider);
        $credentials = bspWireCredentials($tenant);

        $event = app(BspGatewayChannelDriver::class)->parseWebhook(
            BspCredentialFixtures::signedWebhook($provider, BspCredentialFixtures::inboundMessage($provider)),
            $credentials,
        );

        expect($event->kind)->toBe(InboundEventKind::Message)
            ->and($event->mode)->toBe(ChannelMode::BspGateway)
            // From the credentials, always — the payload never gets to say whose tenant it is.
            ->and($event->tenantId)->toBe($tenant->id)
            ->and($event->from)->toBe('919812345678')
            ->and($event->text)->toBe('Where is my order?')
            ->and($event->providerMessageId)->not->toBeEmpty()
            ->and($event->isActionable())->toBeTrue();
    }
});

it('refuses a callback signed with another tenant\'s secret, on every partner', function (): void {
    foreach (BspProvider::cases() as $provider) {
        [$tenant] = bspWireTenant($provider);
        $credentials = bspWireCredentials($tenant);

        // Five different proofs, one refusal: a body signed with a secret this credential set does not
        // hold never becomes an `InboundEvent`, so there is no verdict a caller could forget to consult.
        expect(fn (): mixed => app(BspGatewayChannelDriver::class)->parseWebhook(
            BspCredentialFixtures::signedWebhook(
                $provider,
                BspCredentialFixtures::inboundMessage($provider),
                secret: 'another-tenants-webhook-secret-01',
            ),
            $credentials,
        ))->toThrow(WebhookVerificationException::class);

        // …and an unsigned one, which is the "callback registered without a secret" case.
        expect(fn (): mixed => app(BspGatewayChannelDriver::class)->parseWebhook(
            BspCredentialFixtures::signedWebhook($provider, BspCredentialFixtures::inboundMessage($provider), sign: false),
            $credentials,
        ))->toThrow(WebhookVerificationException::class);
    }
});

it('states, per partner, whether every callback shape names the number it is about', function (): void {
    $registry = app(BspAdapterRegistry::class);

    // The four that always name it are checked on every callback; the four that do not are documented
    // weakenings, each with its argument in its own class docblock. This assertion exists so that turning
    // one of them off is a deliberate edit to a test rather than an unnoticed loosening.
    $binds = [
        BspProvider::Twilio->value => true,
        BspProvider::ThreeSixtyDialog->value => true,
        BspProvider::Gupshup->value => true,
        BspProvider::MessageBird->value => true,
        BspProvider::Vonage->value => false,
        BspProvider::Infobip->value => false,
        BspProvider::Wati->value => false,
        BspProvider::Kaleyra->value => false,
    ];

    foreach (BspProvider::cases() as $provider) {
        expect($registry->for($provider)->requiresRecipientClaim())->toBe($binds[$provider->value]);
    }
});

it('refuses a verified payload that names no recipient on a partner that always names one', function (): void {
    [$tenant] = bspWireTenant(BspProvider::MessageBird);
    $credentials = bspWireCredentials($tenant);

    $payload = BspCredentialFixtures::inboundMessage(BspProvider::MessageBird);
    unset($payload['message']['channelId']);

    // Verified, and it cannot be matched to the credentials the route belongs to — so it is malformed,
    // not accepted unchecked.
    expect(fn (): mixed => app(BspGatewayChannelDriver::class)->parseWebhook(
        BspCredentialFixtures::signedWebhook(BspProvider::MessageBird, $payload),
        $credentials,
    ))->toThrow(WebhookVerificationException::class);
});

it('does not re-ingest a partner\'s echo of the platform\'s own outbound message', function (): void {
    [$tenant] = bspWireTenant(BspProvider::Wati);
    $credentials = bspWireCredentials($tenant);

    $payload = BspCredentialFixtures::inboundMessage(BspProvider::Wati);
    $payload['owner'] = true;

    // WATI echoes outbound messages back on the same webhook. Ingesting one as a customer message would
    // have the bot answer itself, and loop.
    $event = app(BspGatewayChannelDriver::class)->parseWebhook(
        BspCredentialFixtures::signedWebhook(BspProvider::Wati, $payload),
        $credentials,
    );

    expect($event->kind)->toBe(InboundEventKind::Unsupported)
        ->and($event->isActionable())->toBeFalse();
});

it('mints an RS256 JWS for Vonage when the account uses application auth', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($key)->not->toBeFalse();

    $pem = '';
    openssl_pkey_export($key, $pem);

    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    app(ChannelCredentialStore::class)->put(
        $tenant,
        ChannelMode::BspGateway,
        secrets: BspCredentialFixtures::secrets(BspProvider::Vonage) + ['private_key' => $pem],
        config: BspCredentialFixtures::config(BspProvider::Vonage) + ['application_id' => 'app-0001'],
        provider: BspProvider::Vonage,
    );

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::BspGateway,
        'status' => SessionStatus::Connected,
    ]);

    Http::fake(['vonage.test/*' => Http::response(BspCredentialFixtures::sendResponse(BspProvider::Vonage))]);

    app(BspGatewayChannelDriver::class)->send(
        $session,
        TextContent::to('919812345678', 'Your order shipped.', 'vonage-jws'),
    );

    Http::assertSent(function ($request) use ($pem): bool {
        $presented = $request->header('Authorization')[0] ?? '';

        // A bearer JWS rather than Basic: the choice follows what the tenant stored, never a platform
        // preference, and it must not silently fall back to Basic when a private key is present.
        expect($presented)->toStartWith('Bearer eyJ');

        [$header, $claims, $signature] = explode('.', substr($presented, strlen('Bearer ')));
        $decode = static fn (string $s): mixed => json_decode(
            (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true),
            true,
        );

        expect($decode($header))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
            ->and($decode($claims)['application_id'])->toBe('app-0001')
            // A fresh `jti` per token, so two sends in the same second are two distinct tokens — Vonage
            // rejects a replayed one.
            ->and($decode($claims)['jti'])->toBeString()
            ->and($decode($claims)['exp'])->toBeGreaterThan($decode($claims)['iat']);

        // The signature really is the application private key's, over the two segments it covers.
        $private = openssl_pkey_get_private($pem);
        expect($private)->not->toBeFalse();

        $details = openssl_pkey_get_details($private);
        expect($details)->toBeArray();

        $verified = openssl_verify(
            $header.'.'.$claims,
            (string) base64_decode(strtr($signature, '-_', '+/').str_repeat('=', (4 - strlen($signature) % 4) % 4), true),
            (string) $details['key'],
            OPENSSL_ALGO_SHA256,
        );

        expect($verified)->toBe(1);

        return true;
    });
});

it('refuses a Vonage private key OpenSSL cannot sign with, rather than downgrading to Basic', function (): void {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);

    app(ChannelCredentialStore::class)->put(
        $tenant,
        ChannelMode::BspGateway,
        secrets: BspCredentialFixtures::secrets(BspProvider::Vonage) + ['private_key' => 'not-a-pem-key-at-all'],
        config: BspCredentialFixtures::config(BspProvider::Vonage) + ['application_id' => 'app-0001'],
        provider: BspProvider::Vonage,
    );

    $session = Session::factory()->create([
        'tenant_id' => $tenant->id,
        'channel_mode' => ChannelMode::BspGateway,
        'status' => SessionStatus::Connected,
    ]);

    Http::preventStrayRequests();

    // A silent downgrade to Basic would leave a tenant who deliberately configured JWS auth seeing
    // Application API failures about something else entirely. Surfaced as the configuration defect it is,
    // naming the credential key to re-paste — and raised while the request is being *described*, so
    // nothing reaches the wire and no idempotency key is burned on it.
    expect(fn (): mixed => app(BspGatewayChannelDriver::class)->send(
        $session,
        TextContent::to('919812345678', 'hi', 'vonage-bad-key'),
    ))->toThrow(InvalidArgumentException::class, 'private_key');

    Http::assertNothingSent();
});
