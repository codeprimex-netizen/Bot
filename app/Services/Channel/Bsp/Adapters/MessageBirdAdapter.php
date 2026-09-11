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
 * **MessageBird** (Bird) — the Conversations API: a `channel_id` instead of a sender number, and a
 * signed-webhook JWT that binds **both** the body and the callback URL
 * (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3).
 *
 * | Axis | MessageBird |
 * |---|---|
 * | Auth | `Authorization: AccessKey {key}` — its own scheme word, neither Basic nor Bearer |
 * | Send | `POST /v1/send`, JSON, addressed `from: {channelId}` |
 * | Template | `type: hsm` with a namespace, a template name and positional `params` |
 * | Probe | `GET /v1/channels` |
 * | Errors | `{"errors": [{"code": 2, "description": …}]}` — small integers |
 * | Webhook | `MessageBird-Signature-JWT`: HS256 JWT with `payload_hash` **and** `url_hash` |
 * | Webhook identity | `message.channelId` — a channel id, not a phone number |
 *
 * ## The identity is a channel id, and the credentials must carry it
 *
 * A MessageBird WhatsApp number is reached through a **channel**, and both the send (`from`) and
 * every callback (`message.channelId`) name that channel rather than the number. So
 * `senderIdentity()` is overridden to the stored `channel_id`, and the `sender` number is kept as
 * ordinary config for the panel to display — comparing a channel id against a phone number would
 * refuse every MessageBird callback.
 *
 * The comparison is case-folded: a MessageBird channel id is a UUID, which its console renders
 * lower-case and an operator may paste either way, and a capitalisation difference is a typo rather
 * than a cross-tenant attack.
 *
 * ## `url_hash` is why this partner's webhook is the strongest of the eight
 *
 * The JWT covers a digest of the body *and* a digest of the full callback URL, so a captured
 * callback cannot be replayed against a different `route_key` — which is the one thing a
 * body-only MAC (360dialog's, Gupshup's) does not prevent. `BspWebhookVerifier::assertSignedJwt()`
 * is asked for both, and refuses a token that carries only one.
 */
final readonly class MessageBirdAdapter extends BaseBspAdapter
{
    /**
     * The header MessageBird authenticates with, and its scheme word.
     */
    public const string AUTH_HEADER = 'Authorization';

    public const string AUTH_SCHEME = 'AccessKey';

    /**
     * The header MessageBird presents its signed-webhook token in.
     */
    public const string SIGNATURE_HEADER = 'MessageBird-Signature-JWT';

    /**
     * The `config` key holding the Conversations channel id — the identity of both halves.
     */
    public const string CHANNEL_ID_CONFIG_KEY = 'channel_id';

    /**
     * The `config` key holding the WhatsApp template namespace an `hsm` send needs.
     *
     * MessageBird requires it separately from the template name, because a namespace is the WABA
     * the template was approved under and one MessageBird account can front several.
     */
    public const string TEMPLATE_NAMESPACE_CONFIG_KEY = 'template_namespace';

    public function provider(): BspProvider
    {
        return BspProvider::MessageBird;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://conversations.messagebird.com';
    }

    protected function authSecretKey(): string
    {
        return 'access_key';
    }

    /**
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return [...parent::requiredConfigKeys(), self::CHANNEL_ID_CONFIG_KEY];
    }

    /**
     * The Conversations channel id, case-folded — see the class docblock.
     *
     * @throws InvalidArgumentException when the credentials name no channel
     */
    public function senderIdentity(ChannelCredentials $credentials): string
    {
        return mb_strtolower(trim($credentials->requireConfig(self::CHANNEL_ID_CONFIG_KEY)));
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    public function textMessage(ChannelCredentials $credentials, string $recipient, string $text): BspRequest
    {
        return BspRequest::json(
            $this->sendUrl($credentials),
            $this->envelope($credentials, $recipient) + [
                'type' => 'text',
                'content' => ['text' => $text],
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One media attachment, in MessageBird's `content.{type}` object.
     *
     * A document is `file`, and a sticker is sent as an image: the Conversations API has no sticker
     * type, and a `.webp` sent as an image is what MessageBird itself does with one — so this is a
     * documented downgrade rather than a refusal, and the caption `MediaPayload` refuses to attach
     * to a sticker keeps it from arriving with text the caller never asked for.
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        $type = match ($media->kind) {
            MediaKind::Image, MediaKind::Sticker => 'image',
            MediaKind::Video => 'video',
            MediaKind::Audio => 'audio',
            MediaKind::Document => 'file',
        };

        $attachment = ['url' => (string) $media->url];

        if ($media->caption !== null && $media->kind->usesCaption()) {
            $attachment['caption'] = $media->caption;
        }

        return BspRequest::json(
            $this->sendUrl($credentials),
            $this->envelope($credentials, $recipient) + [
                'type' => $type,
                'content' => [$type => $attachment],
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One approved template, as MessageBird's `hsm`.
     *
     * The language policy is `deterministic` for `VonageAdapter::templateMessage()`'s reason: it
     * pins the translation to the one this platform recorded as approved instead of letting the
     * partner choose from the recipient's device locale.
     *
     * `params` is a list of `{"default": …}` objects, which is MessageBird's spelling of a
     * positional parameter — the order of `$parameters` is the mapping.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        $namespace = $credentials->config(self::TEMPLATE_NAMESPACE_CONFIG_KEY);

        $hsm = [
            'templateName' => self::templateHandle($template),
            'language' => ['policy' => 'deterministic', 'code' => $template->language],
            'params' => array_map(
                static fn (string $value): array => ['default' => $value],
                $parameters,
            ),
        ];

        if (is_string($namespace) && trim($namespace) !== '') {
            $hsm['namespace'] = trim($namespace);
        }

        return BspRequest::json(
            $this->sendUrl($credentials),
            $this->envelope($credentials, $recipient) + [
                'type' => 'hsm',
                'content' => ['hsm' => $hsm],
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * The account's channels — read-only, and the route that proves both the access key and that
     * the configured channel exists.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            $this->baseUrl($credentials).'/v1/channels',
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
        return BspSendResult::tryFrom(
            BridgeWire::stringOrNull($payload['id'] ?? null),
            self::digits(BridgeWire::stringOrNull($payload['to'] ?? null)),
        );
    }

    /**
     * MessageBird's error list. Only the first entry is read: the codes in one refusal describe one
     * request, and the fate of the message is decided by the first thing that was wrong with it.
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        if ($status < 400) {
            return null;
        }

        $error = BridgeWire::arrayOrEmpty(BridgeWire::listOrEmpty($payload['errors'] ?? null)[0] ?? null);

        return new BspRefusal(
            code: BridgeWire::stringOrNull($error['code'] ?? null),
            // `parameter` names the field that was wrong — an identifier from MessageBird's own
            // schema, so it quotes nothing of the request's values. `description` does, and is not
            // read.
            type: BridgeWire::stringOrNull($error['parameter'] ?? null),
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public function accountDetailIn(array $payload): ?string
    {
        if (! array_key_exists('items', $payload) && ! array_key_exists('count', $payload)) {
            return null;
        }

        return 'MessageBird accepted these credentials for this account\'s channels.';
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify the signed-webhook JWT, binding the body **and** the URL.
     */
    public function verifyWebhook(
        Request $request,
        ChannelCredentials $credentials,
        BspWebhookVerifier $verifier,
    ): void {
        $verifier->assertSignedJwt(
            $request,
            self::SIGNATURE_HEADER,
            $this->webhookSecret($credentials),
            bindsUrl: true,
        );
    }

    /**
     * `message.channelId`, case-folded — present on every Conversations webhook, which is why
     * `requiresRecipientClaim()` stays `true`.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        $message = BridgeWire::arrayOrEmpty($payload['message'] ?? null);
        $channelId = BridgeWire::stringOrNull($message['channelId'] ?? $payload['channelId'] ?? null);

        return $channelId === null ? null : mb_strtolower($channelId);
    }

    /**
     * One event: a received message, or a status update about a sent one.
     *
     * MessageBird distinguishes them by `message.direction`, not by the webhook `type`: a
     * `message.updated` about a *received* message is a read receipt from the customer's side and
     * has nothing to update on this platform, so only the `sent` direction produces a receipt.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        $message = BridgeWire::arrayOrEmpty($payload['message'] ?? null);
        $providerMessageId = BridgeWire::stringOrNull($message['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a MessageBird callback carries no message id, so nothing could be correlated with it',
            );
        }

        $direction = strtolower((string) BridgeWire::stringOrNull($message['direction'] ?? null));
        $occurredAt = self::timestamp($message['updatedDatetime'] ?? $message['createdDatetime'] ?? null);

        if ($direction === 'received' || $direction === 'inbound') {
            $from = self::digits(BridgeWire::stringOrNull($message['from'] ?? null));

            if ($from === null) {
                throw WebhookVerificationException::malformedPayload(
                    ChannelMode::BspGateway,
                    'a MessageBird received message names no sender',
                );
            }

            return [InboundEvent::message(
                mode: ChannelMode::BspGateway,
                tenantId: $credentials->tenantId,
                providerMessageId: $providerMessageId,
                from: $from,
                text: self::textOf(BridgeWire::arrayOrEmpty($message['content'] ?? null)),
                channelIdentity: $identity,
                occurredAt: $occurredAt,
                payload: $payload,
            )];
        }

        $kind = self::receiptKind(
            BridgeWire::stringOrNull($message['status'] ?? null),
            accepted: ['accepted', 'pending', 'sent'],
            delivered: ['delivered'],
            read: ['read'],
            failed: ['failed', 'rejected', 'deleted'],
        );

        if ($kind === null) {
            return [];
        }

        return [InboundEvent::receipt(
            kind: $kind,
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            occurredAt: $occurredAt,
            failureReason: $kind === InboundEventKind::SendFailure
                ? $credentials->redact(self::failureReason($message))
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
     * MessageBird's small integer codes.
     *
     * | Code | Meaning | Class |
     * |---|---|---|
     * | `2` | request not allowed — the access key was rejected | `AUTH` |
     * | `25`, `30` | not enough balance, or the product is not enabled on the account | `PERMISSION` |
     * | `9`, `10`, `20`, `21` | missing or invalid parameter, unknown resource, no route to the destination | `VALIDATION` |
     * | `98`, `99` | MessageBird's own internal error | `NETWORK` |
     *
     * `25` is `PERMISSION` rather than `VALIDATION` deliberately: an out-of-balance account fails
     * every send until somebody tops it up, so it must **not** retry (`ErrorClass::Permission` is
     * zero attempts) — a retry budget spent on an unfunded account is a queue that never drains and
     * an alert that never fires for the real reason.
     *
     * There is no rate-limit code in the table because MessageBird paces with an HTTP `429` and no
     * body code, which the status fallback in `BspGatewayErrorClassifier` handles.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        return self::classifyByCode($e, [
            ErrorClass::Auth->value => [2],
            ErrorClass::Permission->value => [25, 30],
            ErrorClass::Validation->value => [9, 10, 20, 21],
            ErrorClass::Network->value => [98, 99],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function sendUrl(ChannelCredentials $credentials): string
    {
        return $this->baseUrl($credentials).'/v1/send';
    }

    /**
     * The fields every `/v1/send` body carries.
     *
     * `to` keeps the `+` MessageBird expects on an E.164 address, which is the one place this
     * adapter puts anything back that `digits()` took off.
     *
     * @return array<string, mixed>
     */
    private function envelope(ChannelCredentials $credentials, string $recipient): array
    {
        return [
            'to' => '+'.$recipient,
            'from' => $credentials->requireConfig(self::CHANNEL_ID_CONFIG_KEY),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(#[SensitiveParameter] ChannelCredentials $credentials): array
    {
        return [
            self::AUTH_HEADER => self::AUTH_SCHEME.' '.$credentials->requireSecret($this->authSecretKey()),
        ];
    }

    /**
     * What the customer said, out of a Conversations `content` object.
     *
     * @param  array<string, mixed>  $content
     */
    private static function textOf(array $content): ?string
    {
        $text = BridgeWire::stringOrNull($content['text'] ?? null);

        if ($text !== null) {
            return $text;
        }

        foreach (['image', 'video', 'file', 'audio'] as $shape) {
            $caption = BridgeWire::stringOrNull(
                BridgeWire::arrayOrEmpty($content[$shape] ?? null)['caption'] ?? null,
            );

            if ($caption !== null) {
                return $caption;
            }
        }

        // An interactive reply: MessageBird echoes the pressed label in `interactive.reply.title`.
        return BridgeWire::stringOrNull(
            BridgeWire::arrayOrEmpty(
                BridgeWire::arrayOrEmpty($content['interactive'] ?? null)['reply'] ?? null,
            )['title'] ?? null,
        );
    }

    /**
     * Why MessageBird says a message failed — its own error **code** only.
     *
     * @param  array<string, mixed>  $message
     */
    private static function failureReason(array $message): string
    {
        $code = BridgeWire::stringOrNull(
            BridgeWire::arrayOrEmpty($message['error'] ?? null)['code'] ?? null,
        );

        return $code === null
            ? 'MessageBird reported this message as failed without naming a code.'
            : sprintf('MessageBird reported this message as failed (code %s).', $code);
    }
}
