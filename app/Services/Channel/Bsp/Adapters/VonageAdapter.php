<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp\Adapters;

use App\Enums\BspProvider;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Enums\InboundEventKind;
use App\Enums\MediaKind;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Models\CloudApiTemplate;
use App\Services\Bridge\BridgeWire;
use App\Services\Bridge\MediaPayload;
use App\Services\Channel\Bsp\BspRefusal;
use App\Services\Channel\Bsp\BspRequest;
use App\Services\Channel\Bsp\BspSendResult;
use App\Services\Channel\Bsp\BspWebhookVerifier;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\InboundEvent;
use Illuminate\Http\Request;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * **Vonage** (formerly Nexmo) — the partner that authenticates with a **JWS** it expects the
 * caller to mint, and signs its callbacks with a JWT that binds the body by digest
 * (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3).
 *
 * | Axis | Vonage |
 * |---|---|
 * | Auth | `Authorization: Bearer {RS256 JWS}` when an application private key is stored; HTTP Basic on `api_key:api_secret` otherwise |
 * | Send | `POST /v1/messages`, JSON, `channel: whatsapp` |
 * | Template | `message_type: template` with `whatsapp.policy = deterministic` |
 * | Probe | `GET /v2/applications` |
 * | Errors | RFC 7807 — `{"type": "…#1010", "title": …, "instance": …}`; the code is the URI **fragment** |
 * | Webhook | `Authorization: Bearer {HS256 JWT}` with a `payload_hash` claim |
 * | Webhook identity | `to` on an inbound message, `from` on a status — see `recipientIn()` |
 *
 * ## Two auth schemes, chosen by what is stored, and never silently
 *
 * Vonage's Messages API accepts either. Which one a tenant can use is a property of its Vonage
 * account, so it is settled by which credentials it entered rather than by a platform preference:
 *
 * - an `application_id` (config) **and** a `private_key` (secret) → an RS256 JWS is minted per
 *   request, which is Vonage's own recommendation and the only scheme its Application API accepts
 *   for some features;
 * - otherwise → HTTP Basic on `api_key` (an identifier, so it lives in config) and `api_secret`.
 *
 * The fallback runs only when the private key is **absent**. A private key that is present and
 * unusable is a hard failure naming the key, not a quiet downgrade to Basic: a tenant who
 * deliberately configured JWS auth and is silently sent Basic instead would see its Application
 * API calls fail with an error about something else entirely.
 *
 * ## `INTERACTIVE` is `⚠️`, for the same reason as Twilio's
 *
 * design § 2.3: *"Twilio & Vonage do buttons/lists via content templates"*. So layer 2 keeps the
 * cell `Conditional`, and this adapter's account layer narrows it to `Unsupported` for an account
 * with no template registry configured — a refusal before dispatch rather than a Vonage 422
 * mid-send.
 *
 * ## The webhook identity flips between the two callback shapes
 *
 * On an **inbound message** Vonage sets `from` to the customer and `to` to the business number; on
 * a **status** callback it is the other way round. `recipientIn()` therefore branches on the
 * presence of `status`, which is the field that distinguishes the two — and a shape carrying
 * neither identity returns `null`, which for this adapter is *skipped* rather than refused
 * (`requiresRecipientClaim()` explains what carries the binding instead).
 */
final readonly class VonageAdapter extends BaseBspAdapter
{
    /**
     * The header Vonage authenticates with, and the one it signs a callback in — the same name in
     * both directions, which is a Vonage quirk rather than a coincidence worth relying on.
     */
    public const string AUTH_HEADER = 'Authorization';

    /**
     * The `config` key holding the API key — an identifier Vonage prints in its dashboard, so
     * config rather than a secret.
     */
    public const string API_KEY_CONFIG_KEY = 'api_key';

    /**
     * The `config` key naming the Vonage application a JWS is minted for.
     */
    public const string APPLICATION_ID_CONFIG_KEY = 'application_id';

    /**
     * The `secret_config` key holding the PEM private key a JWS is signed with.
     */
    public const string PRIVATE_KEY_SECRET_KEY = 'private_key';

    /**
     * The channel every message names. Vonage's Messages API fronts several.
     */
    public const string CHANNEL = 'whatsapp';

    /**
     * How long a minted JWS is valid for, in seconds.
     *
     * Short, because it is minted per request and used immediately: a token that outlives the call
     * it was made for is a token that can be replayed if it leaks, and there is no reason for one
     * to exist beyond the request.
     */
    public const int JWS_TTL_SECONDS = 60;

    public function provider(): BspProvider
    {
        return BspProvider::Vonage;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://api.nexmo.com';
    }

    protected function authSecretKey(): string
    {
        return 'api_secret';
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return [...parent::requiredConfigKeys(), self::API_KEY_CONFIG_KEY];
    }

    /**
     * A delivery report names no business number, so the payload check cannot be required.
     *
     * Vonage's inbound message *does* name it (`to`), and `recipientIn()` checks it — so the
     * weakening is bounded to status callbacks, which carry no message content and only ever
     * update a row this platform already created. What binds a status callback to the tenant is
     * that the webhook JWT is verified with the `webhook_secret` of **this** credential row, i.e.
     * of this `(tenant, BSP_GATEWAY, VONAGE)` triple; a second tenant's Vonage account has a
     * different signature secret and its callbacks do not verify here.
     *
     * The residual risk, stated: two tenants of this platform sharing **one** Vonage account would
     * share that secret, and their status callbacks would then be mutually acceptable. Message
     * content is unaffected — that path is checked.
     */
    public function requiresRecipientClaim(): bool
    {
        return false;
    }

    /**
     * Buttons and lists, which Vonage reaches only through a registered content template.
     */
    protected function interactiveSupport(
        ChannelCapabilitySupport $ceiling,
        ChannelCredentials $credentials,
    ): ChannelCapabilitySupport {
        return self::flag($credentials, self::INTERACTIVE_ENABLED_KEY, false)
            ? $ceiling
            : ChannelCapabilitySupport::Unsupported;
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    public function textMessage(ChannelCredentials $credentials, string $recipient, string $text): BspRequest
    {
        return BspRequest::json(
            $this->messagesUrl($credentials),
            $this->envelope($credentials, $recipient) + ['message_type' => 'text', 'text' => $text],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One media attachment, in Vonage's per-kind object.
     *
     * Vonage names the object after the message type and puts the link in `url`; a document is
     * `file` with a display `name`, and a sticker carries no caption at all.
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        $type = match ($media->kind) {
            MediaKind::Image => 'image',
            MediaKind::Video => 'video',
            MediaKind::Audio => 'audio',
            MediaKind::Document => 'file',
            MediaKind::Sticker => 'sticker',
        };

        $attachment = ['url' => (string) $media->url];

        if ($media->caption !== null && $media->kind->usesCaption()) {
            $attachment['caption'] = $media->caption;
        }

        if ($media->filename !== null && $media->kind->usesFilename()) {
            $attachment['name'] = $media->filename;
        }

        return BspRequest::json(
            $this->messagesUrl($credentials),
            $this->envelope($credentials, $recipient) + ['message_type' => $type, $type => $attachment],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One approved template.
     *
     * `whatsapp.policy` is `deterministic` — the only policy that names the language explicitly, so
     * the template that goes out is the one the platform recorded as approved rather than whichever
     * translation Vonage would otherwise pick from the recipient's device locale. A fallback policy
     * would make an approved-template send non-deterministic, which is exactly what an approval
     * registry exists to prevent.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        return BspRequest::json(
            $this->messagesUrl($credentials),
            $this->envelope($credentials, $recipient) + [
                'message_type' => 'template',
                'template' => [
                    'name' => self::templateHandle($template),
                    'parameters' => $parameters,
                ],
                'whatsapp' => ['policy' => 'deterministic', 'locale' => $template->language],
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * The account's applications — read-only, and it exercises whichever auth scheme is in use.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            $this->baseUrl($credentials).'/v2/applications',
            $this->authHeaders($credentials),
            ['page_size' => '1'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading what came back
    |--------------------------------------------------------------------------
    */

    public function readSendResult(array $payload): ?BspSendResult
    {
        return BspSendResult::tryFrom(BridgeWire::stringOrNull($payload['message_uuid'] ?? null));
    }

    /**
     * Vonage's RFC 7807 problem document.
     *
     * The machine-readable part is the **fragment** of the `type` URI
     * (`https://developer.vonage.com/api-errors#unauthorized`), which is why the code here is a
     * token rather than a number — and why `ChannelRequestFailedException::hasErrorCode()` taking
     * strings matters. `title` is carried as the error *type*, where
     * `NORMALISED_CODE_PATTERN` drops it if it is a phrase rather than an identifier; `detail`
     * quotes the request and is not read at all.
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        if ($status < 400) {
            return null;
        }

        return new BspRefusal(
            code: self::fragmentOf(BridgeWire::stringOrNull($payload['type'] ?? null)),
            type: BridgeWire::stringOrNull($payload['title'] ?? null),
            retryAfterSeconds: $retryAfterSeconds,
            traceId: BridgeWire::stringOrNull($payload['instance'] ?? null),
        );
    }

    public function accountDetailIn(array $payload): ?string
    {
        if (! array_key_exists('_embedded', $payload) && ! array_key_exists('total_items', $payload)) {
            return null;
        }

        return 'Vonage accepted these credentials for this account.';
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify Vonage's signed-webhook JWT.
     *
     * The token is HS256 over its own claims under the account's signature secret, and it carries
     * `payload_hash` — the SHA-256 of the request body — which is what binds the proof to *this*
     * body rather than to any body the same token could have escorted.
     */
    public function verifyWebhook(
        Request $request,
        ChannelCredentials $credentials,
        BspWebhookVerifier $verifier,
    ): void {
        $verifier->assertSignedJwt($request, self::AUTH_HEADER, $this->webhookSecret($credentials));
    }

    /**
     * The business number this callback is about: `to` on an inbound message, `from` on a status.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        $isStatus = BridgeWire::stringOrNull($payload['status'] ?? null) !== null;

        return self::digits(BridgeWire::stringOrNull(
            $isStatus ? ($payload['from'] ?? null) : ($payload['to'] ?? null),
        ));
    }

    /**
     * One event: an inbound message, or a status callback about an outbound one.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        $providerMessageId = BridgeWire::stringOrNull($payload['message_uuid'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a Vonage callback names no message_uuid, so nothing could be correlated with it',
            );
        }

        $status = BridgeWire::stringOrNull($payload['status'] ?? null);

        if ($status === null) {
            $from = self::digits(BridgeWire::stringOrNull($payload['from'] ?? null));

            if ($from === null) {
                throw WebhookVerificationException::malformedPayload(
                    ChannelMode::BspGateway,
                    'a Vonage inbound message names no sender',
                );
            }

            return [InboundEvent::message(
                mode: ChannelMode::BspGateway,
                tenantId: $credentials->tenantId,
                providerMessageId: $providerMessageId,
                from: $from,
                text: self::textOf($payload),
                channelIdentity: $identity,
                occurredAt: self::timestamp($payload['timestamp'] ?? null),
                payload: $payload,
            )];
        }

        $kind = self::receiptKind(
            $status,
            accepted: ['submitted', 'accepted'],
            delivered: ['delivered'],
            read: ['read'],
            failed: ['rejected', 'undeliverable', 'failed'],
        );

        if ($kind === null) {
            return [];
        }

        return [InboundEvent::receipt(
            kind: $kind,
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            occurredAt: self::timestamp($payload['timestamp'] ?? null),
            failureReason: $kind === InboundEventKind::SendFailure
                ? $credentials->redact(self::failureReason($payload))
                : null,
            channelIdentity: $identity,
            payload: $payload,
        )];
    }

    /*
    |--------------------------------------------------------------------------
    | Retry policy
    |--------------------------------------------------------------------------
    */

    /**
     * Vonage's `type` fragments — tokens rather than numbers.
     *
     * | Fragment | Class |
     * |---|---|
     * | `unauthorized`, `invalid-api-key`, `invalid-credentials` | `AUTH` |
     * | `forbidden`, `not-enabled`, `account-suspended` | `PERMISSION` |
     * | `throttled`, `rate-limit` | `RATE_LIMIT` |
     * | `invalid-parameters`, `bad-request`, `conflict`, `unsupported-media-type` | `VALIDATION` |
     * | `internal-error`, `service-unavailable` | `NETWORK` |
     *
     * `throttled` is the row worth naming: Vonage paces the Messages API per account, and
     * `RATE_LIMIT` **defers** rather than retries — which keeps a provider's pacing from spending a
     * budget meant for real faults (`ErrorClass::disposition()`).
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        return self::classifyByCode($e, [
            ErrorClass::Auth->value => ['unauthorized', 'invalid-api-key', 'invalid-credentials'],
            ErrorClass::Permission->value => ['forbidden', 'not-enabled', 'account-suspended'],
            ErrorClass::RateLimit->value => ['throttled', 'rate-limit'],
            ErrorClass::Validation->value => [
                'invalid-parameters', 'bad-request', 'conflict', 'unsupported-media-type', 'invalid-param',
            ],
            ErrorClass::Network->value => ['internal-error', 'service-unavailable'],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function messagesUrl(ChannelCredentials $credentials): string
    {
        return $this->baseUrl($credentials).'/v1/messages';
    }

    /**
     * The fields every Messages API body carries.
     *
     * @return array<string, mixed>
     */
    private function envelope(ChannelCredentials $credentials, string $recipient): array
    {
        return [
            'channel' => self::CHANNEL,
            'to' => $recipient,
            'from' => $this->senderIdentity($credentials),
        ];
    }

    /**
     * A JWS bearer when the account uses application auth, HTTP Basic otherwise — see the class
     * docblock for why the choice is never silent.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when a stored private key cannot sign
     */
    private function authHeaders(#[SensitiveParameter] ChannelCredentials $credentials): array
    {
        $applicationId = $credentials->config(self::APPLICATION_ID_CONFIG_KEY);
        $privateKey = $credentials->secret(self::PRIVATE_KEY_SECRET_KEY);

        if ($privateKey !== null && is_string($applicationId) && trim($applicationId) !== '') {
            return [self::AUTH_HEADER => 'Bearer '.self::mintJws(trim($applicationId), $privateKey)];
        }

        return [
            self::AUTH_HEADER => 'Basic '.base64_encode(sprintf(
                '%s:%s',
                $credentials->requireConfig(self::API_KEY_CONFIG_KEY),
                $credentials->requireSecret($this->authSecretKey()),
            )),
        ];
    }

    /**
     * One RS256 JWS for `$applicationId`, signed with `$privateKey`.
     *
     * `jti` is a fresh random identifier per token, which is what makes two tokens minted in the
     * same second distinct — Vonage rejects a replayed `jti`, and a fixed one would make the second
     * request of a burst fail.
     *
     * A key OpenSSL cannot load, or cannot sign with, is a hard failure naming the credential key:
     * it is a configuration defect the tenant can fix, and the alternative — falling back to Basic
     * — is the silent downgrade the class docblock rules out.
     *
     * @throws InvalidArgumentException when the private key cannot sign
     */
    private static function mintJws(string $applicationId, #[SensitiveParameter] string $privateKey): string
    {
        $issuedAt = time();

        $segments = self::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            .'.'
            .self::base64UrlEncode((string) json_encode([
                'application_id' => $applicationId,
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::JWS_TTL_SECONDS,
                'jti' => bin2hex(random_bytes(16)),
            ], JSON_THROW_ON_ERROR));

        $key = openssl_pkey_get_private($privateKey);
        $signature = '';

        if ($key === false || ! openssl_sign($segments, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException(sprintf(
                'The Vonage credentials carry a [%s] that OpenSSL cannot sign with. Re-paste the '
                .'application private key exactly as Vonage issued it, including its PEM header and '
                .'footer — or remove it to authenticate with the API key and secret instead.',
                self::PRIVATE_KEY_SECRET_KEY,
            ));
        }

        return $segments.'.'.self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * The fragment of an RFC 7807 `type` URI, which is where Vonage puts the machine-readable code.
     */
    private static function fragmentOf(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $position = strpos($type, '#');

        if ($position === false) {
            return null;
        }

        $fragment = trim(substr($type, $position + 1));

        return $fragment === '' ? null : $fragment;
    }

    /**
     * What the customer said, for the Messages API shapes that carry words.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function textOf(array $payload): ?string
    {
        $type = BridgeWire::stringOrNull($payload['message_type'] ?? null);

        if ($type === 'reply') {
            // A button or list reply: Vonage puts the label the customer saw in `reply.title`, and
            // the postback id beside it, which stays readable through `InboundEvent::payload()`.
            return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($payload['reply'] ?? null)['title'] ?? null);
        }

        return BridgeWire::stringOrNull($payload['text'] ?? null)
            ?? BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($payload['image'] ?? null)['caption'] ?? null);
    }

    /**
     * Why Vonage says a message failed — its error **code** only.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function failureReason(array $payload): string
    {
        $error = BridgeWire::arrayOrEmpty($payload['error'] ?? null);
        $code = BridgeWire::stringOrNull($error['code'] ?? null);

        return $code === null
            ? 'Vonage reported this message as failed without naming a code.'
            : sprintf('Vonage reported this message as failed (code %s).', $code);
    }
}
