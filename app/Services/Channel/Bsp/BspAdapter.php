<?php

declare(strict_types=1);

namespace App\Services\Channel\Bsp;

use App\Enums\BspProvider;
use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ErrorClass;
use App\Exceptions\Channel\ChannelOperationException;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\ChannelTemplateException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Models\CloudApiTemplate;
use App\Services\Bridge\MediaPayload;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\InboundEvent;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * One BSP partner's **dialect** — its endpoints, its auth scheme, its body shapes, its error
 * vocabulary and its webhook signature — behind a contract that knows about none of them
 * (Req 8.1, 8.2 / A8; design § Channel Mode 2.2 mode 4, 2.3, 2.7).
 *
 * ```php
 * // BspGatewayChannelDriver::send(), for every partner alike
 * $adapter = $this->adapters->for($credentials->provider);           // resolved, never switched on
 * $request = $adapter->textMessage($credentials, $recipient, $text);
 * $payload = $this->guarded($operation, $credentials, $adapter, $request);
 * $result  = $adapter->readSendResult($payload);
 * ```
 *
 * ## Why eight adapters and not one `match ($provider)`
 *
 * design § 2.2 asks for *"one driver, **many providers** behind a per-provider adapter"*, and
 * the reason is arithmetic. Each partner differs on six axes — endpoint, auth scheme, request
 * shape, template route, error vocabulary, webhook signature — so a driver that switched on the
 * provider would carry **six** eight-armed `match` expressions whose arms have to stay aligned
 * by hand. Adding a ninth partner would mean editing six places in a class that also owns
 * idempotency, the circuit breaker, the recipient check and the capability sub-matrix, with a
 * missed arm silently sending a Kaleyra body to Twilio's endpoint under Gupshup's auth header.
 *
 * With this contract, a ninth partner is a new `BspProvider` case plus one class, and
 * `BspAdapterRegistry` fails at construction if the two do not line up. Nothing in
 * `BspGatewayChannelDriver` mentions a partner by name.
 *
 * ## An adapter is a pure function, and that is enforced by what it is given
 *
 * It receives `ChannelCredentials` and returns a `BspRequest`. It gets no HTTP client, no
 * container, no logger and no database handle, so it *cannot* perform I/O, retry, open a
 * breaker, or read a credential row of its own. Everything the platform insists on around a
 * provider call stays in the driver, where it is written once:
 *
 * | Concern | Owner |
 * |---|---|
 * | circuit breaker + bounded inline retry | `App\Services\Channel\ProviderCallGuard`, via the driver |
 * | timeouts, `Http::fake()`-ability | the driver's injected `Illuminate\Http\Client\Factory` |
 * | idempotency on `OutboundContent::idempotencyKey()` | `Concerns\DedupesChannelSends`, via the driver |
 * | ownership of a session id | `Concerns\ResolvesOwnedSession`, via the driver |
 * | the **recipient** check on an inbound webhook | the driver — see `recipientIn()` |
 * | template approval, and the variable check | the driver, against `cloud_api_templates` |
 * | the capability **ceiling** | `ChannelCapability::supportOn()`, via `DerivesChannelPolicy` |
 *
 * ## The one thing an adapter must not be able to do
 *
 * `refineSupport()` is the runtime layer of the per-provider capability sub-matrix, and it is
 * the member with a genuine safety property attached: **it can narrow and it can never widen.**
 * The mechanism is not this interface's trust in eight implementations — the driver combines
 * whatever is returned here through `ChannelCapabilitySupport::refinedBy()`, whose
 * `Unsupported` arm is absorbing, *and* declines to call this at all for a capability the mode
 * already refuses. An adapter that answered `Native` for `GROUPS` changes nothing.
 *
 * That is Property 21's floor, and the failure it prevents is specific: a partner that could
 * widen `GROUPS` would have a group-create dispatched to a gateway with no such API,
 * mid-operation, after the capability gate had already allowed it — the provider error
 * `ModeCapabilityException` exists to replace with a typed pre-dispatch refusal.
 *
 * ## Implementations
 *
 * | Adapter | Auth | Send route | Webhook proof |
 * |---|---|---|---|
 * | `Adapters\TwilioAdapter` | Basic, Account SID + auth token | `POST /2010-04-01/Accounts/{sid}/Messages.json` (form) | `X-Twilio-Signature`: base64 HMAC-SHA1 over URL + sorted params |
 * | `Adapters\ThreeSixtyDialogAdapter` | `D360-API-KEY` | `POST /messages` (Cloud-API-shaped JSON) | `X-Hub-Signature-256`: hex HMAC-SHA256 over the raw body |
 * | `Adapters\GupshupAdapter` | `apikey` | `POST /wa/api/v1/msg` (form) | `X-Gupshup-Signature`: base64 HMAC-SHA256 over the raw body |
 * | `Adapters\VonageAdapter` | `Authorization: Bearer` — RS256 JWS from the application private key, or Basic | `POST /v1/messages` (JSON) | `Authorization: Bearer` — HS256 JWT with `payload_hash` |
 * | `Adapters\MessageBirdAdapter` | `Authorization: AccessKey` | `POST /v1/send` (JSON) | `MessageBird-Signature-JWT`: HS256 JWT with `payload_hash` + `url_hash` |
 * | `Adapters\InfobipAdapter` | `Authorization: App` | `POST /whatsapp/1/message/text` (JSON) | shared secret in an operator-named header |
 * | `Adapters\WatiAdapter` | `Authorization: Bearer` | `POST /api/v1/sendSessionMessage/{number}` (JSON) | shared secret in an operator-named header |
 * | `Adapters\KaleyraAdapter` | `api-key` | `POST /v1/{sid}/messages` (JSON) | shared secret in an operator-named header |
 *
 * The last three are the partners that publish no webhook MAC; `BspWebhookVerifier` sets out
 * what that costs and why a signature scheme was not invented for them.
 */
interface BspAdapter
{
    /*
    |--------------------------------------------------------------------------
    | Which partner this is
    |--------------------------------------------------------------------------
    */

    /**
     * The partner this adapter speaks for.
     *
     * A constant per class, and the key `BspAdapterRegistry` files it under — which is checked
     * against `BspProvider::cases()` at construction, so a partner cannot be registered twice or
     * left out.
     */
    public function provider(): BspProvider;

    /**
     * The partner's default API host, for a tenant that configured no `base_url`.
     *
     * Also what `BspGatewayChannelDriver::isReachable()` probes: it is the one URL that can be
     * named for a partner without holding anybody's credentials.
     *
     * Several partners are **per-account** rather than global — Infobip issues a personal
     * `{id}.api.infobip.com` and WATI a numbered `live-server-{n}.wati.io` — so for those the
     * default is only a shape, and `baseUrl()` must prefer the credentials' own value.
     */
    public function defaultBaseUrl(): string;

    /**
     * The API host these credentials use, without a trailing slash.
     *
     * From the credentials' `base_url` when the tenant set one — §2.7 lists
     * *"endpoint/base URL"* among `BSP_GATEWAY`'s required config precisely because several
     * partners are per-account — and `defaultBaseUrl()` otherwise. A value that is not an
     * absolute `http(s)` URL is treated as absent rather than used, because a partner call
     * carries the tenant's API key and a malformed host is how it is sent somewhere else.
     */
    public function baseUrl(ChannelCredentials $credentials): string;

    /**
     * The `config` keys this partner cannot work without — names only, never values.
     *
     * What `BspGatewayChannelDriver::healthCheck()` reports instead of probing, so task 7.6 can
     * tell a tenant which field to fill in and keep the previous working set
     * (Req 8.6, 8.13 / A8).
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array;

    /**
     * The `secret_config` keys this partner cannot work without — names only.
     *
     * Always includes the credential the partner authenticates with, and the webhook secret:
     * a connection that can only talk is not connected, since without the webhook secret no
     * inbound message can ever be verified (`CloudApiChannelDriver` makes the same call about
     * Meta's `app_secret`).
     *
     * @return list<string>
     */
    public function requiredSecretKeys(): array;

    /**
     * The sender identity these credentials own — the **expected** side of the webhook recipient
     * check, normalised into the same spelling `recipientIn()` returns.
     *
     * This is the security content of `parseWebhook()` and the reason it is read from the
     * *credentials*: a partner signature proves who sent a body, not whose number it is about,
     * and one partner account can legitimately front several tenants' numbers (Req 8.4,
     * Property 23). Six partners identify a sender by phone number, Gupshup by application name,
     * MessageBird by channel id — so normalisation is the adapter's, and it must be applied
     * identically on both sides or a correct payload would be refused.
     *
     * @throws InvalidArgumentException when the credentials name no sender — a configuration
     *                                  defect, not something a request can provoke
     */
    public function senderIdentity(ChannelCredentials $credentials): string;

    /**
     * This partner's answer for `$capability`, given the ceiling it must stay under — the
     * runtime layer of the per-provider capability sub-matrix (design § 2.3).
     *
     * ## The three layers, and which one this is
     *
     * | Layer | Source | Owner |
     * |---|---|---|
     * | 1. mode ceiling | `ChannelCapability::supportOn(BSP_GATEWAY)` | the enum |
     * | 2. what design.md says about the named partner | `BspProvider::declaredSupport()` | the enum |
     * | 3. **this** — what the partner *account* turns out to allow | `channel_credentials.config` | the adapter |
     *
     * Layer 3 is read from the credentials because that is where the answer differs: whether a
     * given Twilio account has the Content API enabled, whether a Gupshup app has an approved
     * template namespace, whether a partner's status callbacks were configured at all, are facts
     * about *one tenant's contract with one partner* — not about the partner, and certainly not
     * an operator preference that could live in `config/wa.php`. design § 2.3 says as much: the
     * sub-matrix is *"resolved from `channel_credentials`"*.
     *
     * ## What an implementation must guarantee
     *
     * - **No I/O.** `supports()` is called on every send and once per capability at session
     *   create (Req 8.2), so read from the credentials already in memory and never from the
     *   network or the database.
     * - **Deterministic** for one credential set: task 8.2 persists the resolved set as a
     *   session's authoritative capability list, and an answer that varied between two calls
     *   would make the persisted set a snapshot of nothing.
     * - **Narrow only.** Returning something wider than `$ceiling` is not forbidden by the
     *   signature and does not need to be: the driver passes it through
     *   `ChannelCapabilitySupport::refinedBy()` and never asks about a capability the mode
     *   refuses. Write the honest answer; the floor is structural.
     */
    public function refineSupport(
        ChannelCapability $capability,
        ChannelCapabilitySupport $ceiling,
        ChannelCredentials $credentials,
    ): ChannelCapabilitySupport;

    /*
    |--------------------------------------------------------------------------
    | Outbound — the request shapes
    |--------------------------------------------------------------------------
    */

    /**
     * The request that sends `$text` to `$recipient`.
     *
     * `$recipient` arrives normalised to E.164 digits by the driver, because that is the one
     * piece of recipient handling every partner shares and getting it wrong is how *"one
     * tenant's typo becomes a message to a stranger"* (`HttpBridgeClient`'s phrase). Adding the
     * partner's own presentation — Twilio's `whatsapp:+` prefix, Vonage's bare digits — is the
     * adapter's.
     *
     * @throws InvalidArgumentException when the credentials cannot address a send
     */
    public function textMessage(ChannelCredentials $credentials, string $recipient, string $text): BspRequest;

    /**
     * The request that sends one media attachment to `$recipient`.
     *
     * `$media` always carries a **URL**: every one of the eight partners fetches media from a
     * link, and none of them shares an upload route, so `BspGatewayChannelDriver::sendMedia()`
     * refuses an inline-bytes payload before an adapter sees it rather than each adapter
     * inventing an upload. That is also the platform's normal path — `MediaPayload::fromUrl()`
     * exists so *"nothing large crosses the PHP boundary"*.
     *
     * @throws ChannelOperationException when this partner has no send-by-link media route at all
     *                                   — `WatiAdapter` is the case, and its `refineSupport()`
     *                                   refuses `MEDIA` so the capability gate stops the operation
     *                                   before dispatch; this is the answer to a direct caller
     * @throws InvalidArgumentException when this partner cannot express the payload's kind
     */
    public function mediaMessage(ChannelCredentials $credentials, string $recipient, MediaPayload $media): BspRequest;

    /**
     * The request that sends one approved template to `$recipient`.
     *
     * The row is handed over whole so the adapter can address the template the way its partner
     * does: `CloudApiTemplate::$provider_template_id` is the partner's own handle (Twilio's
     * `ContentSid`, Gupshup's template id, Infobip's registered name), and `$name`/`$language`
     * are the fallback for the partners that address a template by name.
     *
     * `$parameters` is **positional** and already validated: the driver reads the placeholders
     * out of the stored body, refuses a mismatch locally with `ChannelTemplateException`, and
     * hands over the values in `{{1}}, {{2}}, …` order — so a mis-filled template is never *"a
     * provider rejection charged to the tenant's quality rating"* (`ChannelDriver::sendTemplate()`).
     *
     * @param  list<string>  $parameters  placeholder values, in ascending positional order
     *
     * @throws ChannelTemplateException when the row carries nothing this partner can address — a
     *                                  partner that addresses a template only by its own id
     *                                  (Twilio's `ContentSid`) cannot send a row task 8.4's sync
     *                                  has never given a `provider_template_id`, and that is a
     *                                  422 the tenant can act on rather than a 500
     */
    public function templateMessage(
        ChannelCredentials $credentials,
        string $recipient,
        CloudApiTemplate $template,
        array $parameters,
    ): BspRequest;

    /**
     * A **read-only** request that proves whether these credentials work.
     *
     * What `healthCheck()` sends and what `sessionState()` reads. Read-only is a requirement
     * rather than a preference: the credentials being probed are about to be accepted or
     * rejected by task 7.6, and a probe that registered a number or sent a message would have
     * side effects on a set that is about to be thrown away.
     */
    public function accountProbe(ChannelCredentials $credentials): BspRequest;

    /*
    |--------------------------------------------------------------------------
    | Reading what came back
    |--------------------------------------------------------------------------
    */

    /**
     * The partner's message id out of an accepted send, or `null` when the body carries none.
     *
     * `null` makes the driver raise a transport failure, which is the right answer: a 2xx with
     * no id is a send nothing could ever be reconciled with, and `SendReceipt` refuses to
     * represent one.
     *
     * @param  array<string, mixed>  $payload  the decoded response body
     */
    public function readSendResult(array $payload): ?BspSendResult;

    /**
     * The refusal this response describes, or `null` when it describes none.
     *
     * Called on **every** response, not only on a non-2xx, and that is deliberate: WATI answers
     * `HTTP 200` with `{"result": false}`, so a driver that built a refusal only from
     * `$response->failed()` would record a message as sent that WATI never accepted. A partner
     * whose 2xx always means acceptance simply returns `null` for a 2xx.
     *
     * When the status is a failure and this returns `null` — an HTML error page from a proxy, a
     * body shape the partner added later — the driver builds a status-only refusal, so an
     * unreadable failure is still a typed one.
     *
     * @param  array<string, mixed>  $payload  the decoded response body, or `[]` if it was not JSON
     * @param  int  $status  the HTTP status the partner answered with
     * @param  int|null  $retryAfterSeconds  a numeric `Retry-After` header, when the partner sent one
     */
    public function refusalIn(array $payload, int $status, ?int $retryAfterSeconds): ?BspRefusal;

    /**
     * A short, tenant-facing sentence about what an account probe proved, or `null` when the
     * body does not establish that the credentials work.
     *
     * Rendered from identifiers and states the partner returned — never from its prose, which
     * echoes the request and on a `401` a fragment of the key it was sent. `ChannelHealth` runs
     * even this through `ChannelCredentials::redact()`, so both the belt and the braces are on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function accountDetailIn(array $payload): ?string;

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Refuse `$request` unless it proves it came from this partner.
     *
     * Step 1 of the three `ChannelDriver::parseWebhook()` prescribes — origin, then recipient,
     * then shape — and the only one that is partner-specific enough to live here. The comparison
     * itself is never written in an adapter: `BspWebhookVerifier` carries the five schemes, so
     * an adapter names its header and its scheme and nothing else.
     *
     * @throws WebhookVerificationException when the proof is absent or does not check out
     */
    public function verifyWebhook(
        Request $request,
        ChannelCredentials $credentials,
        BspWebhookVerifier $verifier,
    ): void;

    /**
     * The verified request body as an array.
     *
     * A method rather than an assumption because Twilio posts
     * `application/x-www-form-urlencoded` while the other seven post JSON. Called **after**
     * `verifyWebhook()`, always: a body is decoded only once its origin is established.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException when the body is not the documented encoding
     */
    public function decodeWebhook(Request $request): array;

    /**
     * The sender identity the payload claims to be about, normalised as `senderIdentity()`
     * normalises — or `null` when this payload names none.
     *
     * When it returns a value the driver compares it against `senderIdentity()` with
     * `hash_equals()`, and a mismatch is `WebhookVerificationException::wrongRecipient()` — the
     * check that stops a validly-signed body about tenant A's number from becoming an
     * `InboundEvent` carrying tenant B's id (Req 8.4, Property 23). What happens on a `null` is
     * `requiresRecipientClaim()`'s business.
     *
     * The `Request` is passed as well as the payload because Twilio's recipient is a form field
     * of the request rather than a member of a JSON object, and because a partner that one day
     * puts it in a header should not need a new member here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recipientIn(array $payload, Request $request): ?string;

    /**
     * Whether **every** callback from this partner names the number it is about, so a payload
     * that does not is malformed rather than merely quiet.
     *
     * ```
     * recipientIn() !== null              → compare; a mismatch is wrongRecipient()
     * recipientIn() === null, true here   → malformedPayload()
     * recipientIn() === null, false here  → the check is skipped, and the class docblock says why
     * ```
     *
     * `true` for the four partners whose every callback shape carries the business identity
     * (Twilio's `To`, 360dialog's `metadata.display_phone_number`, Gupshup's `app`,
     * MessageBird's `channelId`).
     *
     * `false` is a **documented weakening**, not a convenience, and each adapter that returns it
     * must argue the case in its own docblock. Two situations produce it honestly:
     *
     * 1. the partner names the identity on an inbound message and **not** on a delivery report
     *    (Vonage, Infobip, Kaleyra) — refusing the report would 403 every receipt;
     * 2. the partner's callback names it nowhere at all (WATI).
     *
     * What carries the binding in those cases is that the webhook secret is **per credential
     * row**, i.e. per `(tenant, BSP_GATEWAY, provider)`: only that tenant's secret verifies the
     * body, so a partner account belonging to one tenant cannot sign traffic that verifies for
     * another. That argument does *not* hold for a partner account shared between two tenants of
     * this platform, which is exactly the case the payload check covers — so the residual risk is
     * real, bounded to those four partners, and stated rather than hidden.
     */
    public function requiresRecipientClaim(): bool;

    /**
     * Every canonical event this payload carries, in document order.
     *
     * A **list**, because partners batch: 360dialog forwards Meta's `entry[].changes[]` envelope
     * verbatim, so one HTTP request can carry several messages and several receipts. Returning
     * `[]` is legitimate and means *"verified, well-formed, and about nothing this release acts
     * on"* — the driver turns that into a single `InboundEvent::unsupported()`, which is a
     * successful parse and a `200` so the partner stops retrying.
     *
     * `$identity` is the already-checked sender identity, to be carried as the event's
     * `channelIdentity`. Every event's `tenantId` must come from `$credentials`, never from the
     * payload — that is the whole point of the check that ran before this.
     *
     * @param  array<string, mixed>  $payload
     * @return list<InboundEvent>
     *
     * @throws WebhookVerificationException when the body is verified but not the documented shape
     */
    public function eventsIn(
        array $payload,
        Request $request,
        ChannelCredentials $credentials,
        string $identity,
    ): array;

    /*
    |--------------------------------------------------------------------------
    | Retry policy
    |--------------------------------------------------------------------------
    */

    /**
     * What this partner's refusal means for the message's fate, or `null` when this adapter does
     * not recognise it.
     *
     * The partner's error vocabulary, and the reason it lives beside the request shape rather
     * than in one big classifier: eight partners number their errors independently — Twilio's
     * `20003` is an authentication failure, MessageBird's `2` is, Infobip spells the same thing
     * `UNAUTHORIZED` — and the class that knows a partner's endpoints is the class that knows
     * its codes. `App\Services\Channel\BspGatewayErrorClassifier` is the single entry in
     * `wa.reliability.retry.classifiers` that dispatches here on `$e->provider`.
     *
     * A `null` lets the classifier fall back to the HTTP status, which is every partner's rough
     * statement. An unrecognised refusal must therefore **keep** the work rather than drop it —
     * see that class for the argument.
     */
    public function classify(ChannelRequestFailedException $e): ?ErrorClass;
}
