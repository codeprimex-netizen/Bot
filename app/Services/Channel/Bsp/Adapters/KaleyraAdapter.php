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
use SensitiveParameter;

/**
 * **Kaleyra** — form-encoded sends under an account SID path segment, a bare `api-key` header, and
 * `E`-prefixed error codes (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3).
 *
 * | Axis | Kaleyra |
 * |---|---|
 * | Auth | `api-key: {key}` — a bare header, no scheme word |
 * | Send | `POST /v1/{sid}/messages`, form-encoded, `channel=whatsapp` |
 * | Template | `type=template` with `template_name` and a JSON `params` list |
 * | Probe | `GET /v1/{sid}/messages?limit=1` |
 * | Errors | `{"error": {"code": "E101", "message": …}}` — letter-prefixed tokens |
 * | Webhook | a shared secret in an operator-named header |
 * | Webhook identity | `to` on an inbound message; a status callback's `to` is the **customer** |
 *
 * ## The account SID is a path segment, so it is config and it is required
 *
 * Every Kaleyra route is `/v1/{sid}/…`. The SID identifies the account, appears in Kaleyra's own
 * console and in every URL, and is therefore an identifier rather than a secret — the same call
 * `TwilioAdapter` makes about its Account SID, and for the same reason: an audit trail has to be able
 * to name which account a send went through.
 *
 * ## The recipient claim flips meaning between the two callback shapes, so it is not required
 *
 * On an inbound message Kaleyra's `to` is the business number, and it is checked. On a **status
 * callback** `to` is the customer the message went to — the same field name, the opposite meaning —
 * so reading it there would compare a customer's number against the credentials' sender and refuse
 * every receipt. `recipientIn()` therefore answers only for the inbound shape and
 * `requiresRecipientClaim()` is `false`; the binding for a status callback is the per-credential-row
 * webhook secret, with the residual risk stated on that method.
 *
 * ## The webhook has no published MAC
 *
 * Like Infobip and WATI, Kaleyra publishes no HMAC webhook signature; a callback is protected with
 * headers the operator configures. So the check is a constant-time comparison of the stored
 * `webhook_secret` against a header — `Authorization` by default, overridable with
 * `webhook_secret_header`. `BspWebhookVerifier` sets out what that does and does not prove, and it is
 * reported as a gap in design.md rather than dressed up as a signature.
 */
final readonly class KaleyraAdapter extends BaseBspAdapter
{
    /**
     * The header Kaleyra authenticates with.
     */
    public const string API_KEY_HEADER = 'api-key';

    /**
     * The header the webhook secret is presented in when the credentials name no other.
     */
    public const string DEFAULT_WEBHOOK_HEADER = 'Authorization';

    /**
     * The `config` key holding the account SID every route is built from.
     */
    public const string SID_CONFIG_KEY = 'sid';

    /**
     * The channel every message names. Kaleyra fronts SMS and voice on the same routes.
     */
    public const string CHANNEL = 'whatsapp';

    public function provider(): BspProvider
    {
        return BspProvider::Kaleyra;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://api.kaleyra.io';
    }

    protected function authSecretKey(): string
    {
        return 'api_key';
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return [...parent::requiredConfigKeys(), self::SID_CONFIG_KEY];
    }

    /**
     * A status callback's `to` is the customer, not the business number — see the class docblock.
     *
     * Residual risk, stated: the binding for a status callback is the per-credential-row webhook
     * secret, so two tenants of this platform sharing **one** Kaleyra SID could not be separated on
     * the payload. Inbound message content is unaffected — `to` means the business number there and
     * is checked.
     */
    public function requiresRecipientClaim(): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    public function textMessage(ChannelCredentials $credentials, string $recipient, string $text): BspRequest
    {
        return BspRequest::form(
            $this->messagesUrl($credentials),
            $this->envelope($credentials, $recipient) + ['type' => 'text', 'body' => $text],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One media attachment, as a `media` message with a link Kaleyra fetches.
     *
     * Kaleyra takes one `media` type and infers the WhatsApp shape from the content type it fetches,
     * so there is no per-kind route — the same arrangement `TwilioAdapter::mediaMessage()` documents,
     * and the reason `MediaKind` is read here only to decide whether a caption and a filename are
     * fields this kind may carry at all.
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        $form = $this->envelope($credentials, $recipient) + [
            'type' => 'media',
            'media_url' => (string) $media->url,
        ];

        if ($media->caption !== null && $media->kind->usesCaption()) {
            $form['caption'] = $media->caption;
        }

        if ($media->filename !== null && $media->kind->usesFilename()) {
            $form['filename'] = $media->filename;
        }

        return BspRequest::form($this->messagesUrl($credentials), $form, $this->authHeaders($credentials));
    }

    /**
     * One approved template.
     *
     * `params` is a JSON-encoded positional list, which is Kaleyra's spelling — the order of
     * `$parameters` is the mapping onto `{{1}}, {{2}}, …`. The `lang_code` is sent so the translation
     * that goes out is the one this platform recorded as approved rather than one Kaleyra picks.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        $form = $this->envelope($credentials, $recipient) + [
            'type' => 'template',
            'template_name' => self::templateHandle($template),
            'lang_code' => $template->language,
        ];

        if ($parameters !== []) {
            $form['params'] = json_encode($parameters, JSON_THROW_ON_ERROR);
        }

        return BspRequest::form($this->messagesUrl($credentials), $form, $this->authHeaders($credentials));
    }

    /**
     * The account's message log, one row — read-only, and it proves the api-key against the SID in
     * the path, which is the pair that has to be right.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            $this->messagesUrl($credentials),
            $this->authHeaders($credentials),
            ['limit' => '1'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading what came back
    |--------------------------------------------------------------------------
    */

    /**
     * Kaleyra answers a send either flat or wrapped in `data`; both are read.
     */
    public function readSendResult(array $payload): ?BspSendResult
    {
        $data = BridgeWire::arrayOrEmpty($payload['data'] ?? null);

        return BspSendResult::tryFrom(BridgeWire::stringOrNull(
            $payload['id'] ?? $payload['message_id'] ?? $data['id'] ?? null,
        ));
    }

    /**
     * Kaleyra's error envelope, in both spellings it uses.
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        if ($status < 400) {
            return null;
        }

        $error = BridgeWire::arrayOrEmpty($payload['error'] ?? null);

        return new BspRefusal(
            code: BridgeWire::stringOrNull($error['code'] ?? $payload['code'] ?? null),
            // `error.message` and the top-level `message` are prose that quotes the request; neither
            // is read.
            type: null,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public function accountDetailIn(array $payload): ?string
    {
        if (! array_key_exists('data', $payload) && ! array_key_exists('total_count', $payload)) {
            return null;
        }

        return 'Kaleyra accepted these credentials for this account.';
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
        $verifier->assertSharedSecretHeader(
            $request,
            $this->webhookSecretHeader($credentials, self::DEFAULT_WEBHOOK_HEADER),
            $this->webhookSecret($credentials),
        );
    }

    /**
     * `to` — but only on an inbound message, where it means the business number.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        if (BridgeWire::stringOrNull($payload['status'] ?? null) !== null) {
            return null;
        }

        return self::digits(BridgeWire::stringOrNull($payload['to'] ?? null));
    }

    /**
     * One event: an inbound customer message, or a status callback about an outbound one.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        $providerMessageId = BridgeWire::stringOrNull($payload['id'] ?? $payload['message_id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a Kaleyra callback carries no message id, so nothing could be correlated with it',
            );
        }

        $status = BridgeWire::stringOrNull($payload['status'] ?? null);

        if ($status === null) {
            $from = self::digits(BridgeWire::stringOrNull($payload['from'] ?? null));

            if ($from === null) {
                throw WebhookVerificationException::malformedPayload(
                    ChannelMode::BspGateway,
                    'a Kaleyra inbound message names no sender',
                );
            }

            return [InboundEvent::message(
                mode: ChannelMode::BspGateway,
                tenantId: $credentials->tenantId,
                providerMessageId: $providerMessageId,
                from: $from,
                text: self::textOf($payload),
                channelIdentity: $identity,
                occurredAt: self::timestamp($payload['created_at'] ?? $payload['timestamp'] ?? null),
                payload: $payload,
            )];
        }

        $kind = self::receiptKind(
            $status,
            accepted: ['queued', 'submitted', 'sent'],
            delivered: ['delivered'],
            read: ['read'],
            failed: ['failed', 'undelivered', 'rejected', 'expired'],
        );

        if ($kind === null) {
            return [];
        }

        return [InboundEvent::receipt(
            kind: $kind,
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            occurredAt: self::timestamp($payload['updated_at'] ?? $payload['timestamp'] ?? null),
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
     * Kaleyra's `E`-prefixed tokens, plus the kebab-case ones its newer routes return.
     *
     * | Token | Class |
     * |---|---|
     * | `E101`, `E102`, `E103`, `authentication_failed`, `invalid_api_key` | `AUTH` |
     * | `E104`, `account_suspended`, `insufficient_balance` | `PERMISSION` |
     * | `E429`, `rate_limit_exceeded` | `RATE_LIMIT` |
     * | `E201`, `E202`, `E203`, `invalid_parameter`, `invalid_number` | `VALIDATION` |
     * | `E500`, `internal_error` | `NETWORK` |
     *
     * `insufficient_balance` is `PERMISSION` — zero attempts — for `MessageBirdAdapter`'s reason: an
     * unfunded account fails every send until somebody tops it up, and a retry budget spent on it is
     * a queue that never drains.
     *
     * Anything else falls through to `null` and the shared status fallback, which for Kaleyra is the
     * same statement one step later. `refusalIn()` also carries a numeric HTTP-shaped `code` when
     * Kaleyra sends one there, and those are covered by the `E`-less rows.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        return self::classifyByCode($e, [
            ErrorClass::Auth->value => ['E101', 'E102', 'E103', 'authentication_failed', 'invalid_api_key', 401],
            ErrorClass::Permission->value => ['E104', 'account_suspended', 'insufficient_balance', 403],
            ErrorClass::RateLimit->value => ['E429', 'rate_limit_exceeded', 429],
            ErrorClass::Validation->value => ['E201', 'E202', 'E203', 'invalid_parameter', 'invalid_number', 400, 422],
            ErrorClass::Network->value => ['E500', 'internal_error', 500, 502, 503],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function messagesUrl(ChannelCredentials $credentials): string
    {
        return sprintf(
            '%s/v1/%s/messages',
            $this->baseUrl($credentials),
            rawurlencode($credentials->requireConfig(self::SID_CONFIG_KEY)),
        );
    }

    /**
     * The form fields every Kaleyra send carries.
     *
     * @return array<string, string>
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
     * @return array<string, string>
     */
    private function authHeaders(#[SensitiveParameter] ChannelCredentials $credentials): array
    {
        return [self::API_KEY_HEADER => $credentials->requireSecret($this->authSecretKey())];
    }

    /**
     * What the customer said.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function textOf(array $payload): ?string
    {
        return BridgeWire::stringOrNull($payload['body'] ?? null)
            ?? BridgeWire::stringOrNull($payload['text'] ?? null)
            ?? BridgeWire::stringOrNull($payload['caption'] ?? null);
    }

    /**
     * Why Kaleyra says a message failed — its own error **code** only.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function failureReason(array $payload): string
    {
        $code = BridgeWire::stringOrNull($payload['error_code'] ?? null)
            ?? BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($payload['error'] ?? null)['code'] ?? null);

        return $code === null
            ? 'Kaleyra reported this message as failed without naming a code.'
            : sprintf('Kaleyra reported this message as failed (code %s).', $code);
    }
}
