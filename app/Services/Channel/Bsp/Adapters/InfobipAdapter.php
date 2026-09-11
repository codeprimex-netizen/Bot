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
 * **Infobip** — a per-account API host, one route per media kind, and error codes that are
 * **identifiers** rather than numbers (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3).
 *
 * | Axis | Infobip |
 * |---|---|
 * | Auth | `Authorization: App {key}` — its own scheme word |
 * | Base URL | **per account** — Infobip issues `{id}.api.infobip.com`, so `base_url` is not optional |
 * | Send | `POST /whatsapp/1/message/{kind}`, JSON — a different path per media kind |
 * | Template | `POST /whatsapp/1/message/template`, with `messages[]` and `templateData.body.placeholders` |
 * | Probe | `GET /account/1/balance` |
 * | Errors | `{"requestError": {"serviceException": {"messageId": "UNAUTHORIZED", …}}}` |
 * | Webhook | a shared secret in an operator-named header — see below |
 * | Webhook identity | `results[].to` on an inbound message; a delivery report names none |
 *
 * ## `messageId` is Infobip's error code, and it is a word
 *
 * Infobip's `serviceException.messageId` is a token — `UNAUTHORIZED`, `TOO_MANY_REQUESTS`,
 * `BAD_REQUEST` — not a number, which is why `ChannelRequestFailedException::hasErrorCode()` accepts
 * strings and why `classify()` below reads words. The field name is misleading: it is not the id of
 * a message, it is the id of the error message, and reading it as a correlation handle would put an
 * error token where a `wamid` belongs.
 *
 * `serviceException.text` is the prose beside it and is **not** read: it quotes the request, and on
 * an authentication failure it quotes the key it was sent (`ChannelRequestFailedException`'s rule).
 *
 * ## The webhook has no MAC, and that is Infobip's protocol rather than a shortcut
 *
 * Infobip publishes no HMAC webhook signature. Its documented way of protecting a callback is HTTP
 * auth or custom headers the operator configures on the callback itself, so this adapter verifies a
 * constant-time match of the stored `webhook_secret` against a header — `Authorization` by default,
 * overridable per credential set with `webhook_secret_header` because the operator chooses it at
 * Infobip.
 *
 * `BspWebhookVerifier` sets out what that costs: possession of the secret is proved, and nothing is
 * bound to the body, so a captured callback can be replayed. Inventing a signature scheme would be
 * worse — it would verify nothing while looking like verification. This is reported as a gap in
 * design.md rather than papered over.
 *
 * ## The recipient claim exists on one callback shape and not the other
 *
 * An inbound message names the business number in `results[].to`, and it is checked. A **delivery
 * report** names only the destination and the message id — there is no business identity in it at
 * all — so `requiresRecipientClaim()` is `false` and a report is accepted on the strength of the
 * per-credential webhook secret. See that method for the residual risk.
 */
final readonly class InfobipAdapter extends BaseBspAdapter
{
    /**
     * The header Infobip authenticates with, and its scheme word.
     */
    public const string AUTH_HEADER = 'Authorization';

    public const string AUTH_SCHEME = 'App';

    /**
     * The header the webhook secret is presented in when the credentials name no other.
     *
     * `Authorization` because that is what an operator configuring callback auth at Infobip will
     * usually fill in; `BspWebhookVerifier::assertSharedSecretHeader()` tolerates a `Bearer `
     * presentation so either spelling verifies.
     */
    public const string DEFAULT_WEBHOOK_HEADER = 'Authorization';

    /**
     * The WhatsApp API version segment Infobip's message routes carry.
     */
    public const string API_VERSION = '1';

    public function provider(): BspProvider
    {
        return BspProvider::Infobip;
    }

    /**
     * The shape of an Infobip host, not a host that will work.
     *
     * Infobip issues a **per-account** base URL, so this default exists only so that a missing
     * `base_url` produces a clean 401/404 from Infobip's shared host rather than a malformed URL —
     * and `requiredConfigKeys()` puts `base_url` among the keys `healthCheck()` refuses without,
     * so a tenant is told to enter theirs before anything is sent.
     */
    public function defaultBaseUrl(): string
    {
        return 'https://api.infobip.com';
    }

    protected function authSecretKey(): string
    {
        return 'api_key';
    }

    /**
     * A delivery report carries no business identity — see the class docblock.
     *
     * What binds one to the tenant is that the webhook secret is per credential row, i.e. per
     * `(tenant, BSP_GATEWAY, INFOBIP)`. Residual risk, stated: two tenants of this platform sharing
     * **one** Infobip account share that secret, and their delivery reports would then be mutually
     * acceptable. Inbound message content is unaffected — `results[].to` is present there and is
     * checked.
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
        return BspRequest::json(
            $this->messageUrl($credentials, 'text'),
            $this->envelope($credentials, $recipient) + ['content' => ['text' => $text]],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One media attachment, on the route named after its kind.
     *
     * Infobip has a **path per kind** rather than a type field — `/message/image`,
     * `/message/document`, `/message/audio`, `/message/video`, `/message/sticker` — and each takes
     * `content.mediaUrl`. A document also carries `filename`, which is the name the recipient sees;
     * audio and stickers carry no caption, which `MediaPayload` has already enforced.
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        $route = match ($media->kind) {
            MediaKind::Image => 'image',
            MediaKind::Video => 'video',
            MediaKind::Audio => 'audio',
            MediaKind::Document => 'document',
            MediaKind::Sticker => 'sticker',
        };

        $content = ['mediaUrl' => (string) $media->url];

        if ($media->caption !== null && $media->kind->usesCaption()) {
            $content['caption'] = $media->caption;
        }

        if ($media->filename !== null && $media->kind->usesFilename()) {
            $content['filename'] = $media->filename;
        }

        return BspRequest::json(
            $this->messageUrl($credentials, $route),
            $this->envelope($credentials, $recipient) + ['content' => $content],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One approved template.
     *
     * The template route is the one Infobip shape that wraps the message in a `messages[]` list even
     * for a single send, so it does not reuse `envelope()`. Placeholders are positional strings
     * under `templateData.body.placeholders`, which is what `$parameters` already is.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        return BspRequest::json(
            $this->messageUrl($credentials, 'template'),
            [
                'messages' => [[
                    'from' => $this->senderIdentity($credentials),
                    'to' => $recipient,
                    'content' => [
                        'templateName' => self::templateHandle($template),
                        'templateData' => ['body' => ['placeholders' => $parameters]],
                        'language' => $template->language,
                    ],
                ]],
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * The account balance — read-only, and the smallest route that proves an Infobip key works on
     * the host it was issued for.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            $this->baseUrl($credentials).'/account/1/balance',
            $this->authHeaders($credentials),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading what came back
    |--------------------------------------------------------------------------
    */

    /**
     * Infobip answers a single send flat and a `messages[]` send inside a list; both shapes are
     * read, because the template route uses the second.
     */
    public function readSendResult(array $payload): ?BspSendResult
    {
        $first = BridgeWire::arrayOrEmpty(BridgeWire::listOrEmpty($payload['messages'] ?? null)[0] ?? null);

        return BspSendResult::tryFrom(
            BridgeWire::stringOrNull($payload['messageId'] ?? $first['messageId'] ?? null),
            self::digits(BridgeWire::stringOrNull($payload['to'] ?? $first['to'] ?? null)),
        );
    }

    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        if ($status < 400) {
            return null;
        }

        $exception = BridgeWire::arrayOrEmpty(
            BridgeWire::arrayOrEmpty($payload['requestError'] ?? null)['serviceException'] ?? null,
        );

        return new BspRefusal(
            // A token, not a number, and not a message id despite the field name.
            code: BridgeWire::stringOrNull($exception['messageId'] ?? null),
            type: null,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public function accountDetailIn(array $payload): ?string
    {
        if (! array_key_exists('balance', $payload)) {
            return null;
        }

        // The balance figure is deliberately not repeated — see `GupshupAdapter::accountDetailIn()`.
        return 'Infobip accepted these credentials for this account.';
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
     * `results[].to` on an inbound message; `null` on a delivery report, which names none.
     *
     * A report is told apart from a message by the presence of `status`, which only a report has.
     * Every result in the body must agree, so a batch cannot mix one tenant's number with another's
     * — the same guarantee `ThreeSixtyDialogAdapter` enforces across `changes[]`, and for the same
     * reason: a mixed body is forged rather than partly usable.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        $identity = null;

        foreach (BridgeWire::listOrEmpty($payload['results'] ?? null) as $entry) {
            $result = BridgeWire::arrayOrEmpty($entry);

            if (BridgeWire::arrayOrEmpty($result['status'] ?? null) !== []) {
                // A delivery report: its `to` is the customer, not the business number, so reading
                // it would compare the wrong two things and refuse every report.
                continue;
            }

            $claimed = self::digits(BridgeWire::stringOrNull($result['to'] ?? null));

            if ($claimed === null) {
                continue;
            }

            if ($identity !== null && ! hash_equals($identity, $claimed)) {
                // Two inbound messages in one body about two different numbers.
                throw WebhookVerificationException::wrongRecipient(
                    ChannelMode::BspGateway,
                    $identity,
                    $claimed,
                );
            }

            $identity = $claimed;
        }

        return $identity;
    }

    /**
     * Every result in the body: inbound messages become message events, delivery reports become
     * receipts.
     *
     * Infobip batches both kinds under one `results[]` key, so this returns a list and
     * `BspGatewayChannelDriver::parseWebhookBatch()` is what task 8.3 must call.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        $events = [];

        foreach (BridgeWire::listOrEmpty($payload['results'] ?? null) as $entry) {
            $result = BridgeWire::arrayOrEmpty($entry);
            $providerMessageId = BridgeWire::stringOrNull($result['messageId'] ?? null);

            if ($providerMessageId === null) {
                throw WebhookVerificationException::malformedPayload(
                    ChannelMode::BspGateway,
                    'an Infobip result carries no messageId, so nothing could be correlated with it',
                );
            }

            $status = BridgeWire::arrayOrEmpty($result['status'] ?? null);

            if ($status === []) {
                $events[] = $this->messageEvent($result, $providerMessageId, $credentials, $identity);

                continue;
            }

            $event = $this->receiptEvent($result, $status, $providerMessageId, $credentials, $identity);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /*
    |--------------------------------------------------------------------------
    | Retry policy
    |--------------------------------------------------------------------------
    */

    /**
     * Infobip's error tokens.
     *
     * | Token | Class |
     * |---|---|
     * | `UNAUTHORIZED` | `AUTH` |
     * | `FORBIDDEN`, `ACCOUNT_SUSPENDED` | `PERMISSION` |
     * | `TOO_MANY_REQUESTS` | `RATE_LIMIT` |
     * | `BAD_REQUEST`, `INVALID_REQUEST`, `NOT_FOUND`, `UNPROCESSABLE_ENTITY` | `VALIDATION` |
     * | `INTERNAL_SERVER_ERROR`, `SERVICE_UNAVAILABLE`, `GATEWAY_TIMEOUT` | `NETWORK` |
     *
     * `TOO_MANY_REQUESTS` **defers** rather than retries and honours Infobip's `Retry-After`
     * verbatim (`ErrorClass::RateLimit`), which is what keeps a partner's pacing from spending a
     * retry budget meant for real faults; `UNAUTHORIZED` is zero attempts, structurally, because an
     * API key does not become valid by being tried again — and a job that kept retrying would hold a
     * send in flight long enough for task 7.6 to mark working credentials invalid on the strength of
     * a queue backlog.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        return self::classifyByCode($e, [
            ErrorClass::Auth->value => ['UNAUTHORIZED'],
            ErrorClass::Permission->value => ['FORBIDDEN', 'ACCOUNT_SUSPENDED'],
            ErrorClass::RateLimit->value => ['TOO_MANY_REQUESTS'],
            ErrorClass::Validation->value => ['BAD_REQUEST', 'INVALID_REQUEST', 'NOT_FOUND', 'UNPROCESSABLE_ENTITY'],
            ErrorClass::Network->value => ['INTERNAL_SERVER_ERROR', 'SERVICE_UNAVAILABLE', 'GATEWAY_TIMEOUT'],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function messageUrl(ChannelCredentials $credentials, string $route): string
    {
        return sprintf('%s/whatsapp/%s/message/%s', $this->baseUrl($credentials), self::API_VERSION, $route);
    }

    /**
     * The fields every single-message Infobip body carries.
     *
     * @return array<string, mixed>
     */
    private function envelope(ChannelCredentials $credentials, string $recipient): array
    {
        return [
            'from' => $this->senderIdentity($credentials),
            'to' => $recipient,
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
     * One inbound `results[]` entry as a canonical message event.
     *
     * @param  array<string, mixed>  $result
     *
     * @throws WebhookVerificationException when the result names no sender
     */
    private function messageEvent(
        array $result,
        string $providerMessageId,
        ChannelCredentials $credentials,
        string $identity,
    ): InboundEvent {
        $from = self::digits(BridgeWire::stringOrNull($result['from'] ?? null));

        if ($from === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'an Infobip inbound message names no sender',
            );
        }

        return InboundEvent::message(
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            from: $from,
            text: self::textOf(BridgeWire::arrayOrEmpty($result['message'] ?? null)),
            channelIdentity: $identity,
            occurredAt: self::timestamp($result['receivedAt'] ?? null),
            payload: $result,
        );
    }

    /**
     * One delivery-report `results[]` entry as a canonical receipt, or `null` for a group Infobip
     * added after this release.
     *
     * The **group name** is read rather than the specific `name`: Infobip's `status.name` is
     * fine-grained (`DELIVERED_TO_HANDSET`, `PENDING_WAITING_DELIVERY`) and grows between releases,
     * while `groupName` is the small stable set — so keying on the group is what keeps a new
     * fine-grained status from silently falling through.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $status
     */
    private function receiptEvent(
        array $result,
        array $status,
        string $providerMessageId,
        ChannelCredentials $credentials,
        string $identity,
    ): ?InboundEvent {
        $kind = self::receiptKind(
            BridgeWire::stringOrNull($status['groupName'] ?? null),
            accepted: ['pending'],
            delivered: ['delivered'],
            read: ['read', 'seen'],
            failed: ['undeliverable', 'rejected', 'expired'],
        );

        if ($kind === null) {
            return null;
        }

        return InboundEvent::receipt(
            kind: $kind,
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            occurredAt: self::timestamp($result['doneAt'] ?? $result['sentAt'] ?? null),
            failureReason: $kind === InboundEventKind::SendFailure
                ? $credentials->redact(self::failureReason($result))
                : null,
            channelIdentity: $identity,
            payload: $result,
        );
    }

    /**
     * What the customer said, out of an Infobip inbound `message` object.
     *
     * @param  array<string, mixed>  $message
     */
    private static function textOf(array $message): ?string
    {
        return BridgeWire::stringOrNull($message['text'] ?? null)
            ?? BridgeWire::stringOrNull($message['caption'] ?? null)
            // A button or list reply: Infobip echoes the pressed label in `title` beside its id.
            ?? BridgeWire::stringOrNull($message['title'] ?? null);
    }

    /**
     * Why Infobip says a message failed — its error **name** and id, both identifiers.
     *
     * @param  array<string, mixed>  $result
     */
    private static function failureReason(array $result): string
    {
        $error = BridgeWire::arrayOrEmpty($result['error'] ?? null);
        $name = BridgeWire::stringOrNull($error['name'] ?? null);
        $id = BridgeWire::stringOrNull($error['id'] ?? null);

        if ($name === null && $id === null) {
            return 'Infobip reported this message as failed without naming a reason.';
        }

        return sprintf(
            '%s%s',
            $name ?? 'Infobip reported this message as failed',
            $id === null ? '' : sprintf(' (id %s)', $id),
        );
    }
}
