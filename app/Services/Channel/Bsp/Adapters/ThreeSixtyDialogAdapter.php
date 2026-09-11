<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp\Adapters;

use App\Enums\BspProvider;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Enums\InboundEventKind;
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
 * **360dialog** — the Cloud-API-equivalent partner: Meta's own request and webhook shapes behind
 * a single `D360-API-KEY` header (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3).
 *
 * | Axis | 360dialog |
 * |---|---|
 * | Auth | `D360-API-KEY: {key}` — one header, no account id, no bearer scheme |
 * | Send | `POST /messages` with **Meta's** Cloud API body (`messaging_product`, `to`, `type`, …) |
 * | Template | Meta's `template` component shape, addressed by name and language |
 * | Probe | `GET /whatsapp_business_profile?fields=about` |
 * | Errors | Meta's `{"error": {"code", "type", "fbtrace_id"}}`, passed through |
 * | Webhook | `X-Hub-Signature-256`: hex HMAC-SHA256 over the raw body |
 * | Webhook body | Meta's `entry[].changes[].value.{messages,statuses}` envelope, **batched** |
 *
 * ## Why this adapter is short, and why that is the point
 *
 * design § 2.3 records that *"360dialog/Gupshup/WATI expose Cloud-API-equivalent interactive +
 * template-sync APIs"*, and `BspProvider::isCloudApiEquivalent()` is that fact. For 360dialog the
 * equivalence is near-total: it is a pass-through to Meta, so the request bodies, the error
 * envelope and the webhook envelope are the ones `CloudApiChannelDriver` already documents in
 * full. What differs is exactly two things — the auth header and the base URL — and everything
 * else in this class is a translation of Meta's shapes that reads the same because it *is* the
 * same.
 *
 * That is also why `INTERACTIVE` resolves to `Native` here rather than `Conditional`: buttons
 * and lists are first-class, not something reachable only through a partner content template
 * (`BspProvider::declaredSupport()` makes that call at layer 2; this adapter's account-level
 * layer only ever narrows it).
 *
 * ## Batching, and the recipient check that has to survive it
 *
 * Because the envelope is Meta's, **one HTTP request can carry several logical events** — the
 * situation `CloudApiChannelDriver`'s docblock sets out at length. `eventsIn()` returns them all,
 * in document order, and `BspGatewayChannelDriver::parseWebhookBatch()` is the method task 8.3
 * must call.
 *
 * The consequence for verification is specific and is handled the same way 7.2 handles it: the
 * recipient is checked **for every change in the body** before a single event is built, so a
 * batch cannot smuggle one foreign number past a check that only looked at the first change. A
 * change naming a number these credentials do not own aborts the whole batch rather than being
 * skipped — a body that mixes two tenants' numbers is not partially usable, it is forged.
 *
 * The identity compared is `metadata.display_phone_number`, reduced to digits, rather than Meta's
 * `phone_number_id`: a 360dialog credential set names the number the tenant owns (§2.7's *"sender
 * id/number"*), and there is no phone-number id in that credential shape to compare against.
 */
final readonly class ThreeSixtyDialogAdapter extends BaseBspAdapter
{
    /**
     * The header 360dialog authenticates every request with.
     */
    public const string API_KEY_HEADER = 'D360-API-KEY';

    /**
     * The header the forwarded Meta payload is signed in — Meta's own, because the payload is
     * Meta's own.
     */
    public const string SIGNATURE_HEADER = 'X-Hub-Signature-256';

    /**
     * The `object` a forwarded WABA notification declares, and the `changes[].field` this release
     * acts on. Anything else is a verified payload with no consumer — a `200`, not a 403.
     */
    public const string WEBHOOK_OBJECT = 'whatsapp_business_account';

    public const string MESSAGES_FIELD = 'messages';

    /**
     * The field every Cloud-API-shaped request body carries, and its only legal value.
     */
    public const string MESSAGING_PRODUCT = 'whatsapp';

    public function provider(): BspProvider
    {
        return BspProvider::ThreeSixtyDialog;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://waba-v2.360dialog.io';
    }

    protected function authSecretKey(): string
    {
        return 'api_key';
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
            $this->envelope($recipient) + [
                'type' => 'text',
                // `preview_url` off by default, for `CloudApiMessage::text()`'s reason: a preview
                // makes Meta fetch whatever the tenant linked, turning a transactional message
                // into an outbound request from Meta's infrastructure.
                'text' => ['preview_url' => false, 'body' => $text],
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One media attachment, by `link` — Meta's shape, keyed on the media kind.
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        $attachment = ['link' => (string) $media->url];

        if ($media->caption !== null && $media->kind->usesCaption()) {
            $attachment['caption'] = $media->caption;
        }

        if ($media->filename !== null && $media->kind->usesFilename()) {
            $attachment['filename'] = $media->filename;
        }

        return BspRequest::json(
            $this->messagesUrl($credentials),
            $this->envelope($recipient) + [
                'type' => $media->kind->value,
                $media->kind->value => $attachment,
            ],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One approved template, in Meta's component shape.
     *
     * Addressed by `name` + `language`, which is how Meta's registry is keyed and therefore how
     * 360dialog's is — so unlike Twilio there is no partner-issued id this can fail on. Only the
     * **body** component is built, for the reason `CloudApiChannelDriver::templateComponents()`
     * gives: header, footer and button parameters live in `cloud_api_templates.components`, which
     * task 8.4's sync populates, and building one from a column nothing has written would be
     * inventing a schema.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        $body = $this->envelope($recipient) + [
            'type' => 'template',
            'template' => [
                'name' => $template->name,
                'language' => ['code' => $template->language],
            ],
        ];

        if ($parameters !== []) {
            $body['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $value): array => ['type' => 'text', 'text' => $value],
                    $parameters,
                ),
            ]];
        }

        return BspRequest::json($this->messagesUrl($credentials), $body, $this->authHeaders($credentials));
    }

    /**
     * The WhatsApp business profile — read-only, and it proves the API key end to end.
     *
     * The profile route rather than a partner-management one because a 360dialog API key is
     * scoped to exactly one number and carries no account id, so the number's own profile is the
     * only thing the key can be asked about without additional credentials §2.7 does not list.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            $this->baseUrl($credentials).'/whatsapp_business_profile',
            $this->authHeaders($credentials),
            ['fields' => 'about,description,vertical'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading what came back
    |--------------------------------------------------------------------------
    */

    public function readSendResult(array $payload): ?BspSendResult
    {
        $messages = BridgeWire::listOrEmpty($payload['messages'] ?? null);
        $contacts = BridgeWire::listOrEmpty($payload['contacts'] ?? null);

        return BspSendResult::tryFrom(
            BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($messages[0] ?? null)['id'] ?? null),
            BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($contacts[0] ?? null)['wa_id'] ?? null),
        );
    }

    /**
     * Meta's error envelope, forwarded verbatim.
     *
     * `fbtrace_id` is carried because it is an opaque correlation handle Meta support asks for and
     * it quotes nothing of the request; `error.message` is not, because on a `401` it echoes part
     * of the key it was sent.
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        if ($status < 400) {
            return null;
        }

        $error = BridgeWire::arrayOrEmpty($payload['error'] ?? null);

        return new BspRefusal(
            // `BridgeWire::stringOrNull()` reads an unquoted JSON number as well as a quoted one,
            // which matters because Meta sends `code` unquoted and several partners quote it.
            code: BridgeWire::stringOrNull($error['code'] ?? null),
            type: BridgeWire::stringOrNull($error['type'] ?? null),
            retryAfterSeconds: $retryAfterSeconds,
            traceId: BridgeWire::stringOrNull($error['fbtrace_id'] ?? null),
        );
    }

    /**
     * The profile route answers `{"data": [{…}]}`.
     *
     * Any `data` list at all proves the key authenticated against this number — the profile's own
     * fields are the tenant's marketing copy rather than a health signal, so nothing is read out
     * of them. An empty body is *not* a proof: 360dialog answers a rejected key with an error
     * envelope, so a 2xx with no `data` is a shape this release does not recognise and the driver
     * reports the credentials as unconfirmed.
     */
    public function accountDetailIn(array $payload): ?string
    {
        if (! array_key_exists('data', $payload)) {
            return null;
        }

        return '360dialog accepted these credentials for this number\'s business profile.';
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify Meta's `X-Hub-Signature-256` over the raw body.
     *
     * Straight to the shared hex-HMAC check, which reads `wa.security.hmac.algorithm` and
     * `wa.security.hmac.prefix` — the same config `CloudApiChannelDriver` verifies Meta's own
     * callbacks with, because this is the same presentation.
     */
    public function verifyWebhook(
        Request $request,
        ChannelCredentials $credentials,
        BspWebhookVerifier $verifier,
    ): void {
        $verifier->assertHmacHex($request, self::SIGNATURE_HEADER, $this->webhookSecret($credentials));
    }

    /**
     * The business number the payload is about, from the first `messages` change's metadata.
     *
     * Only the first: `eventsIn()` re-checks **every** change, so this method answers the driver's
     * one comparison and the batch-wide guarantee is enforced where the changes are walked. A body
     * that is not a WABA notification at all (a subscription to another Meta product) names none,
     * which `requiresRecipientClaim()` turns into a malformed-payload refusal — deliberately
     * stricter than 7.2's `UNSUPPORTED` answer for the same shape, because a 360dialog callback
     * is registered per number and cannot legitimately be about another product.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        foreach (BridgeWire::listOrEmpty($payload['entry'] ?? null) as $entry) {
            foreach (BridgeWire::listOrEmpty(BridgeWire::arrayOrEmpty($entry)['changes'] ?? null) as $change) {
                $claimed = self::numberIn(BridgeWire::arrayOrEmpty($change));

                if ($claimed !== null) {
                    return $claimed;
                }
            }
        }

        return null;
    }

    /**
     * Every message and every status in the forwarded envelope, in document order.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        if (BridgeWire::stringOrNull($payload['object'] ?? null) !== self::WEBHOOK_OBJECT) {
            // Verified, and not a WABA notification: acknowledged rather than refused, so an
            // operator's misconfiguration at 360dialog does not look like an attack.
            return [];
        }

        $events = [];

        foreach (BridgeWire::listOrEmpty($payload['entry'] ?? null) as $entry) {
            foreach (BridgeWire::listOrEmpty(BridgeWire::arrayOrEmpty($entry)['changes'] ?? null) as $change) {
                $events = [...$events, ...$this->eventsFromChange(
                    BridgeWire::arrayOrEmpty($change),
                    $credentials,
                    $identity,
                )];
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
     * Meta's codes, because the refusals are Meta's.
     *
     * The table is deliberately the same policy `CloudApiErrorClassifier` applies and is
     * **restated rather than shared**, for the reason that classifier's own docblock gives:
     * provider error numbers collide, so a classifier keys its table on the mode *and* the
     * partner. A shared table would say that a `4` from any BSP means Meta's app-level rate limit,
     * which is true of 360dialog and of nobody else here.
     *
     * `130429` is the row that matters: 360dialog forwards Meta's throughput limit with Meta's
     * **HTTP 400**, so a status-first reading would fail a send fast that would have succeeded a
     * minute later.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        return self::classifyByCode($e, [
            ErrorClass::RateLimit->value => [4, 80007, 130429, 131048, 131056],
            ErrorClass::Auth->value => [0, 190, 2500],
            ErrorClass::Permission->value => [10, 131031],
            ErrorClass::NotOnWhatsApp->value => [131026],
            ErrorClass::Network->value => [1, 2, 131000, 131009, 131016],
            ErrorClass::Validation->value => [131047, 131051, 130472, 132000, 132001, 132005, 132007, 132012, 132015],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function messagesUrl(ChannelCredentials $credentials): string
    {
        return $this->baseUrl($credentials).'/messages';
    }

    /**
     * The fields every Cloud-API-shaped send body starts with.
     *
     * @return array<string, mixed>
     */
    private function envelope(string $recipient): array
    {
        return [
            'messaging_product' => self::MESSAGING_PRODUCT,
            'recipient_type' => 'individual',
            'to' => $recipient,
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
     * Every canonical event in one `changes[]` element, with the recipient re-checked first.
     *
     * @param  array<string, mixed>  $change
     * @return list<InboundEvent>
     *
     * @throws WebhookVerificationException when this change is about another number, or is malformed
     */
    private function eventsFromChange(array $change, ChannelCredentials $credentials, string $identity): array
    {
        if (BridgeWire::stringOrNull($change['field'] ?? null) !== self::MESSAGES_FIELD) {
            // A template-status update, an account update, a field 360dialog forwards from a Meta
            // release after this one: verified, and consumed by nobody yet.
            return [];
        }

        $claimed = self::numberIn($change);

        if ($claimed === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a forwarded change names no metadata.display_phone_number, so it cannot be matched '
                .'against the route it was delivered on',
            );
        }

        if (! hash_equals($identity, $claimed)) {
            // The batch-wide half of the recipient check — see the class docblock.
            throw WebhookVerificationException::wrongRecipient(ChannelMode::BspGateway, $identity, $claimed);
        }

        $value = BridgeWire::arrayOrEmpty($change['value'] ?? null);
        $metadata = BridgeWire::arrayOrEmpty($value['metadata'] ?? null);
        $events = [];

        foreach (BridgeWire::listOrEmpty($value['messages'] ?? null) as $message) {
            $events[] = $this->messageEvent(
                BridgeWire::arrayOrEmpty($message),
                $metadata,
                $credentials,
                $identity,
            );
        }

        foreach (BridgeWire::listOrEmpty($value['statuses'] ?? null) as $status) {
            $event = $this->statusEvent(BridgeWire::arrayOrEmpty($status), $metadata, $credentials, $identity);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * One `value.messages[]` element.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $metadata
     *
     * @throws WebhookVerificationException when the message names no id or no sender
     */
    private function messageEvent(
        array $message,
        array $metadata,
        ChannelCredentials $credentials,
        string $identity,
    ): InboundEvent {
        $providerMessageId = BridgeWire::stringOrNull($message['id'] ?? null);
        $from = BridgeWire::stringOrNull($message['from'] ?? null);

        if ($providerMessageId === null || $from === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a forwarded message carries no "id" or names no sender, so nothing could be '
                .'correlated with it',
            );
        }

        return InboundEvent::message(
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            from: $from,
            text: self::textOf($message),
            channelIdentity: $identity,
            occurredAt: self::timestamp($message['timestamp'] ?? null),
            payload: ['metadata' => $metadata, 'message' => $message],
        );
    }

    /**
     * One `value.statuses[]` element, or `null` for a status word this release does not act on.
     *
     * The raw word is preserved in the payload because `sent` and `delivered` collapse into one
     * canonical kind, and task 9.5 needs the distinction to apply the never-downgrades rule
     * (Req 20.6 / C3, Property 9). Monotonicity is not enforced here — that is the messaging
     * phase's, exactly as `InboundEventKind` prescribes.
     *
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>  $metadata
     *
     * @throws WebhookVerificationException when the status names no message id
     */
    private function statusEvent(
        array $status,
        array $metadata,
        ChannelCredentials $credentials,
        string $identity,
    ): ?InboundEvent {
        $providerMessageId = BridgeWire::stringOrNull($status['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a forwarded status carries no "id", so it says nothing about which message it is about',
            );
        }

        $kind = self::receiptKind(
            BridgeWire::stringOrNull($status['status'] ?? null),
            accepted: ['sent', 'accepted'],
            delivered: ['delivered'],
            read: ['read'],
            failed: ['failed'],
        );

        if ($kind === null) {
            return null;
        }

        return InboundEvent::receipt(
            kind: $kind,
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            occurredAt: self::timestamp($status['timestamp'] ?? null),
            failureReason: $kind === InboundEventKind::SendFailure
                ? $credentials->redact(self::failureReason($status))
                : null,
            channelIdentity: $identity,
            payload: ['metadata' => $metadata, 'status' => $status],
        );
    }

    /**
     * The business number one change is about, as digits, or `null`.
     *
     * @param  array<string, mixed>  $change
     */
    private static function numberIn(array $change): ?string
    {
        $value = BridgeWire::arrayOrEmpty($change['value'] ?? null);
        $metadata = BridgeWire::arrayOrEmpty($value['metadata'] ?? null);

        return self::digits(BridgeWire::stringOrNull($metadata['display_phone_number'] ?? null));
    }

    /**
     * What the customer said, for the message shapes that carry words.
     *
     * An interactive reply's **title** is used so the conversation engine sees what the customer
     * *chose* either way; the reply id stays available through `InboundEvent::payload()`, which is
     * what a flow correlates on. `CloudApiChannelDriver::textOf()` makes the same call.
     *
     * @param  array<string, mixed>  $message
     */
    private static function textOf(array $message): ?string
    {
        $type = BridgeWire::stringOrNull($message['type'] ?? null);

        if ($type === 'interactive') {
            $interactive = BridgeWire::arrayOrEmpty($message['interactive'] ?? null);

            foreach (['button_reply', 'list_reply'] as $shape) {
                $title = BridgeWire::stringOrNull(
                    BridgeWire::arrayOrEmpty($interactive[$shape] ?? null)['title'] ?? null,
                );

                if ($title !== null) {
                    return $title;
                }
            }

            return null;
        }

        if ($type === 'button') {
            return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($message['button'] ?? null)['text'] ?? null);
        }

        return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($message['text'] ?? null)['body'] ?? null);
    }

    /**
     * Why a forwarded status says a message failed — Meta's error **title** and code only.
     *
     * @param  array<string, mixed>  $status
     */
    private static function failureReason(array $status): string
    {
        $error = BridgeWire::arrayOrEmpty(BridgeWire::listOrEmpty($status['errors'] ?? null)[0] ?? null);
        $title = BridgeWire::stringOrNull($error['title'] ?? null);
        $code = BridgeWire::stringOrNull($error['code'] ?? null);

        if ($title === null && $code === null) {
            return '360dialog reported this message as failed without naming a reason.';
        }

        return sprintf(
            '%s%s',
            $title ?? '360dialog reported this message as failed',
            $code === null ? '' : sprintf(' (code %s)', $code),
        );
    }
}
