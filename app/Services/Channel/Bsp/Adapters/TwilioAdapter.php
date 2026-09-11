<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp\Adapters;

use App\Enums\BspProvider;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\ErrorClass;
use App\Enums\InboundEventKind;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\ChannelTemplateException;
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
 * **Twilio** — the `BSP_GATEWAY` partner whose every axis is its own: Basic auth under an
 * Account SID, form-encoded sends, interactive only through the Content API, and a webhook MAC
 * over the request *URL* rather than the body (Req 8.1, 8.2 / A8; design § Channel Mode 2.2
 * mode 4, 2.3).
 *
 * | Axis | Twilio |
 * |---|---|
 * | Auth | `Authorization: Basic base64(AccountSid:AuthToken)` |
 * | Send | `POST /2010-04-01/Accounts/{sid}/Messages.json`, `application/x-www-form-urlencoded` |
 * | Addressing | `whatsapp:+{E.164}` on both `To` and `From` — the channel prefix is Twilio's |
 * | Template | `ContentSid` + `ContentVariables`, the Content API |
 * | Probe | `GET /2010-04-01/Accounts/{sid}.json` |
 * | Errors | `{"code": 21211, "message": …, "status": 400}` — numeric, five digits |
 * | Webhook | `X-Twilio-Signature`: **base64 HMAC-SHA1** over the full URL plus the sorted form parameters |
 * | Webhook body | form-encoded, not JSON |
 *
 * ## Interactive is `⚠️`, and for a reason that is a capability rather than a rule
 *
 * design § 2.3: *"Twilio & Vonage do buttons/lists via content templates"*, which
 * `BspProvider::interactiveViaContentTemplates()` already records. That makes `INTERACTIVE`
 * `Conditional` at layer 2 — attemptable, subject to the tenant having registered the content
 * template with Twilio — and `interactiveSupport()` below adds the account-level half: a Twilio
 * account with no Content API configured cannot do it at all, so the cell narrows to
 * `Unsupported` rather than being reported as attemptable and failing at the provider.
 *
 * ## The webhook signature is over the URL, not the body, and that changes the check
 *
 * Twilio concatenates the exact callback URL it was configured with, then every POST parameter
 * in **lexical key order** as `key.value`, and signs the result with HMAC-SHA1, presented
 * base64. Three consequences the shared verifier is told about explicitly:
 *
 * 1. **The raw body is not the signed material.** Every other partner here signs the bytes;
 *    Twilio signs a canonical string this class builds, which is why
 *    `BspWebhookVerifier::assertHmacBase64()` takes a `$payload`.
 * 2. **Base64, not hex.** `Concerns\VerifiesProviderSignature::assertSignature()` would reduce a
 *    base64 digest to `''` — it splits at the first `=`, which is base64's padding — so it
 *    cannot be used here and the verifier's base64 method is.
 * 3. **SHA-1.** Not the platform's `wa.security.hmac.algorithm`; Twilio's protocol vocabulary,
 *    the same way `CloudApiChannelDriver::SIGNATURE_HEADER` is Meta's. It is weaker than SHA-256
 *    and is not this platform's choice to make: verifying with anything else would verify
 *    nothing.
 *
 * The URL matters for security here in a way it does not elsewhere: because it is signed, a
 * callback captured on one tenant's `route_key` cannot be replayed onto another's — the MAC
 * covers the path. The recipient check runs anyway (`To` is the business number on every Twilio
 * callback shape), because a signature proves who sent a body and not whose number it is about.
 */
final readonly class TwilioAdapter extends BaseBspAdapter
{
    /**
     * The header Twilio presents its MAC in, and the digest it uses.
     */
    public const string SIGNATURE_HEADER = 'X-Twilio-Signature';

    public const string SIGNATURE_ALGORITHM = 'sha1';

    /**
     * The `config` key holding the Account SID — the `AC…` identifier that is both the Basic-auth
     * username and a path segment of every route.
     *
     * Config rather than a secret: it is an identifier Twilio prints in its own console and in
     * every URL, and treating it as a secret would keep it out of the audit trail that has to
     * name which account a send went through.
     */
    public const string ACCOUNT_SID_CONFIG_KEY = 'account_sid';

    /**
     * The `config` key naming the Messaging Service a content template is sent through, when the
     * account uses one.
     *
     * Also the flag `interactiveSupport()` reads: an account with no Content API and no messaging
     * service cannot render buttons at all.
     */
    public const string MESSAGING_SERVICE_CONFIG_KEY = 'messaging_service_sid';

    /**
     * The channel prefix Twilio requires on a WhatsApp address, on both ends.
     */
    public const string CHANNEL_PREFIX = 'whatsapp:+';

    /**
     * The API version segment Twilio's REST API has carried since 2010 and has never moved.
     *
     * A constant rather than a config key: it is not a version tenants pin (unlike Meta's Graph
     * version), and a wrong value here is a 404 on every route.
     */
    public const string API_VERSION = '2010-04-01';

    public function provider(): BspProvider
    {
        return BspProvider::Twilio;
    }

    public function defaultBaseUrl(): string
    {
        return 'https://api.twilio.com';
    }

    protected function authSecretKey(): string
    {
        return 'auth_token';
    }

    /**
     * `base_url`, `sender`, and the Account SID every route is built from.
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return [...parent::requiredConfigKeys(), self::ACCOUNT_SID_CONFIG_KEY];
    }

    /**
     * The auth token alone — it is both the API password and the webhook signing key.
     *
     * See `verifyWebhook()` for why §2.7's separate webhook secret is not required here.
     *
     * @return list<string>
     */
    public function requiredSecretKeys(): array
    {
        return [$this->authSecretKey()];
    }

    /*
    |--------------------------------------------------------------------------
    | Capabilities
    |--------------------------------------------------------------------------
    */

    /**
     * Buttons and lists, which Twilio reaches only through a registered content template.
     *
     * The account-level half of design § 2.3's *"via content templates"*: an account that has
     * neither the Content API enabled nor a Messaging Service configured has no route to an
     * interactive payload, so the cell is `Unsupported` — refused before dispatch with a typed
     * `ModeCapabilityException` rather than attempted and refused by Twilio mid-send.
     *
     * When it *is* configured the cell stays `Conditional`, never `Native`: the condition (the
     * template must exist at Twilio, approved) is real and is task 8.4's to enforce, and
     * reporting `Native` would tell the pipeline there was no further rule.
     */
    protected function interactiveSupport(
        ChannelCapabilitySupport $ceiling,
        ChannelCredentials $credentials,
    ): ChannelCapabilitySupport {
        $configured = self::flag(
            $credentials,
            self::INTERACTIVE_ENABLED_KEY,
            $credentials->config(self::MESSAGING_SERVICE_CONFIG_KEY) !== null,
        );

        return $configured ? $ceiling : ChannelCapabilitySupport::Unsupported;
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
            $this->envelope($credentials, $recipient) + ['Body' => $text],
            $this->authHeaders($credentials),
        );
    }

    /**
     * One media attachment, by link.
     *
     * Twilio fetches `MediaUrl` itself and infers the WhatsApp message type from the content type
     * it fetches, so there is no per-kind route and no type field here — which also means a
     * sticker is simply a `image/webp` attachment, as Twilio's own documentation describes.
     *
     * The one shape-level divergence: Twilio carries a caption in `Body` beside the attachment
     * rather than in a field of its own, so a captioned media send and a text send use the same
     * form field for different things.
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest
    {
        $form = $this->envelope($credentials, $recipient) + ['MediaUrl' => (string) $media->url];

        if ($media->caption !== null && $media->kind->usesCaption()) {
            // Twilio carries a caption in `Body` beside the attachment rather than in a field of
            // its own — the one place its form shape diverges from every JSON partner here.
            $form['Body'] = $media->caption;
        }

        return BspRequest::form($this->messagesUrl($credentials), $form, $this->authHeaders($credentials));
    }

    /**
     * One approved template, as a Content API send.
     *
     * `ContentSid` is Twilio's own handle and the **only** way it addresses a content template —
     * there is no send-by-name route — so a row task 8.4's sync has never given a
     * `provider_template_id` cannot be sent, and that is a 422 the tenant can act on (*"submit
     * this template to Twilio"*) rather than a 500 or a provider rejection charged to the
     * number's quality rating.
     *
     * `ContentVariables` is a JSON object keyed `"1"`, `"2"`, … because Twilio's content
     * variables are named and the platform's placeholders are positional; the mapping is the
     * position, which is what `$parameters`' order already is.
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest {
        $contentSid = $template->provider_template_id;

        if ($contentSid === null || trim($contentSid) === '') {
            throw ChannelTemplateException::notRegistered(
                ChannelMode::BspGateway,
                $template->name.':'.$template->language,
            );
        }

        $variables = [];

        foreach ($parameters as $index => $value) {
            $variables[(string) ($index + 1)] = $value;
        }

        $form = $this->envelope($credentials, $recipient) + ['ContentSid' => trim($contentSid)];

        if ($variables !== []) {
            $form['ContentVariables'] = json_encode($variables, JSON_THROW_ON_ERROR);
        }

        $service = $credentials->config(self::MESSAGING_SERVICE_CONFIG_KEY);

        if (is_string($service) && trim($service) !== '') {
            // Twilio requires the Messaging Service on a content send when the account has one:
            // it is where the template's approval and its per-channel rendering live.
            $form['MessagingServiceSid'] = trim($service);
        }

        return BspRequest::form($this->messagesUrl($credentials), $form, $this->authHeaders($credentials));
    }

    /**
     * `GET /Accounts/{sid}.json` — read-only, and it proves the SID and the token together.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest
    {
        return BspRequest::get(
            sprintf(
                '%s/%s/Accounts/%s.json',
                $this->baseUrl($credentials),
                self::API_VERSION,
                $credentials->requireConfig(self::ACCOUNT_SID_CONFIG_KEY),
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
        return BspSendResult::tryFrom(
            BridgeWire::stringOrNull($payload['sid'] ?? null),
            self::digits(BridgeWire::stringOrNull($payload['to'] ?? null)),
        );
    }

    /**
     * Twilio's error envelope. A 2xx from Twilio always means accepted, so nothing is read from
     * one.
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal
    {
        if ($status < 400) {
            return null;
        }

        return new BspRefusal(
            code: BridgeWire::stringOrNull($payload['code'] ?? null),
            // Twilio's envelope carries `message` and `more_info` beside the code; neither is read.
            // `message` quotes the request, and `more_info` is a documentation URL that adds
            // nothing a retry decision can key on that the code does not already say.
            type: null,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    public function accountDetailIn(array $payload): ?string
    {
        $status = BridgeWire::stringOrNull($payload['status'] ?? null);

        if (BridgeWire::stringOrNull($payload['sid'] ?? null) === null) {
            return null;
        }

        return sprintf(
            'Twilio accepted these credentials for this account%s.',
            $status === null ? '' : sprintf(', which it reports as %s', $status),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify `X-Twilio-Signature` over Twilio's canonical string, under the **auth token**.
     *
     * The one adapter here that does not verify against `webhook_secret`: Twilio signs its callbacks
     * with the account's auth token, which is also its API password, so there is no separate signing
     * secret to store. §2.7's *"webhook signing secret"* field is therefore unused for this partner
     * and `requiredSecretKeys()` does not ask for it — requiring a value nothing reads would tell a
     * tenant to invent one, and `healthCheck()` would report a complete credential set as incomplete.
     */
    public function verifyWebhook(
        Request $request,
        ChannelCredentials $credentials,
        BspWebhookVerifier $verifier,
    ): void {
        $verifier->assertHmacBase64(
            $request,
            self::SIGNATURE_HEADER,
            $credentials->requireSecret($this->authSecretKey()),
            self::SIGNATURE_ALGORITHM,
            self::canonicalPayload($request),
        );
    }

    /**
     * Twilio posts `application/x-www-form-urlencoded`, so the body is the request's parameters.
     *
     * @return array<string, mixed>
     */
    public function decodeWebhook(Request $request): array
    {
        $payload = [];

        foreach ($request->request->all() as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    /**
     * `To` — the business number, on both callback shapes Twilio sends.
     *
     * Twilio's inbound message and its status callback both address the platform's own number in
     * `To`, which is why `requiresRecipientClaim()` stays `true`: a Twilio payload without it is
     * not a Twilio payload.
     */
    public function recipientIn(array $payload, Request $request): ?string
    {
        return self::digits(BridgeWire::stringOrNull($payload['To'] ?? null));
    }

    /**
     * One event: an inbound customer message, or a status callback.
     *
     * Twilio distinguishes them by which status word is present. `SmsStatus=received` is an
     * inbound message; a `MessageStatus` of `queued`/`sent`/`delivered`/`read`/`failed`/
     * `undelivered` is a receipt about an outbound one. A shape carrying neither — a Twilio
     * feature this release does not consume — is `[]`, which the driver turns into a verified
     * `UNSUPPORTED` event and a `200`, not a 403 storm.
     *
     * @return list<InboundEvent>
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array {
        $messageSid = BridgeWire::stringOrNull($payload['MessageSid'] ?? $payload['SmsSid'] ?? null);

        if ($messageSid === null) {
            throw WebhookVerificationException::malformedPayload(
                ChannelMode::BspGateway,
                'a Twilio callback carries no MessageSid, so nothing could ever be correlated with it',
            );
        }

        $status = BridgeWire::stringOrNull($payload['MessageStatus'] ?? null);
        $smsStatus = BridgeWire::stringOrNull($payload['SmsStatus'] ?? null);
        $from = self::digits(BridgeWire::stringOrNull($payload['From'] ?? null));

        if ($status === null && ($smsStatus === 'received' || $smsStatus === null) && $from !== null) {
            return [InboundEvent::message(
                mode: ChannelMode::BspGateway,
                // From the credentials, never from the payload.
                tenantId: $credentials->tenantId,
                providerMessageId: $messageSid,
                from: $from,
                text: BridgeWire::stringOrNull($payload['Body'] ?? null),
                channelIdentity: $identity,
                payload: $payload,
            )];
        }

        $kind = self::receiptKind(
            $status ?? $smsStatus,
            accepted: ['queued', 'accepted', 'sending', 'sent'],
            delivered: ['delivered'],
            read: ['read'],
            failed: ['failed', 'undelivered'],
        );

        if ($kind === null) {
            return [];
        }

        return [InboundEvent::receipt(
            kind: $kind,
            mode: ChannelMode::BspGateway,
            tenantId: $credentials->tenantId,
            providerMessageId: $messageSid,
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
     * Twilio's five-digit codes.
     *
     * | Code | Meaning | Class |
     * |---|---|---|
     * | `20003` | authentication failed — the SID/token pair was rejected | `AUTH` |
     * | `20005` | account suspended or closed | `PERMISSION` |
     * | `20403`, `20006` | the account may not use this resource | `PERMISSION` |
     * | `20429`, `63018` | too many requests / channel rate limit | `RATE_LIMIT` |
     * | `63003` | no WhatsApp channel found for the `To` address | `NOT_ON_WHATSAPP` |
     * | `21211`, `21606`, `21610`, `21612`, `63016`, `63007`, `63032` | the request itself: bad address, unsubscribed recipient, free-form outside the 24-hour window, unusable sender | `VALIDATION` |
     * | `20500`, `30008` | Twilio's own internal error | `NETWORK` |
     *
     * `63016` earns its row: it is Twilio's way of saying *"outside the session window, use a
     * template"*, which is deterministic — retrying the same free-form body would fail
     * identically, and Req 8.12 wants the tenant prompted for a template instead.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass
    {
        return self::classifyByCode($e, [
            ErrorClass::Auth->value => [20003, 20004],
            ErrorClass::Permission->value => [20005, 20006, 20403],
            ErrorClass::RateLimit->value => [20429, 63018],
            ErrorClass::NotOnWhatsApp->value => [63003],
            ErrorClass::Validation->value => [21211, 21606, 21610, 21612, 63007, 63016, 63032],
            ErrorClass::Network->value => [20500, 30008],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * `POST …/Messages.json` for this account.
     */
    private function messagesUrl(ChannelCredentials $credentials): string
    {
        return sprintf(
            '%s/%s/Accounts/%s/Messages.json',
            $this->baseUrl($credentials),
            self::API_VERSION,
            $credentials->requireConfig(self::ACCOUNT_SID_CONFIG_KEY),
        );
    }

    /**
     * `To` and `From` in Twilio's channel spelling, plus a status callback when one is configured.
     *
     * @return array<string, string>
     */
    private function envelope(ChannelCredentials $credentials, string $recipient): array
    {
        return [
            'To' => self::CHANNEL_PREFIX.$recipient,
            'From' => self::CHANNEL_PREFIX.$this->senderIdentity($credentials),
        ];
    }

    /**
     * Basic auth from the Account SID and the auth token.
     *
     * Built here rather than handed to the HTTP client's `withBasicAuth()` because a
     * `BspRequest` describes a request and does not perform one — see that class. The header is
     * redacted out of any debug dump by its own `__debugInfo()`.
     *
     * @return array<string, string>
     */
    private function authHeaders(#[SensitiveParameter] ChannelCredentials $credentials): array
    {
        return [
            'Authorization' => 'Basic '.base64_encode(sprintf(
                '%s:%s',
                $credentials->requireConfig(self::ACCOUNT_SID_CONFIG_KEY),
                $credentials->requireSecret($this->authSecretKey()),
            )),
        ];
    }

    /**
     * Twilio's canonical signed string: the full callback URL, then every POST parameter in
     * lexical key order as `key` immediately followed by `value`.
     *
     * `fullUrl()` rather than `url()`: Twilio signs the URL it was configured with, query string
     * included, and dropping the query would fail to verify every callback on a URL that has one.
     */
    private static function canonicalPayload(Request $request): string
    {
        $parameters = [];

        foreach ($request->request->all() as $key => $value) {
            // A repeated parameter arrives as an array; Twilio signs each occurrence's value in
            // order, which concatenation reproduces.
            $parameters[(string) $key] = is_array($value)
                ? implode('', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value))
                : (is_scalar($value) ? (string) $value : '');
        }

        ksort($parameters, SORT_STRING);

        $canonical = $request->fullUrl();

        foreach ($parameters as $key => $value) {
            $canonical .= $key.$value;
        }

        return $canonical;
    }

    /**
     * Why Twilio says a message failed, from its error **code** and nothing else.
     *
     * `ErrorMessage` is prose that quotes the request; the code is what an operator matches
     * against the table in `classify()`.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function failureReason(array $payload): string
    {
        $code = BridgeWire::stringOrNull($payload['ErrorCode'] ?? null);

        if ($code === null) {
            return 'Twilio reported this message as failed without naming a code.';
        }

        return sprintf('Twilio reported this message as failed (code %s).', $code);
    }
}
