<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp\Adapters;

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Enums\InboundEventKind;
use App\Exceptions\Channel\ChannelOperationException;
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
 * **WATI** — the partner that refuses a send with **HTTP 200**, has no send-by-link media route, and
 * whose callback names no business number at all. Three genuine divergences, each handled where it
 * is visible rather than smoothed over (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3).
 *
 * | Axis | WATI |
 * |---|---|
 * | Auth | `Authorization: Bearer {token}` |
 * | Base URL | **per account** — WATI issues `live-server-{n}.wati.io`, so `base_url` is not optional |
 * | Send | `POST /api/v1/sendSessionMessage/{number}` with `messageText` in the query string |
 * | Template | `POST /api/v1/sendTemplateMessage?whatsappNumber=…`, named parameters |
 * | Probe | `GET /api/v1/getMessageTemplates` |
 * | Errors | `{"result": false, "info": …}` — **on a 200** |
 * | Webhook | a shared secret in an operator-named header |
 * | Webhook identity | none |
 *
 * ## A refusal with a success status, and why that is the dangerous one
 *
 * WATI answers a rejected send `HTTP 200` with `{"result": false, "info": "…"}`. A driver that built
 * a refusal only from `$response->failed()` would read that as an accepted send, find no message id,
 * and — worse — a driver that defaulted a missing id would record a message as **sent** that WATI
 * never accepted. That is the one outcome that is definitely wrong, so `refusalIn()` here is called
 * on every response and reads `result` before the status.
 *
 * Because WATI supplies no code with it, the refusal is marked with a fixed **type** token
 * (`REFUSED_BY_RESULT`) rather than a fabricated error code, and `classify()` reads that token and
 * answers `VALIDATION` — deterministic, fail-fast. That is the honest classification: WATI uses this
 * shape for *"this number has no open session"* and *"this template is not approved"*, neither of
 * which becomes true by being retried.
 *
 * ## `MEDIA` is refused before dispatch, and that is the sub-matrix doing its job
 *
 * WATI's session API sends **text**. A file is uploaded multipart to `sendSessionFile`, and there is
 * no route that takes a URL for WATI to fetch — which is the shape every other partner here uses and
 * the shape the platform's media pipeline produces (`MediaPayload::fromUrl()`, so *"nothing large
 * crosses the PHP boundary"*).
 *
 * So `refineSupport()` narrows `MEDIA` from the mode's `⚠️ per provider` to `Unsupported`, and this
 * is exactly what a per-provider sub-matrix is *for*: a media send on a WATI session is refused by
 * the capability gate with a typed `ModeCapabilityException` **before** any driver call (Req 8.3,
 * Property 21) instead of reaching WATI and failing there. `mediaMessage()` is consequently
 * unreachable through the pipeline and answers a direct caller with `ChannelOperationException`, the
 * same way `CloudApiChannelDriver::sendPresence()` does — a refusal, never a silent no-op.
 *
 * The narrowing is not overridable by config, unlike the other partners' `media_enabled`: it is a
 * property of WATI's API rather than of one tenant's account, and a credential flag that could turn
 * it back on would produce a send with nowhere to go.
 *
 * ## The callback names no recipient
 *
 * WATI's webhook carries the customer (`waId`), the message and the event type, and **no field for
 * the business number** — a WATI instance is one number, so it never had to. `requiresRecipientClaim()`
 * is therefore `false`, and what binds a callback to the tenant is that the webhook secret is per
 * credential row: one WATI instance belongs to one tenant, so its secret is the identity. The
 * residual risk is stated there.
 */
final readonly class WatiAdapter extends BaseBspAdapter
{
    /**
     * The header WATI authenticates with.
     */
    public const string AUTH_HEADER = 'Authorization';

    /**
     * The header the webhook secret is presented in when the credentials name no other.
     */
    public const string DEFAULT_WEBHOOK_HEADER = 'Authorization';

    /**
     * The marker put in a refusal's `type` when WATI refused a send with a success status.
     *
     * A platform-side token, deliberately in `type` and not in `code`: `code` is where a *partner's*
     * own code goes, and inventing one there would put a value in a field an operator reads as
     * WATI's. It matches `ChannelRequestFailedException::NORMALISED_CODE_PATTERN`, so it survives
     * normalisation intact.
     */
    public const string REFUSED_BY_RESULT = 'wati.result_false';

    public function provider(): BspProvider
    {
        return BspProvider::Wati;
    }

    /**
     * The shape of a WATI host, not a host that will work — WATI issues a numbered server per
     * account, so `base_url` is among the keys `healthCheck()` refuses without.
     */
    public function defaultBaseUrl(): string
    {
        return 'https://live-server.wati.io';
    }

    protected function authSecretKey(): string
    {
        return 'access_token';
    }

    /**
     * WATI's callback names no business number — see the class docblock.
     *
     * Residual risk, stated: the binding is the per-credential-row webhook secret, so two tenants of
     * this platform sharing **one** WATI instance could not be separated on the payload. A WATI
     * instance is provisioned per number, so that configuration is a misconfiguration rather than a
     * supported arrangement — but it is not something this adapter can detect.
     */
    public function requiresRecipientClaim(): bool
    {
        return false;
    }

    /**
     * `MEDIA` is `Unsupported` on WATI, whatever the account config says.
     *
     * The one place a partner's sub-matrix narrows a cell on the strength of the partner's **API**
     * rather than the tenant's account — see the class docblock. Everything else is
     * `BaseBspAdapter`'s uniform resolution, including `INTERACTIVE`, which is `Native` here because
     * WATI is one of design § 2.3's Cloud-API-equivalent partners
     * (`BspProvider::isCloudApiEquivalent()`).
     */
    public function refineSupport(
        ChannelCapability $capability,
        ChannelCapabilitySupport $ceiling,
        ChannelCredentials $credentials,
    ): ChannelCapabilitySupport {
        if ($capability === ChannelCapability::Media) {
            return ChannelCapabilitySupport::Unsupported;
        }

        return parent::refineSupport($capability, $ceiling, $credentials);
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * One session message.
     *
     * The recipient is a **path** segment and the text a **query** parameter, which is WATI's
     * documented shape rather than a choice made here — the body is empty.
     */
    public function textMessage(ChannelCredentials $credentials, string $recipient, string $text): BspRequest
    {
        return BspRequest::json(
            sprintf('%s/api/v1/sendSessionMessage/%s', $this->baseUrl($credentials), rawurlencode($recipient)),
            [],
            $this->authHeaders($credentials),
            ['messageText' => $text],
        );
    }

    /**
     * Refused: WATI has no send-by-link media route.
     *
     * Unreachable through the pipeline, because `refineSupport()` refuses `MEDIA` before dispatch —
     * this is the answer to a direct caller, and a refusal rather than a no-op for
     * `ChannelOperationException`'s reason: a no-op would leave the caller believing an attachment
     * had been sent.
     *
     * @throws ChannelOperationException always
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        throw ChannelOperationException::unsupported(
            ChannelMode::BspGateway,
            'wati.message.media',
            'WATI\'s session API sends text, and a file is uploaded to it multipart rather than '
            .'fetched from a link — so a stored-media URL has no route, and MEDIA is refused by the '
            .'capability gate before any send is attempted',
        );
    }

    /**
     * One approved template.
     *
     * WATI takes template parameters as `{"name": "1", "value": …}` pairs — named, but with the names
     * being the positional indices, which is how a `{{1}}`-style body maps onto it. `broadcast_name`
     * is required by WATI and is used for its own reporting; the template's key is the honest value
     * to put there, since a send from this platform is not part of a WATI broadcast.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        $named = [];

        foreach ($parameters as $index => $value) {
            $named[] = ['name' => (string) ($index + 1), 'value' => $value];
        }

        return BspRequest::json(
            $this->baseUrl($credentials).'/api/v1/sendTemplateMessage',
            [
                'template_name' => self::templateHandle($template),
                'broadcast_name' => $template->name.'_'.$template->language,
                'parameters' => $named,
            ],
            $this->authHeaders($credentials),
            ['whatsappNumber' => $recipient],
        );
    }

    /**
     * The account's message templates — read-only, and the route that proves the bearer token on the
     * account's own server.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            $this->baseUrl($credentials).'/api/v1/getMessageTemplates',
            $this->authHeaders($credentials),
            ['pageSize' => '1', 'pageNumber' => '1'],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading what came back
    |--------------------------------------------------------------------------
    */

    public function readSendResult(array $payload): ?BspSendResult
    {
        $message = BridgeWire::arrayOrEmpty($payload['message'] ?? null);

        return BspSendResult::tryFrom(BridgeWire::stringOrNull(
            $message['whatsappMessageId'] ?? $message['id'] ?? $payload['id'] ?? null,
        ));
    }

    /**
     * A refusal, including the ones WATI delivers with a 200 — see the class docblock.
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        $refusedByResult = self::resultIsFalse($payload);

        if ($status < 400 && ! $refusedByResult) {
            return null;
        }

        return new BspRefusal(
            code: BridgeWire::stringOrNull($payload['code'] ?? null),
            type: $refusedByResult ? self::REFUSED_BY_RESULT : null,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public function accountDetailIn(array $payload): ?string
    {
        if (self::resultIsFalse($payload)) {
            return null;
        }

        if (! array_key_exists('messageTemplates', $payload) && ! array_key_exists('result', $payload)) {
            return null;
        }

        return 'WATI accepted these credentials on this account\'s server.';
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
     * `null`, always: WATI's callback names no business number.
     *
     * A documented answer rather than a gap in the implementation — `requiresRecipientClaim()` is
     * `false` for exactly this, so the driver skips the comparison instead of refusing every
     * callback, and the argument for why that is safe enough is in this class's docblock.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        return null;
    }

    /**
     * One event: an inbound customer message, or a status update about an outbound one.
     *
     * WATI's `eventType` distinguishes them, and `owner` distinguishes direction within a message
     * event: `owner: true` is a message **this platform** sent, echoed back, which must not be
     * re-ingested as a customer message — doing so would answer the platform's own outbound with a
     * bot reply and loop.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        $eventType = strtolower((string) BridgeWire::stringOrNull($payload['eventType'] ?? null));
        $providerMessageId = BridgeWire::stringOrNull($payload['whatsappMessageId'] ?? $payload['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a WATI callback carries no message id, so nothing could be correlated with it',
            );
        }

        if ($eventType === 'message') {
            if (BridgeWire::boolOrNull($payload['owner'] ?? null) === true) {
                // An echo of this platform's own outbound. Verified, and consumed by nobody.
                return [];
            }

            $from = self::digits(BridgeWire::stringOrNull($payload['waId'] ?? null));

            if ($from === null) {
                throw WebhookVerificationException::malformedPayload(
                    ChannelMode::BspGateway,
                    'a WATI inbound message names no waId, so it has no sender',
                );
            }

            return [InboundEvent::message(
                mode: ChannelMode::BspGateway,
                tenantId: $credentials->tenantId,
                providerMessageId: $providerMessageId,
                from: $from,
                text: BridgeWire::stringOrNull($payload['text'] ?? null),
                channelIdentity: $identity,
                occurredAt: self::timestamp($payload['timestamp'] ?? null),
                payload: $payload,
            )];
        }

        $kind = self::receiptKind(
            BridgeWire::stringOrNull($payload['statusString'] ?? $payload['status'] ?? null),
            accepted: ['sent'],
            delivered: ['delivered'],
            read: ['read', 'seen'],
            failed: ['failed', 'undelivered'],
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
                ? $credentials->redact('WATI reported this message as failed.')
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
     * WATI's refusals: the 200-with-`result: false` shape, and nothing else it numbers.
     *
     * The `REFUSED_BY_RESULT` marker is `VALIDATION` — fail fast — because WATI uses that shape for
     * deterministic refusals (*"no open session for this number"*, *"template not approved"*), and
     * neither becomes true by being retried. Retrying would also be worse than useless here: with a
     * 200 status there is nothing for the shared status fallback to read, so an unclassified refusal
     * would take `NETWORK`'s five attempts and put five identical refusals on WATI's account.
     *
     * A WATI refusal that *does* carry an HTTP status falls through to `null` and the shared status
     * fallback, which is that status's honest reading.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        if ($e->errorType === self::REFUSED_BY_RESULT) {
            return ErrorClass::Validation;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, string>
     */
    private function authHeaders(#[SensitiveParameter] ChannelCredentials $credentials): array
    {
        return [
            self::AUTH_HEADER => 'Bearer '.$credentials->requireSecret($this->authSecretKey()),
        ];
    }

    /**
     * Whether the body says `result: false`.
     *
     * Tolerant of both spellings WATI uses — the JSON boolean and the string `"false"` — because a
     * `"false"` read as truthy is precisely the bug this method exists to prevent, and it would
     * record an unsent message as sent.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function resultIsFalse(array $payload): bool
    {
        $result = $payload['result'] ?? null;

        if (is_bool($result)) {
            return ! $result;
        }

        return is_string($result) && strtolower(trim($result)) === 'false';
    }
}
