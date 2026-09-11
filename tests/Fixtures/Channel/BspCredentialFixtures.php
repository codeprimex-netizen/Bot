<?php

declare(strict_types=1);

namespace Tests\Fixtures\Channel;

use App\Enums\BspProvider;
use Illuminate\Http\Request;

/**
 * A complete, per-partner `BSP_GATEWAY` credential set, and the eight callback shapes that go with it —
 * the §2.7 credential table and the partners' own wire vocabularies, in one place, for the task 7.4
 * tests.
 *
 * The eight adapters each declare their own required keys (`BspAdapter::requiredConfigKeys()` /
 * `requiredSecretKeys()`), and `BspGatewayChannelDriver::healthCheck()` refuses to probe a set that
 * is missing one. So every test that needs a *working* partner needs a complete set, and hand-writing
 * eight of them per test file is how one of them drifts from its adapter.
 *
 * Kept as a fixture class rather than a factory because these are not database rows: they are the
 * `config` and `secrets` arrays handed to `ChannelCredentialStore::put()`, and the store is exercised
 * for real in those tests (with real envelope encryption) rather than faked.
 *
 * Hosts are `*.test` so a request that escaped `Http::fake()` fails loudly instead of reaching a
 * partner.
 */
final class BspCredentialFixtures
{
    /**
     * A secret long enough for `ChannelCredentials::redact()` to scrub
     * (`MIN_REDACTABLE_LENGTH`), so the secrecy assertions are meaningful.
     */
    public const string WEBHOOK_SECRET = 'bsp-webhook-secret-0123456789abcdef';

    public const string API_SECRET = 'bsp-api-secret-fedcba9876543210';

    /**
     * The non-secret identifiers for `$provider`.
     *
     * @return array<string, mixed>
     */
    public static function config(BspProvider $provider): array
    {
        $shared = ['base_url' => 'https://'.$provider->webhookSlug().'.test', 'sender' => '15550001111'];

        return match ($provider) {
            BspProvider::Twilio => $shared + ['account_sid' => 'AC00000000000000000000000000000001'],
            BspProvider::Gupshup => $shared + ['app_name' => 'AcmeSupport'],
            BspProvider::Vonage => $shared + ['api_key' => 'a1b2c3d4'],
            BspProvider::MessageBird => $shared + ['channel_id' => 'ffffffff-1111-2222-3333-444444444444'],
            BspProvider::Kaleyra => $shared + ['sid' => 'HXIN0000000000000001'],
            BspProvider::ThreeSixtyDialog,
            BspProvider::Infobip,
            BspProvider::Wati => $shared,
        };
    }

    /**
     * The secret material for `$provider`, keyed as its adapter reads it.
     *
     * Twilio is the one partner with no separate webhook secret: it signs callbacks with the account
     * auth token, which `TwilioAdapter::verifyWebhook()` sets out.
     *
     * @return array<string, mixed>
     */
    public static function secrets(BspProvider $provider): array
    {
        $webhook = ['webhook_secret' => self::WEBHOOK_SECRET];

        return match ($provider) {
            BspProvider::Twilio => ['auth_token' => self::API_SECRET],
            BspProvider::ThreeSixtyDialog, BspProvider::Infobip, BspProvider::Kaleyra => $webhook + ['api_key' => self::API_SECRET],
            BspProvider::Gupshup => $webhook + ['apikey' => self::API_SECRET],
            BspProvider::Vonage => $webhook + ['api_secret' => self::API_SECRET],
            BspProvider::MessageBird => $webhook + ['access_key' => self::API_SECRET],
            BspProvider::Wati => $webhook + ['access_token' => self::API_SECRET],
        };
    }

    /**
     * The secret a partner signs or authenticates its **webhook** with.
     *
     * What a test HMACs a body with, and the reason it is a method rather than a constant: Twilio's is
     * the auth token and the other seven's is the webhook secret.
     */
    public static function webhookSecret(BspProvider $provider): string
    {
        return $provider === BspProvider::Twilio ? self::API_SECRET : self::WEBHOOK_SECRET;
    }

    /**
     * The host `Http::fake()` should intercept for `$provider`.
     */
    public static function host(BspProvider $provider): string
    {
        return $provider->webhookSlug().'.test';
    }

    /**
     * The callback URL a partner posts to — the shape `UrlBuilder::webhook()` builds, with the
     * partner's own slug so two partners of one tenant have two distinct paths
     * (`BspProvider::webhookSlug()`).
     */
    public static function webhookUrl(BspProvider $provider, string $routeKey = 'route-key'): string
    {
        return 'https://platform.test/webhooks/'.$provider->webhookSlug().'/'.$routeKey;
    }

    /**
     * A webhook request for `$provider`, in that partner's own encoding and carrying that partner's own
     * proof.
     *
     * Five genuinely different schemes are produced here, and that is the point of building them in one
     * place: the cross-tenant refusal and the origin check have to hold for **all** of them, not just
     * for whichever is convenient to forge.
     *
     * | Partner | Proof |
     * |---|---|
     * | Twilio | `X-Twilio-Signature`: base64 HMAC-**SHA1** over the full URL plus the sorted form parameters |
     * | 360dialog | `X-Hub-Signature-256`: hex HMAC-SHA256 over the raw body |
     * | Gupshup | `X-Gupshup-Signature`: base64 HMAC-SHA256 over the raw body |
     * | Vonage | `Authorization: Bearer` — HS256 JWT with `payload_hash` |
     * | MessageBird | `MessageBird-Signature-JWT`: HS256 JWT with `payload_hash` **and** `url_hash` |
     * | Infobip, WATI, Kaleyra | the shared secret in `Authorization` |
     *
     * @param  array<string, mixed>  $payload
     * @param  bool  $sign  false to omit the proof entirely — the "callback registered without a secret" case
     * @param  string|null  $secret  another tenant's secret, to forge with
     */
    public static function signedWebhook(
        BspProvider $provider,
        array $payload,
        bool $sign = true,
        ?string $secret = null,
    ): Request {
        $secret ??= self::webhookSecret($provider);
        $url = self::webhookUrl($provider);

        if ($provider === BspProvider::Twilio) {
            // Form-encoded, and the MAC covers the URL rather than the body — so the request has to be
            // built before the signature can be computed.
            $request = Request::create($url, 'POST', $payload);

            if ($sign) {
                $request->headers->set(
                    'X-Twilio-Signature',
                    base64_encode(hash_hmac('sha1', self::twilioCanonical($request, $payload), $secret, true)),
                );
            }

            return $request;
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($sign) {
            $headers += match ($provider) {
                BspProvider::ThreeSixtyDialog => [
                    'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
                ],
                BspProvider::Gupshup => [
                    'HTTP_X_GUPSHUP_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
                ],
                BspProvider::Vonage => [
                    'HTTP_AUTHORIZATION' => 'Bearer '.self::jwt(['payload_hash' => hash('sha256', $body)], $secret),
                ],
                BspProvider::MessageBird => [
                    'HTTP_MESSAGEBIRD_SIGNATURE_JWT' => self::jwt([
                        'payload_hash' => hash('sha256', $body),
                        'url_hash' => hash('sha256', $url),
                    ], $secret),
                ],
                // Infobip, WATI and Kaleyra: the shared secret in a header, because none of the three
                // publishes a webhook MAC (`BspWebhookVerifier`). Twilio cannot reach this arm — it
                // returned above, since its MAC covers the URL rather than the body.
                default => ['HTTP_AUTHORIZATION' => 'Bearer '.$secret],
            };
        }

        return Request::create($url, 'POST', [], [], [], $headers, $body);
    }

    /**
     * An HS256 JWT with `$claims` — the shape Vonage and MessageBird sign a callback with.
     *
     * `$algorithm` is a parameter so a test can present `none` or an unlisted digest and assert the
     * verifier's allowlist refuses it: trusting a token's own `alg` is the canonical JWT defeat.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function jwt(array $claims, string $secret, string $algorithm = 'HS256'): string
    {
        $segments = self::base64Url((string) json_encode(['alg' => $algorithm, 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'.self::base64Url((string) json_encode($claims, JSON_THROW_ON_ERROR));

        if ($algorithm === 'none') {
            return $segments.'.';
        }

        $digest = match ($algorithm) {
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => 'sha256',
        };

        return $segments.'.'.self::base64Url(hash_hmac($digest, $segments, $secret, true));
    }

    /**
     * 360dialog's forwarded Meta envelope — `entry[].changes[].value.{messages,statuses}`.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    public static function metaEnvelope(
        array $messages = [],
        array $statuses = [],
        string $displayNumber = '15550001111',
    ): array {
        $value = ['messaging_product' => 'whatsapp', 'metadata' => [
            'display_phone_number' => $displayNumber,
            'phone_number_id' => '109876543210',
        ]];

        if ($messages !== []) {
            $value['messages'] = $messages;
        }

        if ($statuses !== []) {
            $value['statuses'] = $statuses;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => $value]]]],
        ];
    }

    /**
     * One inbound customer message in `$provider`'s own callback shape, from `$from` to this fixture's
     * sender.
     *
     * The eight shapes agree on nothing — not the field names, not the nesting, not even which end of
     * the conversation `to` refers to — so this is the table that lets one test walk all eight.
     *
     * @return array<string, mixed>
     */
    public static function inboundMessage(BspProvider $provider, string $from = '919812345678'): array
    {
        return match ($provider) {
            BspProvider::Twilio => [
                'MessageSid' => 'SM-IN-1',
                'From' => 'whatsapp:+'.$from,
                'To' => 'whatsapp:+15550001111',
                'Body' => 'Where is my order?',
                'SmsStatus' => 'received',
            ],
            BspProvider::ThreeSixtyDialog => self::metaEnvelope([[
                'id' => 'wamid.IN1',
                'from' => $from,
                'type' => 'text',
                'text' => ['body' => 'Where is my order?'],
            ]]),
            BspProvider::Gupshup => [
                'app' => 'AcmeSupport',
                'type' => 'message',
                'payload' => ['id' => 'gs-IN-1', 'source' => $from, 'payload' => ['text' => 'Where is my order?']],
            ],
            BspProvider::Vonage => [
                'message_uuid' => 'vg-IN-1',
                'to' => '15550001111',
                'from' => $from,
                'channel' => 'whatsapp',
                'message_type' => 'text',
                'text' => 'Where is my order?',
            ],
            BspProvider::MessageBird => [
                'type' => 'message.created',
                'message' => [
                    'id' => 'mb-IN-1',
                    'channelId' => 'ffffffff-1111-2222-3333-444444444444',
                    'direction' => 'received',
                    'from' => '+'.$from,
                    'content' => ['text' => 'Where is my order?'],
                ],
            ],
            BspProvider::Infobip => [
                'results' => [[
                    'messageId' => 'ib-IN-1',
                    'from' => $from,
                    'to' => '15550001111',
                    'message' => ['type' => 'TEXT', 'text' => 'Where is my order?'],
                ]],
                'messageCount' => 1,
            ],
            BspProvider::Wati => [
                'eventType' => 'message',
                'id' => 'wt-IN-1',
                'whatsappMessageId' => 'wamid.WT1',
                'waId' => $from,
                'owner' => false,
                'text' => 'Where is my order?',
            ],
            BspProvider::Kaleyra => [
                'id' => 'kl-IN-1',
                'from' => $from,
                'to' => '15550001111',
                'type' => 'text',
                'body' => 'Where is my order?',
            ],
        };
    }

    /**
     * A partner's own "accepted" send response, keyed as its adapter reads the message id out of it.
     *
     * @return array<string, mixed>
     */
    public static function sendResponse(BspProvider $provider): array
    {
        return match ($provider) {
            BspProvider::Twilio => ['sid' => 'SM-OUT-1', 'to' => 'whatsapp:+919812345678', 'status' => 'queued'],
            BspProvider::ThreeSixtyDialog => ['messages' => [['id' => 'wamid.OUT1']], 'contacts' => [['wa_id' => '919812345678']]],
            BspProvider::Gupshup => ['status' => 'submitted', 'messageId' => 'gs-OUT-1'],
            BspProvider::Vonage => ['message_uuid' => 'vg-OUT-1'],
            BspProvider::MessageBird => ['id' => 'mb-OUT-1', 'status' => 'accepted'],
            BspProvider::Infobip => ['messageId' => 'ib-OUT-1', 'to' => '919812345678', 'messageCount' => 1],
            BspProvider::Wati => ['result' => true, 'message' => ['id' => 'wt-OUT-1', 'whatsappMessageId' => 'wamid.WTOUT']],
            BspProvider::Kaleyra => ['id' => 'kl-OUT-1', 'status' => 'queued'],
        };
    }

    /**
     * The message id `sendResponse()` promises, so a test can assert the adapter read the right field.
     */
    public static function sentMessageId(BspProvider $provider): string
    {
        return match ($provider) {
            BspProvider::Twilio => 'SM-OUT-1',
            BspProvider::ThreeSixtyDialog => 'wamid.OUT1',
            BspProvider::Gupshup => 'gs-OUT-1',
            BspProvider::Vonage => 'vg-OUT-1',
            BspProvider::MessageBird => 'mb-OUT-1',
            BspProvider::Infobip => 'ib-OUT-1',
            BspProvider::Wati => 'wamid.WTOUT',
            BspProvider::Kaleyra => 'kl-OUT-1',
        };
    }

    /**
     * Twilio's canonical signed string: the full URL, then each POST parameter in lexical key order as
     * `key` immediately followed by `value`.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function twilioCanonical(Request $request, array $payload): string
    {
        ksort($payload, SORT_STRING);
        $canonical = $request->fullUrl();

        foreach ($payload as $key => $value) {
            $canonical .= $key.(is_scalar($value) ? (string) $value : '');
        }

        return $canonical;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
