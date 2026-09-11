<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelCapability;
use App\Enums\ChannelMode;
use App\Enums\InboundEventKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Exceptions\Bridge\BridgeRequestFailedException;
use App\Exceptions\Bridge\BridgeUnreachableException;
use App\Exceptions\Channel\ChannelCredentialException;
use App\Exceptions\Channel\ChannelOperationException;
use App\Exceptions\Channel\ChannelRequestFailedException;
use App\Exceptions\Channel\ChannelTemplateException;
use App\Exceptions\Channel\WebhookVerificationException;
use App\Exceptions\Reliability\IdempotencyKeyReuseException;
use App\Exceptions\Reliability\OperationInFlightException;
use App\Models\CloudApiTemplate;
use App\Models\Session;
use App\Services\Bridge\BridgeWire;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\NumberCheck;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionInitDto;
use App\Services\Bridge\SessionStateDto;
use App\Services\Channel\Concerns\DedupesChannelSends;
use App\Services\Channel\Concerns\DerivesChannelPolicy;
use App\Services\Channel\Concerns\ResolvesOwnedSession;
use App\Services\Channel\Concerns\VerifiesProviderSignature;
use App\Services\Reliability\IdempotencyStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The `CLOUD_API` driver: Meta's **WhatsApp Cloud API**, the official Meta-hosted Business
 * Platform, behind the `ChannelDriver` contract (Req 8.2, 8.4, 8.12 / A8; design § Channel
 * Mode 2.2 mode 2, 2.3, 2.5, 2.7).
 *
 * ```php
 * // Registered by ChannelServiceProvider; obtained only through the router, wrapped.
 * $driver = $router->driverFor($session);          // ModeGuardedChannelDriver(CloudApiChannelDriver)
 *
 * $receipt = $driver->send($session, TextContent::to('919812345678', 'Your order shipped.', $key));
 * $receipt = $driver->sendTemplate($session, TemplateRef::fromModel($template), ['1' => 'ACME']);
 * ```
 *
 * It is the reference implementation for the **official** modes: tasks 7.3
 * (`OnPremiseChannelDriver`) and 7.4 (`BspGatewayChannelDriver`) inherit the four pieces this
 * class deliberately does not own — `Concerns\VerifiesProviderSignature`,
 * `Concerns\DedupesChannelSends`, `Concerns\ResolvesOwnedSession` and `ProviderCallGuard` —
 * plus the two exception shapes (`ChannelRequestFailedException`, `ChannelOperationException`)
 * and the *pattern* of a per-mode `ErrorClassifier`. What is genuinely Meta's — the Graph API
 * routes, the error codes, the `entry[].changes[]` webhook envelope — is here and nowhere else.
 *
 * ## It depends on interfaces, so the guards cannot be lost
 *
 * The constructor takes `ChannelCredentialStore`, `IdempotencyStore` and Laravel's HTTP
 * client **factory** — never a concrete store, never `new PendingRequest`, and never a token
 * captured at construction. This is the argument `BaileysChannelDriver` makes about
 * `BridgeClient`, transposed:
 *
 * | If this class had… | What would silently break |
 * |---|---|
 * | its own `ChannelCredential::activeFor()` query | `DatabaseChannelCredentialStore`'s cross-tenant refusal and its audit trail; a driver could read another tenant's row (Property 23) |
 * | a `GuzzleClient` of its own | `Http::fake()` in tests, and the platform's connect/read timeouts |
 * | a memoised access token | Req 8.5's *"decrypted only within the lifetime of the request or job"*, and a rotated token would keep failing until the worker restarted |
 * | its own retry loop | two budgets multiplied together — `ProviderCallGuard` composes the one policy (`RetryPolicy`) with the breaker |
 *
 * ## Credentials — §2.7's row, key by key
 *
 * design § 2.7 gives `CLOUD_API`: *"WABA id, phone-number id, API version"* as config and
 * *"system-user **access token**, webhook **verify token**, app secret"* as secrets. Those six
 * keys are the constants below, and each is read at the moment it is needed rather than held:
 *
 * | Key | Kind | Read by |
 * |---|---|---|
 * | `waba_id` | config | `register()` — the WABA whose phone numbers are enumerated |
 * | `phone_number_id` | config | every send (it *is* the route), and `parseWebhook()`'s recipient check |
 * | `api_version` | config | the Graph API version segment; falls back to `wa.channel.cloud_api.api_version` |
 * | `access_token` | secret | every Graph API request, as a bearer token |
 * | `verify_token` | secret | `verifySubscription()`'s `hub.verify_token` comparison |
 * | `app_secret` | secret | `parseWebhook()`'s `X-Hub-Signature-256` verification |
 *
 * ## `send()` — what reaches the wire, and what "degraded" means here
 *
 * | Content | Graph API body | `SendReceipt::$degraded` |
 * |---|---|---|
 * | `TextContent` (`SEND_SINGLE`, `✅`) | `CloudApiMessage::text()` | `false` |
 * | a rich variant of task 12.4 (`INTERACTIVE`, `✅` on this mode) | `CloudApiMessage::buttons()` / `::list()` | `false` — Meta renders it natively |
 *
 * The flag is `! supportFor($capability)->isNative()`, read from the same matrix the gate
 * reads, exactly as 7.1 computes it — so a `⚠️` cell degrades and a `✅` cell does not, and the
 * two cannot drift. On this mode the `⚠️` cells are *provider-rule* conditions rather than
 * renderings (see the bulk note below), which is why nothing a `TextContent` can express
 * degrades here.
 *
 * `send()` has one dispatch arm today, and that is a statement about `OutboundContent` rather
 * than about Cloud API: no variant of it can express a media attachment or an interactive
 * payload — task 12.4 owns that family — so there is nothing for a second arm to dispatch on,
 * and inventing the accessor here would fix the shape of an interface before the task that
 * owns it exists. Both wire shapes are nevertheless *complete*: media through `sendMedia()`
 * below (a transport method the contract already declares, used by the media send path), and
 * interactive through `CloudApiMessage::buttons()` / `::list()`, which are built, validated and
 * tested. Task 12.4 adds one `match` arm and changes nothing else.
 *
 * ### Bulk is `⚠️`, and honouring that means *not* inventing a bulk endpoint
 *
 * `SEND_BULK` on `CLOUD_API` is *"⚠️ template + quality tier"*. There is no bulk route on the
 * Graph API: a campaign is N calls to `/{phone-number-id}/messages`, paced by the number's
 * **messaging limit** and quality rating rather than by the anti-ban ramp
 * (`requiresAntiBan()` is `false` here, Property 24). So this driver exposes a single send and
 * the campaign runner iterates it — which is what the `⚠️` describes.
 *
 * The tier check itself is **task 8.1's**: Algorithm 9 calls `driver.providerRateVerdict(message)`
 * on the `ELSE` branch, and that member is not on the `ChannelDriver` interface (design.md
 * declares eight members and this is not among them). The seam left for it is
 * `ProviderCallGuard::allows()` plus `CloudApiErrorClassifier`'s `RATE_LIMIT` mapping: until
 * 8.1 lands, a number over its limit is discovered from Meta's `130429`/`4` and **deferred**
 * with Meta's own `Retry-After`, which is the same outcome one step later.
 *
 * ## `sendTemplate()` — the local registry decides, the 24-hour window does not live here
 *
 * The order is: resolve credentials → re-read the template row → check the variables → build
 * the component payload → POST. Every refusal before the POST is a `ChannelTemplateException`
 * (422), because `ChannelDriver::sendTemplate()` requires that *"a missing or surplus variable
 * is a local failure and not a provider rejection charged to the tenant's quality rating"* —
 * and the same is true of an unapproved template.
 *
 * The row is **re-read on every send** (`CloudApiTemplate::sendable()`), never taken from the
 * `TemplateRef`: that type carries only `(name, language, credentialId)` precisely so a
 * template Meta paused five minutes ago cannot still look sendable.
 *
 * **The 24-hour-window rule is task 8.4 and is deliberately absent.** The seam it plugs into is
 * exactly this method: 8.4 decides *whether* a free-form `send()` is allowed or a template is
 * required (`TemplateRequiredException`, Req 8.12) and then calls `sendTemplate()` — which
 * needs no argument for it, because a template send is legal inside the window as well as
 * outside. Nothing here consults a conversation's last-inbound timestamp, and nothing here
 * should: this driver has no conversation state, and a window check that lived in two places
 * would disagree the day one of them was fixed.
 *
 * ## `parseWebhook()` — the recipient comes from the credentials, never from the payload
 *
 * The order the contract prescribes is origin → recipient → shape, and the middle step is the
 * security content of this method. Meta's payload states its own recipient
 * (`value.metadata.phone_number_id`), and so do the credentials — and the credentials' answer
 * is the only one trusted, with the payload's checked *against* it.
 *
 * The attack this closes is not hypothetical for Cloud API, because **one Meta app serves many
 * WABAs**: a body signed with tenant A's app secret is a validly-signed body, and if it were
 * verified under whichever number *it names*, POSTing it to tenant B's route key would produce
 * an `InboundEvent` carrying **B's tenant id** (that is where `tenantId` comes from) and A's
 * message content. One valid signature would inject messages into any other tenant's
 * conversation history, spend B's AI credits, and reply from B's number.
 * `WebhookVerificationException::wrongRecipient()` is that refusal, and
 * `CloudApiChannelDriverTest` asserts it directly.
 *
 * ### Signature, and why it reuses the platform's HMAC config
 *
 * `X-Hub-Signature-256` is `HMAC-SHA256` over the **raw** body under the tenant's Meta **app
 * secret**, compared in constant time. All of that is `Concerns\VerifiesProviderSignature`,
 * which reads `wa.security.hmac.algorithm` and `wa.security.hmac.prefix` — the same config
 * `DatabaseSigningSecretStore` uses, and which already documents `sha256=<hex>` as *"the shape
 * Meta and most gateways use"*. What is **not** reused is `SigningSecretStore` itself: that
 * store issues, seals and rotates secrets *this platform* owns, and Meta's app secret is
 * issued by Meta, entered by a tenant, and only ever verified. See the trait for the full
 * argument.
 *
 * ### Meta batches, and a single `InboundEvent` cannot say so
 *
 * A Cloud API webhook body is `entry[].changes[].value.{messages[],statuses[]}` — Meta
 * explicitly batches, so **one HTTP request can carry several logical events**, and
 * `InboundEvent`'s own docblock names this: the four wire formats *"agree on … not even how
 * many logical events one HTTP request carries"*.
 *
 * `parseWebhook()` returns one event, so this class resolves it in the only way that loses
 * nothing:
 *
 * - **`parseWebhookBatch()` is the real method.** It verifies once and returns
 *   `list<InboundEvent>` — every message and every status, in document order.
 * - **`parseWebhook()` returns the first of them**, and is what the interface requires.
 * - **Every event carries the batch shape in its payload** (`_batch_size`, `_batch_index`), so
 *   a caller holding a single event can *detect* that it is one of several rather than
 *   discovering it from a customer complaint.
 *
 * **Task 8.3 must call `parseWebhookBatch()`.** Its controller resolves `(tenant, session,
 * driver)` from `channel_webhook_routes.route_key` and then parses; if it calls
 * `parseWebhook()` on a batched body it will acknowledge with a `200` and silently drop every
 * event but the first — a dropped customer message, with no error anywhere. The alternative
 * designs were worse: throwing on a batch would 403 legitimate traffic and make Meta retry the
 * whole body forever, and splitting the request into several fake ones would re-verify a
 * signature per event and multiply the cost of a body Meta already batched for us.
 *
 * ### What each Meta field becomes
 *
 * | Payload | Canonical kind | Notes |
 * |---|---|---|
 * | `value.messages[]` | `MESSAGE` | `from`, `id`, `timestamp`; `text.body`, or an interactive reply's title |
 * | `value.statuses[].status = sent` | `DELIVERY_RECEIPT` | see the monotonicity note |
 * | `value.statuses[].status = delivered` | `DELIVERY_RECEIPT` | |
 * | `value.statuses[].status = read` | `READ_RECEIPT` | |
 * | `value.statuses[].status = failed` | `SEND_FAILURE` | `errors[0]` becomes the redacted `failureReason` |
 * | `changes[].field ≠ messages` (`message_template_status_update`, `account_update`, …) | `UNSUPPORTED` | a verified payload this release does not act on: a `200`, not a 403 storm |
 *
 * ### Status monotonicity is not this driver's to enforce, and that is the convention
 *
 * `sent → delivered → read` must never downgrade (Req 20.6 / C3, Property 9), and
 * `InboundEventKind`'s docblock is explicit about where that lives: *"The three receipt cases
 * describe what the provider just told us. The delivery lifecycle a message row holds — and
 * its never-downgrades rule — is a separate vocabulary owned by the messaging phase, which
 * maps from these."* So this driver reports **what Meta said**, in Meta's own words, and task
 * 9.5 applies the monotonic rule when it writes the message row.
 *
 * Two consequences are deliberate. `sent` and `delivered` collapse into one kind, as that
 * enum's table prescribes — so the raw `status` string is preserved in the event's payload,
 * where 9.5 can read it to tell "accepted by Meta" from "on the device". And `occurredAt` is
 * Meta's own `timestamp`, never `now()`, because ordering two receipts that arrived out of
 * order needs the provider's clock and a fabricated one is indistinguishable from a real one
 * afterwards.
 *
 * ## `verifySubscription()` — the `GET` handshake, and a documented divergence
 *
 * Meta confirms a callback URL with `GET ?hub.mode=subscribe&hub.verify_token=…&hub.challenge=…`
 * and expects the challenge echoed verbatim. That is implemented here (task 7.2's scope), and
 * it is **not** part of `parseWebhook()`, which sees POSTs — the contract says so.
 *
 * The divergence worth naming: `ChannelDriver::parseWebhook()`'s docblock and
 * `ChannelWebhookRoute::matchesVerifyToken()` both assign the handshake to **task 8.3**, at the
 * route. Both mechanisms compare the *same* token — the route stores its SHA-256 digest, the
 * credential bag stores the value — so they agree by construction, and 8.3's controller should
 * call this method rather than reimplement the comparison, keeping one constant-time
 * comparison of one token in one place. A token compared with `==` is a webhook takeover, so
 * having two comparisons is exactly one too many.
 *
 * ## `register()` and `healthCheck()`
 *
 * - **`register()`** verifies the number *against the WABA*: one call to
 *   `GET /{waba_id}/phone_numbers` and a search for the configured `phone_number_id`. That
 *   single call answers both halves of *"WABA / phone-number-id verification"* — a wrong WABA
 *   id fails at the provider, and a number that is not on it is simply absent from the list.
 *   It reports state and does not throw: `live()` once Meta says the number is `VERIFIED`,
 *   `pending()` while verification is outstanding or the number is not on the WABA yet. A
 *   registration that *failed at the provider* is still an exception, as the contract requires
 *   — that is the guarded call's `ChannelRequestFailedException`.
 *   No callback is claimed. Meta's callback URL is configured on the **App**, not on a phone
 *   number, and needs an app access token §2.7 does not list among this mode's credentials;
 *   the `route_key` is minted by task 8.3. Reporting a `callbackUrl` this driver did not
 *   register would put a URL on a panel that nothing answers — 7.1's reasoning, unchanged.
 * - **`healthCheck()`** probes `GET /{phone_number_id}` and reports as **data**, never by
 *   raising: an incomplete credential set is `unhealthy()` naming the missing keys (which is
 *   what task 7.6 needs in order to keep the previous working set), and a provider refusal is
 *   `unhealthy()` with a sentence rendered from Meta's error *code* — never its prose, which
 *   quotes the token it was sent. Only a transport failure propagates, because *"we could not
 *   ask"* and *"the provider said no"* lead to different operator actions and the contract
 *   reserves the exception for the first.
 *
 * ## The transport half: eleven inherited methods, three answers
 *
 * `ChannelDriver extends BridgeClient`, so this class inherits eleven methods shaped for the
 * WhatsApp Web protocol. Each gets the honest answer for a Meta-hosted number, and none gets a
 * silent no-op:
 *
 * | Method | On Cloud API |
 * |---|---|
 * | `sendText`, `sendMedia` | **real** — `/{phone-number-id}/messages`; media by `link`, or uploaded to `/media` first when the payload carries bytes |
 * | `sessionState` | **real** — `GET /{phone_number_id}`: a `VERIFIED` number is `CONNECTED`, otherwise `QR_PENDING` (awaiting provider-side verification) |
 * | `isReachable` | **real** — any answer at all from `graph.facebook.com` proves the dependency is up; it needs no credentials, so it is the one probe that works for a tenant who has entered none |
 * | `qr` | **`null`** — a documented answer, not a failure: `BridgeClient::qr()` already returns null for *"this session is not showing one"*, and an official number never shows one |
 * | `checkNumbers` | **all `unknown`** — the Cloud API has no contacts/`onWhatsApp` endpoint. `NumberCheck::isUnknown()` exists for exactly this, and answering `false` instead would record "not on WhatsApp" as a permanent fact and have every later campaign skip a real customer |
 * | `provisionSession`, `startSession`, `stopSession`, `pairingCode`, `sendPresence` | `ChannelOperationException` (422) — see that class for why a refusal beats a no-op |
 *
 * `stopSession()` is the one to be careful about: Meta *does* have a `deregister` route, and
 * calling it here would make "stop this session" silently surrender the number's registration
 * — a destructive, hard-to-reverse act triggered by a routine operation. So it refuses, and
 * deregistration stays a deliberate, audited tenant action for the mode-switch task (8.6).
 */
final readonly class CloudApiChannelDriver implements ChannelDriver
{
    use DedupesChannelSends;
    use DerivesChannelPolicy;
    use ResolvesOwnedSession;
    use VerifiesProviderSignature;

    /**
     * The header Meta presents its HMAC in.
     *
     * A constant rather than a config key, for the reason `BaileysChannelDriver` hardcodes its
     * own: this is Meta's protocol vocabulary, not an operator preference. The *algorithm* and
     * the `sha256=` presentation still come from `wa.security.hmac`, because those are shared
     * with every other signed webhook on the platform.
     */
    public const string SIGNATURE_HEADER = 'X-Hub-Signature-256';

    /**
     * `ChannelCredentials::config()` keys — design § 2.7's *"WABA id, phone-number id, API
     * version"*.
     */
    public const string WABA_CONFIG_KEY = 'waba_id';

    public const string PHONE_NUMBER_CONFIG_KEY = 'phone_number_id';

    public const string API_VERSION_CONFIG_KEY = 'api_version';

    /**
     * `ChannelCredentials::secret()` keys — §2.7's *"system-user access token, webhook verify
     * token, app secret"*. Every one of these names is matched by
     * `App\Support\Pii\PiiKeyRules::SECRET_PATTERN`, so a caller who logs the decrypted bag
     * still gets `[redacted]`.
     */
    public const string ACCESS_TOKEN_SECRET_KEY = 'access_token';

    public const string VERIFY_TOKEN_SECRET_KEY = 'verify_token';

    public const string APP_SECRET_KEY = 'app_secret';

    /**
     * The query parameters of Meta's `GET` subscription handshake.
     */
    public const string HUB_MODE_PARAM = 'hub.mode';

    public const string HUB_TOKEN_PARAM = 'hub.verify_token';

    public const string HUB_CHALLENGE_PARAM = 'hub.challenge';

    /**
     * The only `hub.mode` this platform answers.
     */
    public const string SUBSCRIBE_MODE = 'subscribe';

    /**
     * The longest challenge this will echo, and the shape it must have.
     *
     * Meta sends a short numeric nonce. The bound and the character class are here because the
     * value is **echoed back verbatim** — the one place a driver returns provider input to the
     * network — and an unbounded echo of arbitrary bytes on a public endpoint is a reflection
     * primitive waiting for a caller that forgets `text/plain`.
     */
    public const int MAX_CHALLENGE_LENGTH = 128;

    public const string CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    /**
     * The `object` a Cloud API webhook body declares, and the `changes[].field` this release
     * acts on. Anything else is a verified payload nobody consumes — `UNSUPPORTED`, not a 403.
     */
    public const string WEBHOOK_OBJECT = 'whatsapp_business_account';

    public const string MESSAGES_FIELD = 'messages';

    /**
     * Keys added to every event's payload so a caller holding **one** event can tell that Meta
     * batched several into the request — see the class docblock, and task 8.3.
     */
    public const string BATCH_SIZE_KEY = '_batch_size';

    public const string BATCH_INDEX_KEY = '_batch_index';

    /**
     * Graph API defaults, used when `wa.channel.cloud_api` and the tenant's `api_version` both
     * say nothing usable — so a deleted config key degrades to a working driver rather than to
     * a malformed URL.
     */
    public const string DEFAULT_BASE_URL = 'https://graph.facebook.com';

    public const string DEFAULT_API_VERSION = 'v21.0';

    public const int DEFAULT_TIMEOUT = 15;

    public const int DEFAULT_CONNECT_TIMEOUT = 5;

    /**
     * What Meta calls a phone number that has finished verification.
     */
    public const string VERIFIED_STATUS = 'VERIFIED';

    /**
     * Reserved `$vars` keys carrying the two things `ChannelDriver::sendTemplate()`'s signature
     * has no parameter for — see `sendTemplate()` for the full argument, and prefer
     * `sendTemplateTo()`.
     */
    public const string TEMPLATE_RECIPIENT_VAR = '_to';

    public const string TEMPLATE_KEY_VAR = '_key';

    /**
     * Health probes get a shorter budget than sends: the point of asking is to find out
     * quickly, and a probe that waits fifteen seconds to say "down" has already stalled the
     * screen it feeds (`HttpBridgeClient::HEALTH_TIMEOUT`'s reasoning).
     */
    private const int HEALTH_TIMEOUT = 5;

    public function __construct(
        private HttpFactory $http,
        private ChannelCredentialStore $credentials,
        private IdempotencyStore $idempotency,
        private ProviderCallGuard $guard,
    ) {}

    public function mode(): ChannelMode
    {
        return ChannelMode::CloudApi;
    }

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * Send one message through the Cloud API, at most once per idempotency key.
     *
     * @throws ChannelCredentialException when the tenant has no usable `CLOUD_API` credentials
     * @throws ChannelRequestFailedException when Meta refuses the send
     * @throws BridgeUnreachableException when Meta never answered, so the outcome is unknown
     * @throws IdempotencyKeyReuseException when the key was first used for a different send
     * @throws OperationInFlightException when a duplicate is still in flight
     */
    public function send(Session $session, OutboundContent $content): SendReceipt
    {
        $capability = $content->capability();
        $credentials = $this->credentialsFor($session);

        // Read from the matrix, not decided here — `❌` never arrives, because the router
        // refused it before dispatch (Property 21).
        $degraded = ! $this->supportFor($capability)->isNative();

        $outcome = $this->idempotency->once(
            self::sendScope($session),
            $content->idempotencyKey(),
            // The one dispatch arm; see the class docblock for why there is not yet a second.
            fn (): array => $this->dispatch(
                $session,
                $credentials,
                CloudApiMessage::text($content->recipient(), $content->plainText()),
                'cloud_api.message.text',
                $degraded,
            ),
            $this->sendOptions($session, $this->sendFingerprint(
                $session,
                $capability,
                $content->recipient(),
                $content->plainText(),
            )),
        );

        return $this->receipt($content->idempotencyKey(), $capability, $outcome->payload());
    }

    /**
     * Send a pre-approved template, at most once per idempotency key.
     *
     * ## The reserved `$vars` keys, and the contract gap behind them
     *
     * design § 2.4 declares `sendTemplate(Session, TemplateRef, array $vars): SendReceipt`, and
     * that signature is **missing two things every template send needs**: *who* it goes to, and
     * the idempotency key it is deduplicated on. Neither can be derived — the `Session` is the
     * *sender's* number, and `SendReceipt` refuses to exist without a recipient and a key
     * (rightly: a receipt nothing can be reconciled with is worse than a retry).
     *
     * So this method reads them from `$vars` under two reserved keys, `_to` and `_key`, and
     * `sendTemplateTo()` below is the honest signature that does the work. The reserved names
     * cannot collide with a real placeholder: Meta's positional parameters are `1`, `2`, … and
     * its named parameters are `[a-z0-9_]` **not** starting with an underscore, so a leading
     * `_` is unavailable to a template author.
     *
     * The gap is reported to task 8.4, which owns template sending end to end: the right fix is
     * a `TemplateContent implements OutboundContent` (it already answers `capability()`,
     * `recipient()`, `idempotencyKey()` and `plainText()` — exactly the four facts missing here)
     * so `sendTemplate()` can take one, and this reserved-key convention can be deleted.
     *
     * ## A second, smaller gap in the same signature
     *
     * The contract types `$vars` as `array<string, string|int|float>`, which **no positional
     * template can satisfy**: Meta numbers its body parameters `1`, `2`, … and PHP silently
     * coerces the array key `'1'` to the integer `1`, so `['1' => 'Asha']` is an
     * `array<int, string>` by the time any callee sees it. The `@param` here is widened to
     * `array-key` — legal for an implementation, since a parameter type may only be widened — and
     * both readers of the keys stringify them (`templateComponents()`, `reservedVar()`). Task 8.4
     * should widen the interface to match, or the only callers that type-check will be the ones
     * using named parameters.
     *
     * @param  array<array-key, string|int|float>  $vars  placeholder values, plus `_to` and `_key`
     *
     * @throws InvalidArgumentException when `_to` or `_key` is absent
     * @throws ChannelTemplateException when the template is unknown, not approved, or mis-filled
     * @throws ChannelCredentialException when the tenant has no usable `CLOUD_API` credentials
     * @throws ChannelRequestFailedException when Meta refuses the send
     * @throws BridgeUnreachableException when Meta never answered
     */
    public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
    {
        $recipient = $this->reservedVar($vars, self::TEMPLATE_RECIPIENT_VAR, 'the recipient');
        $idempotencyKey = $this->reservedVar($vars, self::TEMPLATE_KEY_VAR, 'the idempotency key');

        unset($vars[self::TEMPLATE_RECIPIENT_VAR], $vars[self::TEMPLATE_KEY_VAR]);

        return $this->sendTemplateTo($session, $template, $vars, $recipient, $idempotencyKey);
    }

    /**
     * `sendTemplate()` with the two parameters the contract's signature lacks — the method task
     * 8.4 should call.
     *
     * The order is: credentials → **re-read the template row** → check the variables → build
     * Meta's component payload → POST. Everything before the POST is a local refusal, because
     * `ChannelDriver::sendTemplate()` requires that a mis-filled template be *"a local failure
     * and not a provider rejection charged to the tenant's quality rating"*.
     *
     * @param  array<array-key, string|int|float>  $vars  placeholder values, keyed as the template numbers them
     *
     * @throws ChannelTemplateException when the template is unknown, not approved, or mis-filled
     * @throws ChannelCredentialException when the tenant has no usable `CLOUD_API` credentials
     * @throws ChannelRequestFailedException when Meta refuses the send
     * @throws BridgeUnreachableException when Meta never answered
     */
    public function sendTemplateTo(
        Session $session,
        TemplateRef $template,
        array $vars,
        string $recipient,
        string $idempotencyKey,
    ): SendReceipt {
        $credentials = $this->credentialsFor($session);
        $row = $this->sendableTemplate($credentials, $template);
        $components = $this->templateComponents($template, $row, $vars);

        $outcome = $this->idempotency->once(
            self::sendScope($session),
            $idempotencyKey,
            fn (): array => $this->dispatch(
                $session,
                $credentials,
                CloudApiMessage::template($recipient, $row->name, $row->language, $components),
                'cloud_api.message.template',
                degraded: false,
                templateName: $row->name,
            ),
            $this->sendOptions($session, $this->sendFingerprint(
                $session,
                ChannelCapability::Template,
                $recipient,
                $row->body,
                // So one key cannot answer a template send with a free-form send's receipt, or
                // one template's with another's.
                discriminator: $template->key().'|'.json_encode($vars),
            )),
        );

        return $this->receipt($idempotencyKey, ChannelCapability::Template, $outcome->payload());
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * Verify a Cloud API webhook POST and normalise **the first** event it carries.
     *
     * The contract's method, and the reason `parseWebhookBatch()` exists directly below it: Meta
     * batches, and one `InboundEvent` cannot express several. Task 8.3 must call the batch
     * method — the class docblock sets out what is dropped if it does not, and every returned
     * event carries `_batch_size` / `_batch_index` so the loss is detectable rather than silent.
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     * @throws ChannelCredentialException when the tenant stored no app secret to verify against
     */
    public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
    {
        $events = $this->parseWebhookBatch($request, $credentials);

        // `parseWebhookBatch()` never returns an empty list — a verified body with nothing to
        // act on yields exactly one `UNSUPPORTED` event — so this index is always present. The
        // fallback is not defensive programming; it is what makes the guarantee readable.
        return $events[0] ?? InboundEvent::unsupported(
            $this->mode(),
            $credentials->tenantId,
            $credentials->config(self::PHONE_NUMBER_CONFIG_KEY) === null
                ? null
                : $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY),
        );
    }

    /**
     * Verify a Cloud API webhook POST once and normalise **every** event it carries, in document
     * order.
     *
     * ```php
     * // task 8.3's controller, after resolving (tenant, session, driver) from the route key
     * foreach ($driver->parseWebhookBatch($request, $credentials) as $event) {
     *     if ($event->isActionable()) { $this->dispatch($event); }
     * }
     * return response()->noContent();   // one 200 for the whole batch, as Meta expects
     * ```
     *
     * Order of checks is the contract's — origin → recipient → shape — and the recipient check
     * runs for **every** change in the body before a single event is built, so a batch cannot
     * smuggle one foreign `phone_number_id` past a check that only looked at the first.
     *
     * Never empty: a verified body this release does not act on (a template-status update, a
     * kind Meta added after this release) yields one `UNSUPPORTED` event, which is a successful
     * parse and a `200` so Meta stops retrying it.
     *
     * @return non-empty-list<InboundEvent>
     *
     * @throws WebhookVerificationException when origin, recipient, or shape does not check out
     * @throws ChannelCredentialException when the tenant stored no app secret to verify against
     */
    public function parseWebhookBatch(Request $request, ChannelCredentials $credentials): array
    {
        // From the caller, never from the body. A missing key is a wiring defect in task 8.3's
        // controller, so `requireConfig()`'s InvalidArgumentException is the right answer: it is
        // not something a request can provoke.
        $phoneNumberId = $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY);

        // 1. Origin. Over the raw bytes, in constant time, under the tenant's Meta app secret.
        $this->assertSignature($request, self::SIGNATURE_HEADER, $this->appSecret($credentials));

        $payload = $this->decodeWebhook($request);

        if (BridgeWire::stringOrNull($payload['object'] ?? null) !== self::WEBHOOK_OBJECT) {
            // Verified, and not a WABA notification at all. A `200` rather than a 403: this is
            // the shape a subscription to another product would arrive in, and refusing it would
            // make an operator's misconfiguration look like an attack.
            return [InboundEvent::unsupported($this->mode(), $credentials->tenantId, $phoneNumberId, $payload)];
        }

        $events = [];

        foreach (BridgeWire::listOrEmpty($payload['entry'] ?? null) as $entry) {
            foreach (BridgeWire::listOrEmpty(BridgeWire::arrayOrEmpty($entry)['changes'] ?? null) as $change) {
                $events = [...$events, ...$this->eventsFromChange(
                    BridgeWire::arrayOrEmpty($change),
                    $credentials,
                    $phoneNumberId,
                )];
            }
        }

        if ($events === []) {
            // A `messages` change with neither `messages[]` nor `statuses[]`, or an `entry` list
            // that was empty: verified, well-formed, and about nothing.
            return [InboundEvent::unsupported($this->mode(), $credentials->tenantId, $phoneNumberId, $payload)];
        }

        return $this->stamped($events);
    }

    /**
     * Answer Meta's `GET` subscription handshake with the challenge, or refuse it.
     *
     * ```php
     * // task 8.3's webhook route, for the GET verb
     * return response($driver->verifySubscription($request, $credentials), 200)
     *     ->header('Content-Type', 'text/plain');
     * ```
     *
     * The token is compared in **constant time** against the tenant's stored `verify_token`, and
     * the challenge is echoed only on success. Both halves matter: a token compared with `==`
     * leaks its length and then its bytes, and a challenge echoed before the comparison confirms
     * the endpoint to anybody who asks, which is how a webhook is taken over.
     *
     * See the class docblock for why this lives on the driver even though
     * `ChannelWebhookRoute::matchesVerifyToken()` exists — one comparison of one token, in one
     * place.
     *
     * @return string the `hub.challenge` value, to be returned verbatim as `text/plain`
     *
     * @throws WebhookVerificationException when the mode, the token, or the challenge does not check out
     * @throws ChannelCredentialException when the tenant stored no verify token
     */
    public function verifySubscription(Request $request, ChannelCredentials $credentials): string
    {
        $mode = trim((string) $request->query(self::HUB_MODE_PARAM, ''));

        if ($mode !== self::SUBSCRIBE_MODE) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                sprintf('its %s is not "%s", so it is not a subscription handshake', self::HUB_MODE_PARAM, self::SUBSCRIBE_MODE),
            );
        }

        $expected = $credentials->secret(self::VERIFY_TOKEN_SECRET_KEY);

        if ($expected === null) {
            // The platform cannot complete a handshake it has no token for. Reported as the
            // configuration defect it is rather than as a bad token: an operator hunting an
            // attacker because a tenant left a field blank is the failure this distinction
            // prevents.
            throw ChannelCredentialException::missing($this->mode(), $credentials->tenantId);
        }

        $presented = (string) $request->query(self::HUB_TOKEN_PARAM, '');

        if (! self::tokensMatch($expected, $presented)) {
            throw WebhookVerificationException::badVerifyToken(
                $this->mode(),
                // Fingerprinted by the exception, so this identifies the route in a log without
                // writing the credential id or the number down.
                $credentials->credentialId ?? $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY),
            );
        }

        $challenge = trim((string) $request->query(self::HUB_CHALLENGE_PARAM, ''));

        if (preg_match(self::CHALLENGE_PATTERN, $challenge) !== 1) {
            // Refused rather than echoed: this value is the one thing a driver returns straight
            // back to the network, and Meta's own challenge is a short nonce.
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                sprintf('its %s is absent or not a short opaque nonce', self::HUB_CHALLENGE_PARAM),
            );
        }

        return $challenge;
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials & onboarding
    |--------------------------------------------------------------------------
    */

    /**
     * Verify that this session's number is registered under the tenant's WABA, and report what
     * Meta says.
     *
     * One call — `GET /{waba_id}/phone_numbers` — answers both halves of *"WABA / phone-number-id
     * verification"*: a wrong `waba_id` is refused by Meta, and a number that is not on that WABA
     * is simply absent from the list.
     *
     * Reports state, does not throw for a state: `live()` when Meta reports the number
     * `VERIFIED`, `pending()` while verification is outstanding or the number has not appeared on
     * the WABA yet. Task 9.1 keeps a `pending()` session out of `SENDABLE`, which is exactly
     * right — its first send would be refused by Meta. A registration that *failed at the
     * provider* is still an exception, as the contract requires; that is the guarded call's
     * `ChannelRequestFailedException`.
     *
     * Idempotent, and read-only: it registers nothing. Cloud API number registration (the
     * `/register` route, with its 6-digit PIN) is a one-time onboarding act performed by the
     * tenant in Meta's own Business Manager, and a driver that silently re-ran it during a
     * session restart could throw away a working registration — the failure
     * `provisionSession()`'s idempotency exists to prevent, one layer up.
     *
     * @throws ChannelRequestFailedException when Meta refuses the lookup
     * @throws BridgeUnreachableException when Meta never answered
     */
    public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
    {
        $operation = 'cloud_api.register';
        $wabaId = $credentials->requireConfig(self::WABA_CONFIG_KEY);
        $phoneNumberId = $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY);

        $payload = $this->get(
            $operation,
            $credentials,
            $this->graphUrl($credentials, $wabaId.'/phone_numbers'),
            ['fields' => 'id,display_phone_number,verified_name,code_verification_status,quality_rating'],
        );

        $number = $this->findNumber($payload, $phoneNumberId);

        if ($number === null) {
            return RegistrationResult::pending($credentials, sprintf(
                'This phone number id is not listed on WABA %s. Add the number to that WhatsApp '
                .'Business Account in Meta Business Manager, or correct the [%s] setting.',
                self::fingerprint($wabaId),
                self::PHONE_NUMBER_CONFIG_KEY,
            ), providerNumberId: $phoneNumberId);
        }

        $status = BridgeWire::stringOrNull($number['code_verification_status'] ?? null);

        if ($status !== self::VERIFIED_STATUS) {
            return RegistrationResult::pending($credentials, sprintf(
                'Meta has this number on the WABA with verification status %s. It becomes sendable '
                .'once Meta reports %s.',
                $status ?? 'unknown',
                self::VERIFIED_STATUS,
            ), providerNumberId: $phoneNumberId);
        }

        return RegistrationResult::live(
            $credentials,
            // Meta's own id for the number, echoed from the WABA listing rather than from config:
            // it is what every later send addresses itself with, so the value that is recorded
            // should be the one the provider just confirmed.
            BridgeWire::stringOrNull($number['id'] ?? null) ?? $phoneNumberId,
            detail: sprintf(
                'Registered on WABA %s and verified by Meta%s. No callback URL is claimed here: '
                .'Meta\'s webhook is configured on the App rather than on a phone number, and the '
                .'route key is minted when the webhook route is created.',
                self::fingerprint($wabaId),
                ($name = BridgeWire::stringOrNull($number['verified_name'] ?? null)) === null
                    ? ''
                    : sprintf(' as "%s"', $name),
            ),
        );
    }

    /**
     * Probe whether `$credentials` actually work, and report it as **data**.
     *
     * What task 7.6 calls before activating a credential set on save or rotate, and what the
     * connection screen's light reads. Three answers, and only one of them is an exception:
     *
     * | Situation | Answer |
     * |---|---|
     * | a required key is missing | `unhealthy()`, naming the keys — so 7.6 keeps the previous working set |
     * | Meta refused (expired token, unknown number id) | `unhealthy()`, with a sentence rendered from Meta's **code** |
     * | Meta could not be reached at all | `BridgeUnreachableException` propagates |
     *
     * The last row is the contract's own division: *"'we could not ask' and 'the provider said
     * no' lead to different operator actions"*. The middle row never quotes Meta's prose — a
     * `401` body echoes a fragment of the token it was sent — and `ChannelHealth` passes even the
     * rendered sentence through `ChannelCredentials::redact()` on the way in.
     *
     * Read-only: it fetches one node and changes nothing about a credential set that is about to
     * be rejected.
     */
    public function healthCheck(ChannelCredentials $credentials): ChannelHealth
    {
        $missing = $this->missingCredentialKeys($credentials);

        if ($missing !== []) {
            return ChannelHealth::unhealthy($credentials, sprintf(
                'These Cloud API credentials are incomplete: %s. Meta rejects every request '
                .'without them, so they are refused here rather than probed.',
                implode(', ', $missing),
            ));
        }

        $startedAt = hrtime(true);

        try {
            $payload = $this->get(
                'cloud_api.health',
                $credentials,
                $this->graphUrl($credentials, $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY)),
                ['fields' => 'id,display_phone_number,quality_rating,code_verification_status'],
                self::HEALTH_TIMEOUT,
            );
        } catch (ChannelRequestFailedException $refused) {
            return ChannelHealth::unhealthy(
                $credentials,
                $this->healthDetailFor($refused),
                $this->elapsedMs($startedAt),
            );
        }

        $latencyMs = $this->elapsedMs($startedAt);
        $id = BridgeWire::stringOrNull($payload['id'] ?? null);

        if ($id === null) {
            // A 200 whose body is not the node we asked for: Meta answered, and the answer does
            // not establish that these credentials can send. Reported rather than raised,
            // because 7.6's decision is the same either way.
            return ChannelHealth::unhealthy(
                $credentials,
                'Meta answered the credential probe without describing the phone number, so these '
                .'credentials could not be confirmed.',
                $latencyMs,
            );
        }

        return ChannelHealth::healthy($credentials, sprintf(
            'Meta accepted these credentials for this phone number%s%s.',
            ($rating = BridgeWire::stringOrNull($payload['quality_rating'] ?? null)) === null
                ? ''
                : sprintf('; quality rating %s', $rating),
            BridgeWire::stringOrNull($payload['code_verification_status'] ?? null) === self::VERIFIED_STATUS
                ? '; verified'
                : '',
        ), $latencyMs);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport — the eleven inherited methods (see the class docblock's table)
    |--------------------------------------------------------------------------
    */

    /**
     * Refused: a Cloud API number is **registered**, not paired.
     *
     * There is no QR to scan and no socket to provision — design § 2.5 says as much by giving
     * the official modes `driver->register(...)` and leaving QR pairing to Baileys, so task 9.1
     * branches by mode and never reaches this. A no-op returning a plausible `SessionInitDto`
     * would be worse than a refusal: the session would be marked provisioned without Meta having
     * been asked anything.
     *
     * @throws ChannelOperationException always
     */
    public function provisionSession(
        string $sessionId,
        SessionLoginMethod $method,
        ?string $phone = null,
        ?string $authStateDir = null,
    ): SessionInitDto {
        throw ChannelOperationException::unsupported($this->mode(), 'provisionSession', sprintf(
            'a Meta-hosted number is registered under a WABA and verified by Meta rather than '
            .'paired by QR or pairing code — use register(), and read its state with sessionState()'
        ));
    }

    /**
     * Refused: there is no socket to open.
     *
     * A Meta-hosted number is reachable whenever its token is valid; nothing on the platform's
     * side is started or stopped. Refusing rather than no-opping is what stops a session-restart
     * sweep from reporting success for a number it never touched.
     *
     * @throws ChannelOperationException always
     */
    public function startSession(string $sessionId): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'startSession', sprintf(
            'a Meta-hosted number holds no connection this platform opens — it is sendable while '
            .'its access token is valid, which healthCheck() reports'
        ));
    }

    /**
     * Refused, and deliberately **not** mapped onto Meta's `deregister` route.
     *
     * Meta does expose deregistration, and calling it here would make an ordinary "stop this
     * session" surrender the number's registration — destructive, slow to reverse, and triggered
     * by a routine operation. Deregistration stays a deliberate, audited tenant action, which is
     * the mode-switch task's (8.6).
     *
     * @throws ChannelOperationException always
     */
    public function stopSession(string $sessionId, bool $logout = false): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'stopSession', sprintf(
            'a Meta-hosted number has no session to close, and its deregistration is an audited '
            .'tenant action rather than a side effect of stopping one'
        ));
    }

    /**
     * The provider's live view of this number, as a session state.
     *
     * `GET /{phone_number_id}`: a number Meta reports `VERIFIED` is `CONNECTED`, and anything
     * else is `QR_PENDING` — *awaiting provider-side verification*, which is the same meaning
     * that status carries for a Baileys session awaiting a scan. It is a **report**, not the
     * truth: `sessions_wa.status` remains the state of record, and
     * `SessionStatus::canTransitionTo()` may refuse what this says (`SessionStateDto`'s own
     * docblock).
     *
     * @throws ChannelRequestFailedException when Meta refuses the lookup
     * @throws BridgeUnreachableException when Meta never answered
     */
    public function sessionState(string $sessionId): SessionStateDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);

        $payload = $this->get(
            'cloud_api.session.state',
            $credentials,
            $this->graphUrl($credentials, $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY)),
            ['fields' => 'id,display_phone_number,verified_name,code_verification_status'],
        );

        $verified = BridgeWire::stringOrNull($payload['code_verification_status'] ?? null) === self::VERIFIED_STATUS;

        return new SessionStateDto(
            sessionId: $session->id,
            status: $verified ? SessionStatus::Connected : SessionStatus::QrPending,
            // Meta's `display_phone_number` is formatted for humans (`+91 98…`); reduced to
            // digits so it matches what `sessions_wa.phone` holds everywhere else.
            phone: self::digitsOrNull(BridgeWire::stringOrNull($payload['display_phone_number'] ?? null)),
            pushName: BridgeWire::stringOrNull($payload['verified_name'] ?? null),
            disconnectReason: $verified
                ? null
                : BridgeWire::stringOrNull($payload['code_verification_status'] ?? null),
        );
    }

    /**
     * `null`, always — and that is an answer rather than a failure.
     *
     * `BridgeClient::qr()` already documents `null` as *"this session is not showing one"*, which
     * is permanently true of a number that is registered with Meta instead of paired. A
     * connection screen that asks every driver for a QR therefore renders nothing for this mode
     * without having to know which mode it is looking at.
     */
    public function qr(string $sessionId): ?string
    {
        return null;
    }

    /**
     * Refused: pairing codes belong to the WhatsApp Web protocol.
     *
     * The one transport method whose return type (`string`) has no honest empty value — an empty
     * or invented code would be shown to a tenant as something to type into their phone.
     *
     * @throws ChannelOperationException always
     */
    public function pairingCode(string $sessionId, string $phone): string
    {
        throw ChannelOperationException::unsupported($this->mode(), 'pairingCode', sprintf(
            'pairing codes are a WhatsApp Web login method; a Cloud API number is verified by '
            .'Meta with a one-time code during onboarding in Business Manager'
        ));
    }

    /**
     * One text message on the wire — the transport primitive, not the driver-level send.
     *
     * Not idempotent, exactly as `BridgeClient::sendText()` is not: *"it puts one message on the
     * wire each time it is called"*. Deduplication is `send()`'s, above.
     *
     * `$opts` is read through a documented allowlist rather than merged into the body, because
     * Meta **rejects** unknown fields: passing a caller's map through would turn a typo into a
     * provider 400 counted against the number's quality rating.
     *
     * | `$opts` key | Meta field |
     * |---|---|
     * | `preview_url` / `link_preview` (bool) | `text.preview_url` |
     * | `reply_to` (message id) | `context.message_id` |
     *
     * @param  array<string, mixed>  $opts
     *
     * @throws ChannelRequestFailedException when Meta refuses the send
     * @throws BridgeUnreachableException when Meta never answered
     */
    public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);

        return $this->postMessage(
            'cloud_api.message.text',
            $credentials,
            CloudApiMessage::text(
                $jid,
                $text,
                previewUrl: BridgeWire::boolOrNull($opts['preview_url'] ?? $opts['link_preview'] ?? null) ?? false,
                replyTo: BridgeWire::stringOrNull($opts['reply_to'] ?? null),
            ),
        );
    }

    /**
     * One media message on the wire.
     *
     * Meta takes either a `link` it fetches or the `id` of a prior upload, and has no inline-bytes
     * field — so a `MediaPayload::fromUrl()` is sent by link and a `MediaPayload::fromBytes()` is
     * uploaded to `/{phone-number-id}/media` first and sent by id. Both are complete here; the
     * link path is the normal one, for the reason `MediaPayload` gives (*"nothing large crosses
     * the PHP boundary"*).
     *
     * @param  array<string, mixed>  $opts  accepted for contract compatibility; Meta's media
     *                                      message has no option Baileys' `$opts` maps onto, and
     *                                      passing unknown fields through would be a 400
     *
     * @throws ChannelRequestFailedException when Meta refuses the upload or the send
     * @throws BridgeUnreachableException when Meta never answered
     */
    public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
    {
        $session = $this->ownedSession($sessionId);
        $credentials = $this->credentialsFor($session);

        $uploadedId = $media->base64 === null ? null : $this->uploadMedia($credentials, $media);

        return $this->postMessage(
            'cloud_api.message.media',
            $credentials,
            CloudApiMessage::media($jid, $media, $uploadedId),
        );
    }

    /**
     * Refused: the Cloud API has no presence channel.
     *
     * There is no "typing…" a business number can broadcast on Meta's Business Platform, so a
     * silent no-op would leave a caller believing a presence indicator was sent. A caller that
     * wants humane pacing on an official mode uses a delay, not a presence update.
     *
     * @throws ChannelOperationException always
     */
    public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
    {
        throw ChannelOperationException::unsupported($this->mode(), 'sendPresence', sprintf(
            'the Business Platform has no presence channel — a business number cannot broadcast '
            .'"typing" or "online"'
        ));
    }

    /**
     * Every asked number, answered `unknown`.
     *
     * The Cloud API has no `onWhatsApp` / contacts endpoint (the On-Premise API did, which is
     * one of the few things it could do that Cloud API cannot). `NumberCheck` exists with a
     * three-valued `exists` for exactly this, and its docblock states the consequence of the
     * alternative: *"collapsing that into 'not on WhatsApp' would let a rate limit be recorded as
     * a permanent fact about somebody's phone number"* — here it would be a *capability gap*
     * recorded as one, and every later campaign would skip a real customer for ever.
     *
     * The caller's spelling is preserved as the key, and no request is made: there is nothing to
     * ask.
     *
     * @param  list<string>  $numbers
     * @return array<array-key, NumberCheck>
     */
    public function checkNumbers(string $sessionId, array $numbers): array
    {
        // Ownership is still resolved, even though nothing is sent: a caller must not be able to
        // learn whether a session id exists by whether this method answers.
        $this->ownedSession($sessionId);

        $checks = [];

        foreach ($numbers as $number) {
            $checks[$number] = new NumberCheck((string) $number, null);
        }

        return $checks;
    }

    /**
     * Whether `graph.facebook.com` is answering at all.
     *
     * Credential-free on purpose: `BridgeClient::isReachable()` asks *"is the dependency up?"*,
     * and this is the one probe that is meaningful for a tenant who has entered no credentials
     * yet. **Any** HTTP answer counts as reachable, including a 4xx — an unauthenticated Graph
     * request is *supposed* to be refused, and a refusal proves the host is serving. Only a
     * transport failure is a `false`, which is exactly the same rule `HttpBridgeClient` applies
     * to its health route.
     */
    public function isReachable(): bool
    {
        try {
            $this->request(self::HEALTH_TIMEOUT)->get($this->baseUrl().'/'.$this->apiVersion(null).'/');

            return true;
        } catch (Throwable) {
            // Every way of failing to ask is a "no". The one method in the contract that reports
            // a failure as a value.
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    /**
     * The tenant's decrypted `CLOUD_API` credentials for `$session`, or a refusal.
     *
     * Resolved through `ChannelCredentialStore` on **every** call rather than memoised, which is
     * Req 8.5 (*"decrypted only within the lifetime of the request or job"*) plus one practical
     * consequence: a token the tenant has just rotated is picked up by the next send instead of
     * by the next worker restart.
     *
     * `$session->tenant` rather than the acting tenant, and that difference is load-bearing: the
     * store refuses a lookup for a tenant other than the bound one
     * (`CrossTenantAccessException`), so asking about the session's owner is what turns a foreign
     * session into a 403 instead of a send authenticated with the caller's own token.
     *
     * @throws ChannelCredentialException when the tenant has no usable credential set (Req 8.13)
     */
    private function credentialsFor(Session $session): ChannelCredentials
    {
        $credentials = $this->credentials->for($session->tenant, ChannelMode::CloudApi);

        if ($credentials === null || ! $credentials->isComplete()) {
            // Refused, never rerouted onto BAILEYS — see `ChannelCredentialException` for why a
            // silent reroute would send a brand's official traffic from a number it never
            // registered.
            throw ChannelCredentialException::missing(ChannelMode::CloudApi, $session->tenant_id);
        }

        return $credentials;
    }

    /**
     * The tenant's Meta app secret, or a refusal naming the configuration defect.
     *
     * A route whose app secret is absent cannot verify **anything**, so the payload is refused
     * either way. It is refused as a *credential* problem rather than as a bad signature because
     * the two send an operator to different places: one is a tenant field left blank, the other
     * is a rotated secret or a forgery.
     *
     * @throws ChannelCredentialException when no app secret is stored
     */
    private function appSecret(ChannelCredentials $credentials): string
    {
        $secret = $credentials->secret(self::APP_SECRET_KEY);

        if ($secret === null) {
            throw ChannelCredentialException::missing($this->mode(), $credentials->tenantId);
        }

        return $secret;
    }

    /**
     * Which of §2.7's required keys are absent — names only, never values.
     *
     * What `healthCheck()` reports instead of probing, so task 7.6 can tell a tenant which field
     * to fill in. `verify_token` and `app_secret` are **not** required for a *send* to work, but
     * they are required for the mode to function: without them no inbound message can ever be
     * verified, and a connection that can only talk is not connected.
     *
     * @return list<string>
     */
    private function missingCredentialKeys(ChannelCredentials $credentials): array
    {
        $missing = [];

        foreach ([self::WABA_CONFIG_KEY, self::PHONE_NUMBER_CONFIG_KEY] as $key) {
            $value = $credentials->config($key);

            if (! is_string($value) && ! is_int($value)) {
                $missing[] = $key;
            } elseif (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        foreach ([self::ACCESS_TOKEN_SECRET_KEY, self::VERIFY_TOKEN_SECRET_KEY, self::APP_SECRET_KEY] as $key) {
            if (! $credentials->hasSecret($key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /*
    |--------------------------------------------------------------------------
    | Sending internals
    |--------------------------------------------------------------------------
    */

    /**
     * Put one message on the wire and return the replay material.
     *
     * An array rather than a `SendReceipt` because the return value **is** what
     * `idempotency_keys.result` stores, and only JSON-encodable data can be replayed. The receipt
     * is rebuilt from it by `receipt()`, so a fresh send and a replay leave `send()` by exactly
     * the same path.
     *
     * @return array<string, mixed>
     */
    private function dispatch(
        Session $session,
        ChannelCredentials $credentials,
        CloudApiMessage $message,
        string $operation,
        bool $degraded,
        ?string $templateName = null,
    ): array {
        $sent = $this->postMessage($operation, $credentials, $message);

        return [
            'provider_message_id' => $sent->waMessageId,
            'recipient' => $sent->jid,
            'degraded' => $degraded,
            'template' => $templateName,
        ];
    }

    /**
     * `POST /{phone-number-id}/messages`, guarded, and read into the transport's own DTO.
     *
     * Meta answers `{"messages": [{"id": "wamid.…"}], "contacts": [{"wa_id": "…"}]}`. The
     * `wa_id` is preferred as the recorded recipient because it is *"the identity the backend
     * actually addressed"* — Meta resolves some numbers to a different account id — and the
     * addressed digits are the fallback.
     *
     * There is deliberately no accepted-at timestamp: Meta sends none, and
     * `SentMessageDto::$sentAt` of `null` is honest where `now()` would be a fabricated fact on
     * the delivery board.
     *
     * @throws ChannelRequestFailedException when Meta refuses the send
     * @throws BridgeUnreachableException when Meta never answered, or answered unreadably
     */
    private function postMessage(
        string $operation,
        ChannelCredentials $credentials,
        CloudApiMessage $message,
    ): SentMessageDto {
        $payload = $this->post(
            $operation,
            $credentials,
            $this->graphUrl($credentials, $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY).'/messages'),
            $message->body(),
        );

        $messages = BridgeWire::listOrEmpty($payload['messages'] ?? null);
        $providerMessageId = BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($messages[0] ?? null)['id'] ?? null);

        if ($providerMessageId === null) {
            // A 2xx with no message id: nothing could ever be reconciled with this send, and an
            // unreconcilable "sent" is worse than a retry. Treated as a transport failure, which
            // is what `SentMessageDto` would conclude anyway.
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx message response carrying no "messages[0].id"',
            );
        }

        $contacts = BridgeWire::listOrEmpty($payload['contacts'] ?? null);
        $waId = BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($contacts[0] ?? null)['wa_id'] ?? null);

        return new SentMessageDto(
            waMessageId: $providerMessageId,
            jid: $waId ?? $message->recipient(),
        );
    }

    /**
     * Upload inline bytes to `/{phone-number-id}/media` and return Meta's media id.
     *
     * The Graph API has no inline-bytes field on a message, so a `MediaPayload::fromBytes()` has
     * to become an upload first. Multipart, `messaging_product=whatsapp`, and the sniffed MIME
     * type from the payload — never one guessed from a filename, which `MediaPayload` already
     * argues is how an executable arrives as an image.
     *
     * @throws ChannelRequestFailedException when Meta refuses the upload
     * @throws BridgeUnreachableException when Meta never answered, or returned no id
     */
    private function uploadMedia(ChannelCredentials $credentials, MediaPayload $media): string
    {
        $operation = 'cloud_api.media.upload';
        $bytes = base64_decode((string) $media->base64, true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException(
                'A Cloud API media upload needs decodable bytes: the payload\'s base64 could not be '
                .'decoded, and uploading nothing would produce a media id that delivers an empty '
                .'attachment.'
            );
        }

        $url = $this->graphUrl($credentials, $credentials->requireConfig(self::PHONE_NUMBER_CONFIG_KEY).'/media');
        $token = $credentials->requireSecret(self::ACCESS_TOKEN_SECRET_KEY);

        $payload = $this->guarded($operation, $credentials, fn (): Response => $this->request($this->timeout())
            ->withToken($token)
            ->attach('file', $bytes, $media->filename ?? 'upload', ['Content-Type' => $media->mimeType])
            ->post($url, [
                'messaging_product' => CloudApiMessage::MESSAGING_PRODUCT,
                'type' => $media->mimeType,
            ]));

        $id = BridgeWire::stringOrNull($payload['id'] ?? null);

        if ($id === null) {
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx media-upload response carrying no "id"',
            );
        }

        return $id;
    }

    /**
     * Rebuild the driver-level receipt from what Meta said, or from what the ledger replayed.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function receipt(string $idempotencyKey, ChannelCapability $capability, array $payload): SendReceipt
    {
        return new SendReceipt(
            mode: $this->mode(),
            // A send acknowledged with no id is refused by `SendReceipt` itself, which is the
            // correct outcome for the reason `postMessage()` gives.
            providerMessageId: BridgeWire::stringOrNull($payload['provider_message_id'] ?? null) ?? '',
            recipient: BridgeWire::stringOrNull($payload['recipient'] ?? null) ?? '',
            idempotencyKey: $idempotencyKey,
            capability: $capability,
            degraded: ($payload['degraded'] ?? false) === true,
            templateName: BridgeWire::stringOrNull($payload['template'] ?? null),
        );
    }

    /**
     * One reserved `$vars` entry, or a refusal naming it — see `sendTemplate()`.
     *
     * @param  array<array-key, string|int|float>  $vars
     *
     * @throws InvalidArgumentException when the key is absent or empty
     */
    private function reservedVar(array $vars, string $key, string $what): string
    {
        $value = trim((string) ($vars[$key] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException(sprintf(
                'A Cloud API template send needs %s in $vars[%s]: ChannelDriver::sendTemplate() has '
                .'no parameter for it, and a receipt without it could never be reconciled. Prefer '
                .'sendTemplateTo(), which takes both explicitly.',
                $what,
                $key,
            ));
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Template internals
    |--------------------------------------------------------------------------
    */

    /**
     * The approved template row this send must use, re-read from the registry.
     *
     * Three refusals, all before any Graph API call and all `ChannelTemplateException` (422):
     * the reference names another provider account, no row exists for `(account, name, language)`,
     * or the row exists and is not `APPROVED`. The middle two are distinguished by a second
     * lookup **without** the approved filter, because *"submit this template"* and *"wait for
     * review"* are different instructions and a panel that could not tell them apart would give
     * the wrong one half the time.
     *
     * @throws ChannelTemplateException when the template cannot be sent
     */
    private function sendableTemplate(ChannelCredentials $credentials, TemplateRef $template): CloudApiTemplate
    {
        $credentialId = $credentials->credentialId;

        if ($credentialId === null) {
            // `ChannelCredentials::fromModel()` always carries the row id, so this is a
            // programmer-built credential set (a platform() one) being used for a template send —
            // and template approval is meaningless without the account it was approved under.
            throw new InvalidArgumentException(
                'A Cloud API template send needs credentials resolved from a stored credential row: '
                .'template approval is per provider account, and cloud_api_templates is keyed on it.'
            );
        }

        if ($template->credentialId !== null && $template->credentialId !== $credentialId) {
            throw ChannelTemplateException::wrongAccount($this->mode(), $template->key());
        }

        $row = CloudApiTemplate::sendable($credentialId, $template->name, $template->language);

        if ($row instanceof CloudApiTemplate) {
            return $row;
        }

        $unapproved = CloudApiTemplate::query()
            ->forCredential($credentialId)
            ->where('name', $template->name)
            ->where('language', $template->language)
            ->first();

        throw $unapproved instanceof CloudApiTemplate
            ? ChannelTemplateException::notApproved($this->mode(), $template->key(), $unapproved->status)
            : ChannelTemplateException::notRegistered($this->mode(), $template->key());
    }

    /**
     * Meta's `template.components` for `$vars`, checked against the template's own placeholders.
     *
     * ## How the placeholders are known
     *
     * From the stored `body`, which holds the tenant's copy with `{{1}}`-style placeholders
     * (`cloud_api_templates.body`, and `CloudApiTemplateFactory`'s
     * *"Hello {{1}}, your order {{2}} is on its way."*). They are read from the mirrored body
     * rather than from `$vars` for the obvious reason: the point of the check is to catch a
     * `$vars` that does not match.
     *
     * Both directions are refused, and surplus is refused as firmly as missing: a surplus key is
     * nearly always a renamed or removed placeholder, and the send would otherwise go out with a
     * stale value silently occupying the wrong slot.
     *
     * ## What is deliberately not built here
     *
     * Only the **body** component. A header, a footer, or a button URL suffix can also carry
     * parameters, and their definitions live in `cloud_api_templates.components` — *"in the
     * provider's shape"*, populated by **task 8.4's** template sync, which is the task that will
     * know what is in there. Building a header component from a `components` column no sync has
     * ever written would be inventing a schema; a body-only payload is correct for every template
     * whose header is static, which is every template this platform can currently create.
     *
     * @param  array<array-key, string|int|float>  $vars
     * @return list<array<string, mixed>>
     *
     * @throws ChannelTemplateException when the variables do not fill the placeholders exactly
     */
    private function templateComponents(TemplateRef $template, CloudApiTemplate $row, array $vars): array
    {
        $placeholders = self::placeholdersIn($row->body);
        $supplied = array_map(static fn (int|string $key): string => (string) $key, array_keys($vars));

        $missing = array_values(array_diff($placeholders, $supplied));
        $surplus = array_values(array_diff($supplied, $placeholders));

        if ($missing !== [] || $surplus !== []) {
            throw ChannelTemplateException::variableMismatch($this->mode(), $template->key(), $missing, $surplus);
        }

        if ($placeholders === []) {
            // A template with no placeholders has no components, and Meta refuses
            // `"components": []` on some shapes — `CloudApiMessage::template()` omits an empty list
            // for that reason.
            return [];
        }

        $parameters = [];

        foreach ($placeholders as $placeholder) {
            $parameters[] = [
                'type' => 'text',
                // In placeholder order, because Meta's body parameters are **positional**: the
                // first parameter fills `{{1}}` whatever its key was called, so the order this
                // list is built in *is* the mapping.
                'text' => (string) $vars[$placeholder],
            ];
        }

        return [['type' => 'body', 'parameters' => $parameters]];
    }

    /**
     * The `{{n}}` placeholder keys of a template body, in ascending positional order.
     *
     * Ascending numerically rather than in the order they appear in the copy: Meta's parameters
     * are positional, so a body that mentions `{{2}}` before `{{1}}` (which a translation
     * legitimately does) must still send `{{1}}`'s value first.
     *
     * @return list<string>
     */
    private static function placeholdersIn(string $body): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $body, $matches);

        $found = array_values(array_unique($matches[1]));

        usort($found, static function (string $a, string $b): int {
            $numeric = ctype_digit($a) && ctype_digit($b);

            return $numeric ? (int) $a <=> (int) $b : strcmp($a, $b);
        });

        return $found;
    }

    /*
    |--------------------------------------------------------------------------
    | Inbound internals
    |--------------------------------------------------------------------------
    */

    /**
     * The verified body as a string-keyed array.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException when the body is not a JSON object
     */
    private function decodeWebhook(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded)) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'its body is not a JSON object',
            );
        }

        return BridgeWire::arrayOrEmpty($decoded);
    }

    /**
     * Every canonical event in one `entry[].changes[]` element.
     *
     * The **recipient check happens here**, once per change and before any field of the change's
     * value is believed — see the class docblock for the cross-tenant injection that ordering
     * prevents. A change carrying a `phone_number_id` these credentials do not own aborts the
     * whole batch rather than being skipped: a body that mixes one tenant's numbers with another's
     * is not a partially-usable body, it is a forged one.
     *
     * @param  array<string, mixed>  $change
     * @return list<InboundEvent>
     *
     * @throws WebhookVerificationException when the change names another number, or has no metadata
     */
    private function eventsFromChange(array $change, ChannelCredentials $credentials, string $phoneNumberId): array
    {
        if (BridgeWire::stringOrNull($change['field'] ?? null) !== self::MESSAGES_FIELD) {
            // `message_template_status_update`, `account_update`, `phone_number_quality_update`, or
            // a field Meta adds after this release: a verified payload with no consumer here.
            // Task 8.4 subscribes to the template one; until then it is acknowledged and dropped.
            return [InboundEvent::unsupported($this->mode(), $credentials->tenantId, $phoneNumberId, $change)];
        }

        $value = BridgeWire::arrayOrEmpty($change['value'] ?? null);
        $metadata = BridgeWire::arrayOrEmpty($value['metadata'] ?? null);
        $claimed = BridgeWire::stringOrNull($metadata['phone_number_id'] ?? null);

        if ($claimed === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it names no metadata.phone_number_id, so it cannot be matched against the route it '
                .'was delivered on',
            );
        }

        // 2. Recipient. The credentials' answer is the only answer; the payload's claim is checked
        // *against* it (Req 8.4, Property 23).
        if (! hash_equals($phoneNumberId, $claimed)) {
            throw WebhookVerificationException::wrongRecipient($this->mode(), $phoneNumberId, $claimed);
        }

        $events = [];

        // 3. Shape. Messages first, then statuses — document order within each, and Meta puts
        // `messages` before `statuses` when a body carries both.
        foreach (BridgeWire::listOrEmpty($value['messages'] ?? null) as $message) {
            $events[] = $this->messageEvent(
                BridgeWire::arrayOrEmpty($message),
                $metadata,
                BridgeWire::listOrEmpty($value['contacts'] ?? null),
                $credentials,
                $phoneNumberId,
            );
        }

        foreach (BridgeWire::listOrEmpty($value['statuses'] ?? null) as $status) {
            $events[] = $this->statusEvent(
                BridgeWire::arrayOrEmpty($status),
                $metadata,
                $credentials,
                $phoneNumberId,
            );
        }

        return $events;
    }

    /**
     * One `value.messages[]` element as a canonical message event.
     *
     * `text` is the body when there is one, and an interactive reply's own title when the customer
     * pressed a button or picked a row — so the conversation engine sees what the customer
     * *said* either way, and the reply **id** (which is what a flow correlates on) stays available
     * through `InboundEvent::payload()`. A media or location message has no text at all, which is
     * `null` rather than an invented caption.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $metadata
     * @param  list<mixed>  $contacts
     *
     * @throws WebhookVerificationException when the message names no id or no sender
     */
    private function messageEvent(
        array $message,
        array $metadata,
        array $contacts,
        ChannelCredentials $credentials,
        string $phoneNumberId,
    ): InboundEvent {
        $providerMessageId = BridgeWire::stringOrNull($message['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it carries a message with no "id", so nothing could ever be correlated with it',
            );
        }

        $from = BridgeWire::stringOrNull($message['from'] ?? null);

        if ($from === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it carries a message that names no sender',
            );
        }

        return InboundEvent::message(
            mode: $this->mode(),
            // From the credentials, never from the payload.
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            from: $from,
            text: self::textOf($message),
            channelIdentity: $phoneNumberId,
            occurredAt: self::timestampOf($message['timestamp'] ?? null),
            payload: ['metadata' => $metadata, 'message' => $message, 'contacts' => $contacts],
        );
    }

    /**
     * One `value.statuses[]` element as a canonical receipt.
     *
     * The raw `status` string is preserved in the payload because `sent` and `delivered` collapse
     * into one canonical kind (`InboundEventKind`'s own table), and task 9.5 needs the
     * distinction to apply the never-downgrades rule (Req 20.6 / C3, Property 9). Monotonicity is
     * *not* enforced here — see the class docblock.
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
        string $phoneNumberId,
    ): InboundEvent {
        $providerMessageId = BridgeWire::stringOrNull($status['id'] ?? null);

        if ($providerMessageId === null) {
            throw WebhookVerificationException::malformedPayload(
                $this->mode(),
                'it carries a status with no "id", so it says nothing about which message it is about',
            );
        }

        $kind = self::kindOf(BridgeWire::stringOrNull($status['status'] ?? null));
        $payload = ['metadata' => $metadata, 'status' => $status];

        if ($kind === null) {
            // A status Meta added after this release (`deleted`, and whatever follows it):
            // verified, acknowledged, acted on by nobody — not a 403 storm on every Meta feature
            // announcement.
            return InboundEvent::unsupported($this->mode(), $credentials->tenantId, $phoneNumberId, $payload);
        }

        return InboundEvent::receipt(
            kind: $kind,
            mode: $this->mode(),
            tenantId: $credentials->tenantId,
            providerMessageId: $providerMessageId,
            occurredAt: self::timestampOf($status['timestamp'] ?? null),
            failureReason: $kind === InboundEventKind::SendFailure
                ? $credentials->redact(self::failureReasonOf($status))
                : null,
            channelIdentity: $phoneNumberId,
            payload: $payload,
        );
    }

    /**
     * A Meta timestamp as an instant, or null.
     *
     * Meta sends epoch **seconds as a quoted string** (`"1735786800"`), which is the one shape
     * `BridgeWire::timestampOrNull()` cannot read on its own: it takes the string arm and parses
     * the digits as a date, so `1735786800` becomes a year. Converted to an int first, exactly as
     * `BaileysChannelDriver::timestampOf()` does for the sidecar's quoted epochs — and `null`
     * rather than `now()` for anything unreadable, because a fabricated occurrence time is
     * indistinguishable from a real one afterwards.
     */
    private static function timestampOf(mixed $value): ?CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{1,14}$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        return BridgeWire::timestampOrNull($value);
    }

    /**
     * Meta's `statuses[].status` as a canonical kind, or null for one this release does not act on.
     *
     * `sent` and `delivered` are both `DELIVERY_RECEIPT`, which is `InboundEventKind`'s own
     * mapping (*"Meta `statuses[].status` of `sent`/`delivered`"*).
     */
    private static function kindOf(?string $status): ?InboundEventKind
    {
        return match ($status) {
            'sent', 'delivered' => InboundEventKind::DeliveryReceipt,
            'read' => InboundEventKind::ReadReceipt,
            'failed' => InboundEventKind::SendFailure,
            default => null,
        };
    }

    /**
     * A failed status's reason, from Meta's error **title** and code.
     *
     * `title` is a short, fixed phrase (*"Message Undeliverable"*); `message` and `error_data.details`
     * are the fields that quote the request, so they are not read. The code is included because it
     * is what an operator matches against `CloudApiErrorClassifier`, and the whole string still
     * goes through `ChannelCredentials::redact()` before it reaches the event.
     *
     * @param  array<string, mixed>  $status
     */
    private static function failureReasonOf(array $status): string
    {
        $error = BridgeWire::arrayOrEmpty(BridgeWire::listOrEmpty($status['errors'] ?? null)[0] ?? null);
        $title = BridgeWire::stringOrNull($error['title'] ?? null);
        $code = BridgeWire::stringOrNull($error['code'] ?? null);

        if ($title === null && $code === null) {
            return 'Meta reported this message as failed without naming a reason.';
        }

        return sprintf(
            '%s%s',
            $title ?? 'Meta reported this message as failed',
            $code === null ? '' : sprintf(' (code %s)', $code),
        );
    }

    /**
     * What the customer said, for the message shapes that carry words.
     *
     * @param  array<string, mixed>  $message
     */
    private static function textOf(array $message): ?string
    {
        $type = BridgeWire::stringOrNull($message['type'] ?? null);

        if ($type === 'interactive') {
            $interactive = BridgeWire::arrayOrEmpty($message['interactive'] ?? null);

            foreach (['button_reply', 'list_reply'] as $shape) {
                $reply = BridgeWire::arrayOrEmpty($interactive[$shape] ?? null);
                $title = BridgeWire::stringOrNull($reply['title'] ?? null);

                if ($title !== null) {
                    return $title;
                }
            }

            return null;
        }

        if ($type === 'button') {
            // A template quick-reply button: Meta reports it as its own type, with the pressed
            // label in `button.text`.
            return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($message['button'] ?? null)['text'] ?? null);
        }

        return BridgeWire::stringOrNull(BridgeWire::arrayOrEmpty($message['text'] ?? null)['body'] ?? null);
    }

    /**
     * Stamp each event with the batch shape, so a caller holding one can tell there were more.
     *
     * The whole of this class's answer to *"Meta batches and `parseWebhook()` returns one"*: the
     * information is not lost, it is carried where a consumer can find it, and task 8.3 is
     * expected to read it (or, better, to call `parseWebhookBatch()`).
     *
     * @param  list<InboundEvent>  $events
     * @return non-empty-list<InboundEvent>
     */
    private function stamped(array $events): array
    {
        $size = count($events);
        $stamped = [];

        foreach ($events as $index => $event) {
            $stamped[] = new InboundEvent(
                kind: $event->kind,
                mode: $event->mode,
                tenantId: $event->tenantId,
                providerMessageId: $event->providerMessageId,
                from: $event->from,
                channelIdentity: $event->channelIdentity,
                text: $event->text,
                occurredAt: $event->occurredAt,
                failureReason: $event->failureReason,
                payload: [
                    self::BATCH_SIZE_KEY => $size,
                    self::BATCH_INDEX_KEY => $index,
                    ...$event->payload(),
                ],
            );
        }

        /** @var non-empty-list<InboundEvent> $stamped */
        return $stamped;
    }

    /*
    |--------------------------------------------------------------------------
    | The Graph API wire
    |--------------------------------------------------------------------------
    */

    /**
     * One guarded `GET`, decoded.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     *
     * @throws ChannelRequestFailedException when Meta refuses
     * @throws BridgeUnreachableException when Meta never answered, or answered unreadably
     */
    private function get(
        string $operation,
        ChannelCredentials $credentials,
        string $url,
        array $query = [],
        ?int $timeout = null,
    ): array {
        $token = $credentials->requireSecret(self::ACCESS_TOKEN_SECRET_KEY);

        return $this->guarded($operation, $credentials, fn (): Response => $this
            ->request($timeout ?? $this->timeout())
            ->withToken($token)
            ->get($url, $query));
    }

    /**
     * One guarded `POST`, decoded.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ChannelRequestFailedException when Meta refuses
     * @throws BridgeUnreachableException when Meta never answered, or answered unreadably
     */
    private function post(string $operation, ChannelCredentials $credentials, string $url, array $body): array
    {
        $token = $credentials->requireSecret(self::ACCESS_TOKEN_SECRET_KEY);

        return $this->guarded($operation, $credentials, fn (): Response => $this
            ->request($this->timeout())
            ->withToken($token)
            ->post($url, $body));
    }

    /**
     * Run one Graph API request through the breaker and the bounded inline retry, then read it.
     *
     * The failure conversion is here rather than in `ProviderCallGuard` because it is the only
     * provider-specific part: a refusal becomes `ChannelRequestFailedException` carrying Meta's
     * error envelope (which `CloudApiErrorClassifier` reads), and anything else becomes
     * `BridgeUnreachableException` — deliberately **not** the client exception, which can carry
     * the request in a dump, including the bearer token.
     *
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     *
     * @throws ChannelRequestFailedException when Meta refuses
     * @throws BridgeUnreachableException when Meta never answered, or answered unreadably
     */
    private function guarded(string $operation, ChannelCredentials $credentials, callable $call): array
    {
        $response = $this->guard->run(
            $operation,
            ProviderCallGuard::breakerName($this->mode(), $credentials->tenantId),
            function () use ($operation, $call): Response {
                $response = $call();

                if ($response->failed()) {
                    // Raised **inside** the guarded call so the breaker counts a provider refusal
                    // as a failure of the dependency, and so the retry decision is made on the
                    // typed exception rather than on a status code the matrix cannot key on.
                    throw $this->refusalFor($operation, $response);
                }

                return $response;
            },
            fn (Throwable $e): Throwable => $this->failureFor($operation, $e),
        );

        $decoded = $response->json();

        if (! is_array($decoded)) {
            // HTML from a proxy, a truncated body, a bare string: Meta answered 2xx with something
            // that is not a result, so the outcome is unknown and this is a transport failure
            // rather than a result to read (`HttpBridgeClient::decode()`'s reasoning).
            throw BridgeUnreachableException::malformedResponse(
                $operation,
                'a 2xx response whose body is not a JSON object',
            );
        }

        return BridgeWire::arrayOrEmpty($decoded);
    }

    /**
     * Meta's error envelope, read into the platform's typed refusal.
     *
     * `{"error": {"message", "type", "code", "error_subcode", "fbtrace_id"}}` — every field except
     * `message` is carried; that one is the field that quotes the request.
     */
    private function refusalFor(string $operation, Response $response): ChannelRequestFailedException
    {
        $decoded = $response->json();
        $error = is_array($decoded)
            ? BridgeWire::arrayOrEmpty(BridgeWire::arrayOrEmpty($decoded)['error'] ?? null)
            : [];

        return ChannelRequestFailedException::refused(
            mode: $this->mode(),
            operation: $operation,
            status: $response->status(),
            errorCode: BridgeWire::stringOrNull($error['code'] ?? null),
            errorSubcode: BridgeWire::stringOrNull($error['error_subcode'] ?? null),
            errorType: BridgeWire::stringOrNull($error['type'] ?? null),
            retryAfterSeconds: self::retryAfter($response),
            traceId: BridgeWire::stringOrNull($error['fbtrace_id'] ?? null),
        );
    }

    /**
     * What a failure of a Graph API call surfaces as.
     *
     * A refusal or an already-typed transport failure passes through unchanged — it carries what
     * the classifier and the caller need. Anything else (a `ConnectionException`, a client library
     * throwing its own type) becomes a transport failure, which fails **closed**: a send whose
     * transport failed may or may not have happened, and the one answer that is definitely wrong
     * is "sent".
     */
    private function failureFor(string $operation, Throwable $e): Throwable
    {
        return $e instanceof ChannelRequestFailedException
            || $e instanceof BridgeUnreachableException
            || $e instanceof BridgeRequestFailedException
            ? $e
            : BridgeUnreachableException::transportFailed($operation);
    }

    /**
     * A configured pending request: JSON both ways, bounded timeouts, **no internal retries**.
     *
     * `retry()` is deliberately unused, exactly as `HttpBridgeClient` explains: the attempt budget
     * belongs to one layer, and here that is `ProviderCallGuard` composed with `RetryPolicy` in
     * the calling job. A transport that retried internally would multiply the two and turn one
     * duplicate-risk window into three.
     *
     * The client comes from the injected factory, so `Http::fake()` intercepts it in tests and the
     * platform's own timeouts apply.
     */
    private function request(int $timeout): PendingRequest
    {
        return $this->http
            ->asJson()
            ->acceptJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($timeout);
    }

    /**
     * The absolute Graph API URL for one node or edge.
     *
     * `{base}/{version}/{node}` — the version from the tenant's own `api_version` when it set one,
     * so a tenant can stay on an older Graph version while the platform default moves.
     */
    private function graphUrl(ChannelCredentials $credentials, string $node): string
    {
        return sprintf(
            '%s/%s/%s',
            $this->baseUrl(),
            $this->apiVersion($credentials),
            ltrim($node, '/'),
        );
    }

    /**
     * A numeric `Retry-After`, in its numeric form only.
     *
     * The HTTP-date form is ignored rather than parsed, for the reason `RetryPolicy` gives: a
     * clock skew would turn a two-second wait into a two-hour one.
     */
    private static function retryAfter(Response $response): ?int
    {
        $header = trim((string) $response->header('Retry-After'));

        return preg_match('/^\d+$/', $header) === 1 ? (int) $header : null;
    }

    /**
     * The Graph API host, from `wa.channel.cloud_api.base_url`.
     */
    private function baseUrl(): string
    {
        $configured = config('wa.channel.cloud_api.base_url');

        return rtrim(
            is_string($configured) && trim($configured) !== '' ? trim($configured) : self::DEFAULT_BASE_URL,
            '/',
        );
    }

    /**
     * The Graph API version segment: the tenant's `api_version`, then platform config, then the
     * compiled-in default.
     *
     * Normalised to Meta's `vNN.N` spelling, and a value outside that shape is treated as absent —
     * a version segment is part of every URL this driver builds, and one carrying a slash or a
     * space would either 404 or reach a path nobody intended.
     */
    private function apiVersion(?ChannelCredentials $credentials): string
    {
        $candidates = [];

        if ($credentials !== null) {
            $candidates[] = $credentials->config(self::API_VERSION_CONFIG_KEY);
        }

        $candidates[] = config('wa.channel.cloud_api.api_version');

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $version = ltrim(trim($candidate), 'v');

            if (preg_match('/^\d{1,3}(\.\d{1,3})?$/', $version) === 1) {
                return 'v'.$version;
            }
        }

        return self::DEFAULT_API_VERSION;
    }

    private function timeout(): int
    {
        return self::positiveConfig('wa.channel.cloud_api.timeout', self::DEFAULT_TIMEOUT);
    }

    private function connectTimeout(): int
    {
        return self::positiveConfig('wa.channel.cloud_api.connect_timeout', self::DEFAULT_CONNECT_TIMEOUT);
    }

    /**
     * A positive integer from config, falling back to the compiled-in default — so a deleted or
     * nonsensical key degrades to a working timeout rather than to none.
     */
    private static function positiveConfig(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /*
    |--------------------------------------------------------------------------
    | Small readers
    |--------------------------------------------------------------------------
    */

    /**
     * The entry of a WABA phone-number listing whose `id` is `$phoneNumberId`, or null.
     *
     * Compared with `hash_equals()` rather than `===` for the same reason the recipient check is:
     * the value being matched identifies a tenant's number, and using one comparison discipline
     * for both means nobody has to decide per call site which one this is.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function findNumber(array $payload, string $phoneNumberId): ?array
    {
        foreach (BridgeWire::listOrEmpty($payload['data'] ?? null) as $entry) {
            $number = BridgeWire::arrayOrEmpty($entry);
            $id = BridgeWire::stringOrNull($number['id'] ?? null);

            if ($id !== null && hash_equals($phoneNumberId, $id)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * A tenant-facing sentence for a credential probe that Meta refused.
     *
     * Rendered from Meta's **code**, never from its prose — a `401` body echoes part of the token
     * it was sent. `ChannelHealth` runs even this through `ChannelCredentials::redact()`, so the
     * belt and the braces are both on.
     */
    private function healthDetailFor(ChannelRequestFailedException $refused): string
    {
        $reason = match (true) {
            $refused->hasErrorCode(...CloudApiErrorClassifier::AUTH_CODES) => 'Meta rejected the access token — it has expired, been revoked, or was never valid for this number.',
            $refused->hasErrorCode(...CloudApiErrorClassifier::PERMISSION_CODES) => 'The access token is valid but not permitted to manage this number.',
            $refused->hasErrorCode(...CloudApiErrorClassifier::RATE_LIMIT_CODES) => 'Meta is rate-limiting this account, so the credentials could not be confirmed right now.',
            $refused->status === 404 => 'Meta does not know this phone number id.',
            default => 'Meta refused the credential probe.',
        };

        return sprintf(
            '%s (HTTP %d%s). Meta\'s own message is not repeated here: it quotes the request, '
            .'including part of the token it was sent.',
            $reason,
            $refused->status,
            $refused->errorCode === null ? '' : ', code '.$refused->errorCode,
        );
    }

    /**
     * Milliseconds since an `hrtime(true)` reading.
     */
    private static function elapsedMs(float|int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * A human-formatted number reduced to E.164 digits, or null.
     *
     * `HttpBridgeClient::digits()`'s rule: anything that is not 8–15 digits after separators are
     * stripped is `null` rather than a best effort.
     */
    private static function digitsOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        return preg_match('/^\d{8,15}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * A short, stable, non-reversible stand-in for an identifier.
     *
     * The construction `WebhookVerificationException::fingerprint()` uses, so a WABA id named in a
     * registration detail — which is stored and shown — is correlatable without being written down.
     */
    private static function fingerprint(string $value): string
    {
        return $value === '' ? '<none>' : '#'.substr(hash('sha256', $value), 0, 8);
    }
}
