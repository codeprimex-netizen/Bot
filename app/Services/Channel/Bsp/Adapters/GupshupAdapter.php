<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp\Adapters;

use App\Enums\BspProvider;
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
 * **Gupshup** — form-encoded sends with a JSON message document inside one form field, and the one
 * partner here whose webhook identifies its recipient by **application name** rather than by phone
 * number (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3).
 *
 * | Axis | Gupshup |
 * |---|---|
 * | Auth | `apikey: {key}` — a bare header, no scheme word |
 * | Send | `POST /wa/api/v1/msg`, form-encoded, with the message itself as JSON in `message` |
 * | Template | `POST /wa/api/v1/template/msg`, `template={"id": …, "params": […]}` |
 * | Probe | `GET /wa/app/{app}/wallet/balance` |
 * | Errors | `{"status": "error", "message": …}` — the status word, and an optional numeric `code` |
 * | Webhook | `X-Gupshup-Signature`: **base64** HMAC-SHA256 over the raw body |
 * | Webhook identity | the top-level `app` — an application **name**, not a number |
 *
 * ## The recipient identity is an application name, and that is not a cosmetic difference
 *
 * Every other partner here identifies the business end of a conversation by its phone number, so
 * `BaseBspAdapter::senderIdentity()` reduces the credentials' `sender` to E.164 digits and the
 * payload's claim is reduced the same way. Gupshup's callback names no business number at all: it
 * names the **app** the message arrived on (`{"app": "AcmeSupport", …}`), which is the unit a
 * Gupshup API key is scoped to.
 *
 * So both sides of the recipient check are overridden to a case-folded application name, and the
 * comparison stays `hash_equals()` on like things. Getting this wrong in either direction has a
 * failure mode: comparing digits to a name would refuse **every** Gupshup callback, and skipping
 * the check would let a Gupshup partner account fronting two tenants' apps deliver one tenant's
 * inbound traffic onto the other's session (Req 8.4, Property 23).
 *
 * Case-folded because Gupshup echoes the app name as the tenant typed it into its console, and a
 * capitalisation difference between the console and this platform's credential form is a
 * configuration typo that must not read as a cross-tenant attack.
 *
 * ## `INTERACTIVE` is `Native` here
 *
 * Gupshup is one of design § 2.3's Cloud-API-equivalent partners
 * (`BspProvider::isCloudApiEquivalent()`), so buttons and lists are first-class rather than
 * reachable only through a partner content template — layer 2 resolves the `⚠️` to `✅`, and this
 * adapter's account layer can only narrow it (an app with `interactive_enabled = false`).
 */
final readonly class GupshupAdapter extends BaseBspAdapter
{
    /**
     * The header Gupshup authenticates with, and the one it signs a callback in.
     */
    public const string API_KEY_HEADER = 'apikey';

    public const string SIGNATURE_HEADER = 'X-Gupshup-Signature';

    /**
     * The `config` key naming the Gupshup **app** these credentials belong to.
     *
     * Required, and load-bearing twice over: it is `src.name` on every send (Gupshup rejects a
     * send without it) and it is the recipient identity of every callback.
     */
    public const string APP_NAME_CONFIG_KEY = 'app_name';

    /**
     * The channel every route is told to use. Gupshup fronts several; this platform sends WhatsApp.
     */
    public const string CHANNEL = 'whatsapp';

    public function provider(): BspProvider
    {
        return BspProvider::Gupshup;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://api.gupshup.io';
    }

    protected function authSecretKey(): string
    {
        return 'apikey';
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return [...parent::requiredConfigKeys(), self::APP_NAME_CONFIG_KEY];
    }

    /**
     * The Gupshup app name, case-folded — see the class docblock.
     *
     * @throws InvalidArgumentException when the credentials name no app
     */
    public function senderIdentity(ChannelCredentials $credentials): string
    {
        return self::foldedAppName($credentials->requireConfig(self::APP_NAME_CONFIG_KEY));
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    public function textMessage(ChannelCredentials $credentials, string $recipient, string $text): BspRequest
    {
        return $this->messageRequest($credentials, $recipient, ['type' => 'text', 'text' => $text]);
    }

    /**
     * One media attachment, in Gupshup's per-kind message document.
     *
     * Gupshup names its media fields differently per kind — an image carries `originalUrl` and
     * `previewUrl`, a document carries `url` and `filename` under the type `file` — so this is a
     * genuine per-kind mapping rather than one shape with a type label. A sticker uses `url`
     * under the type `sticker` and carries no caption, which `MediaPayload` has already refused
     * to attach.
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        $url = (string) $media->url;

        $message = match ($media->kind) {
            MediaKind::Image => ['type' => 'image', 'originalUrl' => $url, 'previewUrl' => $url],
            MediaKind::Video => ['type' => 'video', 'url' => $url],
            MediaKind::Audio => ['type' => 'audio', 'url' => $url],
            MediaKind::Document => ['type' => 'file', 'url' => $url, 'filename' => $media->filename ?? 'document'],
            MediaKind::Sticker => ['type' => 'sticker', 'url' => $url],
        };

        if ($media->caption !== null && $media->kind->usesCaption()) {
            $message['caption'] = $media->caption;
        }

        return $this->messageRequest($credentials, $recipient, $message);
    }

    /**
     * One approved template, on Gupshup's own template route.
     *
     * Gupshup addresses a template by **its** id when task 8.4's sync recorded one and by name
     * otherwise — `BaseBspAdapter::templateHandle()` is that fallback — and takes the placeholder
     * values as a positional `params` list, which is what `$parameters` already is.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        return BspRequest::form(
            $this->baseUrl($credentials).'/wa/api/v1/template/msg',
            $this->envelope($credentials, $recipient) + [
                'template' => json_encode([
                    'id' => self::templateHandle($template),
                    'params' => $parameters,
                ], JSON_THROW_ON_ERROR),
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * The app's wallet balance — read-only, and the only route a Gupshup app key can be asked
     * about itself with.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            sprintf(
                '%s/wa/app/%s/wallet/balance',
                $this->baseUrl($credentials),
                rawurlencode($credentials->requireConfig(self::APP_NAME_CONFIG_KEY)),
            ),
            $this->authHeaders($credentials),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading what came back
    |--------------------------------------------------------------------------
    */

    public function readSendResult(array $payload): ?BspSendResult
    {
        return BspSendResult::tryFrom(BridgeWire::stringOrNull($payload['messageId'] ?? null));
    }

    /**
     * A refusal, including the ones Gupshup delivers with a **2xx**.
     *
     * Gupshup answers an accepted send `{"status": "submitted", "messageId": …}` and a rejected one
     * `{"status": "error", "message": …}` — and it does not reliably use a non-2xx status for the
     * second. So the status word is read on every response, which is the same reason WATI's
     * adapter reads `result`: a driver that only built a refusal from `$response->failed()` would
     * record a message as sent that the partner never accepted.
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        $word = strtolower((string) BridgeWire::stringOrNull($payload['status'] ?? null));

        if ($status < 400 && $word !== 'error') {
            return null;
        }

        return new BspRefusal(
            // Gupshup's `code`, when it sends one, mirrors the HTTP status; `message` is prose and
            // is not read.
            code: BridgeWire::stringOrNull($payload['code'] ?? null) ?? (string) $status,
            type: null,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public function accountDetailIn(array $payload): ?string
    {
        if (strtolower((string) BridgeWire::stringOrNull($payload['status'] ?? null)) !== 'success') {
            return null;
        }

        // The balance itself is deliberately not repeated: it is commercial data that ends up on a
        // tenant-facing connection screen and in an audit row, and it says nothing about whether
        // the credentials work beyond the fact that Gupshup answered.
        return 'Gupshup accepted these credentials for this application.';
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    public function verifyWebhook(
        Request $request,
        ChannelCredentials $credentials,
        BspWebhookVerifier $verifier,
    ): void {
        $verifier->assertHmacBase64(
            $request,
            self::SIGNATURE_HEADER,
            $this->webhookSecret($credentials),
        );
    }

    /**
     * The `app` this callback is about, case-folded.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        $app = BridgeWire::stringOrNull($payload['app'] ?? null);

        return $app === null ? null : self::foldedAppName($app);
    }

    /**
     * One event: an inbound message, or a message event about an outbound one.
     *
     * Gupshup labels the two with a top-level `type`. A `user-event` (opt-in, opt-out) or a
     * `billing-event` is verified and consumed by nobody in this release, which is `[]` — a `200`
     * so Gupshup stops retrying, not a 403 on every Gupshup feature.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        $type = strtolower((string) BridgeWire::stringOrNull($payload['type'] ?? null));
        $inner = BridgeWire::arrayOrEmpty($payload['payload'] ?? null);

        if ($type === 'message') {
            return [$this->messageEvent($inner, $credentials, $identity)];
        }

        if ($type !== 'message-event') {
            return [];
        }

        $providerMessageId = BridgeWire::stringOrNull($inner['gsId'] ?? $inner['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a Gupshup message event names no id, so it says nothing about which message it is about',
            );
        }

        $kind = self::receiptKind(
            BridgeWire::stringOrNull($inner['type'] ?? null),
            accepted: ['enqueued', 'sent', 'submitted'],
            delivered: ['delivered'],
            read: ['read'],
            failed: ['failed', 'deleted'],
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
                ? $credentials->redact(self::failureReason($inner))
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
     * Gupshup's refusals are carried by the HTTP status, which `refusalIn()` puts in the code
     * when Gupshup sent no code of its own — so the table is keyed on those.
     *
     * | Code | Class |
     * |---|---|
     * | `401` | `AUTH` — the app key was rejected |
     * | `403` | `PERMISSION` — the key is valid and not permitted on this app |
     * | `429` | `RATE_LIMIT` |
     * | `400`, `422` | `VALIDATION` |
     * | `500`, `502`, `503` | `NETWORK` |
     *
     * A code Gupshup does send that is not one of these falls through to `null`, and
     * `BspGatewayErrorClassifier` reads the status — which for Gupshup is the same statement one
     * step later.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        return self::classifyByCode($e, [
            ErrorClass::Auth->value => [401],
            ErrorClass::Permission->value => [403],
            ErrorClass::RateLimit->value => [429],
            ErrorClass::Validation->value => [400, 422],
            ErrorClass::Network->value => [500, 502, 503],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A send on the plain-message route, with `$message` JSON-encoded into the `message` field.
     *
     * @param  array<string, mixed>  $message
     */
    private function messageRequest(
        ChannelCredentials $credentials,
        string $recipient,
        array $message,
    ): BspRequest {
        return BspRequest::form(
            $this->baseUrl($credentials).'/wa/api/v1/msg',
            $this->envelope($credentials, $recipient) + [
                'message' => json_encode($message, JSON_THROW_ON_ERROR),
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * The form fields every Gupshup send carries.
     *
     * `source` is the sender **number** and `src.name` the app name — Gupshup requires both, and
     * they come from two different credential keys, which is why `senderIdentity()` being the app
     * name does not remove the need for `sender`.
     *
     * @return array<string, string>
     */
    private function envelope(ChannelCredentials $credentials, string $recipient): array
    {
        $sender = self::digits($credentials->requireConfig(self::SENDER_CONFIG_KEY));

        if ($sender === null) {
            throw new InvalidArgumentException(sprintf(
                'The Gupshup credentials name a [%s] that is not an E.164 number; Gupshup rejects a '
                .'send whose `source` is not the app\'s own number.',
                self::SENDER_CONFIG_KEY,
            ));
        }

        return [
            'channel' => self::CHANNEL,
            'source' => $sender,
            'destination' => $recipient,
            'src.name' => $credentials->requireConfig(self::APP_NAME_CONFIG_KEY),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(#[SensitiveParameter] ChannelCredentials $credentials): array
    {
        return [self::API_KEY_HEADER => $credentials->requireSecret($this->authSecretKey())];
    }

    /**
     * One `payload` of a `message` callback as a canonical message event.
     *
     * @param  array<string, mixed>  $inner
     *
     * @throws WebhookVerificationException when the message names no id or no sender
     */
    private function messageEvent(
        array $inner,
        ChannelCredentials $credentials,
        string $identity,
    ): InboundEvent {
        $providerMessageId = BridgeWire::stringOrNull($inner['id'] ?? null);
        $from = self::digits(BridgeWire::stringOrNull($inner['source'] ?? null));

        if ($providerMessageId === null || $from === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a Gupshup message names no id or no source number, so nothing could be correlated '
                .'with it',
            );
        }

        return InboundEvent::message(
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            from: $from,
            text: self::textOf($inner),
            channelIdentity: $identity,
            payload: $inner,
        );
    }

    /**
     * What the customer said. Gupshup nests the content one level deeper than the message.
     *
     * A button or list reply carries its `title` and its postback `payload`; the title is what the
     * conversation engine reads, and the postback stays available through `InboundEvent::payload()`
     * for a flow to correlate on — `CloudApiChannelDriver::textOf()`'s convention.
     *
     * @param  array<string, mixed>  $inner
     */
    private static function textOf(array $inner): ?string
    {
        $content = BridgeWire::arrayOrEmpty($inner['payload'] ?? null);

        return BridgeWire::stringOrNull($content['text'] ?? null)
            ?? BridgeWire::stringOrNull($content['title'] ?? null)
            ?? BridgeWire::stringOrNull($content['caption'] ?? null);
    }

    /**
     * Why Gupshup says a message failed — its own reason **code** only.
     *
     * @param  array<string, mixed>  $inner
     */
    private static function failureReason(array $inner): string
    {
        $code = BridgeWire::stringOrNull($inner['code'] ?? null)
            ?? BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($inner['payload'] ?? null)['code'] ?? null);

        return $code === null
            ? 'Gupshup reported this message as failed without naming a code.'
            : sprintf('Gupshup reported this message as failed (code %s).', $code);
    }

    /**
     * An application name reduced to its comparable form.
     *
     * Case-folded and trimmed, and nothing else: a Gupshup app name is an identifier the tenant
     * chose, so normalising further (stripping punctuation, say) would make two genuinely
     * different apps compare equal.
     */
    private static function foldedAppName(string $app): string
    {
        return mb_strtolower(trim($app));
    }
}
